from pathlib import Path
import subprocess,os,sys,json,hashlib,stat,shutil,time
os.umask(0o007);W=Path.cwd();B=W/'probe-work';A=W/'probe-artifact';Q=W/'tools/permissions-probe'
def write(p,v):p.write_text(json.dumps(v,indent=2)+'\n')
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def run(a,check=True,timeout=30,**kw):return subprocess.run(a,check=check,timeout=timeout,**kw)
def capture(a,timeout=20):return subprocess.check_output(a,timeout=timeout).decode().strip()
def meta(p):
 s=p.lstat();return {'uid':s.st_uid,'gid':s.st_gid,'mode':oct(stat.S_IMODE(s.st_mode)),'inode':s.st_ino,'links':s.st_nlink,'sha256':sha(p)}
def absent(kind,name):
 items=capture(['docker',kind,'ls','-aq'] if kind=='container' else ['docker','volume','ls','-q']).splitlines()
 # listing success plus exact inspect absence, not a swallowed daemon failure
 r=run(['docker',kind,'inspect',name],check=False,capture_output=True);assert r.returncode!=0 and ('no such '+('container' if kind=='container' else 'volume')) in r.stderr.decode().lower(),r.stderr.decode()
 return {'kind':kind,'name':name,'absent':True,'listing_succeeded':True}
def resources():return json.loads((A/'resources.json').read_text())
def inspect(name):
 payload=json.loads(capture(['docker','inspect',name]))
 assert isinstance(payload,list) and len(payload)==1,'expected exactly one inspect object'
 x=payload[0];hc=x['HostConfig']
 # Docker omits Tmpfs for containers without configured tmpfs mounts. Preserve
 # absence/null/empty distinctly; none of these is evidence of an effective mount.
 tmpfs=hc.get('Tmpfs')
 assert tmpfs is None or (isinstance(tmpfs,dict) and all(isinstance(k,str) and isinstance(v,str) for k,v in tmpfs.items())),'malformed optional Tmpfs'
 return {'Id':x['Id'],'Name':x['Name'],'Image':x['Image'],'User':x['Config']['User'],'HostConfig':{**{k:hc[k] for k in ['NetworkMode','CapDrop','SecurityOpt','GroupAdd']},'Tmpfs':tmpfs},'TmpfsPresence':'present' if 'Tmpfs' in hc else 'absent','Mounts':x['Mounts'],'State':{k:x['State'][k] for k in ['Status','ExitCode','OOMKilled']}}
def required_mounts(x,lines):
 # Configuration alone is not proof: require effective Docker mounts AND proc
 # mountinfo captured inside the corrected container before its vendor setup.
 assert isinstance(x['Mounts'],list) and isinstance(lines,list),'missing mount evidence'
 proc={}
 for line in lines:
  left,right=line.split(' - ',1);fields=left.split();tail=right.split()
  assert len(fields)>=6 and len(tail)>=3,'malformed mountinfo'
  dest=fields[4];assert dest not in proc,'ambiguous mountinfo'
  proc[dest]=(set(fields[5].split(',')),tail[0])
 expected={'/workspace':('bind',False,str(B/'candidate')),'/workspace/vendor':('tmpfs',True,None),'/evidence':('bind',True,str(B/'candidate-evidence')),'/tmp/hnl-ci':('volume',True,None)}
 for dest,(kind,writable,source) in expected.items():
  matches=[m for m in x['Mounts'] if m['Destination']==dest]
  assert len(matches)==1,('required mount missing/ambiguous',dest)
  m=matches[0];assert m['Type']==kind and m['RW'] is writable,('wrong effective mount',dest)
  if source is not None:assert m['Source']==source,('wrong bind source',dest)
  options,fs=proc[dest];assert ('rw' if writable else 'ro') in options and ('ro' if writable else 'rw') not in options,('wrong proc access',dest)
  if kind=='tmpfs':assert fs=='tmpfs' and {'nosuid','nodev'}<=options,('wrong vendor filesystem/options',dest)
 return {'result':'PASS','kind':'DOCKER_MOUNTS_AND_CONTAINER_PROC','required':list(expected)}
