from pathlib import Path
import subprocess,tempfile,socket,time,json,re,concurrent.futures,hashlib
w=Path(__file__).resolve().parents[2]
e=Path('/evidence') if Path('/evidence').exists() else Path(__file__).resolve().parent
source=(w/'app/Services/ApiFootball/RedisApiFootballQuotaStore.php').read_text()
lua=re.search(r"\$script = <<<'LUA'\n(.*?)\nLUA;",source,re.S).group(1)
r=Path(tempfile.mkdtemp(prefix='stale-reconciliation-redis-'));sock=str(r/'redis.sock')
p=subprocess.Popen(['redis-server','--port','0','--unixsocket',sock,'--unixsocketperm','700','--save','','--appendonly','no','--dir',str(r)],stdout=(r/'server.log').open('w'),stderr=subprocess.STDOUT)
def cmd(*args):
 with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as s:
  s.connect(sock);f=s.makefile('rb');parts=[str(a).encode() for a in args];s.sendall(b'*'+str(len(parts)).encode()+b'\r\n'+b''.join(b'$'+str(len(a)).encode()+b'\r\n'+a+b'\r\n' for a in parts))
  def read():
   h=f.read(1);v=f.readline()[:-2]
   if h==b':':return int(v)
   if h==b'+':return v.decode()
   if h==b'-':raise RuntimeError(v.decode())
   if h==b'$':
    n=int(v)
    if n<0:return None
    x=f.read(n);f.read(2);return x.decode()
   if h==b'*':return [read() for _ in range(int(v))]
   raise RuntimeError('Bad RESP')
  return read()
def reserve(caller='StaleFixtureReconciliation',cls='fixture_repair',cap=500):
 return cmd('EVAL',lua,4,'g','classes','circuit','callers',int(time.time()),cls,cap,7125,cls+'|'+caller,3600,6375,5250)
results=[]
try:
 for _ in range(100):
  if Path(sock).exists():break
  time.sleep(.01)
 info=cmd('INFO','server');version=re.search(r'redis_version:(.*)',info).group(1).strip()
 for name,g,c,stale,n,allowed,reason in [('daily_concurrent',0,0,0,64,20,'stale_daily_limit'),('repair_headroom_concurrent',0,399,0,32,1,'stale_repair_headroom'),('warning_headroom_concurrent',5249,0,0,32,1,'stale_warning_headroom'),('repair_exact400',0,400,0,1,0,'stale_repair_headroom'),('daily_exact20',0,0,20,1,0,'stale_daily_limit')]:
  cmd('FLUSHDB');cmd('SET','g',g);cmd('HSET','classes','fixture_repair',c);cmd('HSET','callers','fixture_repair|StaleFixtureReconciliation',stale)
  with concurrent.futures.ThreadPoolExecutor(max_workers=32) as pool:out=list(pool.map(lambda _:reserve(),range(n)))
  assert sum(v[0] for v in out)==allowed,(name,out)
  assert all(v[0]==1 or v[1]==reason for v in out),(name,out)
  assert int(cmd('GET','g'))==g+allowed
  assert int(cmd('HGET','classes','fixture_repair'))==c+allowed
  assert int(cmd('HGET','callers','fixture_repair|StaleFixtureReconciliation'))==stale+allowed
  if allowed:assert 0<cmd('TTL','g')<=3600
  results.append({'case':name,'requests':n,'allowed':allowed,'denied':n-allowed,'reason':reason})
 cmd('FLUSHDB');cmd('HSET','classes','fixture_repair',400)
 out=[reserve('FinalizeFinishedFixtures') for _ in range(101)]
 assert sum(v[0] for v in out)==100 and out[-1][1]=='class_hard_stop';results.append({'case':'ordinary_repair_retains_100_headroom_and_500_hard_cap','allowed':100,'denied':1})
 for g,cls,cap,reason in [(5250,'calendar',80,'warning_shed'),(6375,'lineups',300,'critical_shed'),(7125,'fixture_repair',500,'global_hard_stop'),(0,'backfill',0,'class_disabled'),(0,'manual',0,'class_disabled')]:
  cmd('FLUSHDB');cmd('SET','g',g);v=reserve('Ordinary',cls,cap);assert v[0]==0 and v[1]==reason;results.append({'case':reason,'class':cls,'denied':True})
 cmd('FLUSHDB');cmd('SET','circuit',int(time.time())+600);assert reserve()[1]=='circuit';results.append({'case':'circuit','denied':True})
 cas_source=source.split('public function compareAndSetRepairScanState',1)[1]
 cas=re.search(r"\$script = <<<'LUA'\n(.*?)\nLUA;",cas_source,re.S).group(1)
 contract=(w/'app/Contracts/ApiFootballQuotaStore.php').read_text()
 schema=int(re.search(r'REPAIR_SCAN_STATE_SCHEMA\s*=\s*(\d+)',contract).group(1))
 maxint=int(re.search(r'REPAIR_SCAN_STATE_MAX_INTEGER\s*=\s*([\d_]+)',contract).group(1).replace('_',''))
 cas=cas.replace('__SCHEMA__',str(schema)).replace('__MAX_SAFE_INTEGER__',str(maxint))
 def state(g,c,top):return {'schema':schema,'generation':g,'cursor':c,'ceiling':top}
 def swap(old,new):return cmd('EVAL',cas,1,'isolated-cas',json.dumps(old) if old else '',json.dumps(new),3600)
 initial=state(1,0,100)
 with concurrent.futures.ThreadPoolExecutor(max_workers=32) as pool:out=list(pool.map(lambda _:swap(None,initial),range(32)))
 assert sum(out)==1
 with concurrent.futures.ThreadPoolExecutor(max_workers=32) as pool:out=list(pool.map(lambda c:swap(initial,state(1,c,100)),range(1,33)))
 assert sum(out)==1
 current=json.loads(cmd('GET','isolated-cas'))
 assert swap(initial,state(1,99,100))==0
 assert swap(current,state(1,0,100))==0
 assert swap(current,state(2,0,100))==0
 assert swap(current,state(1,100,100))==1
 assert swap(state(1,100,100),state(2,0,200))==1
 assert 0<cmd('TTL','isolated-cas')<=3600
 results.append({'case':'real_CAS_32_way_initialization_and_progress_contention_stale_backward_premature_rollover_rejected','initial_winners':1,'progress_winners':1,'rollover':True,'lua_sha256':hashlib.sha256(cas.encode()).hexdigest()})
 receipt={'result':'PASS','redis_version':version,'lua_sha256':hashlib.sha256(lua.encode()).hexdigest(),'transport':'private Unix socket; TCP disabled','persistence':'disabled','cases':results,'application_provider_requests':0,'db_connections':0}
 (e/'redis-real.json').write_text(json.dumps(receipt,indent=2));print(json.dumps(receipt,indent=2))
finally:
 p.terminate();p.wait(timeout=5)
