from pathlib import Path
import os,json,stat,subprocess,hashlib,time,errno
os.umask(0o007);Q=Path('/probe');D=Path('/diagnostics');R=Path('/workspace');E=Path('/evidence')
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def write(p,v):p.write_text(json.dumps(v,indent=2)+'\n')
def meta(p):
 s=p.lstat();return {'uid':s.st_uid,'gid':s.st_gid,'mode':oct(stat.S_IMODE(s.st_mode)),'inode':s.st_ino,'mtime_ns':s.st_mtime_ns,'links':s.st_nlink,'regular':stat.S_ISREG(s.st_mode),'sha256':sha(p)}
def run(a,env=None,timeout=30):subprocess.run(a,check=True,env=env or dict(os.environ,HNL_OUT=str(E)),timeout=timeout)
host=json.loads((D/'host.json').read_text());assert os.getuid()==1000 and os.getgid()==1000 and host['uid']!=1000 and host['gid'] in os.getgroups()
status={l.split(':',1)[0]:l.split(':',1)[1].strip() for l in Path('/proc/self/status').read_text().splitlines() if l.split(':',1)[0] in ['Uid','Gid','Groups','CapEff','CapBnd','NoNewPrivs']};assert status['CapEff']=='0000000000000000' and status['NoNewPrivs']=='1';write(D/'container-identity.json',status)
mounts=[l for l in Path('/proc/self/mountinfo').read_text().splitlines() if l.split()[4] in ['/workspace','/workspace/vendor','/evidence','/tmp/hnl-ci']];write(D/'mountinfo.json',mounts);assert any(l.split()[4]=='/workspace' and 'ro' in l.split()[5].split(',') for l in mounts);assert any(l.split()[4]=='/workspace/vendor' and ' - tmpfs ' in l and 'rw' in l.split()[5].split(',') for l in mounts)
assert not list(Path('/tmp/hnl-ci').iterdir())
f=E/'identities.json';original=meta(f);passes=[]
for phase in range(3):
 if phase==1:
  # Exact setup lines only, never execute run.sh or an application entrypoint.
  lines=(R/'tools/hnl-ci/run.sh').read_text().splitlines();setup=[x for x in lines if x.startswith('cp -a ') or x.startswith('composer dump-autoload ')];assert len(setup)==2
  run(['bash','-euc','\n'.join(setup)],timeout=60)
 run(['python3','tools/hnl-ci/verify.py'])
 m=meta(f);assert m['uid']==host['uid'] and m['inode']==original['inode'] and m['mtime_ns']>original['mtime_ns'];original=m;passes.append(m)
write(D/'overwrite-passes.json',passes)
locked=json.loads((R/'composer.lock').read_text());installed=json.loads(Path('/build/vendor/composer/installed.json').read_text())['packages'];expected={p['name']:p for p in locked['packages']+locked['packages-dev']};assert len(installed)==len(expected)==130
for p in installed:
 for k in ['version','source','dist']:assert p.get(k)==expected[p['name']].get(k),(p['name'],k)
write(D/'dependencies.json',{'count':len(installed),'package_metadata_matches_lock':True,'vendor_mode_after_copy':oct(stat.S_IMODE((R/'vendor').stat().st_mode)),'php':subprocess.check_output(['php','-r','echo PHP_VERSION;']).decode(),'composer':subprocess.check_output(['composer','--version','--no-ansi']).decode()})
f=R/'app/Services/HnlStandingsSync.php';before=sha(f);denials=[]
for op in ['truncate','append','rename','chmod']:
 try:
  if op=='truncate':f.open('w').close()
  elif op=='append':f.open('a').close()
  elif op=='rename':f.rename(f.with_name('FORBIDDEN.php'))
  else:f.chmod(0o666)
 except OSError as e:denials.append({'operation':op,'errno':e.errno});assert e.errno==errno.EROFS
 else:raise AssertionError('readonly operation succeeded: '+op)
 assert sha(f)==before
write(D/'readonly-controls.json',denials)
for n in ['reviewed-manifest.json','harness-manifest.json']:
 for name,h in json.loads((R/'tools/hnl-ci'/n).read_text()).items():assert sha(R/name)==h
if host['gid']!=1000:
 try:Path('/private/identities.json').write_text('FORBIDDEN')
 except PermissionError:write(D/'private-control.json',{'result':'EXPECTED_PERMISSION_ERROR','private_root_mode':'0700','uid_distinct':True})
 else:raise AssertionError('private write succeeded')
else:write(D/'private-control.json',{'result':'NOT_DISTINGUISHABLE_HOST_GID1000'})
inv=json.loads((Q/'producer-inventory.json').read_text());records=[]
for phase in [1,2]:
 run(['php','-d','zend.exception_ignore_args=1',str(Q/'producer-io.php.txt'),str(Q/'producer-inventory.json'),str(phase)])
 for n in inv['python_phpunit_and_test_direct_writes']:
  (E/n).write_text(json.dumps({'result':'INCONCLUSIVE','kind':'IO_ONLY_NO_ENGINE_OR_TESTS','phase':phase})+'\n' if n.endswith('.json') else 'IO_ONLY_NO_ENGINE_OR_TESTS\n')
 m={p.name:meta(p) for p in sorted(E.iterdir())};assert all(x['regular'] and x['links']==1 and x['mode']=='0o660' and x['gid']==host['gid'] for x in m.values());assert not list(E.glob('*.tmp'));assert m['identities.json']['uid']==host['uid'];assert m['engine.json']['uid']==1000;records.append(m)
write(D/'producer-stats.json',records);assert len((E/'trace.ndjson').read_text().splitlines())==2
# Deliberate synthetic failure, not an app command, and never a retry.
r=subprocess.run(['php','-r','fwrite(STDERR,"IO_ONLY_INTENTIONAL_FAILURE\n"); exit(23);'],capture_output=True);assert r.returncode==23;Path('/missing/evidence/intentional-failure.log').write_bytes(r.stderr)
write(D/'probe-phase.json',{'result':'PERMISSIONS_BOUNDARY_PASS','producer_passes':2,'intentional_failure_exit':23,'application_tests':0,'application_assertions':0,'engine_cases':0,'db_connections':0,'provider_calls':0,'sync_attempts':0,'upserts':0,'receipts':0})