def start_probe():
 assert os.getuid()!=1000 and os.getuid()!=0
 suffix=os.environ['GITHUB_RUN_ID'];names={'old':'hnl-perm-old-'+suffix,'corrected':'hnl-perm-new-'+suffix,'volume':'hnl-perm-socket-'+suffix};write(A/'resources.json',names)
 for n in ['old','corrected']:absent('container',names[n])
 absent('volume',names['volume']);run(['docker','volume','create',names['volume']])
 image=json.loads((A/'image.json').read_text())['id'];flags=['--user','1000:1000','--group-add',str(os.getgid()),'--network','none','--cap-drop','ALL','--security-opt','no-new-privileges']
 old=['docker','run','-d','--name',names['old'],*flags,'--mount','type=bind,src='+str(B/'base')+',dst=/workspace,readonly','--mount','type=bind,src='+str(B/'old-0o600')+',dst=/controls/old-0o600','--mount','type=bind,src='+str(B/'old-0o644')+',dst=/controls/old-0o644',image,'sleep','115'];run(old)
 oldstats=[]
 for mode in [0o600,0o644]:
  folder=B/('old-'+oct(mode));f=folder/'identities.json';before=meta(f)
  r=run(['docker','exec','-e','HNL_OUT=/controls/'+folder.name,names['old'],'python3','tools/hnl-ci/verify.py'],check=False,capture_output=True)
  (A/('old-'+oct(mode)+'.log')).write_bytes(r.stdout+r.stderr);assert r.returncode!=0 and b'PermissionError' in r.stderr and b'identities.json' in r.stderr and meta(f)==before;oldstats.append({'mode':oct(mode),'before':before,'after':meta(f),'exit':r.returncode})
 write(A/'old-controls.json',oldstats);write(A/'old-inspect.json',inspect(names['old']));run(['docker','rm','-f','-v',names['old']]);write(A/'success-cleanup.json',absent('container',names['old']))
 # Diagnostics share only numeric host group; no credentials/environment dump.
 A.chmod(0o2770);assert A.stat().st_gid==os.getgid()
 write(A/'pre-boundary-stat.json',{'root':{'uid':(B/'candidate-evidence').stat().st_uid,'gid':(B/'candidate-evidence').stat().st_gid,'mode':oct(stat.S_IMODE((B/'candidate-evidence').stat().st_mode))},'files':{p.name:meta(p) for p in (B/'candidate-evidence').iterdir()}})
 args=['docker','run','-d','--name',names['corrected'],*flags,'--mount','type=volume,src='+names['volume']+',dst=/tmp/hnl-ci','--mount','type=bind,src='+str(B/'candidate')+',dst=/workspace,readonly','--tmpfs','/workspace/vendor:rw,nosuid,nodev,uid=1000,gid=1000,mode=0700']
 for source,dest,ro in [(B/'candidate-evidence','/evidence',False),(A,'/diagnostics',False),(Q,'/probe',True),(B/'private','/private',False),(B/'missing','/missing',False)]:args+=['--mount','type=bind,src='+str(source)+',dst='+dest+(',readonly' if ro else '')]
 args += [image,'python3','/probe/container.py'];run(args)
 x=inspect(names['corrected']);assert x['User']=='1000:1000' and x['HostConfig']['NetworkMode']=='none' and x['HostConfig']['CapDrop']==['ALL'] and str(os.getgid()) in x['HostConfig']['GroupAdd'] and any(v in ['no-new-privileges','no-new-privileges:true'] for v in x['HostConfig']['SecurityOpt']);write(A/'corrected-inspect.json',x)
 exitcode=capture(['docker','wait',names['corrected']],timeout=95);r=run(['docker','logs',names['corrected']],capture_output=True);(A/'container.log').write_bytes(r.stdout+r.stderr);assert exitcode=='0',exitcode
 assert (B/'private/identities.json').read_text()=='UNCHANGED_PRIVATE\n'
 write(A/'host-consumer-before-cleanup.json',{p.name:meta(p) for p in (B/'candidate-evidence').iterdir()})
 write(A/'mount-checks.json',required_mounts(x,json.loads((A/'mountinfo.json').read_text())))
def cleanup():
 records=[]
 if (A/'resources.json').exists():
  n=resources()
  for name in [n['old'],n['corrected']]:
   r=run(['docker','container','inspect',name],check=False,capture_output=True)
   if r.returncode==0:run(['docker','rm','-f','-v',name])
   records.append(absent('container',name))
  r=run(['docker','volume','inspect',n['volume']],check=False,capture_output=True)
  if r.returncode==0:run(['docker','volume','rm',n['volume']])
  records.append(absent('volume',n['volume']))
 write(A/'cleanup.json',{'result':'PASS','resources':records})
