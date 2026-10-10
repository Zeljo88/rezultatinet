from pathlib import Path
import json,hashlib
p=Path('evidence')
# Allowlisted, bounded, synthetic-only output. No environment/config dumps.
for n in ['identities.json','test-results.json','scenarios.jsonl','redis-real.json','mariadb-image.json','database-network.json']:
 f=p/n
 if not f.exists():continue
 s=f.read_text()
 if n=='scenarios.jsonl':s=json.dumps([json.loads(x) for x in s.splitlines()])
 if len(s)>50000:raise RuntimeError('receipt_limit')
 escaped=s.replace('%','%25').replace('\r','%0D').replace('\n','%0A')
 print('::notice title='+n+'::'+escaped)
manifest={f.name:hashlib.sha256(f.read_bytes()).hexdigest() for f in p.iterdir() if f.is_file()}
(p/'SHA256SUMS.json').write_text(json.dumps(manifest,indent=2))
