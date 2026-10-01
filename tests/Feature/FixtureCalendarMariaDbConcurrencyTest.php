<?php

namespace Tests\Feature;

use App\Models\Fixture;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FixtureCalendarMariaDbConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MariaDB concurrency harness requires the isolated mysql connection.');
        }
        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;
        if (! str_starts_with($version, '10.11.') || ! str_contains($version, 'MariaDB')) {
            $this->markTestSkipped('MariaDB 10.11 is required; found '.$version);
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['fixture_scores', 'fixtures', 'teams', 'leagues'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::table('leagues')->insert([
            'api_league_id' => 39,
            'name' => 'Concurrency League',
            'country' => 'Test',
            'current_season' => 2026,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::unprepared('DROP TRIGGER IF EXISTS calendar_team_delay');
        } finally {
            parent::tearDown();
        }
    }

    public function test_simultaneous_absent_fixture_teams_and_score_are_unique_and_idempotent(): void
    {
        $payload = $this->payload(700001, 710001, 710002, 'NS');

        $first = $this->runWorkers([[$payload], [$payload]]);
        $second = $this->runWorkers([[$payload], [$payload]]);

        foreach ([...$first, ...$second] as $result) {
            $this->assertSame(0, $result['failed']);
            $this->assertSame(1, $result['accepted']);
        }
        $this->assertSame(1, DB::table('fixtures')->where('api_fixture_id', 700001)->count());
        $fixtureId = DB::table('fixtures')->where('api_fixture_id', 700001)->value('id');
        $this->assertSame(1, DB::table('fixture_scores')->where('fixture_id', $fixtureId)->count());
        $this->assertSame(2, DB::table('teams')->whereIn('api_team_id', [710001, 710002])->count());
    }

    public function test_simultaneous_calendar_rows_preserve_existing_live_and_terminal_state(): void
    {
        $this->seedTeam(720001, 'Home');
        $this->seedTeam(720002, 'Away');
        foreach (['LIVE', 'FT'] as $offset => $status) {
            $fixtureId = DB::table('fixtures')->insertGetId([
                'api_fixture_id' => 700010 + $offset,
                'league_id' => DB::table('leagues')->value('id'),
                'home_team_id' => DB::table('teams')->where('api_team_id', 720001)->value('id'),
                'away_team_id' => DB::table('teams')->where('api_team_id', 720002)->value('id'),
                'season' => 2026,
                'round' => 'Original',
                'kick_off' => '2026-09-25 12:00:00',
                'status_short' => $status,
                'status_long' => $status,
                'elapsed_minute' => 77,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('fixture_scores')->insert([
                'fixture_id' => $fixtureId,
                'goals_home' => 3,
                'goals_away' => 2,
                'updated_at' => now(),
            ]);
        }

        $results = $this->runWorkers([
            [$this->payload(700010, 720001, 720002, 'NS')],
            [$this->payload(700011, 720001, 720002, 'TBD')],
        ]);

        foreach ($results as $result) {
            $this->assertSame(1, $result['protected']);
            $this->assertSame(0, $result['failed']);
        }
        $this->assertSame(['LIVE', 'FT'], Fixture::whereIn('api_fixture_id', [700010, 700011])->orderBy('api_fixture_id')->pluck('status_short')->all());
        $this->assertSame(['Original', 'Original'], Fixture::whereIn('api_fixture_id', [700010, 700011])->orderBy('api_fixture_id')->pluck('round')->all());
    }

    public function test_simultaneous_existing_ns_and_live_updates_finish_live_with_one_score(): void
    {
        $this->seedTeam(725001, 'Concurrent Home');
        $this->seedTeam(725002, 'Concurrent Away');
        $fixtureId = DB::table('fixtures')->insertGetId([
            'api_fixture_id' => 700015,
            'league_id' => DB::table('leagues')->value('id'),
            'home_team_id' => DB::table('teams')->where('api_team_id', 725001)->value('id'),
            'away_team_id' => DB::table('teams')->where('api_team_id', 725002)->value('id'),
            'season' => 2026,
            'round' => 'Initial NS',
            'kick_off' => '2026-09-25 12:00:00',
            'status_short' => 'NS',
            'status_long' => 'Not Started',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fixture_scores')->insert([
            'fixture_id' => $fixtureId,
            'updated_at' => now(),
        ]);

        $results = $this->runWorkers([
            [$this->payload(700015, 725001, 725002, 'NS')],
            [$this->payload(700015, 725001, 725002, 'LIVE')],
        ]);

        foreach ($results as $result) {
            $this->assertSame(0, $result['failed']);
        }
        $fixture = Fixture::where('api_fixture_id', 700015)->firstOrFail();
        $this->assertSame('LIVE', $fixture->status_short);
        $this->assertSame(1, DB::table('fixtures')->where('api_fixture_id', 700015)->count());
        $this->assertSame(1, DB::table('fixture_scores')->where('fixture_id', $fixture->id)->count());
    }

    public function test_reversed_team_lock_deadlock_retries_and_persistent_lock_failure_is_bounded(): void
    {
        $this->seedTeam(730001, 'Alpha');
        $this->seedTeam(730002, 'Beta');
        DB::unprepared('CREATE TRIGGER calendar_team_delay BEFORE UPDATE ON teams FOR EACH ROW DO SLEEP(0.35)');

        $results = $this->runWorkers([
            [$this->payload(700020, 730001, 730002, 'NS')],
            [$this->payload(700021, 730002, 730001, 'NS')],
        ], lockWaitSeconds: 4);

        foreach ($results as $result) {
            $this->assertSame(1, $result['upserted']);
            $this->assertSame(0, $result['failed']);
        }
        $status = DB::selectOne('SHOW ENGINE INNODB STATUS');
        $innodbStatus = (string) ($status->Status ?? $status->status ?? '');
        $this->assertStringContainsString('LATEST DETECTED DEADLOCK', $innodbStatus);

        DB::unprepared('DROP TRIGGER calendar_team_delay');
        $lockConnection = DB::connection('mariadb');
        $lockConnection->beginTransaction();
        $lockConnection->table('teams')->where('api_team_id', 730001)->lockForUpdate()->first();
        try {
            $bounded = $this->runWorkers([[
                $this->payload(700022, 730001, 730002, 'NS'),
                $this->payload(700023, 740001, 740002, 'NS'),
            ]], lockWaitSeconds: 1)[0];
        } finally {
            $lockConnection->rollBack();
        }

        $this->assertSame(2, $bounded['accepted']);
        $this->assertSame(1, $bounded['failed']);
        $this->assertSame(['lock_timeout' => 1], $bounded['failure_reasons']);
        $this->assertSame(1, $bounded['upserted']);
        $this->assertFalse(Fixture::where('api_fixture_id', 700022)->exists());
        $this->assertTrue(Fixture::where('api_fixture_id', 700023)->exists());
    }

    /** @return list<array> */
    private function runWorkers(array $payloads, int $lockWaitSeconds = 5): array
    {
        $startAt = microtime(true) + 0.75;
        $processes = [];
        foreach ($payloads as $payload) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/FixtureCalendarImportWorker.php'),
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                (string) $startAt,
                (string) $lockWaitSeconds,
            ], base_path(), null, null, 20);
            $process->start();
            $processes[] = $process;
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($result);
            $results[] = $result;
        }

        return $results;
    }

    private function seedTeam(int $apiId, string $name): void
    {
        DB::table('teams')->insert([
            'api_team_id' => $apiId,
            'name' => $name,
            'slug' => strtolower($name).'-'.$apiId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payload(int $fixtureId, int $homeId, int $awayId, string $status): array
    {
        return [
            'fixture' => [
                'id' => $fixtureId,
                'timestamp' => 1790359200,
                'status' => ['short' => $status, 'long' => $status, 'elapsed' => null],
                'venue' => ['name' => 'Concurrency venue'],
                'referee' => null,
            ],
            'league' => ['id' => 39, 'season' => 2026, 'round' => 'Concurrency'],
            'teams' => [
                'home' => ['id' => $homeId, 'name' => 'Team '.$homeId, 'logo' => null],
                'away' => ['id' => $awayId, 'name' => 'Team '.$awayId, 'logo' => null],
            ],
            'goals' => ['home' => null, 'away' => null],
            'score' => [
                'halftime' => ['home' => null, 'away' => null],
                'fulltime' => ['home' => null, 'away' => null],
                'extratime' => ['home' => null, 'away' => null],
                'penalty' => ['home' => null, 'away' => null],
            ],
        ];
    }
}
