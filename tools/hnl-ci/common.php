<?php
require __DIR__.'/test-bootstrap.php';
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\DB;
use App\Services\HnlStandingsSync;
class Inconclusive extends RuntimeException {}
function must($ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
function outdir(): string { return getenv('HNL_OUT') ?: __DIR__.'/out'; }
function putjson(string $name, mixed $data): void {
 $p=outdir().'/'.$name; $tmp=$p.'.'.getmypid().'.tmp';
 file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n"); rename($tmp,$p);
}
function readjson(string $name): mixed { $p=outdir().'/'.$name; return is_file($p)?json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR):null; }
function mark(string $event,array $data=[]):void {
 file_put_contents(outdir().'/trace.ndjson',json_encode(['time'=>microtime(true),'pid'=>getmypid(),'event'=>$event]+$data,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
}
function boot(bool $fake=false): Manager {
 $c=new class extends Container {public function runningUnitTests():bool{return true;}};
 Container::setInstance($c);Facade::clearResolvedInstances();Facade::setFacadeApplication($c);
 $c->instance('config',new Repository(['api_football'=>['enabled_sports'=>['football'],'base_url'=>'https://provider.invalid','max_attempts'=>2,'retry_base_ms'=>0],'services'=>['api_football'=>['key'=>'']]]));
 $db=new Manager($c);
 $db->addConnection($fake?['driver'=>'sqlite','database'=>':memory:','foreign_key_constraints'=>true]:[
 'driver'=>'mysql','unix_socket'=>'/tmp/hnl-ci/mariadb.sock','database'=>'hnl_ci','username'=>'ci','password'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','strict'=>true]);
 $db->setEventDispatcher(new Dispatcher($c));$db->setAsGlobal();$db->bootEloquent();$c->instance('db',$db->getDatabaseManager());
 if(!$fake){DB::statement('SET SESSION innodb_lock_wait_timeout=5');DB::statement('SET SESSION lock_wait_timeout=5');DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');}
 return $db;
}
function payload():array{return json_decode(file_get_contents('tests/Fixtures/hnl-standings-2026.json'),true,512,JSON_THROW_ON_ERROR);}
function snapshot():array{
 $r=[];foreach(['leagues','teams','fixtures','fixture_scores','standings'] as $t)$r[$t]=DB::table($t)->orderBy('id')->get()->map(fn($x)=>(array)$x)->all();return $r;
}
function conserved(array $before,array $after):void{
 foreach(['leagues','teams','fixtures','fixture_scores'] as $t)must($before[$t]===$after[$t],$t.'_conservation');
 $history=fn($rows)=>array_values(array_filter($rows,fn($x)=>$x['league_id']!=835||$x['season']!=2026));
 must($history($before['standings'])===$history($after['standings']),'historical_otherleague_conservation');
}
function resetData(bool $populated=false):void{
 foreach(['fixture_scores','fixtures','standings','teams','leagues'] as $t)DB::table($t)->delete();
 DB::table('leagues')->insert([['id'=>835,'api_league_id'=>210,'name'=>'Synthetic HNL','sport'=>'football','country'=>'Croatia','current_season'=>2025],['id'=>999,'api_league_id'=>999,'name'=>'Synthetic Other','sport'=>'football','country'=>'Test','current_season'=>2026]]);
 foreach(HnlStandingsSync::TEAMS+[9999=>9999] as $api=>$local)DB::table('teams')->insert(['id'=>$local,'api_team_id'=>$api,'name'=>'Synthetic '.$api]);
 DB::table('fixtures')->insert(['id'=>1,'api_fixture_id'=>1,'league_id'=>835,'home_team_id'=>1447,'away_team_id'=>1507,'season'=>2026,'kick_off'=>now('UTC')->subDay(),'status_short'=>'FT']);
 DB::table('fixture_scores')->insert(['id'=>1,'fixture_id'=>1,'goals_home'=>1,'goals_away'=>0]);
 foreach(HnlStandingsSync::TEAMS as $local)DB::table('standings')->insert(['league_id'=>835,'team_id'=>$local,'season'=>2025,'rank'=>1,'points'=>77]);
 DB::table('standings')->insert(['league_id'=>999,'team_id'=>1447,'season'=>2026,'rank'=>1,'points'=>88]);
 if($populated){$v=(new ReflectionClass(HnlStandingsSync::class))->newInstanceWithoutConstructor()->validate(payload());foreach($v['rows'] as $id=>$row)DB::table('standings')->insert(['league_id'=>835,'team_id'=>$id,'season'=>2026]+$row);}
}
function waitFor(callable $probe,string $why,float $seconds=4):mixed{
 $until=microtime(true)+$seconds;do{$v=$probe();if($v)return $v;usleep(10000);}while(microtime(true)<$until);throw new RuntimeException('barrier_timeout_'.$why);
}
