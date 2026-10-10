<?php

namespace Tests\Feature;

use App\Livewire\LeaguePage;
use App\Livewire\LiveScores;
use App\Models\Fixture;
use App\Models\League;
use App\Support\MatchRoute;
use App\Support\SeoHubData;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class MatchTimeUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('app.key', str_repeat('a', 32));
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-10 05:17:00 UTC');
        $this->createSchema();
        DB::table('leagues')->insert(['id' => 1, 'api_league_id' => 39, 'name' => 'Premier League']);
        DB::table('teams')->insert([
            ['id' => 1506, 'api_team_id' => 1506, 'name' => 'Arsenal', 'slug' => 'arsenal'],
            ['id' => 1507, 'api_team_id' => 1507, 'name' => 'Leeds', 'slug' => 'leeds'],
        ]);
        Schema::create('fixture_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('fixture_id');
        });
        Schema::create('basketball_games', function (Blueprint $t): void {
            $t->id();
            $t->dateTime('game_date');
            $t->string('status_short');
            $t->string('home_team')->default('Home');
            $t->string('away_team')->default('Away');
            $t->string('league_name')->default('League');
        });
        Schema::create('tennis_matches', function (Blueprint $t): void {
            $t->id();
            $t->dateTime('match_date');
            $t->string('status');
            $t->string('player_home')->default('Home');
            $t->string('player_away')->default('Away');
            $t->string('tournament_name')->default('Tournament');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Http::assertNothingSent();
        parent::tearDown();
    }

    private function fixture(string $date, string $status = 'NS'): int
    {
        static $provider = 100;

        return DB::table('fixtures')->insertGetId([
            'api_fixture_id' => ++$provider, 'league_id' => 1, 'home_team_id' => 1506,
            'away_team_id' => 1507, 'season' => 2026, 'kick_off' => $date, 'status_short' => $status,
        ]);
    }

    public function test_screenshot_times_are_regional_while_db_and_route_dates_stay_utc(): void
    {
        $id = $this->fixture('2026-10-10 11:30:00');
        $this->fixture('2026-10-10 14:00:00');
        $this->fixture('2026-10-10 16:30:00');
        Livewire::test(LiveScores::class)->assertSee('13:30')->assertSee('16:00')->assertSee('18:30');
        $this->assertSame('2026-10-10 11:30:00', DB::table('fixtures')->where('id', $id)->value('kick_off'));
        $midnight = Fixture::find($this->fixture('2026-10-09 22:30:00'));
        $this->assertSame('arsenal-vs-leeds-09-10-2026', MatchRoute::slugFor($midnight));
        $this->assertSame($midnight->id, MatchRoute::resolve(MatchRoute::slugFor($midnight))->id);
        Livewire::test(LiveScores::class)->assertSee('00:30')->assertSee('arsenal-vs-leeds-09-10-2026');
    }

    public function test_all_sport_lists_and_counts_agree_at_local_day_edges_and_dst_days(): void
    {
        foreach ([
            ['2026-10-10', '2026-10-09 22:00:00', '2026-10-10 22:00:00'],
            ['2026-03-29', '2026-03-28 23:00:00', '2026-03-29 22:00:00'],
            ['2026-10-25', '2026-10-24 22:00:00', '2026-10-25 23:00:00'],
        ] as [$day, $start, $end]) {
            foreach (['fixtures', 'basketball_games', 'tennis_matches'] as $table) {
                DB::table($table)->delete();
            }
            Carbon::setTestNow($day.' 10:00:00 UTC');
            $instants = [Carbon::parse($start)->subSecond(), Carbon::parse($start), Carbon::parse($end)->subSecond(), Carbon::parse($end)];
            foreach ($instants as $instant) {
                $this->fixture($instant->format('Y-m-d H:i:s'));
                DB::table('basketball_games')->insert(['game_date' => $instant, 'status_short' => 'NS']);
                DB::table('tennis_matches')->insert(['match_date' => $instant, 'status' => 'NS']);
            }
            foreach (['football', 'basketball', 'tennis'] as $sport) {
                $component = new LiveScores;
                $component->mount('today', $sport);
                $this->assertSame($day, $component->selectedDate);
                $this->assertSame(2, $component->counts['all']);
                $this->assertSame(2, $component->counts['upcoming']);
                $rows = collect($component->fixtures)->flatMap(fn ($group) => $group['fixtures'] ?? $group);
                $this->assertCount(2, $rows);
                $this->assertSame($start, Carbon::parse($rows[0]['kick_off'])->utc()->format('Y-m-d H:i:s'));
            }
        }
    }

    public function test_navigation_and_page_labels_follow_zagreb_midnight_not_utc(): void
    {
        Carbon::setTestNow('2026-10-09 22:30:00 UTC');
        $this->fixture('2026-10-09 22:30:00');
        foreach (['today' => '2026-10-10', 'tomorrow' => '2026-10-11', 'yesterday' => '2026-10-09'] as $tab => $date) {
            Livewire::test(LiveScores::class, ['initialTab' => $tab])->assertSet('selectedDate', $date);
        }
        $component = Livewire::test(LiveScores::class);
        $component->call('setDate', '2026-10-17')->assertSet('selectedDate', '2026-10-17');
        foreach (['2026-10-18', '2026-10-06', '2026-02-30', 'not-a-date', '2026-10-10T00:00:00Z'] as $invalid) {
            $component->call('setDate', $invalid)->assertSet('selectedDate', '2026-10-17');
        }
        $component->call('setDate', '2026-10-07')->assertSet('selectedDate', '2026-10-07');
        $this->get('/')->assertOk()->assertSee('10. oktobar 2026.');
        $this->get('/sutra')->assertOk()->assertSee('11. oktobar 2026.');
        $this->get('/jucer')->assertOk()->assertSee('9. oktobar 2026.');
    }

    public function test_league_and_seo_today_windows_use_the_same_local_boundaries(): void
    {
        Carbon::setTestNow('2026-10-09 22:30:00 UTC');
        $id = $this->fixture('2026-10-09 22:15:00', '1H');
        $this->fixture('2026-10-09 21:59:59', '1H');
        $this->fixture('2026-10-10 22:00:00', '1H');
        $league = new LeaguePage;
        $league->league = League::find(1);
        $league->loadFixtures();
        $this->assertSame([$id], array_column($league->fixtures, 'id'));
        $windows = SeoHubData::footballWindows();
        $this->assertCount(1, $windows['live']);
        $this->assertSame('2026-10-09 22:15:00', $windows['live'][0]['date']->format('Y-m-d H:i:s'));
    }

    public function test_home_routes_never_render_the_disconnected_derby_banner(): void
    {
        $id = $this->fixture('2026-10-10 13:00:00');
        foreach (['NS', '1H', 'HT', '2H', 'ET', 'BT', 'P', 'LIVE', 'FT', 'AET', 'PEN', 'PST', 'CANC', 'ABD', 'SUSP', 'INT', 'TBD'] as $status) {
            DB::table('fixtures')->where('id', $id)->update(['status_short' => $status]);
            foreach (['/', '/jucer', '/sutra', '/nogomet', '/kosarka', '/tenis'] as $path) {
                $response = $this->get($path)->assertOk();
                foreach (['kickoffMs:', 'serverMs:', 'Dinamo vs Hajduk', 'Zvezda vs Partizan', 'Sarajevo vs Željezničar', 'String(days)', 'homepage-top', 'googlesyndication', 'adsbygoogle'] as $marker) {
                    $response->assertDontSee($marker, false);
                }
            }
        }
        DB::table('fixtures')->where('id', $id)->update(['status_short' => 'NS']);
        $this->get('/')->assertSee('15:00')->assertSee('Arsenal')->assertSee('Leeds');
    }

    public function test_hydrated_dates_are_canonical_valid_and_bounded_before_all_sport_queries(): void
    {
        Carbon::setTestNow('2026-10-09 22:30:00 UTC');
        foreach (['football', 'basketball', 'tennis'] as $sport) {
            $component = Livewire::test(LiveScores::class, ['sport' => $sport]);
            foreach (['not-a-date', '2026-02-30', '2026-10-30', '2026-10-06', '2026-10-18',
                '2026-1-10', '2026-10-1', '0000-01-01', '2026-13-01', '2026-00-01',
                '2026-10-00', '2026-10-10T00:00:00Z', ' 2026-10-10', "2026-10-10\n", ''] as $invalid) {
                $component->set('selectedDate', $invalid)->call('loadFixtures')
                    ->assertSet('selectedDate', '2026-10-10')->assertHasNoErrors();
                // Direct reads/actions must be safe even outside Livewire hooks.
                $direct = new LiveScores;
                $direct->sport = $sport;
                $direct->selectedDate = $invalid;
                $this->assertSame(0, $direct->getCounts()['all']);
                $this->assertSame('2026-10-10', $direct->selectedDate);
                $direct->selectedDate = $invalid;
                $this->assertSame('2026-10-09', $direct->getPrevDate());
                $direct->selectedDate = $invalid;
                $this->assertSame('2026-10-11', $direct->getNextDate());
            }
            foreach (['2026-10-07', '2026-10-17', '2026-10-09', '2026-10-11'] as $valid) {
                $component->set('selectedDate', $valid)->call('loadFixtures')->assertSet('selectedDate', $valid);
            }
        }
    }

    public function test_long_open_hydration_reloads_rows_when_selected_day_leaves_the_allowed_window(): void
    {
        $this->fixture('2026-10-10 11:30:00');
        $component = Livewire::test(LiveScores::class)->assertSee('13:30');
        Carbon::setTestNow('2026-10-14 05:17:00 UTC');
        $component->call('setFilter', 'sve')->assertSet('selectedDate', '2026-10-14')
            ->assertSet('counts.all', 0)->assertDontSee('13:30');
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
