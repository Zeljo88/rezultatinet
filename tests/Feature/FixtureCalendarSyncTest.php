<?php

namespace Tests\Feature;

use App\Contracts\ApiFootballQuotaStore;
use App\Exceptions\ApiFootballBlocked;
use App\Exceptions\ApiFootballProviderResponseException;
use App\Exceptions\FixtureCalendarDisabled;
use App\Livewire\LiveScores;
use App\Models\Fixture;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\ApiFootball\RedisApiFootballQuotaStore;
use App\Services\ApiFootballService;
use App\Services\FixtureCalendarImporter;
use App\Support\ApiFootballBlockReason;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class FixtureCalendarSyncTest extends TestCase
{
    private int $leagueId;

    private int $homeTeamId;

    private int $awayTeamId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-25 10:00:00 UTC');
        config()->set('app.key', str_repeat('a', 32));
        config()->set('api_football.retry_base_ms', 0);
        config()->set('api_football.calendar.lock_store', 'array');
        Http::preventStrayRequests();
        $this->createSchema();

        $this->leagueId = DB::table('leagues')->insertGetId([
            'api_league_id' => 39,
            'name' => 'Premier League',
            'country' => 'England',
            'logo_url' => null,
            'current_season' => 2026,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->homeTeamId = $this->insertTeam(1001, 'Home');
        $this->awayTeamId = $this->insertTeam(1002, 'Away');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Schema::dropIfExists('fixture_scores');
        Schema::dropIfExists('fixtures');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('leagues');

        parent::tearDown();
    }

    public function test_provider_path_is_disabled_by_default_before_quota_or_http(): void
    {
        config()->set('api_football.calendar.enabled', false);
        $quota = new CalendarQuotaSpy;
        $service = new ApiFootballService(new ApiFootballGateway($quota));

        try {
            $service->getCalendarFixturesByDate('2026-09-26');
            $this->fail('Expected the calendar feature gate to fail closed.');
        } catch (FixtureCalendarDisabled) {
            $this->assertSame(0, $quota->reserveCalls);
        }

        Http::assertNothingSent();
    }

    public function test_disabled_command_fails_before_lock_quota_or_http(): void
    {
        config()->set('api_football.calendar.enabled', false);
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldNotReceive('getCalendarFixturesByDate');
        $this->app->instance(ApiFootballService::class, $api);

        $this->assertSame(1, Artisan::call('sync:fixture-calendar', ['--window' => 'near']));
        $this->assertStringContainsString('disabled', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_calendar_path_retries_with_physical_accounting_and_fixed_identity(): void
    {
        config()->set('api_football.calendar.enabled', true);
        Http::fakeSequence()
            ->push([], 500)
            ->push(['response' => [['fixture' => ['id' => 99]]]], 200);
        $quota = new CalendarQuotaSpy;
        $service = new ApiFootballService(new ApiFootballGateway($quota));

        $this->assertSame([['fixture' => ['id' => 99]]], $service->getCalendarFixturesByDate('2026-09-26'));
        $this->assertSame(2, $quota->reserveCalls);
        $this->assertSame([
            ['calendar', 'FixtureCalendarSync'],
            ['calendar', 'FixtureCalendarSync'],
        ], $quota->reservations);
        $this->assertSame(['transient_error', 'success'], array_column($quota->records, 'outcome'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/fixtures?date=2026-09-26'));
    }

    public function test_calendar_429_opens_the_shared_circuit_without_retry(): void
    {
        config()->set('api_football.calendar.enabled', true);
        Http::fakeSequence()->push([], 429, ['Retry-After' => '120']);
        $quota = new CalendarQuotaSpy;
        $service = new ApiFootballService(new ApiFootballGateway($quota));

        try {
            $service->getCalendarFixturesByDate('2026-09-26');
            $this->fail('Expected a typed provider rate-limit block.');
        } catch (ApiFootballBlocked $blocked) {
            $this->assertSame(ApiFootballBlockReason::ProviderRateLimited, $blocked->reason);
        }
        $this->assertSame(1, $quota->reserveCalls);
        $this->assertCount(1, $quota->circuits);
        $this->assertSame('provider_429', $quota->circuits[0]['reason']);
        $this->assertSame('rate_limited', $quota->records[0]['outcome']);
        Http::assertSentCount(1);
    }

    public function test_calendar_class_cap_has_dedicated_typed_telemetry_and_no_http_attempt(): void
    {
        config()->set('api_football.calendar.enabled', true);
        $quota = new CalendarQuotaSpy;
        $quota->reservation = ['allowed' => false, 'reason' => 'class_hard_stop'];
        $service = new ApiFootballService(new ApiFootballGateway($quota));

        try {
            $service->getCalendarFixturesByDate('2026-09-26');
            $this->fail('Expected a typed calendar quota block.');
        } catch (ApiFootballBlocked $blocked) {
            $this->assertSame(ApiFootballBlockReason::CalendarBudget, $blocked->reason);
        }

        $this->assertSame(1, $quota->reserveCalls);
        Http::assertNothingSent();
    }

    public function test_redis_calendar_quota_enforces_eighty_physical_attempts(): void
    {
        $redis = new CalendarReservationRedis;
        Redis::shouldReceive('connection')->with('cache')->andReturn($redis);
        $store = new RedisApiFootballQuotaStore;

        for ($attempt = 1; $attempt <= 80; $attempt++) {
            $this->assertTrue($store->reserve('calendar', 'FixtureCalendarSync')['allowed']);
        }

        $blocked = $store->reserve('calendar', 'FixtureCalendarSync');
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('class_hard_stop', $blocked['reason']);
        $this->assertSame(80, $blocked['value']);
        $this->assertSame([80], array_values(array_unique($redis->budgets)));
    }

    public function test_single_flight_overlap_skips_without_provider_request(): void
    {
        config()->set('api_football.calendar.enabled', true);
        $identity = strtolower(trim((string) config('app.name')."\0".(string) config('app.env')));
        $lock = Cache::store('array')->lock('api-football:calendar-sync:'.hash('sha256', $identity), 7200);
        $this->assertTrue($lock->get());

        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldNotReceive('getCalendarFixturesByDate');
        $this->app->instance(ApiFootballService::class, $api);

        $this->assertSame(0, Artisan::call('sync:fixture-calendar', ['--window' => 'near']));
        $this->assertStringContainsString('already running', Artisan::output());
        Http::assertNothingSent();

        $lock->release();
    }

    public function test_near_command_uses_exact_utc_dates_once_each(): void
    {
        config()->set('api_football.calendar.enabled', true);
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldReceive('getCalendarFixturesByDate')->once()->with('2026-09-25')->andReturn([]);
        $api->shouldReceive('getCalendarFixturesByDate')->once()->with('2026-09-26')->andReturn([]);
        $this->app->instance(ApiFootballService::class, $api);

        $this->assertSame(0, Artisan::call('sync:fixture-calendar', ['--window' => 'near']));
        $this->assertStringContainsString('2 dates', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_scheduler_is_absent_by_default_then_registers_exact_utc_windows(): void
    {
        $calendarEvents = fn (): array => array_values(array_filter(
            app(Schedule::class)->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'sync:fixture-calendar'),
        ));

        $this->assertSame([], $calendarEvents());

        config()->set('api_football.calendar.enabled', true);
        require base_path('routes/console.php');

        $events = $calendarEvents();
        $this->assertCount(3, $events);
        $byName = [];
        foreach ($events as $event) {
            $byName[$event->description] = $event;
            $this->assertSame('UTC', $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame(180, $event->expiresAt);
        }
        $this->assertSame('0 */6 * * *', $byName['fixture-calendar-near']->expression);
        $this->assertSame('30 6 * * *', $byName['fixture-calendar-week']->expression);
        $this->assertSame('15 3 * * 3', $byName['fixture-calendar-month']->expression);
    }

    public function test_import_is_idempotent_and_updates_calendar_fields_by_provider_id(): void
    {
        $importer = new FixtureCalendarImporter;
        $payload = $this->payload(7001, 'NS', '2026-09-27 14:30:00', season: 2026);

        $this->assertSame($this->importResult(rows: 1, accepted: 1, upserted: 1), $importer->import([$payload]));

        $payload['league']['season'] = 2027;
        $payload['league']['round'] = 'Round 9';
        $payload['fixture']['timestamp'] = CarbonImmutable::parse('2026-09-27 16:45:00', 'UTC')->timestamp;
        $payload['fixture']['status'] = ['short' => 'TBD', 'long' => 'Time to be defined', 'elapsed' => null];
        $this->assertSame($this->importResult(rows: 1, accepted: 1, upserted: 1), $importer->import([$payload]));
        $this->assertSame($this->importResult(rows: 1, accepted: 1, upserted: 1), $importer->import([$payload]));

        $this->assertSame(1, Fixture::where('api_fixture_id', 7001)->count());
        $fixture = Fixture::where('api_fixture_id', 7001)->firstOrFail();
        $this->assertSame($this->leagueId, $fixture->league_id);
        $this->assertSame(2027, $fixture->season);
        $this->assertSame('Round 9', $fixture->round);
        $this->assertSame('TBD', $fixture->status_short);
        $this->assertSame('2026-09-27 16:45:00', $fixture->kick_off->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, DB::table('fixture_scores')->where('fixture_id', $fixture->id)->count());
    }

    public function test_future_payload_cannot_mutate_any_existing_live_or_terminal_fixture(): void
    {
        $statuses = ['1H', '2H', 'HT', 'ET', 'BT', 'P', 'SUSP', 'INT', 'LIVE', 'FT', 'AET', 'PEN', 'AWD', 'WO', 'CANC', 'ABD'];
        foreach ($statuses as $index => $status) {
            $fixtureId = DB::table('fixtures')->insertGetId([
                'api_fixture_id' => 8000 + $index,
                'league_id' => $this->leagueId,
                'home_team_id' => $this->homeTeamId,
                'away_team_id' => $this->awayTeamId,
                'season' => 2026,
                'round' => 'Original',
                'kick_off' => '2026-09-25 12:00:00',
                'status_long' => $status,
                'status_short' => $status,
                'elapsed_minute' => 77,
                'venue_name' => 'Original venue',
                'referee' => 'Original referee',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('fixture_scores')->insert([
                'fixture_id' => $fixtureId,
                'goals_home' => 3,
                'goals_away' => 2,
                'home_halftime' => 1,
                'away_halftime' => 1,
                'home_fulltime' => 3,
                'away_fulltime' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $payloads = array_map(
            fn (int $index): array => $this->payload(8000 + $index, 'NS', '2026-10-10 18:00:00', season: 2027),
            array_keys($statuses),
        );
        $result = (new FixtureCalendarImporter)->import($payloads);

        $this->assertSame($this->importResult(rows: count($statuses), accepted: count($statuses), protected: count($statuses)), $result);
        foreach ($statuses as $index => $status) {
            $fixture = Fixture::where('api_fixture_id', 8000 + $index)->firstOrFail();
            $this->assertSame($status, $fixture->status_short);
            $this->assertSame(2026, $fixture->season);
            $this->assertSame('Original', $fixture->round);
            $this->assertSame('2026-09-25 12:00:00', $fixture->kick_off->utc()->format('Y-m-d H:i:s'));
            $this->assertSame(3, $fixture->score()->value('goals_home'));
        }
    }

    public function test_malformed_rows_are_isolated_in_first_middle_and_last_positions(): void
    {
        $importer = new FixtureCalendarImporter;

        foreach ([0, 1, 2] as $malformedPosition) {
            $base = 10000 + ($malformedPosition * 10);
            $rows = [
                $this->payload($base, 'NS', '2026-09-28 12:00:00', 2026),
                $this->payload($base + 1, 'TBD', '2026-09-28 13:00:00', 2026),
                $this->payload($base + 2, 'PST', '2026-09-28 14:00:00', 2026),
            ];
            unset($rows[$malformedPosition]['teams']['home']['name']);

            $result = $importer->import($rows);

            $this->assertSame($this->importResult(
                rows: 3,
                accepted: 2,
                upserted: 2,
                skipped: 1,
                skipReasons: ['malformed_row' => 1],
            ), $result);
            $this->assertSame(2, Fixture::whereBetween('api_fixture_id', [$base, $base + 2])->count());
        }
    }

    public function test_partial_nested_fields_and_wrong_types_skip_without_stopping_later_valid_row(): void
    {
        $rows = [];
        foreach (range(0, 7) as $offset) {
            $rows[] = $this->payload(10100 + $offset, 'NS', '2026-09-29 12:00:00', 2026);
        }
        unset($rows[0]['fixture']['status']);
        $rows[1]['league'] = 'invalid';
        unset($rows[2]['teams']['away']['id']);
        $rows[3]['teams']['home']['name'] = ['invalid'];
        $rows[4]['score']['fulltime'] = null;
        unset($rows[5]['goals']['away']);
        $rows[6]['teams']['home']['name'] = str_repeat('x', 101);

        $result = (new FixtureCalendarImporter)->import($rows);

        $this->assertSame($this->importResult(
            rows: 8,
            accepted: 1,
            upserted: 1,
            skipped: 7,
            skipReasons: ['malformed_row' => 7],
        ), $result);
        $this->assertTrue(Fixture::where('api_fixture_id', 10107)->exists());
    }

    public function test_unknown_status_is_rejected_while_all_known_semantics_remain_accepted(): void
    {
        $statuses = ['NS', 'TBD', 'PST', 'INT', 'SUSP', '1H', 'HT', '2H', 'ET', 'BT', 'P', 'LIVE', 'FT', 'AET', 'PEN', 'AWD', 'WO', 'CANC', 'ABD'];
        $rows = [$this->payload(10200, 'ZZ', '2026-09-30 12:00:00', 2026)];
        foreach ($statuses as $offset => $status) {
            $rows[] = $this->payload(10201 + $offset, $status, '2026-09-30 13:00:00', 2026);
        }

        $result = (new FixtureCalendarImporter)->import($rows);

        $this->assertSame($this->importResult(
            rows: 20,
            accepted: 19,
            upserted: 19,
            skipped: 1,
            skipReasons: ['unknown_status' => 1],
        ), $result);
        $this->assertFalse(Fixture::where('api_fixture_id', 10200)->exists());
        $this->assertSame($statuses, Fixture::where('api_fixture_id', '>=', 10201)->orderBy('api_fixture_id')->pluck('status_short')->all());
    }

    public function test_database_failure_rolls_back_one_row_and_later_rows_continue(): void
    {
        DB::unprepared("CREATE TRIGGER reject_calendar_fixture BEFORE INSERT ON fixtures WHEN NEW.api_fixture_id = 10301 BEGIN SELECT RAISE(ABORT, 'forced row failure'); END");
        $rows = [
            $this->payload(10300, 'NS', '2026-10-01 12:00:00', 2026),
            $this->payload(10301, 'NS', '2026-10-01 13:00:00', 2026),
            $this->payload(10302, 'NS', '2026-10-01 14:00:00', 2026),
        ];

        $result = (new FixtureCalendarImporter)->import($rows);

        $this->assertSame($this->importResult(
            rows: 3,
            accepted: 3,
            upserted: 2,
            failed: 1,
            failureReasons: ['database_error' => 1],
        ), $result);
        $this->assertSame([10300, 10302], Fixture::whereIn('api_fixture_id', [10300, 10301, 10302])->orderBy('api_fixture_id')->pluck('api_fixture_id')->all());
    }

    public function test_command_returns_failure_after_processing_all_rows_when_a_row_fails(): void
    {
        config()->set('api_football.calendar.enabled', true);
        DB::unprepared("CREATE TRIGGER reject_command_fixture BEFORE INSERT ON fixtures WHEN NEW.api_fixture_id = 10400 BEGIN SELECT RAISE(ABORT, 'forced row failure'); END");
        $api = Mockery::mock(ApiFootballService::class);
        $api->shouldReceive('getCalendarFixturesByDate')->once()->with('2026-09-25')->andReturn([
            $this->payload(10400, 'NS', '2026-10-01 12:00:00', 2026),
            $this->payload(10401, 'NS', '2026-10-01 13:00:00', 2026),
        ]);
        $api->shouldReceive('getCalendarFixturesByDate')->once()->with('2026-09-26')->andReturn([]);
        $this->app->instance(ApiFootballService::class, $api);

        $this->assertSame(1, Artisan::call('sync:fixture-calendar', ['--window' => 'near']));
        $this->assertStringContainsString('failed after isolated processing', Artisan::output());
        $this->assertFalse(Fixture::where('api_fixture_id', 10400)->exists());
        $this->assertTrue(Fixture::where('api_fixture_id', 10401)->exists());
    }

    public function test_2xx_provider_envelopes_are_strictly_classified_without_retry_or_success(): void
    {
        config()->set('api_football.calendar.enabled', true);
        $cases = [
            'absent response' => [['errors' => []], 'malformed_envelope'],
            'scalar response' => [['errors' => [], 'response' => 'invalid'], 'malformed_envelope'],
            'object response' => [['errors' => [], 'response' => ['fixture' => ['id' => 1]]], 'malformed_envelope'],
            'provider errors with response' => [['errors' => ['rateLimit' => 'invalid request'], 'response' => []], 'provider_error'],
            'wrong errors type' => [['errors' => 'invalid', 'response' => []], 'malformed_envelope'],
        ];

        $sequence = Http::fakeSequence();
        foreach ($cases as [$body]) {
            $sequence->push($body, 200);
        }
        $sequence->push('{not-json', 200, ['Content-Type' => 'application/json']);

        foreach ($cases as $label => [$body, $classification]) {
            $quota = new CalendarQuotaSpy;
            $service = new ApiFootballService(new ApiFootballGateway($quota));

            try {
                $service->getCalendarFixturesByDate('2026-09-26');
                $this->fail('Expected strict envelope failure for '.$label);
            } catch (ApiFootballProviderResponseException $e) {
                $this->assertSame($classification, $e->classification, $label);
            }

            $this->assertSame(1, $quota->reserveCalls, $label);
            $this->assertSame([$classification], array_column($quota->records, 'outcome'), $label);
        }

        $quota = new CalendarQuotaSpy;
        try {
            (new ApiFootballService(new ApiFootballGateway($quota)))->getCalendarFixturesByDate('2026-09-26');
            $this->fail('Expected malformed JSON failure.');
        } catch (ApiFootballProviderResponseException $e) {
            $this->assertSame('malformed_envelope', $e->classification);
        }
        $this->assertSame(['malformed_envelope'], array_column($quota->records, 'outcome'));
    }

    public function test_tomorrow_ui_uses_tomorrow_utc_and_counts_ns_and_tbd_as_upcoming(): void
    {
        foreach (['NS', 'TBD'] as $index => $status) {
            DB::table('fixtures')->insert([
                'api_fixture_id' => 9000 + $index,
                'league_id' => $this->leagueId,
                'home_team_id' => $this->homeTeamId,
                'away_team_id' => $this->awayTeamId,
                'season' => 2026,
                'round' => 'Round 1',
                'kick_off' => '2026-09-26 12:00:00',
                'status_long' => $status,
                'status_short' => $status,
                'elapsed_minute' => null,
                'venue_name' => null,
                'referee' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Livewire::test(LiveScores::class, ['initialTab' => 'tomorrow', 'sport' => 'football'])
            ->assertSet('selectedDate', '2026-09-26')
            ->assertSet('counts.upcoming', 2)
            ->assertSet('counts.all', 2);
    }

    private function importResult(
        int $rows,
        int $accepted = 0,
        int $upserted = 0,
        int $protected = 0,
        int $skipped = 0,
        int $failed = 0,
        array $skipReasons = [],
        array $failureReasons = [],
    ): array {
        return [
            'rows' => $rows,
            'accepted' => $accepted,
            'upserted' => $upserted,
            'protected' => $protected,
            'skipped' => $skipped,
            'failed' => $failed,
            'skip_reasons' => $skipReasons,
            'failure_reasons' => $failureReasons,
        ];
    }

    private function payload(int $apiId, string $status, string $kickOff, int $season): array
    {
        return [
            'fixture' => [
                'id' => $apiId,
                'timestamp' => CarbonImmutable::parse($kickOff, 'UTC')->timestamp,
                'status' => ['short' => $status, 'long' => $status, 'elapsed' => null],
                'venue' => ['name' => 'Calendar venue'],
                'referee' => 'Calendar referee',
            ],
            'league' => ['id' => 39, 'season' => $season, 'round' => 'Round 1'],
            'teams' => [
                'home' => ['id' => 1001, 'name' => 'Home', 'logo' => null],
                'away' => ['id' => 1002, 'name' => 'Away', 'logo' => null],
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

    private function insertTeam(int $apiId, string $name): int
    {
        return DB::table('teams')->insertGetId([
            'api_team_id' => $apiId,
            'name' => $name,
            'logo_url' => null,
            'slug' => strtolower($name),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('leagues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('api_league_id')->unique();
            $table->string('name');
            $table->string('country')->nullable();
            $table->string('logo_url')->nullable();
            $table->unsignedSmallInteger('current_season')->nullable();
            $table->timestamps();
        });
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('api_team_id')->unique();
            $table->string('name');
            $table->string('logo_url')->nullable();
            $table->string('slug')->nullable();
            $table->timestamps();
        });
        Schema::create('fixtures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('api_fixture_id')->unique();
            $table->unsignedBigInteger('league_id');
            $table->unsignedBigInteger('home_team_id');
            $table->unsignedBigInteger('away_team_id');
            $table->unsignedSmallInteger('season');
            $table->string('round')->nullable();
            $table->dateTime('kick_off');
            $table->string('status_long')->nullable();
            $table->string('status_short')->nullable();
            $table->unsignedSmallInteger('elapsed_minute')->nullable();
            $table->string('venue_name')->nullable();
            $table->string('referee')->nullable();
            $table->dateTime('lineups_fetched_at')->nullable();
            $table->timestamps();
        });
        Schema::create('fixture_scores', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fixture_id')->unique();
            $table->unsignedTinyInteger('goals_home')->nullable();
            $table->unsignedTinyInteger('goals_away')->nullable();
            $table->unsignedTinyInteger('home_halftime')->nullable();
            $table->unsignedTinyInteger('away_halftime')->nullable();
            $table->unsignedTinyInteger('home_fulltime')->nullable();
            $table->unsignedTinyInteger('away_fulltime')->nullable();
            $table->unsignedTinyInteger('home_extratime')->nullable();
            $table->unsignedTinyInteger('away_extratime')->nullable();
            $table->unsignedTinyInteger('home_penalties')->nullable();
            $table->unsignedTinyInteger('away_penalties')->nullable();
            $table->timestamps();
        });
    }
}

final class CalendarQuotaSpy implements ApiFootballQuotaStore
{
    public int $reserveCalls = 0;

    public array $reservations = [];

    public array $records = [];

    public array $circuits = [];

    public array $reservation = ['allowed' => true];

    public function reserve(string $endpointClass, string $caller): array
    {
        $this->reserveCalls++;
        $this->reservations[] = [$endpointClass, $caller];

        return $this->reservation + ['global' => $this->reserveCalls, 'class' => $this->reserveCalls];
    }

    public function record(string $endpointClass, string $caller, int|string $status, string $outcome): void
    {
        $this->records[] = compact('endpointClass', 'caller', 'status', 'outcome');
    }

    public function openCircuit(int $until, string $reason): void
    {
        $this->circuits[] = compact('until', 'reason');
    }

    public function status(): array
    {
        return [];
    }

    public function acquireRepair(int $fixtureId, string $caller): bool
    {
        return false;
    }

    public function releaseRepair(int $fixtureId): void {}

    public function recordRepair(int $fixtureId, string $outcome, bool $terminal = false): void {}

    public function repairScanState(string $scan): ?array
    {
        return null;
    }

    public function compareAndSetRepairScanState(string $scan, ?array $expected, array $next): bool
    {
        return false;
    }
}

final class CalendarReservationRedis
{
    public int $calendar = 0;

    public int $global = 0;

    public array $budgets = [];

    public function eval(mixed ...$arguments): array
    {
        $endpointClass = (string) $arguments[7];
        $budget = (int) $arguments[8];
        $this->budgets[] = $budget;

        if ($endpointClass !== 'calendar') {
            return [0, 'class_disabled', 0];
        }
        if ($this->calendar >= $budget) {
            return [0, 'class_hard_stop', $this->calendar];
        }

        $this->calendar++;
        $this->global++;

        return [1, $this->global, $this->calendar];
    }
}
