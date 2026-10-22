<?php
require __DIR__.'/test-bootstrap.php';
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\{Facade,DB,Cache,Log,Http};
use Illuminate\Support\Carbon;
use Illuminate\Http\Client\Factory;
use App\Services\{HnlDailyRefresh,HnlDailyPolicy,HnlDailyValidator};
use App\Services\ApiFootball\ApiFootballGateway;
use App\Contracts\ApiFootballQuotaStore;
function savej($n,$v) { $p=getenv('CASE_DIR').'/'.$n.'.json'; $h=fopen($p,'w');fwrite($h,json_encode($v,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));fflush($h);fsync($h);fclose($h); }
function check($v,$m) { if(!$v) throw new RuntimeException($m); }
function barrier($stage) {
 if(getenv('PAUSE')!==$stage) return;
 savej(getenv('LABEL').'-barrier',['stage'=>$stage,'pid'=>getmypid(),'connection'=>DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
 $end=microtime(true)+22;
 while(!is_file(getenv('CASE_DIR').'/release-'.getenv('LABEL').'.json')) {if(microtime(true)>$end) throw new RuntimeException('barrier_timeout');usleep(10000);}
}
function boot() {
 Carbon::setTestNow(Carbon::parse(getenv('CLOCK')?:'2026-10-23 04:45:00','UTC'));
 $c=new class extends Container {public function runningUnitTests():bool{return true;}};
 Container::setInstance($c);Facade::clearResolvedInstances();Facade::setFacadeApplication($c);
 $c->instance('config',new Repository(['api_football'=>['enabled_sports'=>['football'],'base_url'=>'https://provider.invalid','max_attempts'=>2,'retry_base_ms'=>0],'services'=>['api_football'=>['key'=>'']]]));
 $db=new Manager($c);$db->addConnection(['driver'=>'mysql','unix_socket'=>'/tmp/hnl-ci/mariadb.sock','database'=>'hnl_ci','username'=>'ci','password'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,'timezone'=>'+00:00']);
 $db->setEventDispatcher(new Dispatcher($c));$db->setAsGlobal();$db->bootEloquent();$c->instance('db',$db->getDatabaseManager());$c->bind('db.schema',fn()=>DB::connection()->getSchemaBuilder());
 DB::extend('mysql',function($config,$name){$pdo=(new MySqlConnector)->connect($config);return new class($pdo,$config['database'],'',$config) extends MySqlConnection {
  public function commit(){barrier('precommit');parent::commit();barrier('postcommit');if(getenv('PAUSE')==='lostack') {barrier('lostack');throw new RuntimeException('ack_lost_after_real_commit');}}
  protected function runQueryCallback($query,$bindings,Closure $callback){try{return parent::runQueryCallback($query,$bindings,$callback);}catch(Throwable $e){if(getenv('LABEL'))savej(getenv('LABEL').'-sql-error',['class'=>get_class($e),'sql'=>$query,'errorInfo'=>$e->errorInfo??null]);throw $e;}}
 };});
 DB::statement('SET SESSION innodb_lock_wait_timeout=5');DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
 $log=Mockery::mock();$log->shouldReceive('channel')->andReturnSelf();$log->shouldReceive('info','log')->andReturnNull();Log::swap($log);
 $cache=Mockery::mock();$cache->shouldReceive('store')->andReturnSelf();$cache->shouldReceive('add')->andReturnTrue();Cache::swap($cache);
}
function service() {
 $q=Mockery::mock(ApiFootballQuotaStore::class);$q->shouldReceive('record','openCircuit')->andReturnNull();
 $q->shouldReceive('reserve')->andReturnUsing(function(){if(getenv('MODE')==='midnight-reserve')Carbon::setTestNow(Carbon::parse('2026-10-24 00:00:00','UTC'));return ['allowed'=>true];});
 $f=new Factory;$f->preventStrayRequests();$f->fake(function($request,$options){
  check($options['allow_redirects']===false && $options['timeout']===15,'transport_options');
  $pdo=new PDO('mysql:unix_socket=/tmp/hnl-ci/mariadb.sock;dbname=hnl_ci','ci','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $claims=$pdo->query("SELECT id,outcome,utc_day FROM hnl_standings_runs")->fetchAll(PDO::FETCH_ASSOC);
  check(count(array_filter($claims,fn($r)=>$r['outcome']==='claimed'))===1,'durable_claim_not_visible_before_physical');
  $h=fopen(getenv('CASE_DIR').'/physical.ndjson','a');flock($h,LOCK_EX);fwrite($h,json_encode(['pid'=>getmypid(),'caller'=>getenv('LABEL'),'claims'=>$claims])."\n");fflush($h);fsync($h);flock($h,LOCK_UN);fclose($h);
  barrier('uncertain-http');if(getenv('MODE')==='network')throw new Illuminate\Http\Client\ConnectionException('fake_uncertain');
  $body=json_decode(file_get_contents('tests/Fixtures/hnl-oct22-envelope.json'),true);
  if(getenv('CHANGED')==='1'){foreach($body['response'][0]['league']['standings'][0] as &$r){$r['update']='2026-10-22T04:00:00Z';$r['form']='DDDDD';}unset($r);}
  barrier('afterresponse');return Factory::response($body,200);
 });Http::swap($f);return new HnlDailyRefresh(new ApiFootballGateway($q));
}
function image(){ $v=[];foreach(['leagues','teams','fixtures','fixture_scores','standings'] as $t)$v[$t]=DB::table($t)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();return $v; }
function seed(){
 DB::statement('SET FOREIGN_KEY_CHECKS=0');foreach(['hnl_standings_runs','fixture_scores','fixtures','standings','teams','leagues'] as $t)DB::statement('DROP TABLE IF EXISTS '.$t);DB::statement('SET FOREIGN_KEY_CHECKS=1');
 DB::unprepared(file_get_contents(__DIR__.'/schema.sql'));
 (require 'deployment/hnl-daily/2026_10_22_000001_create_hnl_standings_runs.php')->up();
 $b=json_decode(file_get_contents(HnlDailyPolicy::BASELINE),true);$rows=array_merge($b['current'],$b['archive']);
 DB::table('leagues')->insert(['id'=>835,'api_league_id'=>210,'name'=>'HNL','sport'=>'football','country'=>'Croatia','current_season'=>2025]);
 foreach(array_unique(array_column($rows,'team_id')) as $id)DB::table('teams')->insert(['id'=>$id,'api_team_id'=>array_search($id,HnlDailyValidator::TEAMS,true)?:90000+$id,'name'=>'fixture-'.$id]);
 DB::table('standings')->insert($rows);
 DB::table('fixtures')->insert(['id'=>1,'api_fixture_id'=>1,'league_id'=>835,'home_team_id'=>1447,'away_team_id'=>1507,'season'=>2026,'kick_off'=>'2026-10-18 15:00:00']);
 DB::table('fixture_scores')->insert(['id'=>1,'fixture_id'=>1,'goals_home'=>1,'goals_away'=>0]);
}
