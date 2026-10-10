<?php

namespace Tests\Unit;

use App\Services\StaleFixturePolicy;
use App\Support\FootballFixtureStatus;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use UnexpectedValueException;

class StaleFixturePolicyTest extends TestCase
{
    public function test_sealed_cohort_and_all_five_saved_provider_outcomes(): void
    {
        $policy = new StaleFixturePolicy;
        $cohort = $policy->cohort();
        $this->assertCount(111, $cohort);
        $this->assertCount(40, array_unique(array_column($cohort, 'api_league_id')));
        $rows = json_decode(file_get_contents(base_path('tests/Fixtures/stale-five-retained.json')), true);
        foreach ($rows as $row) {
            $identity = array_values(array_filter($cohort, fn ($i) => $i['api_fixture_id'] === $row['fixture']['id']))[0];
            $facts = $policy->response([$row], $identity);
            $this->assertSame('validated', $facts['outcome']);
            $this->assertSame($row['fixture']['status']['short'], $facts['fixture']['status_short']);
            $this->assertSame($row['goals']['home'], $facts['score']['goals_home']);
            $this->assertSame($row['goals']['away'], $facts['score']['goals_away']);
            $this->assertSame(gmdate('Y-m-d H:i:s', $row['fixture']['timestamp']), $facts['fixture']['kick_off']);
        }
    }

    public function test_utc_age_and_change_boundaries_protect_progression(): void
    {
        $policy = new StaleFixturePolicy;
        $identity = $policy->cohort()[0];
        $now = CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC');
        $snapshot = ['fixture' => [...$identity, 'status_short' => 'NS', 'kick_off' => '2026-10-10 06:00:00', 'updated_at' => '2026-10-10 10:00:00', 'created_at' => '2026-10-01 12:00:00', 'elapsed_minute' => null, 'elapsed_extra' => null],
            'score' => ['id' => $identity['score_id'], 'fixture_id' => $identity['id'], 'updated_at' => '2026-10-10 10:00:00', ...array_fill_keys(StaleFixturePolicy::SCORE_FIELDS, null)]];
        $this->assertNull($policy->eligibility($snapshot, $identity, $now));
        foreach ([['kick_off', '2026-10-10 06:00:01', 'too_recent_or_future'], ['kick_off', '2026-09-10 11:59:59', 'older_than_30_days_needs_review'], ['updated_at', '2026-10-10 10:00:01', 'recently_changed'], ['status_short', 'FT', 'protected_status'], ['status_short', '2H', 'protected_status'], ['elapsed_minute', 0, 'progressing']] as [$field, $value, $reason]) {
            $changed = $snapshot;
            $changed['fixture'][$field] = $value;
            $this->assertSame($reason, $policy->eligibility($changed, $identity, $now));
        }
        $snapshot['fixture']['status_short'] = 'PST';
        $snapshot['fixture']['kick_off'] = '2026-10-09 12:00:00';
        $this->assertNull($policy->eligibility($snapshot, $identity, $now));
        $snapshot['fixture']['kick_off'] = '2026-10-09 12:00:01';
        $this->assertSame('too_recent_or_future', $policy->eligibility($snapshot, $identity, $now));
        $snapshot['score']['goals_home'] = 0;
        $this->assertSame('progressing_score', $policy->eligibility($snapshot, $identity, $now));
    }

    public function test_adversarial_envelopes_identities_and_scores_never_create_facts(): void
    {
        $policy = new StaleFixturePolicy;
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/stale-five-retained.json')), true)[0];
        $identity = array_values(array_filter($policy->cohort(), fn ($i) => $i['api_fixture_id'] === $row['fixture']['id']))[0];
        $this->assertSame(['outcome' => 'empty'], $policy->response([], $identity));
        $cases = [[$row, $row]];
        foreach (['fixture.id', 'league.id', 'league.season', 'teams.home.id', 'teams.away.id', 'fixture.timestamp', 'goals.home', 'score.fulltime.home', 'score.halftime.home', 'fixture.status.short', 'fixture.status.elapsed'] as $path) {
            $bad = $row;
            data_set($bad, $path, 'malformed');
            $cases[] = [$bad];
        }
        $bad = $row;
        $bad['score']['fulltime']['home'] = 99;
        $cases[] = [$bad];
        $bad = $row;
        $bad['fixture']['status']['short'] = 'NS';
        $cases[] = [$bad];
        foreach ($cases as $rows) {
            try {
                $policy->response($rows, $identity);
                $this->fail('Invalid facts accepted');
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['1H', 'HT', '2H', 'ET', 'BT', 'P', 'LIVE', 'INT', 'SUSP'] as $status) {
            $live = $row;
            $live['fixture']['status']['short'] = $status;
            $this->assertSame(['outcome' => 'observed_progressing'], $policy->response([$live], $identity));
        }
        foreach (['CANC', 'ABD', 'AWD', 'WO'] as $status) {
            $unplayed = $row;
            $unplayed['fixture']['status'] = ['short' => $status, 'long' => $status, 'elapsed' => null];
            $unplayed['goals'] = ['home' => null, 'away' => null];
            foreach ($unplayed['score'] as &$pair) {
                $pair = ['home' => null, 'away' => null];
            }
            unset($pair);
            $facts = $policy->response([$unplayed], $identity);
            $this->assertSame($status, $facts['fixture']['status_short']);
            $this->assertNull($facts['score']['goals_home']);
        }
    }

    public function test_locked_current_status_rejects_regressing_live_payloads_but_allows_score_corrections(): void
    {
        foreach (FootballFixtureStatus::TERMINAL as $terminal) {
            foreach (['NS', 'PST', '1H', '2H', 'ET', 'LIVE', 'SUSP'] as $incoming) {
                $this->assertFalse(FootballFixtureStatus::canPersistLive($terminal, $incoming, 90, 80));
            }
        }
        foreach (['1H', 'HT', '2H', 'ET', 'P', 'BT', 'LIVE', 'INT', 'SUSP'] as $current) {
            $this->assertFalse(FootballFixtureStatus::canPersistLive($current, 'NS', null, null));
        }
        $this->assertFalse(FootballFixtureStatus::canPersistLive('2H', 'HT', 70, 45));
        $this->assertFalse(FootballFixtureStatus::canPersistLive('2H', '2H', 70, 69));
        $this->assertTrue(FootballFixtureStatus::canPersistLive('2H', '2H', 70, 70));
        $this->assertTrue(FootballFixtureStatus::canPersistLive('2H', 'FT', 89, 90));
        $this->assertTrue(FootballFixtureStatus::canPersistLive('PST', 'NS', null, null));
        foreach ([['PEN', 'FT'], ['PEN', 'AET'], ['AET', 'FT'], ['ET', 'FT'], ['P', 'FT']] as [$current, $incoming]) {
            $this->assertFalse(FootballFixtureStatus::canPersistLive($current, $incoming, 120, 120));
        }
        $this->assertFalse(FootballFixtureStatus::canPersistLive('NS', 'UNKNOWN', null, null));
        $this->assertTrue(FootballFixtureStatus::canPersistLive('NS', 'CANC', null, null));
        $this->assertTrue(FootballFixtureStatus::canPersistLive('FT', 'FT', 90, 90));
    }
}
