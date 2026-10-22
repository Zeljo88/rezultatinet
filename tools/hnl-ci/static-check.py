# Local prelaunch check; PyYAML is a host tool, not a runner dependency.
from pathlib import Path
import ast,subprocess,json,hashlib,yaml
p=Path('tools/hnl-ci');wf=Path('.github/workflows/hnl-mariadb-once.yml')
w=yaml.safe_load(wf.read_text());events=w.get('on',w.get(True));assert set(events)=={'workflow_dispatch'}
assert w['permissions']=={'contents':'read'} and len(w['jobs'])==1
j=w['jobs']['isolated'];assert j['timeout-minutes']==15 and 'github.run_attempt == 1' in j['if']
for f in Path('.github/workflows').glob('*.yml'):
 if f==wf:continue
 q=yaml.safe_load(f.read_text());triggers=q.get('on',q.get(True));assert not (set(triggers)&{'workflow_run','create','check_run','check_suite'})
 if 'push' in triggers:assert triggers['push']['branches']==['rex/a1b-main-integration-2026-09-17']
 assert 'pull_request_target' not in triggers
for f in p.glob('*.php'):subprocess.run(['php','-l',str(f)],check=True,capture_output=True)
for f in p.glob('*.py'):ast.parse(f.read_text(),filename=str(f))
for f in p.glob('*.sh'):subprocess.run(['bash','-n',str(f)],check=True)
s=wf.read_text();assert s.count('docker run')==2 and s.count('--network none')==2
assert 'secrets.' not in s and 'pull_request_target' not in s and 'workflow_run' not in s
assert 'persist-credentials: false' in s and 'mariadb:10.11.13' in s
assert not Path('.env').exists() and not Path('bootstrap/cache/config.php').exists()
assert 'timeout --signal=TERM --kill-after=1 29s' in (p/'run.sh').read_text()
assert 'innodb_lock_wait_timeout=5' in (p/'common.php').read_text()
assert 'INNODB_LOCK_WAITS' in (p/'engine.php').read_text()
assert 'driver_code\']===1205' in (p/'engine.php').read_text()
assert "'sync_observed_deadlock_victim'" in (p/'engine.php').read_text()
assert "throw new Inconclusive('other_deadlock_victim')" in (p/'engine.php').read_text()
print('PASS workflow one-job/trigger isolation, syntax, time bounds, source/no-env guards')

# Ownership boundary and phase ordering are part of the delivery contract.
steps=j['steps'];first=steps[1]['run'];engine=next(x['run'] for x in steps if x.get('name','').startswith('Run one bounded'))
seal=next(x for x in steps if x.get('name')=='Seal bounded synthetic evidence')
assert first.index('umask 0007') < first.index('mkdir -m 2770 evidence') < first.index('verify.py')
assert engine.index('umask 0007') < engine.index('verify.py') < engine.index('deliver.py') < engine.rindex('verify.py') < engine.index('docker run')
assert '--user 1000:1000 --group-add "$(id -g)"' in engine
assert 'dst=/workspace,readonly' in engine and '--tmpfs /workspace/vendor:rw,nosuid,nodev,uid=1000,gid=1000,mode=0700' in engine
assert '--cap-drop ALL --security-opt no-new-privileges' in engine
assert 'chmod 0777' not in s and 'chown' not in s
assert seal['if']=='always()' and seal['run'].index('--check-only') < seal['run'].index('docker inspect')
assert steps[-1]['if']=='always()' and 'evidence' not in steps[-1]['run']
r=(p/'run.sh').read_text()
assert r.index('umask 0007') < r.index('cp -a /build/vendor/.') < r.index('verify.py') < r.index('init.php') < r.index('for scenario') < r.index('regressions.py')
print('PASS scoped group evidence, readonly source, isolation and phase-order checks (static only)')
assert seal['id']=='seal' and steps[-2]['if']=="always() && steps.seal.outcome == 'success'"
