<?php

namespace App\Services;

use App\Models\Fixture;
use App\Models\FixtureScore;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

final class StaleFixturePolicy
{
    public const MANIFEST_SHA256 = '0c91ee704d554546f26a265c23e03287d56f7173f45b52292c02e5bff82c7233';

    public const CALLER = 'StaleFixtureReconciliation';

    public const SCORE_FIELDS = ['goals_home', 'goals_away', 'home_halftime', 'away_halftime', 'home_fulltime', 'away_fulltime', 'home_extratime', 'away_extratime', 'home_penalties', 'away_penalties'];

    public function cohort(): array
    {
        $bytes = file_get_contents(resource_path('data/stale-fixture-cohort.json'));
        if (hash('sha256', $bytes) !== self::MANIFEST_SHA256) {
            throw new UnexpectedValueException('manifest_drift');
        }

        return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    }

    public function snapshot(Fixture $fixture, ?FixtureScore $score): array
    {
        return ['fixture' => $fixture->getRawOriginal(), 'score' => $score?->getRawOriginal()];
    }

    public function eligibility(array $snapshot, array $identity, CarbonImmutable $now): ?string
    {
        $f = $snapshot['fixture'];
        $s = $snapshot['score'];
        foreach (['id', 'api_fixture_id', 'league_id', 'home_team_id', 'away_team_id', 'season'] as $key) {
            if ((int) ($f[$key] ?? 0) !== $identity[$key]) {
                return 'identity_changed';
            }
        }
        if (! $s || (int) $s['id'] !== $identity['score_id'] || (int) $s['fixture_id'] !== $identity['id']) {
            return 'score_identity_changed';
        }
        if (! in_array($f['status_short'], ['NS', 'PST'], true)) {
            return 'protected_status';
        }
        foreach (['elapsed_minute', 'elapsed_extra'] as $field) {
            if (($f[$field] ?? null) !== null) {
                return 'progressing';
            }
        }
        foreach (self::SCORE_FIELDS as $field) {
            if (! array_key_exists($field, $s) || $s[$field] !== null) {
                return 'progressing_score';
            }
        }
        if (empty($f['kick_off']) || empty($f['updated_at'])) {
            return 'missing_clock';
        }
        $kickoff = CarbonImmutable::parse($f['kick_off'], 'UTC');
        if ($kickoff < $now->subDays(30)) {
            return 'older_than_30_days_needs_review';
        }
        if ($kickoff > $now->subHours($f['status_short'] === 'NS' ? 6 : 24)) {
            return 'too_recent_or_future';
        }
        foreach ([$f['updated_at'], $s['updated_at'] ?? $s['created_at'] ?? $f['created_at'] ?? null] as $time) {
            if (! $time || CarbonImmutable::parse($time, 'UTC') > $now->subHours(2)) {
                return 'recently_changed';
            }
        }

        return null;
    }

