<?php

namespace App\Services;

use App\Models\Fixture;
use App\Models\FixtureScore;
use App\Models\League;
use App\Models\Team;
use App\Support\FootballFixtureStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class FixtureCalendarImporter
{
    /** Existing match state owned by live polling/finalization must stay untouched. */
    private const PROTECTED_EXISTING_STATUSES = [
        '1H', '2H', 'HT', 'ET', 'BT', 'P', 'SUSP', 'INT', 'LIVE',
        'FT', 'AET', 'PEN', 'AWD', 'WO', 'CANC', 'ABD',
    ];

    /** Every status currently documented by API-Football for football fixtures. */
    private const ACCEPTED_STATUSES = [
        'TBD', 'NS', '1H', 'HT', '2H', 'ET', 'BT', 'P', 'SUSP', 'INT',
        'FT', 'AET', 'PEN', 'PST', 'CANC', 'ABD', 'AWD', 'WO', 'LIVE',
    ];

    /** States the calendar path may create or apply to an unprotected fixture. */
    private const IMPORTABLE_CALENDAR_STATUSES = ['TBD', 'NS', 'PST', 'CANC'];

    /** Maximum sanitized non-upsert row records returned for one provider date. */
    private const ROW_TELEMETRY_LIMIT = 100;

    /**
     * Every accepted row uses its own transaction with three bounded deadlock retries.
     *
     * @return array{rows: int, accepted: int, upserted: int, protected: int, skipped: int, failed: int,
     *   skip_reasons: array<string, int>, failure_reasons: array<string, int>,
     *   row_telemetry: list<array{fixture_id: ?int, league_id: ?int, status: ?string, date_bucket: ?string, reason: string}>,
     *   row_telemetry_total: int, row_telemetry_truncated: int, row_telemetry_limit: int}
     */
    public function import(array $payload): array
    {
        $result = [
            'rows' => count($payload), 'accepted' => 0, 'upserted' => 0,
            'protected' => 0, 'skipped' => 0, 'failed' => 0,
            'skip_reasons' => [], 'failure_reasons' => [],
            'row_telemetry' => [], 'row_telemetry_total' => 0,
            'row_telemetry_truncated' => 0, 'row_telemetry_limit' => self::ROW_TELEMETRY_LIMIT,
        ];

        foreach ($payload as $data) {
            $telemetry = $this->sanitizeTelemetry($data);
            try {
                $validation = $this->validateRow($data);
            } catch (Throwable) {
                $validation = ['reason' => 'malformed_row'];
            }
            if (isset($validation['reason'])) {
                $this->incrementReason($result, 'skipped', 'skip_reasons', $validation['reason']);
                $this->recordTelemetry($result, $telemetry, $validation['reason']);

                continue;
            }

            $data = $validation['data'];
            $fixtureId = $data['fixture']['id'];
            $leagueApiId = $data['league']['id'];
            $homeApiId = $data['teams']['home']['id'];
            $awayApiId = $data['teams']['away']['id'];
            $timestamp = $data['fixture']['timestamp'];
            $season = $data['league']['season'];
            $incomingStatus = $data['fixture']['status']['short'];

            try {
                $league = League::where('api_league_id', $leagueApiId)->first();
            } catch (Throwable $e) {
                $result['accepted']++;
                $reason = $this->failureReason($e);
                $this->incrementReason($result, 'failed', 'failure_reasons', $reason);
                $this->recordTelemetry($result, $telemetry, 'failure_'.$reason);

                continue;
            }
            if (! $league) {
                $this->incrementReason($result, 'skipped', 'skip_reasons', 'unknown_league');
                $this->recordTelemetry($result, $telemetry, 'unknown_league');

                continue;
            }

            try {
                $outcome = DB::transaction(
                    function () use ($data, $fixtureId, $league, $homeApiId, $awayApiId, $timestamp, $season, $incomingStatus): string {
                        $existing = Fixture::where('api_fixture_id', $fixtureId)->lockForUpdate()->first();
                        if ($existing && in_array(FootballFixtureStatus::normalize($existing->status_short), self::PROTECTED_EXISTING_STATUSES, true)) {
                            return 'protected';
                        }
                        if (! in_array($incomingStatus, self::IMPORTABLE_CALENDAR_STATUSES, true)) {
                            return 'calendar_status_not_importable';
                        }

                        $homeTeam = $this->persistTeam($homeApiId, $data['teams']['home']);
                        $awayTeam = $this->persistTeam($awayApiId, $data['teams']['away']);

                        $attributes = [
                            'league_id' => $league->id,
                            'home_team_id' => $homeTeam->id,
                            'away_team_id' => $awayTeam->id,
                            'season' => $season,
                            'round' => $data['league']['round'] ?? null,
                            'kick_off' => CarbonImmutable::createFromTimestampUTC($timestamp)->format('Y-m-d H:i:s'),
                            'status_long' => $data['fixture']['status']['long'] ?? null,
                            'status_short' => $incomingStatus,
                            'elapsed_minute' => $data['fixture']['status']['elapsed'] ?? null,
                            'venue_name' => $data['fixture']['venue']['name'] ?? null,
                            'referee' => $data['fixture']['referee'] ?? null,
                        ];
                        $fixture = Fixture::where('api_fixture_id', $fixtureId)->lockForUpdate()->first();
                        if ($fixture && in_array(FootballFixtureStatus::normalize($fixture->status_short), self::PROTECTED_EXISTING_STATUSES, true)) {
                            return 'protected';
                        }
                        if (! $fixture) {
                            $fixture = Fixture::query()->createOrFirst(['api_fixture_id' => $fixtureId], $attributes);
                            $created = $fixture->wasRecentlyCreated;
                            $fixture = Fixture::whereKey($fixture->getKey())->lockForUpdate()->firstOrFail();
                            if (! $created && in_array(FootballFixtureStatus::normalize($fixture->status_short), self::PROTECTED_EXISTING_STATUSES, true)) {
                                return 'protected';
                            }
                        }
                        $fixture->fill($attributes)->save();

                        $scoreAttributes = [
                            'goals_home' => $data['goals']['home'] ?? null,
                            'goals_away' => $data['goals']['away'] ?? null,
                            'home_fulltime' => $data['score']['fulltime']['home'] ?? null,
                            'away_fulltime' => $data['score']['fulltime']['away'] ?? null,
                            'home_halftime' => $data['score']['halftime']['home'] ?? null,
                            'away_halftime' => $data['score']['halftime']['away'] ?? null,
                            'home_extratime' => $data['score']['extratime']['home'] ?? null,
                            'away_extratime' => $data['score']['extratime']['away'] ?? null,
                            'home_penalties' => $data['score']['penalty']['home'] ?? null,
                            'away_penalties' => $data['score']['penalty']['away'] ?? null,
                        ];
                        $score = FixtureScore::query()->createOrFirst(['fixture_id' => $fixture->id], $scoreAttributes);
                        $score->fill($scoreAttributes)->save();

                        return 'upserted';
                    },
                    attempts: 3,
                );

                if ($outcome === 'calendar_status_not_importable') {
                    $this->incrementReason($result, 'skipped', 'skip_reasons', $outcome);
                } else {
                    $result['accepted']++;
                    $result[$outcome]++;
                }
                if ($outcome !== 'upserted') {
                    $this->recordTelemetry(
                        $result,
                        $telemetry,
                        $outcome === 'protected' ? 'protected_existing' : $outcome,
                    );
                }
            } catch (Throwable $e) {
                $result['accepted']++;
                $reason = $this->failureReason($e);
                $this->incrementReason($result, 'failed', 'failure_reasons', $reason);
                $this->recordTelemetry($result, $telemetry, 'failure_'.$reason);
            }
        }

        return $result;
    }

    private function persistTeam(int $apiId, array $data): Team
    {
        $team = Team::where('api_team_id', $apiId)->lockForUpdate()->first();
        if (! $team) {
            $team = Team::query()->createOrFirst(
                ['api_team_id' => $apiId],
                ['name' => $data['name'], 'logo_url' => $data['logo']],
            );
            $team = Team::whereKey($team->getKey())->lockForUpdate()->firstOrFail();
        }

        $team->fill(['name' => $data['name'], 'logo_url' => $data['logo']])->save();

        return $team;
    }

    /** @return array{data: array}|array{reason: string} */
    private function validateRow(mixed $data): array
    {
        if (! is_array($data)
            || ! $this->hasArrays($data, ['fixture', 'league', 'teams', 'goals', 'score'])
            || ! $this->hasArrays($data['fixture'], ['status'])
            || ! $this->hasArrays($data['teams'], ['home', 'away'])
            || ! $this->hasArrays($data['score'], ['halftime', 'fulltime', 'extratime', 'penalty'])) {
            return ['reason' => 'malformed_row'];
        }

        foreach ([
            [$data['fixture'], 'id', 4294967295],
            [$data['league'], 'id', 4294967295],
            [$data['teams']['home'], 'id', 4294967295],
            [$data['teams']['away'], 'id', 4294967295],
            [$data['league'], 'season', 65535],
            [$data['fixture'], 'timestamp', PHP_INT_MAX],
        ] as [$container, $key, $maximum]) {
            if ($this->positiveInt($container[$key] ?? null, $maximum) === null) {
                return ['reason' => 'malformed_row'];
            }
        }

        $statusValue = $data['fixture']['status']['short'] ?? null;
        if (! is_string($statusValue)) {
            return ['reason' => 'malformed_row'];
        }
        $status = FootballFixtureStatus::normalize($statusValue);
        if (! in_array($status, self::ACCEPTED_STATUSES, true)) {
            return ['reason' => 'unknown_status'];
        }

        foreach (['home', 'away'] as $side) {
            $team = &$data['teams'][$side];
            $logo = $this->nullableString($team, 'logo', 255);
            if (! is_string($team['name'] ?? null) || trim($team['name']) === ''
                || mb_strlen($team['name']) > 100 || $logo === false) {
                return ['reason' => 'malformed_row'];
            }
            $team['name'] = trim($team['name']);
            $team['logo'] = $logo;
            unset($team);
        }

        foreach ([
            [$data['league'], 'round', 50],
            [$data['fixture']['status'], 'long', 50],
            [$data['fixture'], 'referee', 100],
        ] as [$container, $key, $maximum]) {
            if ($this->nullableString($container, $key, $maximum) === false) {
                return ['reason' => 'malformed_row'];
            }
        }
        if ($this->nullableInt($data['fixture']['status'], 'elapsed', 65535) === false) {
            return ['reason' => 'malformed_row'];
        }

        if (array_key_exists('venue', $data['fixture'])) {
            if (! is_array($data['fixture']['venue'])
                || $this->nullableString($data['fixture']['venue'], 'name', 100) === false) {
                return ['reason' => 'malformed_row'];
            }
        } else {
            $data['fixture']['venue'] = ['name' => null];
        }

        foreach ([
            [$data['goals'], 'home'], [$data['goals'], 'away'],
            [$data['score']['halftime'], 'home'], [$data['score']['halftime'], 'away'],
            [$data['score']['fulltime'], 'home'], [$data['score']['fulltime'], 'away'],
            [$data['score']['extratime'], 'home'], [$data['score']['extratime'], 'away'],
            [$data['score']['penalty'], 'home'], [$data['score']['penalty'], 'away'],
        ] as [$container, $key]) {
            if ($this->nullableInt($container, $key, 255, required: true) === false) {
                return ['reason' => 'malformed_row'];
            }
        }

        try {
            CarbonImmutable::createFromTimestampUTC($data['fixture']['timestamp']);
        } catch (Throwable) {
            return ['reason' => 'malformed_row'];
        }

        $data['fixture']['status']['short'] = $status;

        return ['data' => $data];
    }

    private function hasArrays(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data) || ! is_array($data[$key])) {
                return false;
            }
        }

        return true;
    }

    private function positiveInt(mixed $value, int $maximum): ?int
    {
        return is_int($value) && $value > 0 && $value <= $maximum ? $value : null;
    }

    private function nullableString(array $data, string $key, int $maximum): string|false|null
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return is_string($data[$key]) && mb_strlen($data[$key]) <= $maximum ? $data[$key] : false;
    }

    private function nullableInt(array $data, string $key, int $maximum, bool $required = false): int|false|null
    {
        if (! array_key_exists($key, $data)) {
            return $required ? false : null;
        }
        if ($data[$key] === null) {
            return null;
        }

        return is_int($data[$key]) && $data[$key] >= 0 && $data[$key] <= $maximum
            ? $data[$key]
            : false;
    }

    private function failureReason(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            $code = (string) $e->getCode();
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($code === '40001' || $driverCode === 1213) {
                return 'deadlock_exhausted';
            }
            if ($driverCode === 1205) {
                return 'lock_timeout';
            }

            return 'database_error';
        }

        return 'persistence_error';
    }

    /**
     * @return array{fixture_id: ?int, league_id: ?int, status: ?string, date_bucket: ?string}
     */
    private function sanitizeTelemetry(mixed $data): array
    {
        $telemetry = [
            'fixture_id' => null,
            'league_id' => null,
            'status' => null,
            'date_bucket' => null,
        ];
        if (! is_array($data)) {
            return $telemetry;
        }

        $fixture = is_array($data['fixture'] ?? null) ? $data['fixture'] : [];
        $league = is_array($data['league'] ?? null) ? $data['league'] : [];
        $telemetry['fixture_id'] = $this->positiveInt($fixture['id'] ?? null, 4294967295);
        $telemetry['league_id'] = $this->positiveInt($league['id'] ?? null, 4294967295);

        $statusContainer = is_array($fixture['status'] ?? null) ? $fixture['status'] : [];
        $rawStatus = $statusContainer['short'] ?? null;
        if (is_string($rawStatus)) {
            $normalized = FootballFixtureStatus::normalize($rawStatus);
            if ($normalized !== null && preg_match('/\A[A-Z0-9_-]{1,16}\z/', $normalized) === 1) {
                $telemetry['status'] = $normalized;
            }
        }

        $timestamp = $this->positiveInt($fixture['timestamp'] ?? null, PHP_INT_MAX);
        if ($timestamp !== null) {
            try {
                $telemetry['date_bucket'] = CarbonImmutable::createFromTimestampUTC($timestamp)->format('Y-m-d');
            } catch (Throwable) {
                // A malformed timestamp has no safe date bucket.
            }
        }

        return $telemetry;
    }

    /**
     * @param  array{fixture_id: ?int, league_id: ?int, status: ?string, date_bucket: ?string}  $telemetry
     */
    private function recordTelemetry(array &$result, array $telemetry, string $reason): void
    {
        $result['row_telemetry_total']++;
        if (count($result['row_telemetry']) >= self::ROW_TELEMETRY_LIMIT) {
            $result['row_telemetry_truncated']++;

            return;
        }

        $result['row_telemetry'][] = [...$telemetry, 'reason' => $reason];
    }

    private function incrementReason(array &$result, string $counter, string $reasons, string $reason): void
    {
        $result[$counter]++;
        $result[$reasons][$reason] = ($result[$reasons][$reason] ?? 0) + 1;
        ksort($result[$reasons]);
    }
}
