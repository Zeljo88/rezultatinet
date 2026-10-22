from pathlib import Path
import subprocess,os,json,hashlib,stat,sys
os.umask(0o007)
W=Path.cwd();B=W/'probe-work';B.mkdir();A=W/'probe-artifact';A.mkdir();Q=W/'tools/permissions-probe'
for n,h in json.loads((Q/'wrapper-manifest.json').read_text()).items():assert hashlib.sha256((W/n).read_bytes()).hexdigest()==h,('wrapper drift',n)
C='9deffc32b1466f4187238f6cc72311cd88a5ac9b';O='7a0c294bfcb50c15d14bb6d09e94136cae0ef92e'
def run(args,cwd=W,env=None):subprocess.run(args,cwd=cwd,env=env,check=True,timeout=45)
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def write(p,v):p.write_text(json.dumps(v,indent=2)+'\n')
assert os.getuid()!=0
if '--local' not in sys.argv:assert os.getuid()!=1000
write(A/'host.json',{'uid':os.getuid(),'gid':os.getgid(),'groups':os.getgroups(),'kind':'IO_ONLY_NO_DATABASE'})
env=dict(os.environ);env.pop('GITHUB_SHA',None)
for label,commit in [('candidate',C),('base',O)]:
 clone=B/(label+'-git');run(['git','clone','--no-hardlinks','--no-checkout',str(W),str(clone)]);run(['git','checkout','--detach',commit],clone)
 out=B/(label+'-evidence');out.mkdir(mode=0o2770);out.chmod(0o2770);assert out.stat().st_gid==os.getgid()
 run(['python3','tools/hnl-ci/verify.py'],clone,env|{'HNL_OUT':str(out)})
 patch=subprocess.check_output(['git','diff','--binary','da9b83982de3ff800c0b656bdcf8f8cb86526585','HEAD'],cwd=clone)
 write(out/'delivery.json',{'head':commit,'test_workflow_diff_sha256':hashlib.sha256(patch).hexdigest()})
 for n in ['mariadb-image.json','database-network.json']:write(out/n,{'kind':'IO_ONLY_NO_DATABASE','database_started':False})
 run(['python3','tools/hnl-ci/deliver.py',str(B/label),str(out)],clone,env)
 run(['python3','tools/hnl-ci/verify.py'],B/label,env|{'HNL_OUT':str(out)})
 if label=='candidate':
  p=json.loads((out/'packaging.json').read_text());assert p['files']==301 and p['tree']=='9a5d66718029497ce980b49ce4b019085d67dea6';assert p['archive_sha256']=='c7687afc54d600cbf946b762bd308fcafe3e5504e126303c14519e68d889a531';assert p['manifest_sha256']=='1e72c2f953dc65bb614bc499cd0a0ab13eb94d5ea1f16b14687d55d2c797aeaf'
  assert len(list(out.iterdir()))==6 and all(stat.S_IMODE(f.stat().st_mode)==0o660 for f in out.iterdir())
  write(A/'packaging.json',p)
  (B/label/'vendor').mkdir()
# Old modes modeled from matching old verifier; originals never changed.
import shutil
for mode in [0o600,0o644]:
 t=B/('old-'+oct(mode));shutil.copytree(B/'base-evidence',t);t.chmod(0o777);(t/'identities.json').chmod(mode)
missing=B/'missing';missing.mkdir();(missing/'evidence').mkdir(mode=0o2770);(missing/'evidence').chmod(0o2770)
private=B/'private';private.mkdir(mode=0o700);(private/'identities.json').write_text('UNCHANGED_PRIVATE\n')
write(A/'source-conservation.json',{'candidate':C,'base':O,'reviewed_files':274,'harness_files':26,'dockerfile_sha256':sha(B/'candidate/tools/hnl-ci/Dockerfile'),'lock_sha256':sha(B/'candidate/composer.lock')})
if '--local' not in sys.argv:
 run(['docker','version','--format','{{.Server.Version}}'])
 subprocess.run(['docker','build','-f',str(B/'candidate/tools/hnl-ci/Dockerfile'),'-t','hnl-permissions-'+os.environ['GITHUB_RUN_ID'],str(B/'candidate')],check=True,timeout=210)
 image=subprocess.check_output(['docker','image','inspect','hnl-permissions-'+os.environ['GITHUB_RUN_ID'],'--format','{{.Id}}']).decode().strip();write(A/'image.json',{'id':image,'dockerfile_sha256':sha(B/'candidate/tools/hnl-ci/Dockerfile'),'lock_sha256':sha(B/'candidate/composer.lock')})
print('PASS setup exact301/protected274/harness26; NO application execution')
