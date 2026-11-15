#!/usr/bin/env python3
"""Prepare a project-scoped PHP launcher without changing system or user PHP files."""
from pathlib import Path
import json
import re
import shlex
import subprocess

backend = Path(__file__).resolve().parent.parent
probe = subprocess.run([
    'php', '-r',
    'echo json_encode(["binary" => PHP_BINARY, "ini" => php_ini_loaded_file(), "scanned" => php_ini_scanned_files(), "extensions" => ini_get("extension_dir"), "bcmath" => extension_loaded("bcmath")]);'
], capture_output=True, text=True)
if probe.returncode:
    raise SystemExit('Cannot inspect the selected PHP runtime. Check that PHP CLI starts successfully.')
try:
    runtime = json.loads(probe.stdout)
except ValueError:
    raise SystemExit('The selected PHP launcher did not return valid runtime information.')

# Preserve the selected runtime's extension definitions, including locally installed SQLite.
files = [Path(name.strip()) for name in (runtime['scanned'] or '').split(',') if name.strip()]

def priority(path):
    module = re.sub(r'^\d+-', '', path.stem)
    return (0 if module == 'mysqlnd' else 1 if module == 'pdo' else 2)

contents = [(path.name, path.read_text()) for path in sorted(files, key=priority)]
module = Path(runtime['extensions']) / 'bcmath.so'
if not runtime['bcmath'] and module.is_file():
    contents.append(('bcmath.ini', 'extension=' + str(module) + '\n'))

root = backend / 'storage' / 'app' / 'private' / 'php-cli'
config_dir = root / 'conf.d'
bin_dir = root / 'bin'
for directory in [root, config_dir, bin_dir]:
    directory.mkdir(parents=True, exist_ok=True)
    directory.chmod(0o700)
for path in config_dir.glob('join-*.ini'):
    path.unlink()
for index, (name, content) in enumerate(contents):
    path = config_dir / ('join-' + str(index).zfill(4) + '-' + name)
    path.write_text(content)
    path.chmod(0o600)
launcher = bin_dir / 'php'
lines = ['#!/bin/sh', 'export PHP_INI_SCAN_DIR=' + shlex.quote(str(config_dir))]
if runtime['ini']:
    lines.append('export PHPRC=' + shlex.quote(runtime['ini']))
lines.append('exec ' + shlex.quote(runtime['binary']) + ' "$@"')
launcher.write_text('\n'.join(lines) + '\n')
launcher.chmod(0o700)
print(bin_dir)
