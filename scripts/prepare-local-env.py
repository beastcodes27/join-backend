#!/usr/bin/env python3
"""Prepare matching, ignored credentials for the local Compose services."""
from pathlib import Path
import re
import secrets

backend = Path(__file__).resolve().parent.parent
app_env = backend / '.env'
service_env = backend / 'deploy' / '.env'
if not app_env.is_file():
    raise SystemExit('Create backend/.env from .env.example before starting local services.')


def values(path):
    if not path.is_file():
        return {}
    parsed = dict(re.findall(r'^([A-Z_]+)=(.*)$', path.read_text(), re.M))
    return {key: value.strip().strip('\"\'') for key, value in parsed.items()}


def update(path, entries):
    content = path.read_text() if path.exists() else '# Local credentials. Never commit this file.\n'
    for key, value in entries.items():
        pattern = r'^' + re.escape(key) + r'=.*$'
        line = key + '=' + value
        content = re.sub(pattern, lambda _: line, content, flags=re.M) if re.search(pattern, content, re.M) else content.rstrip() + '\n' + line + '\n'
    path.write_text(content)
    path.chmod(0o600)


app = values(app_env)
if app.get('APP_ENV') != 'local':
    raise SystemExit('This helper is only for APP_ENV=local; it will not change production credentials.')
services = values(service_env)
redis_port = services.get('JOIN_REDIS_PORT', '6387')
if not redis_port.isdecimal() or not 1024 <= int(redis_port) <= 65535:
    raise SystemExit('JOIN_REDIS_PORT must be an integer between 1024 and 65535.')
shared = {}
for key in ['LIVEKIT_API_KEY', 'LIVEKIT_API_SECRET', 'REDIS_PASSWORD']:
    app_value = app.get(key)
    service_value = services.get(key)
    usable = lambda value: value and value.lower() not in ['null', 'none']
    if usable(app_value) and usable(service_value) and app_value != service_value:
        raise SystemExit('Local credential mismatch for ' + key + '. Reconcile the two ignored .env files before starting.')
    shared[key] = app_value if usable(app_value) else service_value if usable(service_value) else secrets.token_hex(24 if key == 'LIVEKIT_API_KEY' else 32)
for key, expected in [('LIVEKIT_URL', 'ws://localhost:7880'), ('LIVEKIT_HTTP_URL', 'http://localhost:7880')]:
    if app.get(key) and app[key] != expected:
        raise SystemExit('A custom ' + key + ' is configured. This helper will not replace an existing media deployment.')
update(service_env, {**shared, 'JOIN_REDIS_PORT': redis_port})
update(app_env, {**shared, 'REDIS_HOST': '127.0.0.1', 'REDIS_PORT': redis_port, 'LIVEKIT_URL': 'ws://localhost:7880', 'LIVEKIT_HTTP_URL': 'http://localhost:7880'})
print('Local Redis/LiveKit credentials are synchronized in ignored environment files; values were not displayed.')
print('Project Redis host port: 127.0.0.1:' + redis_port + ' (container port 6379).')
