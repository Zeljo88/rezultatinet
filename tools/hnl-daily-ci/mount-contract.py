from pathlib import Path
import subprocess,os,sys,json,hashlib,stat,shutil,time,errno,re
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
def mount_options(value):
 assert isinstance(value,str) and value,'missing mount options'
 parts=value.split(',');keys=[v.split('=',1)[0] for v in parts]
 assert all(parts) and len(keys)==len(set(keys)),'duplicate/empty mount options'
 for pair in [{'ro','rw'},{'suid','nosuid'},{'dev','nodev'},{'exec','noexec'}]:assert not pair<=set(parts),'contradictory mount options'
 assert not any(v.split('=',1)[0] in {'ro','rw','nosuid','nodev','noexec','suid','dev','exec'} and '=' in v for v in parts),'malformed flag option'
 return set(parts)
def mount_path(value):
 # Decode only the escapes defined by proc mountinfo; reject unknown escapes.
 assert isinstance(value,str) and not re.search(r'\\(?!040|011|012|134)',value),'invalid mount escape'
 value=re.sub(r'\\(040|011|012|134)',lambda m:chr(int(m[1],8)),value)
 assert value.startswith('/') and str(Path(value))==value and '..' not in Path(value).parts,'noncanonical mount path'
 return value
def proc_mounts(lines):
 assert isinstance(lines,list) and lines,'missing proc mount evidence'
 out={};ids=set()
 for line in lines:
  assert isinstance(line,str) and line.count(' - ')==1,'malformed mountinfo separator'
  left,right=line.split(' - ');f=left.split();tail=right.split()
  assert len(f)>=6 and len(tail)==3,'malformed mountinfo fields'
  assert f[0].isdigit() and int(f[0])>0 and f[1].isdigit() and re.fullmatch(r'[0-9]+:[0-9]+',f[2]),'malformed mount identity'
  assert f[0] not in ids,'duplicate mount id'
  ids.add(f[0]);dest=mount_path(f[4]);assert dest not in out,'duplicate mount target'
  out[dest]={'id':f[0],'parent':f[1],'device':f[2],'root':mount_path(f[3]),'options':mount_options(f[5]),'fs':tail[0],'source':tail[1],'super':mount_options(tail[2])}
 return out
def readonly_kernel(lines):
 proc=proc_mounts(lines)
 assert set(proc)=={'/workspace','/workspace/vendor','/evidence','/tmp/hnl-ci'},'missing or unexpected protected mount'
 assert 'ro' in proc['/workspace']['options'] and 'rw' not in proc['/workspace']['options'],'workspace not kernel readonly'
 vendor=proc['/workspace/vendor']
 assert vendor['fs']=='tmpfs' and vendor['source']=='tmpfs' and vendor['root']=='/'
 assert {'rw','nosuid','nodev','noexec'}<=vendor['options'],'wrong vendor kernel protections'
 assert vendor['parent']==proc['/workspace']['id'] and {'rw','mode=700','uid=1000','gid=1000'}<=vendor['super']
 for dest in ['/evidence','/tmp/hnl-ci']:assert 'rw' in proc[dest]['options'],'required writable output mount'
 return proc
