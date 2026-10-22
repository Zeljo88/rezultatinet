"""One job, one monotonic deadline; no application or probe implementation changes."""
from pathlib import Path
import hashlib, importlib.util, json, os, signal, subprocess, sys, time
sys.dont_write_bytecode=True
W=Path.cwd(); Q=W/'tools/permissions-probe'; F=W/'full-work'; A=W/'combined-artifact'
CANDIDATE='9deffc32b1466f4187238f6cc72311cd88a5ac9b'
SCENARIOS='empty-release populated-release empty-membership populated-membership empty-team populated-team empty-league populated-league timeout deadlock midfailure success receipt-failure'.split()
# Absolute job elapsed ceilings. Each includes process termination overhead.
CEILINGS={'setup':160,'probe':245,'probe_cleanup':255,'probe_publish':275,'full':800,'cleanup':825,'seal':840,'upload':900}
DURATIONS={'setup':100,'probe':85,'probe_cleanup':10,'probe_publish':20,'full':525,'cleanup':25,'seal':15}
FULL_REQUIRED=525; FINAL_RESERVE=100
os.umask(0o007)
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def read(p):return json.loads(p.read_text())
def write(p,v):
 p.parent.mkdir(parents=True,exist_ok=True)
 with p.open('w') as f:json.dump(v,f,indent=2,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
def load_probe():
 spec=importlib.util.spec_from_file_location('exact_probe',Q/'host.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);m.W=W;m.B=W/'probe-work';m.A=W/'probe-artifact';return m
class Inconclusive(Exception):pass
class Controller:
 def __init__(self):
  self.start=float(Path(os.environ['RUNNER_TEMP'],'hnl-combined-start').read_text());self.deadline=self.start+900
  self.phase_end=self.start+800;self.full_called=False
 def remaining(self):return self.deadline-time.monotonic()
 def phase(self,name):self.phase_end=min(self.start+CEILINGS[name],time.monotonic()+DURATIONS[name])
 def run(self,args,cwd=W,cap=None,check=True,env=None):
  seconds=self.phase_end-time.monotonic()-2
  if cap is not None:seconds=min(seconds,cap-2)
  if seconds<=0:raise Inconclusive('phase budget exhausted before command')
  p=subprocess.Popen(args,cwd=cwd,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,start_new_session=True)
  try:out,err=p.communicate(timeout=seconds)
  except subprocess.TimeoutExpired:
   os.killpg(p.pid,signal.SIGTERM)
   try:p.communicate(timeout=1)
   except subprocess.TimeoutExpired:os.killpg(p.pid,signal.SIGKILL);p.communicate(timeout=1)
   raise Inconclusive('command deadline: '+args[0])
  if any(str(a).endswith('/setup.py') or str(a).endswith('/host.py') or str(a)=='tools/hnl-ci/run.sh' for a in args):
   label='full-harness' if args[-1]=='tools/hnl-ci/run.sh' else ('setup' if args[-1].endswith('/setup.py') else 'probe-'+args[-1])
   payload=out+b'\n'+err
   assert len(payload)<2_000_000
   A.mkdir(exist_ok=True);(A/(label+'.log')).write_bytes(payload)
  if check and p.returncode:raise RuntimeError('command failed: '+repr(args[:4])+' exit='+str(p.returncode))
  return subprocess.CompletedProcess(args,p.returncode,out,err)
 def probe_gate(self):
  p=W/'probe-artifact';manifest=read(p/'READBACK_SHA256SUMS.json')
  assert set(manifest)=={str(f.relative_to(p)) for f in p.rglob('*') if f.is_file() and f.name!='READBACK_SHA256SUMS.json'}
  assert all(not (p/n).is_symlink() and sha(p/n)==h for n,h in manifest.items())
  s=read(p/'probe-summary.json');assert s['result']=='PERMISSIONS_PROBE_PASS' and s['attempt']==1 and s['reruns']==0 and all(v==0 for v in s['counters'].values())
  assert s['scope']=='IO_ONLY_NO_ENGINE_NO_DATABASE'
  r=read(p/'resources.json');cleanup=read(p/'cleanup.json');assert cleanup['result']=='PASS'
  assert {(x['kind'],x['name']) for x in cleanup['resources']}=={('container',r['old']),('container',r['corrected']),('volume',r['volume'])}
  assert all(x['absent'] is True and x['listing_succeeded'] is True for x in cleanup['resources'])
  assert read(p/'private-control.json')['result']=='EXPECTED_PERMISSION_ERROR'
  assert len(read(p/'overwrite-passes.json'))==3 and len(read(p/'producer-stats.json'))==2
  phase=read(p/'probe-phase.json');assert phase['result']=='PERMISSIONS_BOUNDARY_PASS' and phase['producer_passes']==2 and phase['intentional_failure_exit']==23
  assert read(p/'mount-checks.json')['result']=='PASS'
  assert read(p/'corrected-inspect.json')['Image']==read(p/'image.json')['id']
  m=load_probe();m.readonly_outcomes(read(p/'readonly-controls.json'))
  m.required_mounts(read(p/'corrected-inspect.json'),read(p/'mountinfo.json'),read(p/'container-identity.json'),read(p/'host.json'),r['volume'])
  assert read(p/'dependencies.json')['package_metadata_matches_lock'] is True
  produced=read(p/'producer-stats.json')[-1];consumed=read(p/'host-consumer-before-cleanup.json')
  assert set(produced)==set(consumed)
  for n in produced:
   assert all(produced[n][k]==consumed[n][k] for k in consumed[n])
  checks=read(p/'host-controls.json');assert len(checks)==9 and all(x['expected_rejection'] for x in checks)
  assert {x.get('kind') for x in checks if 'kind' in x}=={'symlink','hardlink','fifo','mode'}
  for folder in ['existing-evidence','missing-evidence']:
   for n,h in read(p/folder/'SHA256SUMS.json').items():assert sha(p/folder/n)==h
  assert read(p/'existing-evidence/result.json')['result']=='INCONCLUSIVE'
  assert read(p/'missing-evidence/result.json')['result']=='FAILED'
  self.conservation(W/'probe-work/candidate')
  # Independently re-observe absence; published absence is necessary but not alone sufficient.
  for kind,name in [('container',r['old']),('container',r['corrected']),('volume',r['volume'])]:self.absent(kind,name)
  write(A/'pre-db-probe-readback.json',{'result':'PASS','scope':'LOCAL_HOST_READBACK_NOT_EXTERNAL_ARTIFACT_DOWNLOAD','seal_sha256':sha(p/'READBACK_SHA256SUMS.json'),'elapsed':time.monotonic()-self.start})
 def conservation(self,root):
  for name,count in [('reviewed-manifest.json',274),('harness-manifest.json',26)]:
   manifest=read(root/'tools/hnl-ci'/name);assert len(manifest)==count
   assert all(sha(root/n)==h for n,h in manifest.items())
 def absent(self,kind,name):
  self.run(['docker',kind,'ls','-aq' if kind=='container' else '-q'])
  r=self.run(['docker',kind,'inspect',name],check=False)
  assert r.returncode!=0 and ('no such '+kind).encode() in r.stderr.lower()
  return {'kind':kind,'name':name,'absent':True,'listing_succeeded':True}
 def admit_full(self):
  self.probe_gate()
  if self.full_called:raise AssertionError('second full execution forbidden')
  if self.remaining()<FULL_REQUIRED+FINAL_RESERVE:raise Inconclusive('insufficient full 525s plus final 100s reserve BEFORE DB')
  self.full_called=True;self.phase('full');self.full()
 def full(self):
  # Separate export and six-file preinitialisation; never copy probe synthetic outputs.
  F.mkdir();E=F/'candidate-evidence';E.mkdir(mode=0o2770);E.chmod(0o2770)
  env=dict(os.environ);env.pop('GITHUB_SHA',None);env['HNL_OUT']=str(E)
  source=W/'probe-work/candidate-git'
  self.run(['python3','tools/hnl-ci/verify.py'],cwd=source,env=env)
  diff=self.run(['git','diff','--binary','da9b83982de3ff800c0b656bdcf8f8cb86526585','HEAD'],cwd=source).stdout
  write(E/'delivery.json',{'head':CANDIDATE,'combined_head':os.environ['GITHUB_SHA'],'test_workflow_diff_sha256':hashlib.sha256(diff).hexdigest(),'combined_manifest_sha256':sha(W/'tools/combined-ci/manifest.json')})
  self.run(['python3','tools/hnl-ci/deliver.py',str(F/'candidate'),str(E)],cwd=source,env=env)
  pkg=read(E/'packaging.json');assert pkg['files']==301 and pkg['head']==CANDIDATE and pkg['archive_sha256']=='c7687afc54d600cbf946b762bd308fcafe3e5504e126303c14519e68d889a531'
  self.conservation(F/'candidate');(F/'candidate/vendor').mkdir()
  suffix=os.environ['GITHUB_RUN_ID'];assert suffix.isdigit()
  names={'test':'hnl-combined-test-'+suffix,'db':'hnl-combined-db-'+suffix,'volume':'hnl-perm-socket-'+suffix+'1'}
  for k in ['test','db']:self.absent('container',names[k])
  self.absent('volume',names['volume']);write(A/'full-resources.json',names)
  self.run(['docker','volume','create',names['volume']])
  image=read(W/'probe-artifact/image.json')['id'];assert image.startswith('sha256:')
  flags=['--user','1000:1000','--group-add',str(os.getgid()),'--network','none','--cap-drop','ALL','--security-opt','no-new-privileges']
  self.run(['docker','run','-d','--name',names['test'],*flags,'--mount','type=volume,src='+names['volume']+',dst=/tmp/hnl-ci','--mount','type=bind,src='+str(F/'candidate')+',dst=/workspace,readonly','--tmpfs','/workspace/vendor:rw,nosuid,nodev,uid=1000,gid=1000,mode=0700','--mount','type=bind,src='+str(E)+',dst=/evidence',image,'sleep','530'])
  # Fresh live process + descendant inventory, assessed by the unchanged reviewed parser.
  script="from pathlib import Path;import json;print(json.dumps({'identity':{l.split(':',1)[0]:l.split(':',1)[1].strip() for l in Path('/proc/self/status').read_text().splitlines() if l.split(':',1)[0] in ['Uid','Gid','Groups','CapEff','CapBnd','NoNewPrivs']},'mounts':[l for l in Path('/proc/self/mountinfo').read_text().splitlines() if any(l.split()[4]==r or l.split()[4].startswith(r+'/') for r in ['/workspace','/workspace/vendor','/evidence','/tmp/hnl-ci'])]}))"
  capture=json.loads(self.run(['docker','exec',names['test'],'python3','-c',script]).stdout)
  raw=json.loads(self.run(['docker','inspect',names['test']]).stdout)[0]
  x={'Image':raw['Image'],'User':raw['Config']['User'],'HostConfig':raw['HostConfig'],'TmpfsPresence':'present' if 'Tmpfs' in raw['HostConfig'] else 'absent','Mounts':raw['Mounts']}
  assert x['Image']==image;m=load_probe();m.B=F
  assessment=m.required_mounts(x,capture['mounts'],capture['identity'],read(W/'probe-artifact/host.json'),names['volume'])
  write(A/'full-mount-gate.json',{'inspect':x,'process':capture,'assessment':assessment,'image':image})
  # Complete fullphase start admission again after export/container gates, before DB startup.
  if self.phase_end-time.monotonic()<510:raise Inconclusive('insufficient DB35+run475 budget before DB')
  db_end=time.monotonic()+35
  dbimage=read(A/'database-image.json')['id']
  self.run(['docker','run','-d','--name',names['db'],'--network','none','--mount','type=volume,src='+names['volume']+',dst=/tmp/hnl-ci','--mount','type=bind,src='+str(F/'candidate/tools/hnl-ci/database.sh')+',dst=/ci-database.sh,readonly','--entrypoint','bash',dbimage,'/ci-database.sh'],cap=10)
  db=json.loads(self.run(['docker','inspect',names['db']],cap=5).stdout)[0]
  assert db['HostConfig']['NetworkMode']=='none' and db['Image']==dbimage
  # Poll readiness only; no scenario retry or second DB startup.
  while True:
   r=self.run(['docker','exec',names['db'],'mariadb-admin','--socket=/tmp/hnl-ci/mariadb.sock','-uroot','ping','--silent'],check=False,cap=min(3,db_end-time.monotonic()))
   if r.returncode==0:break
   if time.monotonic()+3>=db_end:raise Inconclusive('database startup budget')
   time.sleep(.2)
  self.run(['docker','exec',names['db'],'mariadb','--socket=/tmp/hnl-ci/mariadb.sock','-uroot','-e',"CREATE DATABASE hnl_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'ci'@'localhost' IDENTIFIED VIA unix_socket; GRANT ALL ON hnl_ci.* TO 'ci'@'localhost'; GRANT PROCESS ON *.* TO 'ci'@'localhost';"],cap=max(0,db_end-time.monotonic()))
  write(E/'mariadb-image.json',read(A/'database-image.json'));write(E/'database-network.json','none')
  self.run(['docker','exec',names['test'],'bash','tools/hnl-ci/run.sh'],cap=475)
  # Exact harness run.sh does all13 cases and the100/987 selection, once.
  self.full_result(E)
  self.conservation(F/'candidate')
  if time.monotonic()>self.phase_end:raise Inconclusive('fullphase deadline')
  write(A/'full-phase.json',{'result':'PASS','engine_cases':13,'tests':100,'assertions':987})
 def full_result(self,E):
  cases={p.name.removesuffix('.result.json'):read(p) for p in E.glob('*.result.json')}
  assert set(cases)==set(SCENARIOS) and all(c['result']=='PASS' for c in cases.values())
  r=read(E/'regressions.json');assert r['result']=='PASS' and r['unique_tests']==100 and r['assertions']==987
  assert read(E/'result.json')['result']=='CI_PASSED_FOR_RELEASE_REVIEW'
  assert read(E/'engine.json')['schema_sha256']==sha(F/'candidate/tools/hnl-ci/schema.sql')
 def execute(self):
  if time.monotonic()-self.start>60:raise Inconclusive('checkout exceeded60s')
  assert os.environ['GITHUB_RUN_ATTEMPT']=='1' and os.environ['GITHUB_REF']=='refs/heads/ci/hnl-combined-20261022-once'
  assert os.getuid() not in [0,1000] and os.getgid()!=1000
  for n,h in read(W/'tools/combined-ci/manifest.json').items():assert sha(W/n)==h
  self.phase('setup');self.run(['python3',str(Q/'setup.py')])
  self.run(['docker','pull','mariadb:10.11.13'])
  db=json.loads(self.run(['docker','image','inspect','mariadb:10.11.13']).stdout)[0]
  write(A/'database-image.json',{'id':db['Id'],'RepoDigests':db['RepoDigests']})
  self.phase('probe');self.run(['python3',str(Q/'host.py'),'probe'])
  self.phase('probe_cleanup');self.run(['python3',str(Q/'host.py'),'cleanup'])
  self.phase('probe_publish');self.run(['python3',str(Q/'host.py'),'publish'])
  self.admit_full()
 def cleanup(self):
  self.phase('cleanup');records=[]
  # Cleanup both phases even after setup/probe/full failure; never suppress absence errors.
  if (W/'probe-artifact/resources.json').exists():self.run(['python3',str(Q/'host.py'),'cleanup'])
  if (A/'full-resources.json').exists():
   n=read(A/'full-resources.json')
   for kind,name in [('container',n['test']),('container',n['db']),('volume',n['volume'])]:
    r=self.run(['docker',kind,'inspect',name],check=False)
    if r.returncode==0:self.run(['docker','rm','-f','-v',name] if kind=='container' else ['docker','volume','rm',name])
    records.append(self.absent(kind,name))
  write(A/'cleanup.json',{'result':'PASS','full_resources':records})
 def seal(self):
  self.phase('seal');cleanup_ok=(A/'cleanup.json').exists() and read(A/'cleanup.json')['result']=='PASS'
  E=F/'candidate-evidence'
  if E.exists():
   # Publisher path is fixed evidence/, so use a separate parent with the genuine full root.
   E.rename(F/'evidence');E=F/'evidence'
   self.run(['python3',str(F/'candidate/tools/hnl-ci/publish.py')],cwd=F)
   before=(E/'SHA256SUMS.json').read_bytes();self.run(['python3',str(F/'candidate/tools/hnl-ci/publish.py')],cwd=F)
   assert before==(E/'SHA256SUMS.json').read_bytes()
   assert all(sha(E/n)==h for n,h in read(E/'SHA256SUMS.json').items())
  ok=cleanup_ok and (A/'full-phase.json').exists() and read(A/'full-phase.json')['result']=='PASS' and not (A/'failure.json').exists()
  write(A/'result.json',{'result':'PENDING_EXTERNAL_ARTIFACT_READBACK' if ok else 'FAILED_OR_INCONCLUSIVE','container_full_pass':ok,'external_artifact_readback':'NOT_RUN','reruns':0,'attempt':1,'elapsed':time.monotonic()-self.start})
  # Probe cleanup may be reobserved after its gate; reseal only its outer transport manifest.
  for root in [W/'probe-artifact',A,E]:
   if not root.exists():continue
   files={str(p.relative_to(root)):sha(p) for p in sorted(root.rglob('*')) if p.is_file() and p.name!='TRANSPORT_SHA256SUMS.json'}
   assert sum((root/n).stat().st_size for n in files)<20_000_000
   for n in files:
    p=root/n;assert not p.is_symlink() and p.stat().st_nlink==1 and p.stat().st_size<2_000_000
    assert not any(marker in p.read_bytes() for marker in [b'github_pat_',b'ghp_',b'PRIVATE KEY'])
   write(root/'TRANSPORT_SHA256SUMS.json',files)
  if time.monotonic()>self.start+840:raise Inconclusive('no complete60s upload reserve')
  with open(os.environ['GITHUB_OUTPUT'],'a') as f:f.write('upload=true\n')
if __name__=='__main__':
 c=Controller()
 try:getattr(c,sys.argv[1])()
 except Exception as exc:
  write(A/'failure.json',{'phase':sys.argv[1],'error':type(exc).__name__,'detail':str(exc),'elapsed':time.monotonic()-c.start});raise
