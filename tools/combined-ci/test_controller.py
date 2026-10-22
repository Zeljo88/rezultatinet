"""Synthetic command-boundary tests; zero Docker/DB/application execution."""
from pathlib import Path
import contextlib,copy,hashlib,importlib.util,json,os,shutil,subprocess,sys,tempfile,time,unittest
from unittest.mock import patch
sys.dont_write_bytecode=True
ROOT=Path(os.environ.get('COMBINED_PROJECT_ROOT',str(Path(__file__).resolve().parents[4])))
SOURCE=ROOT/'worktrees/hnl-ci-evidence-permissions-20261022'
FIX=ROOT/'artifacts/hnl-ci-effective-mount-final-review-20261022/fixtures'
WRAPPER=Path(__file__).resolve().parents[1]/'permissions-probe'
spec=importlib.util.spec_from_file_location('controller',Path(__file__).with_name('controller.py'));c=importlib.util.module_from_spec(spec);spec.loader.exec_module(c)
def fixture(n):return json.loads((FIX/n).read_text())
class Boundary(unittest.TestCase):
 def setUp(self):
  self.root=Path(tempfile.mkdtemp(prefix=self._testMethodName+'-',dir=os.environ['COMBINED_TEST_ROOT']));self.calls=[];self.live=set();self.vol=set();self.fault=None
  self.stack=contextlib.ExitStack();self.addCleanup(self.stack.close)
  for name,value in [('W',self.root),('F',self.root/'full-work'),('A',self.root/'combined-artifact'),('Q',WRAPPER)]:self.stack.enter_context(patch.object(c,name,value))
  self.stack.enter_context(patch.dict(os.environ,{'RUNNER_TEMP':str(self.root),'GITHUB_RUN_ID':'39754381222','GITHUB_SHA':'SYNTHETIC_COMBINED','GITHUB_REF':'refs/heads/ci/hnl-combined-20261022-once','GITHUB_RUN_ATTEMPT':'1'}))
  (self.root/'hnl-combined-start').write_text(str(time.monotonic()))
  b=self.root/'probe-work';b.mkdir();shutil.copytree(SOURCE,b/'candidate',ignore=shutil.ignore_patterns('.git','vendor'))
  (b/'candidate-git').symlink_to(SOURCE,target_is_directory=True)
  q=self.root/'tools/combined-ci';q.mkdir(parents=True);(q/'manifest.json').write_text('{}\n')
  self.p=self.root/'probe-artifact';self.p.mkdir();self.build_probe()
  c.write(c.A/'database-image.json',{'id':'sha256:DB','RepoDigests':['synthetic']})
  self.ctrl=c.Controller();self.ctrl.run=self.command
  # Full test flags and captured host mapping agree; no real UID transition.
  self.stack.enter_context(patch.object(c.os,'getgid',return_value=1001))
 def build_probe(self):
  p=self.p;b=self.root/'probe-work'
  host=fixture('host.json');host.update(uid=1001,gid=1001);c.write(p/'host.json',host)
  x=fixture('corrected-inspect.json');x['Image']='sha256:TEST';old=next(m['Source'] for m in x['Mounts'] if m['Destination']=='/workspace').removesuffix('/candidate')
  for m in x['Mounts']:m['Source']=m['Source'].replace(old,str(b))
  c.write(p/'corrected-inspect.json',x)
  c.write(p/'mountinfo.json',[l.replace(old,str(b)) for l in fixture('mountinfo.json')])
  c.write(p/'container-identity.json',fixture('container-identity.json'))
  resources=fixture('resources.json');c.write(p/'resources.json',resources)
  c.write(p/'cleanup.json',{'result':'PASS','resources':[{'kind':kind,'name':name,'absent':True,'listing_succeeded':True} for kind,name in [('container',resources['old']),('container',resources['corrected']),('volume',resources['volume'])]]})
  c.write(p/'probe-summary.json',{'result':'PERMISSIONS_PROBE_PASS','attempt':1,'reruns':0,'counters':{'engine':0,'db':0},'scope':'IO_ONLY_NO_ENGINE_NO_DATABASE'})
  c.write(p/'private-control.json',{'result':'EXPECTED_PERMISSION_ERROR'})
  c.write(p/'overwrite-passes.json',[{}, {}, {}]);c.write(p/'producer-stats.json',[{'trace.ndjson':{'sha256':'synthetic'}},{'trace.ndjson':{'sha256':'synthetic'}}])
  c.write(p/'host-consumer-before-cleanup.json',{'trace.ndjson':{'sha256':'synthetic'}})
  c.write(p/'probe-phase.json',{'result':'PERMISSIONS_BOUNDARY_PASS','producer_passes':2,'intentional_failure_exit':23})
  c.write(p/'mount-checks.json',{'result':'PASS'});c.write(p/'dependencies.json',{'package_metadata_matches_lock':True})
  before={'sha256':'synthetic'};c.write(p/'readonly-controls.json',{'kind':'DENIALS_NOT_STANDALONE_MOUNT_PROOF','before':before,'operations':[{'operation':op,'status':'DENIED','errno':13,'after':before} for op in ['truncate','append','rename','chmod']]})
  c.write(p/'host-controls.json',[{'kind':kind,'expected_rejection':True} for kind in ['symlink','hardlink','fifo','mode']]+[{'tamper':n,'expected_rejection':True} for n in range(5)])
  for name,result in [('existing-evidence','INCONCLUSIVE'),('missing-evidence','FAILED')]:
   c.write(p/name/'result.json',{'result':result});c.write(p/name/'SHA256SUMS.json',{'result.json':c.sha(p/name/'result.json')})
  c.write(p/'image.json',{'id':'sha256:TEST'})
  self.reseal()
 def reseal(self):c.write(self.p/'READBACK_SHA256SUMS.json',{str(f.relative_to(self.p)):c.sha(f) for f in self.p.rglob('*') if f.is_file() and f.name!='READBACK_SHA256SUMS.json'})
 def command(self,args,cwd=None,cap=None,check=True,env=None):
  args=list(map(str,args));self.calls.append(args)
  if args[0]=='git':
   assert args[1:3]==['diff','--binary']
   return subprocess.run(args,cwd=cwd,check=True,capture_output=True,timeout=10)
  if args[0]=='python3':
   assert args[1] in ['tools/hnl-ci/verify.py','tools/hnl-ci/deliver.py'] or args[1].endswith('/tools/hnl-ci/publish.py'),args
   return subprocess.run(args,cwd=cwd,env=env,check=check,capture_output=True,timeout=20)
  assert args[0]=='docker',args
  out=b'';err=b'';code=0
  if args[1] in ['container','volume'] and args[2]=='ls':pass
  elif args[1] in ['container','volume'] and args[2]=='inspect':
   if args[3] not in (self.live if args[1]=='container' else self.vol):code=1;err=('No such '+args[1]).encode()
  elif args[1:3]==['volume','create']:self.vol.add(args[-1])
  elif args[1:3]==['volume','rm']:self.vol.discard(args[-1])
  elif args[1]=='rm':
   if self.fault=='cleanup':raise RuntimeError('synthetic cleanup failure')
   self.live.discard(args[-1])
  elif args[1]=='run':
   name=args[args.index('--name')+1];self.live.add(name)
   self.assertEqual(args[args.index('--network')+1],'none')
   if 'test-' in name:
    for k,v in [('--user','1000:1000'),('--group-add','1001'),('--cap-drop','ALL'),('--security-opt','no-new-privileges')]:self.assertEqual(args[args.index(k)+1],v)
    self.assertIn('sha256:TEST',args)
   else:self.assertIn('sha256:DB',args)
  elif args[1]=='inspect':
   if '-db-' in args[-1]:obj={'HostConfig':{'NetworkMode':'none'},'Image':'sha256:DB'}
   else:
    x=c.read(self.p/'corrected-inspect.json');old=str(self.root/'probe-work');new=str(c.F);vol=c.read(c.A/'full-resources.json')['volume']
    for m in x['Mounts']:
     m['Source']=m['Source'].replace(old,new)
     if m['Destination']=='/tmp/hnl-ci':m['Source']='/var/lib/docker/volumes/'+vol+'/_data';m['Name']=vol
    obj={'Config':{'User':x['User']},'HostConfig':x['HostConfig'],'Mounts':x['Mounts'],'Image':'sha256:TEST'}
    if self.fault=='full-mount':obj['HostConfig']['NetworkMode']='bridge'
   out=json.dumps([obj]).encode()
  elif args[1]=='exec':
   if args[3]=='python3':
    mounts=c.read(self.p/'mountinfo.json');old=str(self.root/'probe-work');vol=c.read(self.p/'resources.json')['volume'];new=c.read(c.A/'full-resources.json')['volume']
    out=json.dumps({'identity':c.read(self.p/'container-identity.json'),'mounts':[l.replace(old,str(c.F)).replace(vol,new) for l in mounts]}).encode()
   elif args[3]=='bash':
    if self.fault=='engine':raise RuntimeError('synthetic engine failure')
    E=c.F/'candidate-evidence'
    for scenario in c.SCENARIOS:c.write(E/(scenario+'.result.json'),{'result':'INCONCLUSIVE' if self.fault=='inconclusive' and scenario=='deadlock' else 'PASS'})
    c.write(E/'regressions.json',{'result':'PASS','unique_tests':100,'assertions':987})
    c.write(E/'result.json',{'result':'CI_PASSED_FOR_RELEASE_REVIEW'})
    c.write(E/'engine.json',{'schema_sha256':'wrong' if self.fault=='schema' else c.sha(c.F/'candidate/tools/hnl-ci/schema.sql')})
   else:assert args[3] in ['mariadb-admin','mariadb'],args
  else:raise AssertionError(args)
  if check and code:raise RuntimeError('mock command failure')
  return subprocess.CompletedProcess(args,code,out,err)
 def db_calls(self):return [a for a in self.calls if a[:2]==['docker','run'] and any('-db-' in s for s in a)]
 def full_calls(self):return [a for a in self.calls if a[:2]==['docker','exec'] and a[-2:]==['bash','tools/hnl-ci/run.sh']]
 def reject(self):
  with self.assertRaises((AssertionError,KeyError,c.Inconclusive,RuntimeError)):self.ctrl.admit_full()
  self.assertEqual(self.db_calls(),[])
 def test_full_success_once(self):
  self.ctrl.admit_full();self.assertEqual(len(self.db_calls()),1);self.assertEqual(len(self.full_calls()),1)
  with self.assertRaises(AssertionError):self.ctrl.admit_full()
  self.assertEqual(len(self.db_calls()),1)
 def test_probe_failure(self):
  x=c.read(self.p/'probe-summary.json');x['result']='FAILED';c.write(self.p/'probe-summary.json',x);self.reseal();self.reject()
 def test_probe_partial(self):
  x=c.read(self.p/'readonly-controls.json');x['operations'].pop();c.write(self.p/'readonly-controls.json',x);self.reseal();self.reject()
 def test_cleanup_failure(self):
  x=c.read(self.p/'cleanup.json');x['resources'][0]['absent']=False;c.write(self.p/'cleanup.json',x);self.reseal();self.reject()
 def test_insufficient_budget(self):
  self.ctrl.remaining=lambda:624.99;self.reject()
 def test_private_group_missing(self):
  c.write(self.p/'private-control.json',{'result':'NOT_DISTINGUISHABLE_HOST_GID1000'});self.reseal();self.reject()
 def test_producer_partial(self):
  c.write(self.p/'producer-stats.json',[{}]);self.reseal();self.reject()
 def test_publisher_partial(self):
  c.write(self.p/'host-controls.json',[]);self.reseal();self.reject()
 def test_host_readback_tamper(self):
  c.write(self.p/'private-control.json',{'result':'EXPECTED_PERMISSION_ERROR','tamper':True});self.reject()
 def test_host_consumer_mismatch(self):
  c.write(self.p/'host-consumer-before-cleanup.json',{});self.reseal();self.reject()
 def test_full_mount_mismatch_no_db(self):self.fault='full-mount';self.reject()
 def test_engine_failure(self):
  self.fault='engine'
  with self.assertRaises(RuntimeError):self.ctrl.admit_full()
  self.assertEqual(len(self.db_calls()),1);self.assertEqual(len(self.full_calls()),1);self.assertFalse((c.A/'full-phase.json').exists())
 def test_engine_inconclusive(self):
  self.fault='inconclusive'
  with self.assertRaises(AssertionError):self.ctrl.admit_full()
  self.assertEqual(len(self.full_calls()),1);self.assertFalse((c.A/'full-phase.json').exists())
 def test_schema_identity_mismatch(self):
  self.fault='schema'
  with self.assertRaises(AssertionError):self.ctrl.admit_full()
  self.assertEqual(len(self.full_calls()),1);self.assertFalse((c.A/'full-phase.json').exists())
 def test_full_cleanup_failure(self):
  self.ctrl.admit_full();self.fault='cleanup'
  # Do not call the exact probe subprocess here; its cleanup was tested separately.
  (self.p/'resources.json').rename(self.p/'resources.saved.json')
  with self.assertRaises(RuntimeError):self.ctrl.cleanup()
  self.assertEqual(c.read(c.A/'cleanup.json')['result'],'FAILED')
 def test_actual_publisher_and_host_seal(self):
  self.ctrl.admit_full()
  c.write(c.A/'cleanup.json',{'result':'PASS','full_resources':[]})
  with patch.dict(os.environ,{'GITHUB_OUTPUT':str(self.root/'outputs')}):self.ctrl.seal()
  self.assertEqual(c.read(c.A/'result.json')['result'],'PENDING_EXTERNAL_ARTIFACT_READBACK')
  for root in [c.A,self.p,c.F/'evidence']:
   self.assertTrue(all(c.sha(root/n)==h for n,h in c.read(root/'TRANSPORT_SHA256SUMS.json').items()))
 def test_cleanup_failure_seals_failure_only(self):
  self.ctrl.admit_full()
  c.write(c.A/'failure.json',{'phase':'cleanup','result':'FAILED'})
  with patch.dict(os.environ,{'GITHUB_OUTPUT':str(self.root/'outputs')}):self.ctrl.seal()
  self.assertEqual(c.read(c.A/'result.json')['result'],'FAILED_OR_INCONCLUSIVE')
  self.assertFalse(c.read(c.A/'result.json')['container_full_pass'])
 def test_real_process_group_timeout(self):
  ctrl=c.Controller();ctrl.phase_end=time.monotonic()+2.5
  started=time.monotonic()
  with self.assertRaises(c.Inconclusive):ctrl.run(['python3','-c','import time; time.sleep(5)'])
  self.assertLess(time.monotonic()-started,2.5)
 def tearDown(self):c.write(self.root/'MOCK_COMMANDS.json',{'scope':'SYNTHETIC_COMMAND_BOUNDARY_NOT_CONTAINER_PROOF','calls':self.calls,'real_db_calls':0})
if __name__=='__main__':unittest.main(verbosity=2)
