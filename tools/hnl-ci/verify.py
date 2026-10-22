from pathlib import Path
import json,hashlib,subprocess,os,stat
root=Path.cwd();d=root/'tools/hnl-ci';out=Path(os.environ.get('HNL_OUT',d/'out'))
# A fresh host-created, setgid evidence root is shared only with the test group.
# Never repair/reuse unsafe input paths by chmod/chown or following a link.
os.umask(0o007)
meta=out.lstat()
assert stat.S_ISDIR(meta.st_mode) and stat.S_IMODE(meta.st_mode)==0o2770, 'unsafe evidence root'
assert meta.st_gid in {os.getegid(),*os.getgroups()}, 'missing evidence group'
preinit={'identities.json','delivery.json','mariadb-image.json','database-network.json','delivery-manifest.json','packaging.json'}
for f in out.iterdir():
 st=f.lstat()
 assert f.name in preinit and stat.S_ISREG(st.st_mode) and st.st_nlink==1, ('unsafe pre-init evidence',f.name)
 assert stat.S_IMODE(st.st_mode)==0o660 and st.st_gid==meta.st_gid, ('evidence mode/group drift',f.name)

def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
p=json.loads((d/'provenance.json').read_text());m=json.loads((d/'reviewed-manifest.json').read_text())
for name,h in m.items():assert sha(root/name)==h,('reviewed byte drift',name)
h=json.loads((d/'harness-manifest.json').read_text())
for name,digest in h.items():assert sha(root/name)==digest,('harness byte drift',name)
assert sha(d/'reviewed-manifest.json')==p['reviewed_manifest_sha256']
# Schema is exact data-free source DDL, not a lookalike SQLite definition.
s=(root/'database/schema/mariadb-schema.sql').read_text();chunks=[]
for t in ['leagues','teams','fixtures','fixture_scores','standings']:
 start=s.index('CREATE TABLE `'+t+'`');chunks.append(s[start:s.index(';',start)+1])
assert (d/'schema.sql').read_text()=='\n\n'.join(chunks)+'\n'
if (root/'.git').exists():
 def git(*args):return subprocess.check_output(['git',*args]).decode().strip()
 assert git('rev-parse',p['reviewed_commit']+'^{tree}')==p['reviewed_tree']
 subprocess.run(['git','merge-base','--is-ancestor',p['reviewed_commit'],'HEAD'],check=True)
 changes=git('diff','--name-only',p['reviewed_commit'],'HEAD').splitlines()
 assert all(x.startswith('tools/hnl-ci/') or x=='.github/workflows/hnl-mariadb-once.yml' for x in changes),changes
 if os.environ.get('GITHUB_SHA'):assert git('rev-parse','HEAD')==os.environ['GITHUB_SHA']
(out/'identities.json').write_text(json.dumps(p|{'harness_manifest_sha256':sha(d/'harness-manifest.json'),'all_reviewed_files_identical':len(m),'harness_files_verified':len(h)},indent=2)+'\n')
print('PASS exact reviewed bytes and test-only harness manifest')