def publish():
 assert json.loads((A/'cleanup.json').read_text())['result']=='PASS'
 tool=B/'candidate/tools/hnl-ci/publish.py'
 def pub(parent,ok=True):
  r=run(['python3',str(tool)],cwd=parent,check=False,capture_output=True);assert (r.returncode==0)==ok,(r.returncode,r.stderr.decode());return r
 def seal(parent,kind):
  q=parent/'evidence';r=pub(parent);(A/(kind+'-publish.log')).write_bytes(r.stdout+r.stderr);first=(q/'SHA256SUMS.json').read_bytes();pub(parent);assert first==(q/'SHA256SUMS.json').read_bytes()
  for n,h in json.loads(first).items():assert sha(q/n)==h
  return json.loads((q/'result.json').read_text())
 checks=[]
 if (A/'probe-phase.json').exists() and (A/'mount-checks.json').exists():
  assert json.loads((A/'mount-checks.json').read_text())['result']=='PASS'
  assert json.loads((A/'probe-phase.json').read_text())['result']=='PERMISSIONS_BOUNDARY_PASS'
  # Publisher sees the real container-created files after container removal.
  flow=B/'existing';flow.mkdir();(B/'candidate-evidence').rename(flow/'evidence');before=(flow/'evidence/result.json').read_bytes();v=seal(flow,'existing');assert v['result']=='INCONCLUSIVE' and (flow/'evidence/result.json').read_bytes()==before
  v=seal(B/'missing','missing');assert v=={'result':'FAILED','completed_engine_scenarios':0,'no_rerun':True}
  for kind in ['symlink','hardlink','fifo','mode']:
   d=B/('reject-'+kind);d.mkdir();q=d/'evidence';q.mkdir(mode=0o2770);q.chmod(0o2770);outside=d/'outside.json';outside.write_text('UNCHANGED_SENTINEL\n');target=q/'identities.json'
   if kind=='symlink':target.symlink_to(outside)
   elif kind=='hardlink':os.link(outside,target)
   elif kind=='fifo':os.mkfifo(target,0o660)
   else:target.write_text('{}\n');target.chmod(0o644)
   r=pub(d,False);assert not (q/'SHA256SUMS.json').exists() and outside.read_text()=='UNCHANGED_SENTINEL\n';checks.append({'kind':kind,'expected_rejection':True,'upload_eligible':False,'outside_unchanged':True})
  # Hash-tampered source on disposable copies; never alter candidate/export.
  for num,name in enumerate(['app/Services/HnlStandingsSync.php','tools/hnl-ci/engine.php','tools/hnl-ci/schema.sql','composer.lock','tools/hnl-ci/harness-manifest.json']):
   d=B/('tamper-'+str(num));shutil.copytree(B/'candidate',d);f=d/name;f.write_bytes(f.read_bytes()+b'\nIO_ONLY_TAMPER');q=B/('tamper-evidence-'+str(num));q.mkdir(mode=0o2770);q.chmod(0o2770)
   r=run(['python3','tools/hnl-ci/verify.py'],cwd=d,env=dict(os.environ,HNL_OUT=str(q)),check=False,capture_output=True);assert r.returncode!=0 and not (q/'identities.json').exists();checks.append({'tamper':name,'expected_rejection':True})
  for source,dest in [(flow/'evidence',A/'existing-evidence'),(B/'missing/evidence',A/'missing-evidence')]:shutil.copytree(source,dest)
  verdict='PERMISSIONS_PROBE_PASS'
 else:
  # Stop substantive phases on unexpected failure. Fresh safe failure publication only.
  d=B/'unexpected';d.mkdir();q=d/'evidence';q.mkdir(mode=0o2770);q.chmod(0o2770);v=seal(d,'unexpected');assert v['result']=='FAILED';shutil.copytree(q,A/'failure-evidence');verdict='FAILED'
 for manifest in ['reviewed-manifest.json','harness-manifest.json']:
  for n,h in json.loads((B/'candidate/tools/hnl-ci'/manifest).read_text()).items():assert sha(B/'candidate'/n)==h
 write(A/'host-controls.json',checks)
 counters={n:0 for n in ['application_cases','application_tests','application_assertions','engine_cases','provider_calls','sync_attempts','db_connections','upserts','receipts']}
 write(A/'probe-summary.json',{'result':verdict,'scope':'IO_ONLY_NO_ENGINE_NO_DATABASE','counters':counters,'attempt':1,'reruns':0,'full_job_authorized':False})
 # Readback after cleanup and publication; normalised artifact modes are not mount proof.
 paths=sorted(p for p in A.rglob('*') if p.is_file());assert sum(p.stat().st_size for p in paths)<20_000_000
 for p in paths:
  assert p.stat().st_size<2_000_000 and p.stat().st_nlink==1 and not p.is_symlink();b=p.read_bytes();assert not any(x in b for x in [b'github_pat_',b'ghp_',b'PRIVATE KEY'])
 write(A/'READBACK_SHA256SUMS.json',{str(p.relative_to(A)):sha(p) for p in paths})
 assert all(sha(A/n)==h for n,h in json.loads((A/'READBACK_SHA256SUMS.json').read_text()).items())
 print(verdict)
if __name__=='__main__':{'probe':start_probe,'cleanup':cleanup,'publish':publish}[sys.argv[1]]()
