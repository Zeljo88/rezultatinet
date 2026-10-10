import subprocess,hashlib,json
from pathlib import Path
def git(*args):return subprocess.check_output(['git',*args])
base='c77062b3188535d4d7fb62855929315d0f312666';review='89c3d13c2bbca13a71281a82270bb784621ebfeb'
runtime=[s for s in git('diff','--name-only',base,review).decode().splitlines() if s.startswith(('app/','resources/','config/','routes/','database/'))]
assert len(runtime)==13,runtime
cohort=Path('resources/data/stale-fixture-cohort.json')
data=json.loads(cohort.read_text())
print(json.dumps({'head':git('rev-parse','HEAD').decode().strip(),'tree':git('rev-parse','HEAD^{tree}').decode().strip(),'reviewed':review,'reviewed_tree':git('rev-parse',review+'^{tree}').decode().strip(),'base':base,'candidate_diff_sha256':hashlib.sha256(git('diff','--binary',base,'HEAD')).hexdigest(),'runtime_diff_sha256':hashlib.sha256(git('diff','--binary',base,'HEAD','--',*runtime)).hexdigest(),'ci_delta_sha256':hashlib.sha256(git('diff','--binary',review,'HEAD')).hexdigest(),'runtime_paths':runtime,'runtime_blobs':{p:git('rev-parse','HEAD:'+p).decode().strip() for p in runtime},'cohort_sha256':hashlib.sha256(cohort.read_bytes()).hexdigest()},indent=2))
