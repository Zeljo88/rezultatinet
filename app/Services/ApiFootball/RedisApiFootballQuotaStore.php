<?php

namespace App\Services\ApiFootball;

use App\Contracts\ApiFootballQuotaStore;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisApiFootballQuotaStore implements ApiFootballQuotaStore
{
    private const PREFIX = 'api-football:quota:';

    private const REPAIR_SCAN_STATE_SCHEMA = 3;

    private const REPAIR_SCAN_STATE_TTL = 604800;

    public function reserve(string $endpointClass, string $caller): array
    {
        $this->assertSafeToken($endpointClass);
        $this->assertSafeToken($caller);
        $day = now('UTC')->format('Y-m-d');
        $nowUtc = now('UTC');
        $ttl = $nowUtc->copy()->addDay()->startOfDay()->timestamp - $nowUtc->timestamp + 3600;
        $budget = (int) config("api_football.budgets.{$endpointClass}", 0);
        $warn = (int) config('api_football.thresholds.warn', 5250);
        $critical = (int) config('api_football.thresholds.critical', 6375);
        $hardStop = (int) config('api_football.thresholds.hard_stop', 7125);
        $now = time();

        $script = <<<'LUA'
local circuit = tonumber(redis.call('GET', KEYS[3]) or '0')
if circuit > tonumber(ARGV[1]) then return {0, 'circuit', circuit} end
local global = tonumber(redis.call('GET', KEYS[1]) or '0')
local class = tonumber(redis.call('HGET', KEYS[2], ARGV[2]) or '0')
if tonumber(ARGV[3]) <= 0 then return {0, 'class_disabled', class} end
if global >= tonumber(ARGV[4]) then return {0, 'global_hard_stop', global} end
if global >= tonumber(ARGV[7]) and ARGV[2] ~= 'live' and ARGV[2] ~= 'fixture_repair' then return {0, 'critical_shed', global} end
if global >= tonumber(ARGV[8]) and (ARGV[2] == 'manual' or ARGV[2] == 'backfill' or ARGV[2] == 'events' or ARGV[2] == 'scorers') then return {0, 'warning_shed', global} end
if class >= tonumber(ARGV[3]) then return {0, 'class_hard_stop', class} end
global = redis.call('INCR', KEYS[1])
class = redis.call('HINCRBY', KEYS[2], ARGV[2], 1)
redis.call('HINCRBY', KEYS[4], ARGV[5], 1)
redis.call('EXPIRE', KEYS[1], ARGV[6])
redis.call('EXPIRE', KEYS[2], ARGV[6])
redis.call('EXPIRE', KEYS[4], ARGV[6])
return {1, global, class}
LUA;

        try {
            $result = Redis::connection('cache')->eval($script, 4,
                self::PREFIX."global:{$day}", self::PREFIX."classes:{$day}", self::PREFIX.'circuit_until',
                self::PREFIX."callers:{$day}", $now, $endpointClass, $budget, $hardStop,
                $endpointClass.'|'.$caller, $ttl, $critical, $warn
            );
        } catch (Throwable $e) {
            throw new \RuntimeException('API quota state unavailable; request denied.', 0, $e);
        }

        if ((int) ($result[0] ?? 0) !== 1) {
            return ['allowed' => false, 'reason' => (string) ($result[1] ?? 'quota_unknown'), 'value' => (int) ($result[2] ?? 0)];
        }

        return ['allowed' => true, 'global' => (int) $result[1], 'class' => (int) $result[2], 'state' => $this->thresholdState((int) $result[1])];
    }

    public function record(string $endpointClass, string $caller, int|string $status, string $outcome): void
    {
        $day = now('UTC')->format('Y-m-d');
        $nowUtc = now('UTC');
        $ttl = $nowUtc->copy()->addDay()->startOfDay()->timestamp - $nowUtc->timestamp + 3600;
        $field = $this->safeField($endpointClass.'|'.$caller.'|'.$status.'|'.$outcome);
        try {
            Redis::connection('cache')->pipeline(function ($pipe) use ($day, $field, $ttl) {
                $key = self::PREFIX."outcomes:{$day}";
                $pipe->hincrby($key, $field, 1);
                $pipe->expire($key, $ttl);
            });
        } catch (Throwable) {
            // Reservation already accounted for the physical attempt. Telemetry failure must not trigger another request.
        }
    }

    public function openCircuit(int $until, string $reason): void
    {
        $until = max(time() + 60, min($until, now('UTC')->addDay()->timestamp));
        try {
            Redis::connection('cache')->pipeline(function ($pipe) use ($until, $reason) {
                $pipe->set(self::PREFIX.'circuit_until', $until);
                $pipe->setex(self::PREFIX.'circuit_reason', max(60, $until - time()), $this->safeField($reason));
            });
        } catch (Throwable) {
            // Future reservations fail closed if Redis remains unavailable.
        }
    }

    public function status(): array
    {
        $day = now('UTC')->format('Y-m-d');
        try {
            $redis = Redis::connection('cache');
            $global = (int) ($redis->get(self::PREFIX."global:{$day}") ?? 0);
            $bootstrap = (int) ($redis->get(self::PREFIX."bootstrap:{$day}") ?? 0);
            $classes = $redis->hgetall(self::PREFIX."classes:{$day}");
            $circuitUntil = (int) ($redis->get(self::PREFIX.'circuit_until') ?? 0);

            return [
                'day' => $day,
                'global' => $global,
                'observed_physical' => array_sum(array_map('intval', $classes)),
                'bootstrap' => $bootstrap,
                'threshold_state' => $this->thresholdState($global),
                'classes' => $classes,
                'callers' => $redis->hgetall(self::PREFIX."callers:{$day}"),
                'outcomes' => $redis->hgetall(self::PREFIX."outcomes:{$day}"),
                'circuit_until' => $circuitUntil,
                'circuit_reason' => $redis->get(self::PREFIX.'circuit_reason'),
                'reset_at' => now('UTC')->addDay()->startOfDay()->toIso8601String(),
            ];
        } catch (Throwable $e) {
            throw new \RuntimeException('API quota state unavailable.', 0, $e);
        }
    }

    public function acquireRepair(int $fixtureId, string $caller): bool
    {
        $key = self::PREFIX."repair:{$fixtureId}";
        $lock = self::PREFIX."repair-lock:{$fixtureId}";
        $max = (int) config('api_football.repair.max_attempts_per_fixture', 4);
        $script = <<<'LUA'
if redis.call('SET', KEYS[2], ARGV[1], 'NX', 'EX', 300) == false then return 0 end
local terminal = redis.call('HGET', KEYS[1], 'terminal')
local attempts = tonumber(redis.call('HGET', KEYS[1], 'attempts') or '0')
local next_at = tonumber(redis.call('HGET', KEYS[1], 'next_at') or '0')
if terminal == '1' or attempts >= tonumber(ARGV[2]) or next_at > tonumber(ARGV[3]) then
 redis.call('DEL', KEYS[2]); return 0
end
redis.call('HSET', KEYS[1], 'caller', ARGV[4])
redis.call('EXPIRE', KEYS[1], 2592000)
return 1
LUA;
        try {
            return (int) Redis::connection('cache')->eval($script, 2, $key, $lock, bin2hex(random_bytes(8)), $max, time(), $caller) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    public function recordRepair(int $fixtureId, string $outcome, bool $terminal = false): void
    {
        $key = self::PREFIX."repair:{$fixtureId}";
        $lock = self::PREFIX."repair-lock:{$fixtureId}";
        $base = (int) config('api_football.repair.base_cooldown_seconds', 7200);
        $max = (int) config('api_football.repair.max_attempts_per_fixture', 4);
        $script = <<<'LUA'
local attempts = redis.call('HINCRBY', KEYS[1], 'attempts', 1)
local is_terminal = ARGV[3] == '1' or attempts >= tonumber(ARGV[4])
local cooldown = tonumber(ARGV[1]) * (2 ^ math.max(0, attempts - 1))
redis.call('HSET', KEYS[1], 'outcome', ARGV[2], 'last_at', ARGV[5], 'next_at', tonumber(ARGV[5]) + cooldown, 'terminal', is_terminal and 1 or 0)
redis.call('EXPIRE', KEYS[1], 2592000)
redis.call('DEL', KEYS[2])
return attempts
LUA;
        try {
            Redis::connection('cache')->eval($script, 2, $key, $lock, $base, $this->safeField($outcome), $terminal ? 1 : 0, $max, time());
        } catch (Throwable) {
            // A missing repair state is conservative: the short lock remains until expiry.
        }
    }

    public function releaseRepair(int $fixtureId): void
    {
        try {
            Redis::connection('cache')->del(self::PREFIX."repair-lock:{$fixtureId}");
        } catch (Throwable) {
            // Fail conservatively: the short repair lock expires after five minutes.
        }
    }

    public function repairScanState(string $scan): ?array
    {
        $this->assertSafeToken($scan);

        try {
            $value = Redis::connection('cache')->get($this->repairScanStateKey($scan));
            if ($value === null) {
                return null;
            }

            $state = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($state)) {
                return null;
            }

            return $this->normalizeRepairScanState($state);
        } catch (Throwable) {
            // Missing, expired, malformed, or unavailable state restarts bounded initialization.
            return null;
        }
    }

    public function compareAndSetRepairScanState(string $scan, ?array $expected, array $next): bool
    {
        $this->assertSafeToken($scan);
        $expectedJson = $expected === null
            ? ''
            : json_encode($this->normalizeRepairScanState($expected), JSON_THROW_ON_ERROR);
        $nextJson = json_encode($this->normalizeRepairScanState($next), JSON_THROW_ON_ERROR);
        $script = <<<'LUA'
local function valid(state)
 if type(state) ~= 'table' or state.schema ~= 3 then return false end
 for _, field in ipairs({'generation', 'cursor', 'ceiling'}) do
  if type(state[field]) ~= 'number' or state[field] ~= math.floor(state[field]) then return false end
 end
 return state.generation >= 1 and state.cursor >= 0 and state.ceiling >= 0 and state.cursor <= state.ceiling
end

local current = redis.call('GET', KEYS[1])
if ARGV[1] == '' and current then
 local decoded, previous = pcall(cjson.decode, current)
 if decoded and valid(previous) then return 0 end
 current = false
elseif (current or '') ~= ARGV[1] then
 return 0
end

local next = cjson.decode(ARGV[2])
if not valid(next) then return 0 end

if current then
 local previous = cjson.decode(current)
 if next.generation == previous.generation then
  if next.ceiling ~= previous.ceiling or next.cursor < previous.cursor then return 0 end
 elseif next.generation == previous.generation + 1 then
  if previous.cursor < previous.ceiling or next.cursor ~= 0 then return 0 end
 else
  return 0
 end
else
 if next.generation ~= 1 or next.cursor ~= 0 then return 0 end
end

redis.call('SETEX', KEYS[1], ARGV[3], ARGV[2])
return 1
LUA;
        try {
            return (int) Redis::connection('cache')->eval(
                $script,
                1,
                $this->repairScanStateKey($scan),
                $expectedJson,
                $nextJson,
                self::REPAIR_SCAN_STATE_TTL,
            ) === 1;
        } catch (Throwable) {
            // Malformed state self-heals atomically; unavailable Redis remains fail-closed.
            return false;
        }
    }

    /**
     * The application name and environment are non-secret, stable deployment
     * identity inputs. Their full SHA-256 digest keeps the Redis token safe and
     * makes environments distinct unless both normalized inputs are identical
     * (or a cryptographically negligible SHA-256 collision occurs).
     */
    private function repairScanStateKey(string $scan): string
    {
        $application = strtolower(trim((string) config('app.name', 'laravel')));
        $environment = strtolower(trim((string) config('app.env', 'production')));
        $namespace = hash('sha256', $application."\0".$environment);

        return self::PREFIX."repair-scan:v3:env:{$namespace}:{$scan}";
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{schema: int, generation: int, cursor: int, ceiling: int}
     */
    private function normalizeRepairScanState(array $state): array
    {
        $normalized = [
            'schema' => (int) ($state['schema'] ?? 0),
            'generation' => (int) ($state['generation'] ?? 0),
            'cursor' => (int) ($state['cursor'] ?? -1),
            'ceiling' => (int) ($state['ceiling'] ?? -1),
        ];

        if (
            $normalized['schema'] !== self::REPAIR_SCAN_STATE_SCHEMA
            || $normalized['generation'] < 1
            || $normalized['cursor'] < 0
            || $normalized['ceiling'] < 0
            || $normalized['cursor'] > $normalized['ceiling']
        ) {
            throw new \UnexpectedValueException('Invalid fixture repair scan state.');
        }

        return $normalized;
    }

    private function thresholdState(int $count): string
    {
        if ($count >= (int) config('api_football.thresholds.hard_stop', 7125)) {
            return 'hard_stop';
        }
        if ($count >= (int) config('api_football.thresholds.critical', 6375)) {
            return 'critical';
        }
        if ($count >= (int) config('api_football.thresholds.warn', 5250)) {
            return 'warning';
        }

        return 'normal';
    }

    private function assertSafeToken(string $value): void
    {
        if (! preg_match('/^[a-z0-9_.:-]+$/i', $value)) {
            throw new \InvalidArgumentException('Unsafe telemetry token.');
        }
    }

    private function safeField(string $value): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9_.:|=-]/', '_', $value) ?: 'unknown', 0, 180);
    }
}
