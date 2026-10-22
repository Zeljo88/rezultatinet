<?php
require __DIR__.'/common.php';
use Illuminate\Support\Facades\DB;
$case=$argv[1];putenv('CASE_DIR=/evidence/'.$case);mkdir(getenv('CASE_DIR'),0770,true);boot();$workers=[];$holder=false;$checks=0;
function ok($v,$m){global $checks;$checks++;check($v,$m);}
function launch($label,$pause='',$mode='',$changed=false,$clock='2026-10-23 04:45:00'){
 global $workers;$env=getenv();foreach(['LABEL'=>$label,'PAUSE'=>$pause,'MODE'=>$mode,'CHANGED'=>$changed?'1':'0','CLOCK'=>$clock] as $k=>$v)$env[$k]=$v;
 $p=proc_open(['php','-d','zend.exception_ignore_args=1',__DIR__.'/worker.php'],[0=>['file','/dev/null','r'],1=>['file',getenv('CASE_DIR').'/'.$label.'.stdout','w'],2=>['file',getenv('CASE_DIR').'/'.$label.'.stderr','w']],$pipes,getcwd(),$env);check(is_resource($p),'spawn');$workers[$label]=$p;return $p;
}
function readj($n){return json_decode(file_get_contents(getenv('CASE_DIR').'/'.$n.'.json'),true);}
function awaitfile($n,$seconds=4){$end=microtime(true)+$seconds;while(!is_file(getenv('CASE_DIR').'/'.$n.'.json')){if(microtime(true)>$end)throw new RuntimeException('missing_barrier_'.$n);usleep(10000);}return readj($n);}
function releaseworker($n){touch(getenv('CASE_DIR').'/release-'.$n.'.json');}
function joinworker($n,$killed=false){global $workers;$end=microtime(true)+8;do{$s=proc_get_status($workers[$n]);if(!$s['running'])break;if(microtime(true)>$end)throw new RuntimeException('worker_join_timeout');usleep(10000);}while(true);savej($n.'-exit',$s);proc_close($workers[$n]);unset($workers[$n]);if($killed){ok($s['signaled']&&$s['termsig']===9,'not_observed_sigkill');return null;}ok($s['exitcode']===0,'worker_nonzero_'.$n);return readj($n.'-result');}
function diagnostics($name){$p=new PDO('mysql:unix_socket=/tmp/hnl-ci/mariadb.sock;dbname=hnl_ci','ci','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$v=[];foreach(['trx'=>'SELECT * FROM information_schema.INNODB_TRX','waits'=>'SELECT * FROM information_schema.INNODB_LOCK_WAITS','processes'=>'SHOW FULL PROCESSLIST','ledger'=>'SELECT * FROM hnl_standings_runs'] as $k=>$sql)$v[$k]=$p->query($sql)->fetchAll(PDO::FETCH_ASSOC);savej($name,$v);return $v;}
function observewait($label){$w=awaitfile($label.'-started');$end=microtime(true)+4;$polls=[];do{$v=diagnostics('latest-poll');$polls[]=['at'=>microtime(true),'trx'=>$v['trx'],'waits'=>$v['waits']];foreach($v['trx'] as $r)if((int)$r['trx_mysql_thread_id']===$w['connection'] && $r['trx_state']==='LOCK WAIT'){savej('observed-wait',['worker'=>$w,'polls'=>$polls]);return;}usleep(25000);}while(microtime(true)<$end);savej('failed-polls',$polls);throw new RuntimeException('no_actual_lock_wait_observed');}
function calls(){ $p=getenv('CASE_DIR').'/physical.ndjson';return is_file($p)?count(file($p,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)):0; }
function killworker($n){global $workers;diagnostics($n.'-prekill');proc_terminate($workers[$n],9);joinworker($n,true);diagnostics($n.'-afterkill');}
try {
 seed();$before=image();savej('before',$before);$meta=(array)DB::selectOne('SELECT VERSION() AS version, @@tx_isolation AS isolation_level, @@innodb_lock_wait_timeout AS lock_timeout, @@skip_networking AS skip_networking, @@innodb_flush_log_at_trx_commit AS durable_commit');ok(str_starts_with($meta['version'],'10.11.14-')&&$meta['isolation_level']==='REPEATABLE-READ'&&(int)$meta['lock_timeout']===5&&(int)$meta['skip_networking']===1&&(int)$meta['durable_commit']===1,'engine_contract');savej('engine',$meta);
 if($case==='migration'){
  $m=require 'deployment/hnl-daily/2026_10_22_000001_create_hnl_standings_runs.php';$m->down();ok(!DB::getSchemaBuilder()->hasTable('hnl_standings_runs'),'empty_down');$m->up();$ddl=(array)DB::selectOne('SHOW CREATE TABLE hnl_standings_runs');savej('ddl',$ddl);ok(str_contains(json_encode($ddl),'InnoDB'),'ledger_engine');
  launch('manual');$r=joinworker('manual');ok($r['outcome']==='committed','migration_service');try{$m->down();throw new RuntimeException('populated_down_accepted');}catch(RuntimeException $e){ok(str_contains($e->getMessage(),'populated rollback refused'),'wrong_rollback_error');}ok(DB::table('hnl_standings_runs')->count()===1,'claim_erased');
 } elseif(in_array($case,['concurrent-claim','manual-scheduler'])){
  launch('manual','afterclaim');awaitfile('manual-barrier');ok(DB::table('hnl_standings_runs')->count()===1,'claim_not_durable');launch('scheduler');$r=joinworker('scheduler');ok($r['outcome']==='slot_consumed','duplicate_not_denied');releaseworker('manual');$r=joinworker('manual');ok($r['outcome']==='committed','winner_not_committed');ok(calls()===1,'overlap_extra_request');ok(image()===$before,'unchanged_touch');
 } elseif(str_starts_with($case,'crash-')){
  $stage=substr($case,6);$changed=in_array($stage,['midwrite','precommit']);launch('manual',$stage,'',$changed);awaitfile('manual-barrier');killworker('manual');$n=calls();ok($n<=1,'crash_extra_request');$row=DB::table('hnl_standings_runs')->first();
  if(in_array($stage,['postcommit','lostack'])){ok($row->outcome==='committed'&&$row->changed_rows===0,'atomic_nochange_marker_missing');$r=service()->reconcile($row->id);ok($r['outcome']==='committed','fresh_reconcile');ok(image()===$before,'nochange_wrote_rows');launch('restart');ok(joinworker('restart')['outcome']==='slot_consumed','postcommit_replay');}
  elseif($stage==='beforeclaim'){ok(!$row&&$n===0,'preclaim_effect');launch('restart');ok(joinworker('restart')['outcome']==='committed','preclaim_restart');}
  else{ok($row->outcome==='claimed','crash_claim_reset');ok(image()===$before,'crash_partial_commit');ok(service()->reconcile($row->id)['outcome']==='commit_unknown','false_reconcile');launch('restart');ok(joinworker('restart')['outcome']==='slot_consumed','same_day_replay');launch('tomorrow','','',false,'2026-10-24 04:45:00');$r=joinworker('tomorrow');ok($r['outcome']==='failed'&&$r['reason']==='hnl_daily_unresolved_run','unresolved_nextday_replay');ok(calls()===$n,'nextday_extra_request');}
 } elseif(in_array($case,['lock-timeout','deadlock','identity-drift','membership-drift','lease-overtake'])){
  DB::beginTransaction();$holder=true;
  if($case==='deadlock'){DB::table('teams')->where('id',1507)->lockForUpdate()->first();DB::table('standings')->update(['description'=>'holder-weight']);}
  else DB::table('leagues')->where('id',835)->lockForUpdate()->first();
  launch('manual','','',false);observewait('manual');
  if($case==='lock-timeout'){$r=joinworker('manual');ok($r['outcome']==='failed','timeout_result');ok(readj('manual-sql-error')['errorInfo'][1]===1205,'actual1205_missing');DB::rollBack();$holder=false;}
  elseif($case==='deadlock'){DB::table('leagues')->where('id',835)->lockForUpdate()->first();$r=joinworker('manual');ok($r['outcome']==='failed','deadlock_result');ok(readj('manual-sql-error')['errorInfo'][1]===1213,'actual1213_sync_victim_missing');DB::rollBack();$holder=false;}
  elseif($case==='lease-overtake'){killworker('manual');DB::rollBack();$holder=false;launch('overtaker');ok(joinworker('overtaker')['outcome']==='slot_consumed','overtaker_replayed');}
  else{if($case==='identity-drift')DB::table('teams')->where('id',1507)->update(['api_team_id'=>999999]);else DB::table('standings')->where('id',538)->delete();DB::commit();$holder=false;$expected=image();$r=joinworker('manual');ok($r['outcome']==='failed','drift_admitted');ok(image()===$expected,'drift_overwritten');}
  ok(calls()===0,'prefetch_lock_replayed');
 } elseif($case==='lease-expiry-overtake'){
  launch('stale','beforehttp','lease-lost');awaitfile('stale-barrier');launch('newowner','','',false,'2026-10-24 04:45:00');observewait('newowner');releaseworker('stale');ok(joinworker('stale')['outcome']==='failed','stale_owner_admitted');ok(joinworker('newowner')['outcome']==='committed','newowner_not_serialized');ok(calls()===1&&image()===$before,'lease_overtake_changed_or_replayed');
 } elseif(in_array($case,['midnight-before','midnight-reserve','lease-lost','network'])){
  launch('manual','',$case);$r=joinworker('manual');ok($r['outcome']==='failed','failure_not_refused');ok(image()===$before,'failure_mutated');ok(calls()===($case==='network'?1:0),'physical_boundary');
  if($case==='network'){launch('tomorrow','','',false,'2026-10-24 04:45:00');$r=joinworker('tomorrow');ok($r['reason']==='hnl_daily_unresolved_run'&&calls()===1,'uncertain_network_replay');}
 } elseif($case==='changed-nochange'){
  launch('manual','','',true);$r=joinworker('manual');ok($r['outcome']==='committed'&&$r['changed_rows']===10,'changed_commit');$after=image();foreach($before['standings'] as $i=>$row){if($row['season']===2025)ok($after['standings'][$i]===$row,'archive_changed');else{ok($after['standings'][$i]['id']===$row['id']&&$after['standings'][$i]['created_at']===$row['created_at'],'identity_changed');}}foreach(['teams','leagues','fixtures','fixture_scores'] as $t)ok($before[$t]===$after[$t],'reference_changed');
  launch('tomorrow','','',true,'2026-10-24 04:45:00');$r=joinworker('tomorrow');ok($r['outcome']==='committed'&&$r['changed_rows']===0&&image()===$after,'nextday_nochange');ok(calls()===2,'two_days_not_two_calls');
 }else throw new RuntimeException('unknown_case');
 savej('after',image());savej('ledger',DB::table('hnl_standings_runs')->orderBy('utc_day')->get()->all());savej('result',['result'=>'PASS','case'=>$case,'assertions'=>$checks,'physical_fake_requests'=>calls(),'provider_requests'=>0]);
}catch(Throwable $e){savej('failure',['case'=>$case,'class'=>get_class($e),'message'=>$e->getMessage(),'assertions'=>$checks]);try{diagnostics('failure-before-cleanup');}catch(Throwable){}foreach(array_keys($workers) as $n){try{killworker($n);}catch(Throwable){}}if($holder)DB::rollBack();exit(1);}
