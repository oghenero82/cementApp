#!/usr/bin/env python3
"""Build a clean, installable CI4 distribution from tracked application source."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', default='php')
parser.add_argument('--composer', default='composer', help='Composer executable or composer.phar path')
parser.add_argument('--output', default='downloads/CementApp-CI4.zip')
args = parser.parse_args()
output = (ROOT / args.output).resolve()
output.parent.mkdir(parents=True, exist_ok=True)
subprocess.run(['npm', 'run', 'build'], cwd=ROOT, check=True)
tracked = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
source_commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT).decode().strip()
folders = ('app/', 'resources/', 'integrations/', 'docs/')
files = {'composer.json', 'composer.lock', 'spark', 'preload.php', '.env.example',
         'start-cement.sh', 'start-cement.bat', 'scripts/start.php', 'scripts/router.php',
         'scripts/install_native_extension.py'}
with tempfile.TemporaryDirectory(prefix='cement-ci4-') as temp:
    stage = Path(temp) / 'CementApp-CI4'
    stage.mkdir()
    for name in tracked:
        if not name or not (name.startswith(folders) or name in files):
            continue
        source = ROOT / name
        if source.is_symlink():
            raise RuntimeError('Distribution source must not contain symbolic links: ' + name)
        target = stage / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, target)
    shutil.copytree(ROOT / 'public', stage / 'public')
    # Never copy the working installation's writable/ directory.
    for directory in ['', 'cache', 'logs', 'session', 'uploads', 'attachments', 'debugbar']:
        target = stage / 'writable' / directory
        target.mkdir(parents=True, exist_ok=True)
        (target / 'index.html').write_text('<!-- Private CI4 runtime directory -->\n')
    shutil.copy2(ROOT / 'writable/.htaccess', stage / 'writable/.htaccess')
    composer = [args.php, args.composer] if args.composer.endswith('.phar') else [args.composer]
    subprocess.run(composer + ['install', '--no-dev', '--prefer-dist', '--optimize-autoloader',
                               '--no-interaction', '--no-progress', '--no-scripts'], cwd=stage, check=True)
    # Distribution files must be self-contained, without vendor documentation links.
    for link in (stage / 'vendor').rglob('*'):
        if link.is_symlink():
            if link.suffix == '.php':
                content = link.resolve().read_bytes()
                link.unlink()
                link.write_bytes(content)
            else:
                link.unlink()
    # Composer may fall back to source installs; omit their VCS metadata.
    for metadata in list((stage / 'vendor').rglob('.git')):
        if metadata.is_dir():
            shutil.rmtree(metadata)
        else:
            metadata.unlink()
    (stage / 'README.md').write_text((ROOT / 'docs/CI4_PACKAGE.md').read_text())
    (stage / 'PACKAGE.json').write_text(json.dumps({
        'name': 'CementApp-CI4', 'sourceCommit': source_commit,
        'framework': 'CodeIgniter 4', 'dependencies': 'production Composer lock',
        'entryPoint': 'public/index.php', 'setup': 'README.md',
    }, indent=2) + '\n')
    for path in stage.rglob('*'):
        if path.is_symlink() or path.name == '.env' or path.suffix in {'.sqlite', '.db'}:
            raise RuntimeError('Unexpected private/runtime file: ' + str(path.relative_to(stage)))
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(stage.rglob('*')):
            if path.is_file():
                archive.write(path, path.relative_to(stage.parent))
checksum = hashlib.sha256(output.read_bytes()).hexdigest()
output.with_suffix('.zip.sha256').write_text(checksum + '  ' + output.name + '\n')
print(f'Created {output.name}: {output.stat().st_size:,} bytes; SHA256 {checksum}')
