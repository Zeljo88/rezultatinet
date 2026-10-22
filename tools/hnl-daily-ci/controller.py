from pathlib import Path
import subprocess,os,json,hashlib,time,sys,importlib.util,signal,stat
os.umask(0o007)
W=Path.cwd();B=W/'daily-work';A=W/'daily-artifact';Q=W/'tools/hnl-daily-ci';E=B/'candidate-evidence';C=B/'candidate'
start=float(Path(os.environ['RUNNER_TEMP'],'hnl-daily-start').read_text());end=start+800
suffix=os.environ['GITHUB_RUN_ID'];names={'test':'hnl-daily-test-'+suffix,'db':'hnl-daily-db-'+suffix,'socket':'hnl-perm-socket-'+suffix+'2','data':'hnl-daily-data-'+suffix}
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def save(n,v):A.mkdir(exist_ok=True);p=A/(n+'.json');p.write_text(json.dumps(v,indent=2)+'\n')
def run(args,cap=45,check=True):
 remaining=min(cap,end-time.monotonic()-2);assert remaining>0,'aggregate_deadline'
 p=subprocess.Popen(args,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,start_new_session=True)
 try:b=p.communicate(timeout=remaining)[0]
 except subprocess.TimeoutExpired:os.killpg(p.pid,signal.SIGKILL);p.communicate(timeout=1);raise RuntimeError('command_deadline')
 if len(b)>2_000_000:raise RuntimeError('oversize_output')
 if check and p.returncode:
  (A/'failed-command.stdout').write_bytes(b);raise RuntimeError('command_failed '+str(args[:3]))
 return p.returncode,b

def absent(kind,name):
 run(['docker',kind,'ls','-aq' if kind=='container' else '-q']);rc,b=run(['docker',kind,'inspect',name],check=False);assert rc and ('no such '+kind).encode() in b.lower();return {'kind':kind,'name':name,'absent':True,'listing_succeeded':True}
