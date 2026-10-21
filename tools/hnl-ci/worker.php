<?php
require __DIR__.'/common.php';
use App\Contracts\ApiFootballQuotaStore;
use App\Services\ApiFootball\ApiFootballGateway;
use App\Services\HnlStandingsSync;
use App\Models\Standing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Factory;
use Illuminate\Database\Events\TransactionBeginning;
$case=$argv[1];$mode=$argv[2]??'normal';$fake=$mode==='fake';$db=boot($fake);
$state=['case'=>$case,'pid'=>getmypid(),'connection_id'=>$fake?0:DB::selectOne('SELECT CONNECTION_ID() AS id')->id,'fetches'=>0,'transaction_attempts'=>0,'saves'=>0,'receipts'=>0,'receipt_attempts'=>0];
$flush=function()use(&$state,$case){putjson($case.'.state.json',$state);};
$db->getEventDispatcher()->listen(TransactionBeginning::class,function()use(&$state,$flush){$state['transaction_attempts']++;$flush();});
if($fake){
 // Only the local fake smoke mode uses SQLite; engine runner refuses this mode.
 $sql=file_get_contents(__DIR__.'/fake-schema.sql');DB::unprepared($sql);resetData();$state['mode']='LOCAL_FAKE_SQLITE_NOT_ENGINE_PROOF';
}
$log=Mockery::mock();$log->shouldReceive('channel')->andReturnSelf();
$log->shouldReceive('info')->andReturnUsing(function($event,$data)use(&$state,$flush,$mode,$fake){
 if($event!=='hnl_standings_committed')return;
 $state['receipt_attempts']++;must(DB::transactionLevel()===0,'receipt_before_commit');
 if(!$fake){$p=new PDO('mysql:unix_socket=/tmp/hnl-ci/mariadb.sock;dbname=hnl_ci','ci','');$state['receipt_external_visible_rows']=(int)$p->query('SELECT COUNT(*) FROM standings WHERE league_id=835 AND season=2026')->fetchColumn();must($state['receipt_external_visible_rows']===10,'receipt_not_visible_to_independent_connection');}
 $state['receipt_transaction_level']=DB::transactionLevel();$flush();
 if($mode==='receipt-failure')throw new RuntimeException('injected_receipt_sink_failure');
 $state['receipts']++;$state['receipt']=$data;$flush();
});Log::swap($log);
$quota=Mockery::mock(ApiFootballQuotaStore::class);$quota->shouldReceive('reserve')->andReturn(['allowed'=>true]);$quota->shouldReceive('record')->andReturnNull();
Http::swap(new Factory);Http::preventStrayRequests();
Http::fake(function($request,$options)use(&$state,$flush){
 must($request->url()==='https://provider.invalid/standings?league=210&season=2026','fake_endpoint');must(!$options['allow_redirects'],'redirects');
 $state['fetches']++;$state['at_fetch']=snapshot();$flush();mark('fake_fetch',['case'=>$state['case'],'fetches'=>$state['fetches']]);return Http::response(payload(),200);
});
Standing::saved(function()use(&$state,$flush,$mode){
 $state['saves']++;$flush();
 if($state['saves']===3&&$mode==='deadlock'){
  mark('sync_three_upserts_before_deadlock',['connection_id'=>$state['connection_id']]);
  DB::update('UPDATE hnl_ci_weight SET value=value+1 WHERE id=1');
 }
 if($state['saves']===3&&$mode==='midfailure'){
  DB::statement("SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected_mid_upsert_failure'");
 }
});
$flush();mark('sync_start',['case'=>$case,'connection_id'=>$state['connection_id']]);
try{$state['result']=(new HnlStandingsSync(new ApiFootballGateway($quota)))->sync();}
catch(Throwable $e){$state['error_class']=get_class($e);$state['error_code']=$e->getCode();$state['driver_code']=$e instanceof Illuminate\Database\QueryException?($e->errorInfo[1]??null):null;$state['reason']=$e instanceof Illuminate\Database\QueryException?'database_exception':$e->getMessage();}
$state['final_transaction_level']=DB::transactionLevel();$state['done']=true;$flush();mark('sync_end',['case'=>$case,'fetches'=>$state['fetches'],'driver_code'=>$state['driver_code']??null]);
Mockery::close();
