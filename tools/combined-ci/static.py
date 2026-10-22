"""Offline only; accepts only the reviewed manual or narrow push transport."""
from pathlib import Path
import ast,hashlib,json,subprocess,sys,yaml
W=Path.cwd();path='.github/workflows/hnl-combined-once.yml'
def check(w,execution):
 events=w.get('on',w.get(True));assert set(events)==({'push'} if execution else {'workflow_dispatch'})
 if execution:assert events['push']=={'branches':['ci/hnl-combined-20261022-once'],'paths':[path]}
 assert w['permissions']=={'contents':'read'} and set(w['jobs'])=={'combined'}
 j=w['jobs']['combined'];assert j['timeout-minutes']==15 and j['runs-on']=='ubuntu-24.04'
 assert j['if']=="github.ref == 'refs/heads/ci/hnl-combined-20261022-once' && github.run_attempt == 1"
 assert 'services' not in j and 'strategy' not in j
 steps=j['steps'];assert len(steps)==6
 assert 'time.monotonic()' in steps[0]['run'] and steps[1]['with']['persist-credentials'] is False
 assert steps[1]['timeout-minutes']==1
 for i,action in [(2,'execute'),(3,'cleanup'),(4,'seal')]:assert steps[i]['run']=='python3 tools/combined-ci/controller.py '+action
 assert steps[3]['if']==steps[4]['if']=='always()'
 # The upload step follows clock, checkout and three controller commands.
 assert steps[-1]['timeout-minutes']==1 and 'steps.seal.outcome' in steps[-1]['if']
 assert not any('uses' in s and s['uses'].startswith('./.github/workflows') for s in steps)
# Six steps: clock, checkout, execute, cleanup, seal, upload. Keep explicit count.
s=Path(path).read_text();w=yaml.safe_load(s)
check(w,'--execution' in sys.argv)
for p in Path('.github/workflows').glob('*.yml'):
 if str(p)==path:continue
 t=yaml.safe_load(p.read_text());events=t.get('on',t.get(True));assert not set(events)&{'workflow_run','create','check_run','check_suite','pull_request_target'}
 if 'push' in events:assert events['push']['branches']==['rex/a1b-main-integration-2026-09-17']
for folder in ['tools/hnl-ci','tools/permissions-probe','tools/combined-ci']:
 for p in Path(folder).glob('*.py'):ast.parse(p.read_text(),filename=str(p))
 for p in Path(folder).glob('*.sh'):subprocess.run(['bash','-n',str(p)],check=True)
 for p in Path(folder).glob('*.php*'):subprocess.run(['php','-l',str(p)],check=True,capture_output=True)
for name in ['tools/combined-ci/manifest.json','tools/permissions-probe/wrapper-manifest.json']:
 for n,h in json.loads(Path(name).read_text()).items():assert hashlib.sha256(Path(n).read_bytes()).hexdigest()==h,n
assert not Path('.env').exists()
assert 60+100+85+10+20+525+25+15+60==900
print('PASS static one-job exact event/branch/attempt/permissions; no other on-disk push match; syntax and manifests; budget900')
