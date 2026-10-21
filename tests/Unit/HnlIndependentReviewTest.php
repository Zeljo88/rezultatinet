<?php

namespace Tests\Unit;

use App\Console\Commands\SyncStandings;
use App\Contracts\ApiFootballQuotaStore;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\HnlStandingsSync;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;
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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class HnlIndependentReviewTest extends TestCase
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


    #[DataProvider('adversarialCases')]
    public function test_independent_malformed_input_conserves_existing_table(string $case): void
    {
        $this->sync($this->payload());
        $before = $this->snapshot();
        $this->receipts = [];
        $e = $this->payload();
        $r = &$e['response'][0]['league']['standings'][0];
        switch ($case) {
            case 'extra parameter': $e['parameters']['page'] = 1; break;
            case 'boolean league': $e['parameters']['league'] = true; break;
            case 'float season': $e['parameters']['season'] = 2026.0; break;
            case 'zero page': $e['paging']['current'] = 0; break;
            case 'string total': $e['paging']['total'] = '1'; break;
            case 'null errors': $e['errors'] = null; break;
            case 'empty scalar response': $e['response'] = ''; break;
            case 'second league': $e['response'][] = $e['response'][0]; break;
            case 'string league id': $e['response'][0]['league']['id'] = '210'; break;
            case 'string team': $r[0]['team']['id'] = '608'; break;
            case 'boolean rank': $r[0]['rank'] = true; break;
            case 'float stat': $r[0]['all']['played'] = 10.0; break;
            case 'boolean stat': $r[0]['home']['lose'] = false; break;
            case 'null stat': $r[0]['away']['goals']['for'] = null; break;
            case 'home storage overflow': $r[0]['home']['played'] = 256; break;
            case 'goal storage overflow': $r[0]['all']['goals']['for'] = 65536; break;
            case 'diff storage overflow': $r[0]['goalsDiff'] = -32769; break;
            case 'points deduction': $r[0]['points'] -= 3; break;
            case 'negative points': $r[0]['points'] = -1; break;
            case 'rank points order': [$r[0],$r[1]] = [$r[1],$r[0]]; $r[0]['rank']=1; $r[1]['rank']=2; break;
            case 'missing description': unset($r[0]['description']); break;
            case 'text numeric': $r[0]['description'] = 123; break;
            case 'text overflow': $r[0]['description'] = str_repeat('x',256); break;
            case 'form newline': $r[0]['form'] = "WWW\n"; break;
            case 'form length': $r[0]['form'] = 'WWWWWW'; break;
            case 'future timestamp': $r[0]['update'] = '2099-01-01T00:00:00Z'; break;
            case 'timestamp timezone missing': $r[0]['update'] = '2026-10-20T00:00:00'; break;
            case 'blank name': $r[0]['team']['name'] = '  '; break;
            default: throw new RuntimeException('unknown case');
        }
        $rejected = false;
        try { $this->sync($e); } catch (\Throwable) { $rejected = true; }
        self::assertTrue($rejected, $case);
        self::assertEquals($before, $this->snapshot());
        self::assertSame([], $this->receipts);
        Http::assertSentCount(1);
    }
    public static function adversarialCases(): array
    {
        return array_map(fn($x)=>[$x], ['extra parameter','boolean league','float season','zero page','string total','null errors','empty scalar response','second league','string league id','string team','boolean rank','float stat','boolean stat','null stat','home storage overflow','goal storage overflow','diff storage overflow','points deduction','negative points','rank points order','missing description','text numeric','text overflow','form newline','form length','future timestamp','timestamp timezone missing','blank name']);
    }
    #[DataProvider('localCases')]
    public function test_independent_identity_and_season_guards_before_network(string $case): void
    {
        switch($case) {
            case 'foreign current membership': DB::table('standings')->insert(['league_id'=>835,'team_id'=>999999,'season'=>2026]); break;
            case 'changed provider mapping': DB::table('teams')->where('id',1507)->update(['api_team_id'=>77777]); break;
            case 'changed league': DB::table('leagues')->where('id',835)->update(['api_league_id'=>999]); break;
            case 'wrong sport': DB::table('leagues')->where('id',835)->update(['sport'=>'basketball']); break;
            case 'no recent season': DB::table('fixtures')->where('id',1)->update(['kick_off'=>now('UTC')->subDays(46)]); break;
            case 'future only': DB::table('fixtures')->where('id',1)->update(['kick_off'=>now('UTC')->addDay()]); break;
        }
        $before=$this->snapshot(); $failed=false;
        try { $this->sync($this->payload()); } catch(RuntimeException) { $failed=true; }
        self::assertTrue($failed); Http::assertNothingSent(); self::assertEquals($before,$this->snapshot()); self::assertSame([],$this->receipts);
    }
    public static function localCases(): array { return array_map(fn($x)=>[$x],['foreign current membership','changed provider mapping','changed league','wrong sport','no recent season','future only']); }

    public function test_league_and_identity_reads_precede_transport_then_retry_is_bounded(): void
    {
        $e=$this->payload(); $n=0; DB::enableQueryLog();
        Http::fake(function()use($e,&$n){
            $n++;
            self::assertSame(1,DB::transactionLevel());
            $q=array_column(DB::getQueryLog(),'query');
            self::assertStringContainsString('"leagues"',$q[0]);
            self::assertStringContainsString('"standings"',$q[1]);
            self::assertStringContainsString('"teams"',$q[2]);
            return Http::response($n===1?[]:$e,$n===1?500:200);
        });
        $out=(new HnlStandingsSync(new ApiFootballGateway($this->quota)))->sync();
        self::assertSame(2,$n); self::assertSame(2,$out['attempt_claims']); self::assertSame(1,count($this->receipts));
    }
    public function test_two_failed_transport_attempts_leave_all_rows_and_no_receipt(): void
    {
        Http::fake(fn()=>Http::response([],500));
        $failed=false;
        try { (new HnlStandingsSync(new ApiFootballGateway($this->quota)))->sync(); } catch(\Throwable) { $failed=true; }
        self::assertTrue($failed); Http::assertSentCount(2); self::assertEquals($this->initial,$this->snapshot()); self::assertSame([],$this->receipts);
    }
    public function test_legitimate_tied_rank_swap_and_nullable_text_are_supported(): void
    {
        $e=$this->payload(); $r=&$e['response'][0]['league']['standings'][0];
        [$r[3],$r[4]]=[$r[4],$r[3]]; $r[3]['rank']=4; $r[4]['rank']=5;
        $r[0]['form']=null; $r[0]['description']=null;
        $out=$this->sync($e); self::assertSame('committed',$out['outcome']);
        self::assertSame(4,DB::table('standings')->where('league_id',835)->where('season',2026)->where('team_id',1447)->value('rank'));
    }
    public static function venueCases(): array { return [['appearances'],['results'],['goals']]; }
    #[DataProvider('venueCases')]
    public function test_impossible_home_away_match_totals_must_be_rejected(string $case): void
    {
        $this->sync($this->payload()); $before=$this->snapshot(); $this->receipts=[];
        $e=$this->payload(); $r=&$e['response'][0]['league']['standings'][0];
        if ($case==='appearances') {
            [$r[0]['home'],$r[0]['away']]=[$r[0]['away'],$r[0]['home']];
        } elseif ($case==='goals') {
            $r[0]['home']['goals']['for']--; $r[0]['away']['goals']['for']++;
        } else {
            foreach ($r as &$team) {
                if ($team['home']['win']>0 && $team['away']['draw']>0) {
                    $team['home']['win']--; $team['away']['win']++;
                    $team['home']['draw']++; $team['away']['draw']--; break;
                }
            } unset($team);
        }
        $totals=[];
        foreach (['home','away'] as $side) {
            foreach (['played','win','draw','lose'] as $field) $totals[$side][$field]=array_sum(array_column(array_column($r,$side),$field));
            foreach (['for','against'] as $field) $totals[$side]['goals'][$field]=array_sum(array_map(fn($team)=>$team[$side]['goals'][$field],$r));
        }
        $failed=false; try { $this->sync($e); } catch(\Throwable) { $failed=true; }
        file_put_contents(getenv('HNL_EVIDENCE').'/venue-counterexample-'.$case.'.json', json_encode(['rejected'=>$failed, 'case'=>$case, 'totals'=>$totals, 'before_row'=>$before[3][11] ?? null, 'after_row'=>DB::table('standings')->where('league_id',835)->where('season',2026)->where('team_id',1507)->first(), 'receipts'=>$this->receipts, 'unchanged_snapshot'=>$before==$this->snapshot()],JSON_PRETTY_PRINT));
        self::assertTrue($failed,'Impossible complete-league venue balance: '.$case);
        self::assertEquals($before,$this->snapshot()); self::assertSame([],$this->receipts);
    }
}