def execute():
 assert os.environ['GITHUB_RUN_ATTEMPT']=='1' and os.environ['GITHUB_REF']=='refs/heads/ci/hnl-daily-final-20261022-once'
 assert os.getuid() not in [0,1000] and os.getgid()!=1000
 manifest=json.loads((Q/'source-manifest.json').read_text());assert all(sha(W/n)==h for n,h in manifest.items())
 B.mkdir();C.mkdir();E.mkdir(mode=0o2770);E.chmod(0o2770)
 # Copy exact committed members, NOT git archive (export-ignore changes payload).
 for n,h in manifest.items():
  p=C/n;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((W/n).read_bytes());p.chmod(0o644);assert sha(p)==h
 (C/'vendor').mkdir();save('source-proof',{'manifest':manifest,'exact_files':len(manifest),'head':os.environ['GITHUB_SHA']})
 for k in ['test','db']:absent('container',names[k])
 for k in ['socket','data']:absent('volume',names[k])
 save('resources',names)
 rc,b=run(['docker','build','-f',str(Q/'Dockerfile'),'-t','hnl-daily-'+suffix,str(C)],220);(A/'build.stdout').write_bytes(b)
 _,b=run(['docker','image','inspect','hnl-daily-'+suffix]);image=json.loads(b)[0]['Id'];save('php-image',{'id':image,'composer_lock':sha(C/'composer.lock'),'dockerfile':sha(Q/'Dockerfile')})
 run(['docker','pull','mariadb:10.11.14'],90);_,b=run(['docker','image','inspect','mariadb:10.11.14']);db=json.loads(b)[0];save('database-image',{'id':db['Id'],'RepoDigests':db['RepoDigests']})
 for k in ['socket','data']:run(['docker','volume','create',names[k]])
 run(['docker','run','-d','--name',names['test'],'--user','1000:1000','--group-add',str(os.getgid()),'--network','none','--cap-drop','ALL','--security-opt','no-new-privileges','--mount','type=volume,src='+names['socket']+',dst=/tmp/hnl-ci','--mount','type=bind,src='+str(C)+',dst=/workspace,readonly','--tmpfs','/workspace/vendor:rw,nosuid,nodev,uid=1000,gid=1000,mode=0700','--mount','type=bind,src='+str(E)+',dst=/evidence',image,'sleep','800'])
 script="from pathlib import Path;import json;print(json.dumps({'identity':{l.split(':',1)[0]:l.split(':',1)[1].strip() for l in Path('/proc/self/status').read_text().splitlines() if l.split(':',1)[0] in ['Uid','Gid','Groups','CapEff','CapBnd','NoNewPrivs']},'mounts':[l for l in Path('/proc/self/mountinfo').read_text().splitlines() if any(l.split()[4]==r or l.split()[4].startswith(r+'/') for r in ['/workspace','/workspace/vendor','/evidence','/tmp/hnl-ci'])]}))"
 _,b=run(['docker','exec',names['test'],'python3','-c',script]);capture=json.loads(b)
 _,b=run(['docker','inspect',names['test']]);raw=json.loads(b)[0];x={'Image':raw['Image'],'User':raw['Config']['User'],'HostConfig':raw['HostConfig'],'TmpfsPresence':'present' if 'Tmpfs' in raw['HostConfig'] else 'absent','Mounts':raw['Mounts']}
 spec=importlib.util.spec_from_file_location('mount_contract',Q/'mount-contract.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);m.B=B
 assessed=m.required_mounts(x,capture['mounts'],capture['identity'],{'uid':os.getuid(),'gid':os.getgid()},names['socket']);save('mount-gate',{'inspect':x,'capture':capture,'assessment':assessed})
 assert end-time.monotonic()>460,'insufficient_engine_plus_cleanup_budget'
 run(['docker','run','-d','--name',names['db'],'--network','none','--mount','type=volume,src='+names['data']+',dst=/var/lib/mysql','--mount','type=volume,src='+names['socket']+',dst=/tmp/hnl-ci','--mount','type=bind,src='+str(C/'tools/hnl-daily-ci/database.sh')+',dst=/ci-database.sh,readonly','--entrypoint','bash',db['Id'],'/ci-database.sh'])
 _,b=run(['docker','inspect',names['db']]);d=json.loads(b)[0];assert d['HostConfig']['NetworkMode']=='none' and d['Image']==db['Id'];save('database-container',{'image':d['Image'],'network':d['HostConfig']['NetworkMode'],'mounts':d['Mounts']})
 ready=time.monotonic()+30
 while True:
  rc,_=run(['docker','exec',names['db'],'mariadb-admin','--socket=/tmp/hnl-ci/mariadb.sock','-uroot','ping','--silent'],3,False)
  if rc==0:break
  assert time.monotonic()<ready,'db_startup_timeout';time.sleep(.2)
 run(['docker','exec',names['db'],'mariadb','--socket=/tmp/hnl-ci/mariadb.sock','-uroot','-e',"CREATE DATABASE hnl_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'ci'@'localhost' IDENTIFIED VIA unix_socket; GRANT ALL ON hnl_ci.* TO 'ci'@'localhost'; GRANT PROCESS ON *.* TO 'ci'@'localhost';"])
 rc,b=run(['docker','exec',names['test'],'bash','tools/hnl-daily-ci/run.sh'],420,False);(A/'suite.stdout').write_bytes(b);assert rc==0,'engine_or_regression_failed_STOP_NO_RERUN'
 assert all(sha(C/n)==h for n,h in manifest.items());save('source-conservation',{'result':'PASS','files':len(manifest)})
def cleanup():
 global end;end=start+840;records=[];errors=[]
 if (A/'resources.json').exists():
  for kind,k in [('container','test'),('container','db'),('volume','socket'),('volume','data')]:
   try:
    rc,_=run(['docker',kind,'inspect',names[k]],5,False)
    if rc==0:run(['docker','rm','-f','-v',names[k]] if kind=='container' else ['docker','volume','rm',names[k]],10)
    records.append(absent(kind,names[k]))
   except Exception as e:errors.append(str(e))
 save('cleanup',{'result':'FAIL' if errors else 'PASS','resources':records,'errors':errors})
 assert not errors,'cleanup_failed'
def seal():
 # Same publisher inode/type/mode/group/bounds contract, applied recursively to scenario directories.
 for root in [A,E]:
  if not root.exists():continue
  manifest={};total=0
  for p in sorted(root.rglob('*')):
   st=p.lstat();assert not p.is_symlink()
   if p.is_dir():continue
   assert stat.S_ISREG(st.st_mode) and st.st_nlink==1 and stat.S_IMODE(st.st_mode)==0o660 and st.st_gid==os.getgid(),str(p)
   assert p.suffix in ['.json','.xml','.stdout','.stderr','.ndjson'],'bad_evidence_type'
   # Local PHPUnit SQLite files are test state, not needed for published result; include hashes only below.
   data=p.read_bytes();total+=len(data);assert len(data)<2_000_000 and total<40_000_000
   assert not any(x in data for x in [b'github_pat_',b'ghp_',b'PRIVATE KEY'])
   if p.name!='TRANSPORT_SHA256SUMS.json':manifest[str(p.relative_to(root))]=sha(p)
  (root/'TRANSPORT_SHA256SUMS.json').write_text(json.dumps(manifest,indent=2)+'\n')
 assert time.monotonic()<start+840,'upload_reserve_lost'
if __name__=='__main__':
 try:globals()[sys.argv[1]]()
 except Exception as e:save('failure-'+sys.argv[1],{'error':type(e).__name__,'detail':str(e),'elapsed':time.monotonic()-start});raise
