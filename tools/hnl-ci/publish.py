from pathlib import Path
import json,hashlib,os,stat,sys,re
os.umask(0o007)
p=Path('evidence')
meta=p.lstat()
assert stat.S_ISDIR(meta.st_mode) and stat.S_IMODE(meta.st_mode)==0o2770, 'unsafe evidence root'
assert meta.st_gid in {os.getegid(),*os.getgroups()}, 'missing evidence group'
# Check before any read, overwrite or shell redirection, including failure paths.
allowed={'.json','.xml','.log','.ndjson','.stdout','.stderr'}
for f in p.iterdir():
 st=f.lstat()
 assert stat.S_ISREG(st.st_mode) and st.st_nlink==1 and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]*',f.name) and f.suffix in allowed, ('unsafe evidence path',f.name)
 assert stat.S_IMODE(st.st_mode)==0o660 and st.st_gid==meta.st_gid, ('evidence mode/group drift',f.name)
if sys.argv[1:]==['--check-only']:
 print('PASS safe evidence paths/modes/group');sys.exit(0)
assert not sys.argv[1:], 'unexpected publisher arguments'
if not (p/'result.json').exists():
 cases=[json.loads(x.read_text()) for x in p.glob('*.result.json')]
 verdict='INCONCLUSIVE' if any(x['result']=='INCONCLUSIVE' for x in cases) else 'FAILED'
 (p/'result.json').write_text(json.dumps({'result':verdict,'completed_engine_scenarios':len(cases),'no_rerun':True},indent=2)+'\n')
# Only synthetic test outputs; never publish env, application logs or container logs.
allowed={'.json','.xml','.log','.ndjson','.stdout','.stderr'}
for f in p.iterdir():
 assert f.is_file() and f.suffix in allowed,('unexpected evidence path',f.name)
 b=f.read_bytes();assert len(b)<2_000_000,('unexpected evidence size',f.name)
 assert b'github_pat_' not in b and b'ghp_' not in b and b'PRIVATE KEY' not in b,('sensitive marker',f.name)
manifest={f.name:hashlib.sha256(f.read_bytes()).hexdigest() for f in sorted(p.iterdir()) if f.name!='SHA256SUMS.json'}
(p/'SHA256SUMS.json').write_text(json.dumps(manifest,indent=2)+'\n')
for name in ['result.json','engine.json','regressions.json']:
 if (p/name).exists():print(name,(p/name).read_text()[:18000])
for f in sorted(p.glob('*.result.json')):print(f.name,f.read_text())