    /** Validate exact single-ID facts. Live observations are evidence only. */
    public function response(array $rows, array $identity): array
    {
        if ($rows === []) {
            return ['outcome' => 'empty'];
        }
        if (count($rows) !== 1 || ! array_is_list($rows) || ! is_array($rows[0])) {
            throw new UnexpectedValueException('ambiguous_response');
        }
        $d = $rows[0];
        foreach ([['fixture.id', 'api_fixture_id'], ['league.id', 'api_league_id'], ['league.season', 'season'], ['teams.home.id', 'home_api_id'], ['teams.away.id', 'away_api_id']] as [$path, $field]) {
            if (data_get($d, $path) !== $identity[$field]) {
                throw new UnexpectedValueException('provider_identity_mismatch');
            }
        }
        $status = data_get($d, 'fixture.status.short');
        if (! in_array($status, ['NS', 'TBD', 'PST', 'FT', 'AET', 'PEN', 'CANC', 'ABD', 'AWD', 'WO', '1H', 'HT', '2H', 'ET', 'BT', 'P', 'LIVE', 'INT', 'SUSP'], true)) {
            throw new UnexpectedValueException('unknown_status');
        }
        $timestamp = data_get($d, 'fixture.timestamp');
        $date = data_get($d, 'fixture.date');
        if (! is_int($timestamp) || $timestamp < 946684800 || $timestamp > 4102444800
            || ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $date)
            || CarbonImmutable::parse($date)->timestamp !== $timestamp
            || CarbonImmutable::parse($date)->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $date)) {
            throw new UnexpectedValueException('invalid_kickoff');
        }
        $long = data_get($d, 'fixture.status.long');
        if ($long !== null && (! is_string($long) || mb_strlen($long) > 50)) {
            throw new UnexpectedValueException('invalid_status_text');
        }
        $elapsed = $this->nullableInt(data_get($d, 'fixture.status', []), 'elapsed', 65535);
        $extra = $this->nullableInt(data_get($d, 'fixture.status', []) + ['extra' => null], 'extra', 65535);
        $scores = [];
        foreach (['goals' => ['goals_home', 'goals_away'], 'score.halftime' => ['home_halftime', 'away_halftime'], 'score.fulltime' => ['home_fulltime', 'away_fulltime'], 'score.extratime' => ['home_extratime', 'away_extratime'], 'score.penalty' => ['home_penalties', 'away_penalties']] as $path => [$home, $away]) {
            $pair = data_get($d, $path);
            if (! is_array($pair)) {
                throw new UnexpectedValueException('missing_score_pair');
            }
            $scores[$home] = $this->nullableInt($pair, 'home', 255);
            $scores[$away] = $this->nullableInt($pair, 'away', 255);
            if (($scores[$home] === null) !== ($scores[$away] === null)) {
                throw new UnexpectedValueException('partial_score_pair');
            }
        }
        if (in_array($status, ['NS', 'TBD', 'PST'], true)
            && ($elapsed !== null || $extra !== null || array_filter($scores, fn ($v) => $v !== null) !== [])) {
            throw new UnexpectedValueException('prematch_progression');
        }
        if (in_array($status, ['FT', 'AET', 'PEN'], true)) {
            foreach (['home', 'away'] as $side) {
                $goals = $scores['goals_'.$side];
                $full = $scores[$side.'_fulltime'];
                $half = $scores[$side.'_halftime'];
                $et = $scores[$side.'_extratime'];
                if ($goals === null || $full === null || ($half !== null && $half > $full)
                    || ($status === 'FT' && ($goals !== $full || $et !== null || $scores[$side.'_penalties'] !== null))
                    || ($status !== 'FT' && ($et === null || $goals !== $et || $full > $et))
                    || ($status === 'AET' && $scores[$side.'_penalties'] !== null)
                    || ($status === 'PEN' && $scores[$side.'_penalties'] === null)) {
                    throw new UnexpectedValueException('inconsistent_completed_score');
                }
            }
            if ($status === 'PEN' && ($scores['goals_home'] !== $scores['goals_away'] || $scores['home_penalties'] === $scores['away_penalties'])) {
                throw new UnexpectedValueException('inconsistent_penalty_result');
            }
        }
        if (in_array($status, ['1H', 'HT', '2H', 'ET', 'BT', 'P', 'LIVE', 'INT', 'SUSP'], true)) {
            return ['outcome' => 'observed_progressing'];
        }

        return ['outcome' => 'validated', 'fixture' => [
            'status_short' => $status, 'status_long' => $long,
            'kick_off' => CarbonImmutable::createFromTimestampUTC($timestamp)->format('Y-m-d H:i:s'),
            'elapsed_minute' => $elapsed, 'elapsed_extra' => $extra,
        ], 'score' => $scores];
    }

    private function nullableInt(array $container, string $key, int $max): ?int
    {
        if (! array_key_exists($key, $container) || ($container[$key] !== null && (! is_int($container[$key]) || $container[$key] < 0 || $container[$key] > $max))) {
            throw new UnexpectedValueException('invalid_nullable_integer');
        }

        return $container[$key];
    }
}
