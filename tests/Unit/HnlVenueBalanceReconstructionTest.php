<?php

namespace Tests\Unit;

use App\Contracts\ApiFootballQuotaStore;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\HnlStandingsSync;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HnlVenueBalanceReconstructionTest extends TestCase
{
    private Manager $db;

    private array $receipts = [];

    private array $initial;

    private $quota;

    protected function setUp(): void
    {
        $c = new class extends Container
        {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        Container::setInstance($c);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($c);
        $c->instance('config', new Repository(['api_football' => ['enabled_sports' => ['football'],
            'base_url' => 'https://provider.invalid', 'max_attempts' => 2, 'retry_base_ms' => 0],
            'services' => ['api_football' => ['key' => '']]]));
        $this->db = new Manager($c);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $this->db->setEventDispatcher(new Dispatcher($c));
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        $c->instance('db', $this->db->getDatabaseManager());
        $schema = $this->db->schema();
        $schema->create('leagues', function (Blueprint $t) {
            $t->id();
            $t->integer('api_league_id')->unique();
            $t->string('sport');
            $t->string('country');
            $t->timestamps();
        });
        $schema->create('teams', function (Blueprint $t) {
            $t->id();
            $t->integer('api_team_id')->unique();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('logo_url')->nullable();
            $t->timestamps();
        });
        $schema->create('fixtures', function (Blueprint $t) {
            $t->id();
            $t->integer('league_id');
            $t->integer('season');
            $t->dateTime('kick_off');
        });
        $schema->create('standings', function (Blueprint $t) {
            $t->id();
            $t->integer('league_id');
            $t->integer('team_id');
            $t->integer('season');
            foreach (['rank', 'played', 'win', 'draw', 'lose', 'goals_for', 'goals_against', 'goal_diff', 'points'] as $field) {
                $t->integer($field)->default(0);
            }
            foreach (['home', 'away'] as $scope) {
                foreach (['played', 'win', 'draw', 'lose', 'goals_for', 'goals_against'] as $field) {
                    $t->integer($scope.'_'.$field)->default(0);
                }
            }
            $t->string('form')->nullable();
            $t->string('description')->nullable();
            $t->timestamps();
            $t->unique(['league_id', 'team_id', 'season']);
        });
        DB::table('leagues')->insert(['id' => 835, 'api_league_id' => 210, 'sport' => 'football', 'country' => 'Croatia']);
        DB::table('fixtures')->insert(['id' => 1, 'league_id' => 835, 'season' => 2026, 'kick_off' => now('UTC')->subDay()]);
        foreach (HnlStandingsSync::TEAMS as $api => $local) {
            DB::table('teams')->insert(['id' => $local, 'api_team_id' => $api, 'name' => 'Existing name '.$api, 'slug' => null]);
            DB::table('standings')->insert(['league_id' => 835, 'team_id' => $local, 'season' => 2025, 'points' => 77]);
        }
        DB::table('standings')->insert(['league_id' => 999, 'team_id' => 1447, 'season' => 2026, 'points' => 88]);
        $this->initial = $this->snapshot();
        $log = Mockery::mock();
        $log->shouldReceive('channel')->andReturnSelf();
        $log->shouldReceive('info')->andReturnUsing(function ($event, $data) {
            if ($event === 'hnl_standings_committed') {
                self::assertSame(0, DB::transactionLevel());
                self::assertSame(10, DB::table('standings')->where('league_id', 835)->where('season', 2026)->count());
                $this->receipts[] = $data;
            }
        });
        Log::swap($log);
        $this->quota = Mockery::mock(ApiFootballQuotaStore::class);
        $this->quota->shouldReceive('reserve')->andReturn(['allowed' => true]);
        $this->quota->shouldReceive('record')->andReturnNull();
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->db->getDatabaseManager()->disconnect();
        Mockery::close();
        Facade::clearResolvedInstances();
    }

    private function payload(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/hnl-standings-2026.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function sync(array $envelope): array
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) use ($envelope) {
            self::assertSame('https://provider.invalid/standings?league=210&season=2026', $request->url());
            self::assertFalse($options['allow_redirects']);

            return Http::response(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 200);
        });

        return (new HnlStandingsSync(new ApiFootballGateway($this->quota)))->sync();
    }

    private function snapshot(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toArray(), ['leagues', 'teams', 'fixtures', 'standings']);
    }

    public static function venueViolations(): array
    {
        $cases = [];
        foreach (['played', 'win', 'lose', 'draw', 'for', 'against'] as $field) {
            foreach ([false, true] as $populated) {
                $cases[$field.($populated ? '-populated' : '-empty')] = [$field, $populated];
            }
        }

        return $cases;
    }

    #[DataProvider('venueViolations')]
    public function test_each_venue_equality_fails_closed_without_refetch(string $field, bool $populated): void
    {
        if ($populated) {
            $this->sync($this->payload());
        }
        $before = $this->snapshot();
        $this->receipts = [];
        $envelope = $this->payload();
        $rows = &$envelope['response'][0]['league']['standings'][0];
        if ($field === 'played') {
            [$rows[0]['home'], $rows[0]['away']] = [$rows[0]['away'], $rows[0]['home']];
        } else {
            foreach ($rows as &$row) {
                if (in_array($field, ['for', 'against'], true)) {
                    if ($row['home']['goals'][$field] > 0) {
                        $row['home']['goals'][$field]--;
                        $row['away']['goals'][$field]++;
                        break;
                    }
                } elseif ($row['home'][$field] > 0) {
                    $row['home'][$field]--;
                    $row['away'][$field]++;
                    $row['home']['played']--;
                    $row['away']['played']++;
                    break;
                }
            }
            unset($row);
        }
        $opposite = ['played' => 'played', 'win' => 'lose', 'lose' => 'win',
            'draw' => 'draw', 'for' => 'against', 'against' => 'for'][$field];
        $sum = static fn (string $side, string $stat): int => array_sum(array_map(
            static fn (array $row): int => in_array($stat, ['for', 'against'], true)
                ? $row[$side]['goals'][$stat] : $row[$side][$stat], $rows));
        self::assertNotSame($sum('home', $field), $sum('away', $opposite));
        // These mutations preserve every team's ALL totals and points; no repair is allowed.
        $unmodified = $envelope;
        $failure = null;
        try {
            $this->sync($envelope);
        } catch (RuntimeException $exception) {
            $failure = $exception->getMessage();
        }
        self::assertNotNull($failure);
        self::assertStringStartsWith('hnl_standings_venue_balance_', $failure);
        self::assertSame($unmodified, $envelope);
        self::assertEquals($before, $this->snapshot());
        self::assertSame([], $this->receipts);
        self::assertSame(0, DB::transactionLevel());
        Http::assertSentCount(1);
    }

    public function test_valid_balanced_venue_reversal_is_exact_idempotent_and_preserves_history(): void
    {
        $envelope = $this->payload();
        foreach ($envelope['response'][0]['league']['standings'][0] as &$row) {
            [$row['home'], $row['away']] = [$row['away'], $row['home']];
        }
        unset($row);
        $receipt = $this->sync($envelope);
        self::assertSame('committed', $receipt['outcome']);
        self::assertSame(10, $receipt['changed_rows']);
        self::assertSame(1, $receipt['attempt_claims']);
        Http::assertSentCount(1);
        $after = $this->snapshot();
        self::assertEquals(array_slice($this->initial, 0, 3), array_slice($after, 0, 3));
        self::assertEquals($this->initial[3], array_slice($after[3], 0, 11));
        foreach ($envelope['response'][0]['league']['standings'][0] as $row) {
            $stored = DB::table('standings')->where('league_id', 835)->where('season', 2026)
                ->where('team_id', HnlStandingsSync::TEAMS[$row['team']['id']])->first();
            foreach (['home', 'away'] as $side) {
                foreach (['played', 'win', 'lose', 'draw'] as $stat) {
                    self::assertSame($row[$side][$stat], $stored->{$side.'_'.$stat});
                }
                foreach (['for', 'against'] as $stat) {
                    self::assertSame($row[$side]['goals'][$stat], $stored->{$side.'_goals_'.$stat});
                }
            }
        }
        $second = $this->sync($envelope);
        self::assertSame(0, $second['changed_rows']);
        self::assertEquals($after, $this->snapshot());
        self::assertCount(2, $this->receipts);
        Http::assertSentCount(1);
    }
}
