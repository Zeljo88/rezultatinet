<?php

namespace App\Services\ApiFootball;

use App\Contracts\ApiFootballQuotaStore;
use App\Exceptions\ApiFootballBlocked;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApiFootballGateway
{
    public function __construct(private readonly ApiFootballQuotaStore $quota) {}

    public function get(string $path, array $query, string $endpointClass, string $caller): array
    {
        if (! in_array('football', config('api_football.enabled_sports', []), true)) {
            throw new ApiFootballBlocked('Football provider is not allowlisted.');
        }

        $maxAttempts = min(2, max(1, (int) config('api_football.max_attempts', 2)));
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $reservation = $this->quota->reserve($endpointClass, $caller);
            } catch (Throwable $e) {
                throw new ApiFootballBlocked('API-Football quota state unavailable; request denied.', 0, $e);
            }
            if (! ($reservation['allowed'] ?? false)) {
                $this->limitedLog('blocked:'.$endpointClass.':'.($reservation['reason'] ?? 'unknown'), 'warning', 'API-Football request blocked', [
                    'endpoint_class' => $endpointClass, 'caller' => $caller, 'reason' => $reservation['reason'] ?? 'unknown',
                ]);
                throw new ApiFootballBlocked('API-Football request blocked: '.($reservation['reason'] ?? 'quota unavailable'));
            }

            $started = microtime(true);
            try {
                $response = Http::baseUrl((string) config('api_football.base_url'))
                    ->withHeaders([
                        'x-apisports-key' => (string) config('services.api_football.key'),
                    ])
                    ->acceptJson()
                    ->timeout((int) config('api_football.timeout', 15))
                    ->get($path, $query);
            } catch (ConnectionException $e) {
                $this->recordAttempt($endpointClass, $caller, 'network', 'transient_error', $attempt, $started);
                if ($attempt < $maxAttempts) {
                    $this->backoff($attempt);

                    continue;
                }

                return [];
            } catch (Throwable $e) {
                $this->recordAttempt($endpointClass, $caller, 'exception', 'failed', $attempt, $started);

                return [];
            }

            $status = $response->status();
            if ($status === 429) {
                $until = $this->retryUntil($response);
                $this->quota->openCircuit($until, 'provider_429');
                $this->recordAttempt($endpointClass, $caller, 429, 'rate_limited', $attempt, $started);
                $this->limitedLog('429', 'critical', 'API-Football rate limited; circuit opened', [
                    'endpoint_class' => $endpointClass, 'caller' => $caller, 'circuit_until' => gmdate(DATE_ATOM, $until),
                ]);

                return [];
            }

            if ($response->successful()) {
                $this->recordAttempt($endpointClass, $caller, $status, 'success', $attempt, $started);

                return $response->json('response', []);
            }

            if ($status >= 500 && $attempt < $maxAttempts) {
                $this->recordAttempt($endpointClass, $caller, $status, 'transient_error', $attempt, $started);
                $this->backoff($attempt);

                continue;
            }

            $this->recordAttempt($endpointClass, $caller, $status, $status >= 500 ? 'transient_exhausted' : 'client_error', $attempt, $started);

            return [];
        }

        return [];
    }

    private function recordAttempt(string $class, string $caller, int|string $status, string $outcome, int $attempt, float $started): void
    {
        $this->quota->record($class, $caller, $status, $outcome);
        Log::channel('api_football')->info('provider_attempt', [
            'endpoint_class' => $class, 'caller' => $caller, 'attempt' => $attempt, 'status' => $status,
            'outcome' => $outcome, 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    private function retryUntil(Response $response): int
    {
        $now = time();
        $retryAfter = trim((string) $response->header('Retry-After', ''));
        if (ctype_digit($retryAfter)) {
            return $now + max(60, min((int) $retryAfter, 86400));
        }
        if ($retryAfter !== '' && ($parsed = strtotime($retryAfter)) !== false && $parsed > $now) {
            return min($parsed, $now + 86400);
        }

        foreach (['x-ratelimit-requests-reset', 'x-ratelimit-reset'] as $header) {
            $value = trim((string) $response->header($header, ''));
            if (ctype_digit($value)) {
                $number = (int) $value;

                return $number > $now ? min($number, $now + 86400) : $now + max(60, min($number, 86400));
            }
        }

        return $now + 900;
    }

    private function backoff(int $attempt): void
    {
        $base = max(0, (int) config('api_football.retry_base_ms', 250));
        if ($base === 0) {
            return;
        }
        usleep(($base * (2 ** ($attempt - 1)) + random_int(0, $base)) * 1000);
    }

    private function limitedLog(string $key, string $level, string $message, array $context): void
    {
        try {
            if (! Cache::store('redis')->add('api-football:log:'.sha1($key), 1, 300)) {
                return;
            }
        } catch (Throwable) {
            // Continue with one safe log when rate-limit cache is unavailable.
        }
        Log::channel('api_football')->log($level, $message, $context);
    }
}
