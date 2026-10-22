<?php
require __DIR__.'/common.php';boot();
try {
 savej(getenv('LABEL').'-started',['pid'=>getmypid(),'connection'=>DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
 DB::listen(function($e){
  if(str_starts_with($e->sql,'insert into `hnl_standings_runs`'))barrier('afterclaim');
  if(str_starts_with($e->sql,'update `standings`'))barrier('midwrite');
 });
 $s=service();barrier('beforeclaim');
 $r=(new ReflectionMethod($s,'execute'))->invoke($s,function(){barrier('beforehttp');if(getenv('MODE')==='midnight-before')Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-10-24 00:00:00','UTC'));if(getenv('MODE')==='lease-lost')throw new RuntimeException('lease_lost');});
 savej(getenv('LABEL').'-result',$r);
} catch(Throwable $e){savej(getenv('LABEL').'-error',['class'=>get_class($e),'message'=>$e->getMessage()]);exit(1);}
