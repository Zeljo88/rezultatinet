<?php
require __DIR__.'/common.php';
use Illuminate\Support\Facades\DB;
$case=$argv[1]??'';must(in_array($case,['success','receipt-failure','midfailure','timeout','deadlock','empty-release','populated-release','empty-membership','populated-membership','empty-team','populated-team','empty-league','populated-league'],true),'unknown_scenario');
boot();$version=DB::selectOne('SELECT VERSION() AS version, @@tx_isolation AS isolation_level, @@innodb_lock_wait_timeout AS lock_timeout');
must(str_starts_with($version->version,'10.11.13-MariaDB'),'engine_version');must($version->isolation_level==='REPEATABLE-READ'&&(int)$version->lock_timeout===5,'engine_configuration');
$pidA=(int)DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$children=[];
function launch(string $name,string $mode='normal'):array{
 global $children;
 $p=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',__DIR__.'/worker.php',$name,$mode],[0=>['file','/dev/null','r'],1=>['file',outdir().'/'.$name.'.stdout','w'],2=>['file',outdir().'/'.$name.'.stderr','w']],$pipes);
 must(is_resource($p),'worker_spawn');$children[]=$p;
 $s=waitFor(fn()=>readjson($name.'.state.json'),'worker_start');return [$p,$s['connection_id']];
}
function finish($p,string $name):array{
 $s=waitFor(fn()=>($v=readjson($name.'.state.json'))&&($v['done']??false)?$v:null,'worker_finish',7);
 $rc=proc_close($p);must($rc===0,'worker_exit');must($s['final_transaction_level']===0,'transaction_level_after');return $s;
}
function lockWait(int $b,int $a):array{
 return waitFor(function()use($b,$a){
  $rows=DB::select('SELECT r.trx_mysql_thread_id AS requesting_connection, b.trx_mysql_thread_id AS blocking_connection, r.trx_weight AS request_weight, b.trx_weight AS blocker_weight, l.lock_table, l.lock_index, l.lock_type, l.lock_mode, l.lock_data FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id WHERE r.trx_mysql_thread_id=? AND b.trx_mysql_thread_id=?',[$b,$a]);return $rows?array_map(fn($x)=>(array)$x,$rows):null;
 },'observed_innodb_wait');
}
function counts(array $s,int $fetches,int $receipts):void{must($s['fetches']===$fetches,'fetch_count');must($s['transaction_attempts']===1,'no_transaction_retry');must($s['receipts']===$receipts,'receipt_count');}
try{
 $pop=str_starts_with($case,'populated');resetData($pop);$before=snapshot();putjson($case.'.before.json',$before);
 if(str_contains($case,'-release')||str_contains($case,'-membership')||str_contains($case,'-team')||str_contains($case,'-league')||$case==='timeout'){
  DB::beginTransaction();DB::select('SELECT id FROM leagues WHERE id=835 FOR UPDATE');mark('holder_league_lock',['case'=>$case,'connection_id'=>$pidA]);
  [$p,$pidB]=launch($case);$wait=lockWait($pidB,$pidA);must(str_contains($wait[0]['lock_table'],'leagues'),'blocked_on_league');
  $blocked=readjson($case.'.state.json');must($blocked['fetches']===0&&$blocked['saves']===0&&$blocked['receipts']===0,'prefetch_block_has_no_effects');putjson($case.'.lock-wait.json',['engine'=>$version,'wait'=>$wait,'blocked'=>$blocked]);mark('observed_prefetch_wait',['case'=>$case]);
  if($case==='timeout'){$s=finish($p,$case);must($s['driver_code']===1205,'actual_lock_timeout');counts($s,0,0);must($s['saves']===0&&$s['receipt_attempts']===0,'timeout_no_write_receipt');DB::rollBack();must(snapshot()===$before,'timeout_snapshot');}
  else{
   if(str_contains($case,'-release')){DB::table('leagues')->where('id',835)->update(['current_season'=>2026]);if($pop)DB::table('standings')->where('league_id',835)->where('season',2026)->update(['points'=>777]);}
   if(str_contains($case,'-membership'))DB::table('standings')->insert(['league_id'=>835,'team_id'=>9999,'season'=>2026,'rank'=>11,'points'=>0]);
   if(str_contains($case,'-team'))DB::table('teams')->where('id',1447)->update(['api_team_id'=>888888]);
   if(str_contains($case,'-league'))DB::table('leagues')->where('id',835)->update(['api_league_id'=>888888]);
   $committed=snapshot();putjson($case.'.holder-postimage.json',$committed);mark('holder_commit_begin',['case'=>$case]);DB::commit();$s=finish($p,$case);
   if(str_contains($case,'-release')){counts($s,1,1);must($s['at_fetch']===$committed,'locked_current_snapshot_observed');must($s['result']['changed_rows']===10,'ten_changed');conserved($committed,snapshot());}
   else{$expected=str_contains($case,'-membership')?'current_snapshot_identity':(str_contains($case,'-team')?'local_team_identity':'local_league');counts($s,0,0);must($s['reason']==='hnl_standings_'.$expected,'identity_rejected');must($s['saves']===0&&$s['receipt_attempts']===0,'rejected_no_writes');must(snapshot()===$committed,'concurrent_identity_preserved');}
  }
 }elseif($case==='deadlock'){
  DB::statement('CREATE TABLE IF NOT EXISTS hnl_ci_weight (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');DB::table('hnl_ci_weight')->delete();for($i=1;$i<=256;$i++)DB::table('hnl_ci_weight')->insert(['id'=>$i,'value'=>0]);
  $winnerId=DB::table('standings')->where('league_id',999)->value('id');DB::beginTransaction();DB::update('UPDATE hnl_ci_weight SET value=1');DB::table('standings')->where('id',$winnerId)->update(['points'=>89]);
  [$p,$pidB]=launch($case,'deadlock');$wait=lockWait($pidB,$pidA);$s0=readjson($case.'.state.json');must($s0['fetches']===1&&$s0['saves']===3,'postfetch_three_actual_upserts');must(str_contains($wait[0]['lock_table'],'hnl_ci_weight'),'actual_deadlock_second_resource');must($wait[0]['blocker_weight']>$wait[0]['request_weight'],'controlled_transaction_weight');putjson($case.'.lock-wait.json',$wait);
  mark('closing_real_deadlock_cycle',['holder'=>$pidA,'sync'=>$pidB]);
  try{DB::select('SELECT id FROM leagues WHERE id=835 FOR UPDATE');DB::commit();}catch(Illuminate\Database\QueryException $x){if(($x->errorInfo[1]??null)===1213){if(DB::transactionLevel())DB::rollBack();$other=finish($p,$case);putjson('deadlock-other-victim-worker.json',$other);putjson('deadlock-engine-status.json',DB::select('SHOW ENGINE INNODB STATUS'));throw new Inconclusive('other_deadlock_victim');}throw $x;}
  $s=finish($p,$case);must(($s['driver_code']??null)===1213,'sync_observed_deadlock_victim');counts($s,1,0);must($s['saves']===3&&$s['receipt_attempts']===0,'deadlock_no_replay_no_receipt');
  $after=snapshot();$expected=$before;foreach($expected['standings'] as &$row)if($row['league_id']==999)$row['points']=89;unset($row);must($after===$expected,'complete_sync_rollback_winner_preserved');must(DB::table('hnl_ci_weight')->where('value',1)->count()===256,'winner_weight_commit');
  putjson('deadlock-engine-status.json',DB::select('SHOW ENGINE INNODB STATUS'));
 }else{
  [$p]=launch($case,in_array($case,['midfailure','receipt-failure'],true)?$case:'normal');$s=finish($p,$case);
  if($case==='midfailure'){counts($s,1,0);must($s['driver_code']===1644&&$s['saves']===3&&$s['receipt_attempts']===0,'injected_actual_db_failure');must(snapshot()===$before,'atomic_mid_upsert_rollback');}
  else{counts($s,1,$case==='success'?1:0);must($s['result']['changed_rows']===10&&$s['saves']===10,'ten_rows_success');must($s['result']['outcome']===($case==='success'?'committed':'committed_receipt_unavailable'),'receipt_outcome');must($s['receipt_attempts']===1&&$s['receipt_transaction_level']===0&&$s['receipt_external_visible_rows']===10,'postcommit_receipt');$after=snapshot();conserved($before,$after);
   if($case==='success'){[$p]=launch('success-idempotent');$s2=finish($p,'success-idempotent');counts($s2,1,1);must($s2['saves']===0&&$s2['result']['changed_rows']===0,'exact_no_change');must(snapshot()===$after,'idempotency_snapshot');}
  }
 }
 putjson($case.'.after.json',snapshot());putjson($case.'.result.json',['result'=>'PASS','engine'=>$version,'case'=>$case,'fetches'=>$s['fetches'],'transaction_attempts'=>$s['transaction_attempts'],'saves'=>$s['saves'],'receipts'=>$s['receipts'],'driver_code'=>$s['driver_code']??null]);echo "PASS $case\n";
}catch(Throwable $e){if(DB::transactionLevel())DB::rollBack();putjson($case.'.result.json',['result'=>$e instanceof Inconclusive?'INCONCLUSIVE':'FAILED','case'=>$case,'error_class'=>get_class($e),'reason'=>$e instanceof Illuminate\Database\QueryException?'database_exception':$e->getMessage()]);fwrite(STDERR,"FAILED $case: ".get_class($e).": ".($e instanceof Illuminate\Database\QueryException?'database_exception':$e->getMessage())."\n");foreach($children as $child)if(is_resource($child)){proc_terminate($child,9);proc_close($child);}exit($e instanceof Inconclusive?3:1);
}finally{foreach($children as $p)if(is_resource($p)){proc_terminate($p,9);proc_close($p);}}