def required_mounts(x,lines,identity,host,volume):
 # Bind/volume: exact Docker Mounts plus proc. Tmpfs: required declaration plus
 # effective proc; Docker Mounts may omit tmpfs (captured run39754381222).
 proc=readonly_kernel(lines);hc=x['HostConfig']
 assert x['User']=='1000:1000' and hc['NetworkMode']=='none' and hc['CapDrop']==['ALL']
 assert type(host['uid']) is int and type(host['gid']) is int and host['uid'] not in [0,1000]
 assert hc['GroupAdd']==[str(host['gid'])] and hc['SecurityOpt'] in [['no-new-privileges'],['no-new-privileges:true']]
 for k in ['Uid','Gid']:assert identity[k].split()==['1000']*4,('wrong numeric process identity',k)
 groups=identity['Groups'].split();assert all(g.isdigit() for g in groups) and set(map(int,groups))=={1000,host['gid']}
 assert identity['CapEff']==identity['CapBnd']=='0000000000000000' and identity['NoNewPrivs']=='1'
 assert x['TmpfsPresence']=='present' and isinstance(hc['Tmpfs'],dict) and set(hc['Tmpfs'])=={'/workspace/vendor'},'required tmpfs declaration'
 assert mount_options(hc['Tmpfs']['/workspace/vendor'])=={'rw','nosuid','nodev','uid=1000','gid=1000','mode=0700'},'wrong tmpfs declaration'
 assert isinstance(volume,str) and re.fullmatch(r'hnl-perm-socket-[0-9]+',volume),'wrong named socket volume'
 assert isinstance(x['Mounts'],list),'missing Docker mounts'
 mounts={}
 for m in x['Mounts']:
  dest=mount_path(m['Destination']);assert dest not in mounts,'duplicate Docker mount'
  mounts[dest]=m
 expected={'/workspace':('bind',False,str(B/'candidate')),'/evidence':('bind',True,str(B/'candidate-evidence')),'/tmp/hnl-ci':('volume',True,'/var/lib/docker/volumes/'+volume+'/_data')}
 for dest,(kind,writable,source) in expected.items():
  m=mounts[dest];p=proc[dest]
  assert m['Type']==kind and m['RW'] is writable and m['Source']==source,('wrong Docker mount',dest)
  if kind=='volume':assert m['Name']==volume and m['Driver']=='local'
  assert ('rw' if writable else 'ro') in p['options'],('wrong proc access',dest)
  # This review supports the captured ext4 runner shape. Other root/fs layouts
  # require evidence review, not inferred host-path translation/default pass.
  assert p['root']==source and p['fs']=='ext4' and p['source'].startswith('/dev/'),('unsupported or contradictory proc backing',dest)
  assert 'rw' in p['super'],('wrong backing superblock access',dest)
 workspace=proc['/workspace'];evidence=proc['/evidence']
 assert (workspace['device'],workspace['source'])==(evidence['device'],evidence['source']),'inconsistent sibling bind backing'
 for p in proc.values():
  if p['device']==workspace['device']:assert (p['fs'],p['source'])==(workspace['fs'],workspace['source']),'contradictory backing device source'
 for dest in mounts:
  if any(dest.startswith(root+'/') for root in ['/workspace','/evidence','/tmp/hnl-ci']):assert dest=='/workspace/vendor','unexpected protected overlay'
 vendor=proc['/workspace/vendor']
 assert vendor['root']=='/' and vendor['fs']=='tmpfs' and vendor['source']=='tmpfs' and vendor['device'].startswith('0:'),'wrong vendor filesystem/source'
 assert vendor['parent']==proc['/workspace']['id'],'wrong vendor parent'
 assert {'rw','nosuid','nodev','noexec'}<=vendor['options'],'wrong vendor mount protections'
 assert {'rw','mode=700','uid=1000','gid=1000'}<=vendor['super'],'wrong initial tmpfs owner/options'
 if '/workspace/vendor' in mounts:
  m=mounts['/workspace/vendor'];assert m['Type']=='tmpfs' and m['RW'] is True and m.get('Source','') in ['', 'tmpfs'],'contradictory Docker vendor mount'
 return {'result':'PASS','kind':'CONFIG_AND_EFFECTIVE_KERNEL_MOUNTS_WITH_NUMERIC_IDENTITY','required':list(proc),'vendor_docker_entry':'present' if '/workspace/vendor' in mounts else 'absent_observed','scope':'MOUNT_ASSESSMENT_ONLY_NOT_PERMISSION_PROBE_PASS'}
def source_meta(p):
 s=p.lstat();assert stat.S_ISREG(s.st_mode) and s.st_nlink==1,'unsafe source target'
 return {'uid':s.st_uid,'gid':s.st_gid,'mode':oct(stat.S_IMODE(s.st_mode)),'inode':s.st_ino,'links':s.st_nlink,'sha256':sha(p)}
def readonly_outcomes(record):
 assert record['kind']=='DENIALS_NOT_STANDALONE_MOUNT_PROOF'
 assert [r['operation'] for r in record['operations']]==['truncate','append','rename','chmod']
 for row in record['operations']:
  allowed={errno.EROFS,errno.EACCES}
  if row['operation']=='chmod':allowed.add(errno.EPERM)
  assert row['status']=='DENIED' and type(row['errno']) is int and row['errno'] in allowed,('unexpected operation outcome',row)
  assert row['after']==record['before'],'source changed despite denial'
 return {'result':'PASS','kind':'ALL_DENIED_SOURCE_UNCHANGED_REQUIRES_KERNEL_PROOF'}
def readonly_controls(f,emit):
 record={'kind':'DENIALS_NOT_STANDALONE_MOUNT_PROOF','before':source_meta(f),'parent':{'uid':f.parent.stat().st_uid,'gid':f.parent.stat().st_gid,'mode':oct(stat.S_IMODE(f.parent.stat().st_mode))},'operations':[{'operation':op,'status':'NOT_RUN'} for op in ['truncate','append','rename','chmod']]}
 emit(record)
 for row in record['operations']:
  op=row['operation']
  try:
   if op=='truncate':f.open('w').close()
   elif op=='append':f.open('a').close()
   elif op=='rename':f.rename(f.with_name('FORBIDDEN.php'))
   else:f.chmod(0o666)
  except OSError as exc:row.update(status='DENIED',errno=exc.errno)
  else:row.update(status='UNEXPECTED_SUCCESS',errno=None)
  finally:
   try:row['after']=source_meta(f)
   except (OSError,AssertionError) as exc:row['after_error']=type(exc).__name__
   emit(record) # Persist expected denial too, before any assertion can stop us.
  allowed={errno.EROFS,errno.EACCES}|({errno.EPERM} if op=='chmod' else set())
  assert row['status']=='DENIED' and row['errno'] in allowed,('unexpected readonly outcome',row)
  assert row.get('after')==record['before'],'source mutated'
 readonly_outcomes(record)
 return record
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
 assessment=required_mounts(x,json.loads((A/'mountinfo.json').read_text()),json.loads((A/'container-identity.json').read_text()),json.loads((A/'host.json').read_text()),names['volume'])
 readonly_outcomes(json.loads((A/'readonly-controls.json').read_text()))
 write(A/'mount-checks.json',assessment)
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
