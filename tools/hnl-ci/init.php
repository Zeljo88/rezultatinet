<?php
require __DIR__.'/common.php';
use Illuminate\Support\Facades\DB;
boot();must(PHP_MAJOR_VERSION===8&&PHP_MINOR_VERSION===3,'PHP83');
$v=DB::selectOne('SELECT VERSION() AS version, @@tx_isolation AS isolation_level, @@innodb_lock_wait_timeout AS lock_timeout, @@skip_networking AS skip_networking, DATABASE() AS db');
must(str_starts_with($v->version,'10.11.13-MariaDB')&&$v->db==='hnl_ci'&&(int)$v->skip_networking===1,'exact_isolated_engine');
foreach(explode(';',file_get_contents(__DIR__.'/schema.sql')) as $sql)if(trim($sql)!=='')DB::unprepared($sql);
$schemas=[];foreach(['leagues','teams','fixtures','fixture_scores','standings'] as $t)$schemas[$t]=(array)DB::selectOne('SHOW CREATE TABLE `'.$t.'`');
$types=DB::select("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='hnl_ci' AND TABLE_NAME='standings' ORDER BY ORDINAL_POSITION");
$engines=DB::select("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='hnl_ci' ORDER BY TABLE_NAME");must(count($engines)===5,'five_conservation_tables');foreach($engines as $e)must($e->ENGINE==='InnoDB','all_innodb');
putjson('engine.json',['php'=>PHP_VERSION,'engine'=>$v,'engines'=>$engines,'schema'=>$schemas,'standings_columns'=>$types,'schema_sha256'=>hash_file('sha256',__DIR__.'/schema.sql')]);
echo "Exact socket-only MariaDB 10.11.13 / PHP 8.3 / InnoDB / REPEATABLE-READ verified\n";
