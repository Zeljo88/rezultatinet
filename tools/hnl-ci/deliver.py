"""Materialize exact committed CI input; never read source bytes from the worktree."""
from pathlib import Path, PurePosixPath
import hashlib
import io
import json
import subprocess
import sys
import tarfile


def sha(data):
    return hashlib.sha256(data).hexdigest()


def safe(name):
    p = PurePosixPath(name)
    if not name or p.is_absolute() or any(x in ('', '.', '..', '.git') for x in name.split('/')) or '\\' in name:
        raise ValueError(('unsafe path', name))
    return name


def members(data):
    files, seen = {}, set()
    with tarfile.open(fileobj=io.BytesIO(data), mode='r:') as archive:
        for item in archive:
            name = safe(item.name.rstrip('/') if item.isdir() else item.name)
            if name in seen:
                raise ValueError(('duplicate member', name))
            seen.add(name)
            if item.isdir():
                continue
            if not item.isfile():
                raise ValueError(('non-regular member', name))
            files[name] = (archive.extractfile(item).read(), item.mode & 0o777)
    return files


def verify(files, expected):
    if set(files) != set(expected):
        raise ValueError(('member mismatch', sorted(set(expected) - set(files)), sorted(set(files) - set(expected))))
    for name, (data, mode) in files.items():
        safe(name)
        if {'sha256': sha(data), 'mode': mode, 'size': len(data)} != expected[name]:
            raise ValueError(('member drift', name))


def main(destination, evidence):
    def git(*args):
        return subprocess.check_output(['git', *args])
    head = git('rev-parse', 'HEAD').decode().strip()
    expected = {}
    for entry in git('ls-tree', '-rz', head).split(b'\0'):
        if not entry:
            continue
        meta, path = entry.split(b'\t', 1)
        mode, kind, oid = meta.decode().split()
        name = safe(path.decode())
        if kind != 'blob' or mode not in ('100644', '100755'):
            raise ValueError(('unsupported tracked entry', name, mode))
        data = git('cat-file', 'blob', oid)
        expected[name] = {'sha256': sha(data), 'mode': int(mode, 8) & 0o777, 'size': len(data)}
    # The root archive intentionally inherits /.github export-ignore. Exporting
    # the explicit subtree avoids that ancestor attribute without modifying it.
    root_archive = git('-c', 'tar.umask=0022', 'archive', '--format=tar', head)
    github_archive = git('-c', 'tar.umask=0022', 'archive', '--format=tar', '--prefix=.github/', head + ':.github')
    files = members(root_archive)
    for name, value in members(github_archive).items():
        if name in files:
            raise ValueError(('overlapping exports', name))
        files[name] = value
    verify(files, expected)
    target, out = Path(destination), Path(evidence)
    archive_path = target.with_name(target.name + '-delivery.tar')
    if archive_path.exists():
        raise ValueError('archive destination must be absent')
    if target.exists():
        raise ValueError('destination must be absent')
    out.mkdir(parents=True, exist_ok=True)
    # Canonical transport archive: sorted regular files, fixed metadata, no links.
    stream = io.BytesIO()
    with tarfile.open(fileobj=stream, mode='w', format=tarfile.PAX_FORMAT) as archive:
        for name in sorted(files):
            data, mode = files[name]
            item = tarfile.TarInfo(name)
            item.size, item.mode, item.mtime = len(data), mode, 0
            archive.addfile(item, io.BytesIO(data))
    payload = stream.getvalue()
    delivered = members(payload)
    verify(delivered, expected)
    target.mkdir()
    for name, (data, mode) in delivered.items():
        dest = target / name
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(data)
        dest.chmod(mode)
    manifest = (json.dumps(expected, indent=2, sort_keys=True) + '\n').encode()
    archive_path.write_bytes(payload)
    (out / 'delivery-manifest.json').write_bytes(manifest)
    (out / 'packaging.json').write_text(json.dumps({
        'head': head, 'tree': git('rev-parse', head + '^{tree}').decode().strip(),
        'files': len(expected), 'archive_sha256': sha(payload),
        'manifest_sha256': sha(manifest), 'root_export_sha256': sha(root_archive),
        'github_export_sha256': sha(github_archive),
        'workflows': sorted(n for n in expected if n.startswith('.github/')),
        'result': 'PASS',
    }, indent=2) + '\n')
    print('PASS exact safe Git export delivery:', len(expected), 'files')


if __name__ == '__main__':
    main(*sys.argv[1:])
