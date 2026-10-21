from pathlib import Path
import json,hashlib
p=Path('evidence');p.mkdir(exist_ok=True)
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
manifest={f.name:hashlib.sha256(f.read_bytes()).hexdigest() for f in sorted(p.iterdir())}
(p/'SHA256SUMS.json').write_text(json.dumps(manifest,indent=2)+'\n')
for name in ['result.json','engine.json','regressions.json']:
 if (p/name).exists():print(name,(p/name).read_text()[:18000])
for f in sorted(p.glob('*.result.json')):print(f.name,f.read_text())
