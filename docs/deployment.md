# Linux VPS private beta deployment

This is a deployment runbook and unexecuted configuration template, not evidence of a deployed or security-certified service. Validate it on staging before inviting users. Full dependency resolution, real Firebase/LiveKit tests, and the immediate-revocation limitation in [architecture.md](architecture.md) remain launch gates.

## Host and layout

Use supported Linux with Nginx, PHP 8.4 FPM/CLI and PDO SQLite, mbstring, XML, curl, openssl, sodium, bcmath, zip, and phpredis. Install Composer, Node 24, SQLite CLI, Redis 7, a firewall, and an ACME client such as Certbot. Keep PHP-FPM/Redis on local sockets or private networks. Use a dedicated unprivileged deploy user and reviewed SSH keys. Never run Laravel as root.

Check out backend at `/srv/join-meeting/backend` and frontend at `/srv/join-meeting/frontend`. Make `/var/lib/join-meeting` writable by `www-data`; create the SQLite database there with directory mode 0750 and file mode 0640. The containing directory must be writable for SQLite WAL/SHM files. Keep deployment secrets and Firebase Admin JSON outside the web roots. Never place SQLite on NFS or an ephemeral release directory.

Resolve Composer dependencies on a trusted build host, commit `composer.lock`, then use `composer install --no-dev --optimize-autoloader` for deploys. Resolve the frontend once and commit its package lock; subsequently use `npm ci` and `npm run build`. Run dependency audits and review findings before beta. Avoid changing dependency versions directly on a live host.

Set backend `.env` using the README table: production, debug false, public HTTPS APP_URL/FRONTEND_URL, absolute SQLite path, secure encrypted HttpOnly cookies, Redis sessions/cache/queues, Firebase credentials/project, LiveKit URLs/keys, tested capacity/duration. Generate `APP_KEY` once and keep a secure backup. Apply `php artisan migrate --force`, then `php artisan config:cache` and `php artisan route:cache`. Give `www-data` write access only to storage, bootstrap/cache, and the persistent database directory. Protect `.env` and credential files; the PHP worker needs read access to the Admin JSON.

In frontend `.env`, set only the documented `VITE_FIREBASE_*` public web-app configuration and leave `VITE_API_URL` empty for same-origin API requests. These settings are embedded at build time. Build and serve `dist/`. Do not put any server-side secret in a `VITE_` variable.

## DNS, certificates, and Nginx

Point meeting, LiveKit, and TURN domain records at the appropriate public IPs. Issue certificates first using an HTTP-only ACME configuration or DNS challenge; the supplied HTTPS template requires certificate files to exist. A certificate used for both meeting and LiveKit server blocks must contain both names. TURN needs its own valid certificate/name or a suitable multi-domain certificate. Configure automatic renewal, Nginx reload, and LiveKit certificate reload/restart as supported by the selected version.

The Nginx template variables are:

| Variable | Value |
|---|---|
| `MEETING_DOMAIN` | Meeting app hostname, no scheme |
| `LIVEKIT_DOMAIN` | Browser-reachable LiveKit WSS hostname |
| `FIREBASE_AUTH_DOMAIN` | Exact Firebase authDomain allowed in the CSP |
| `TLS_CERT_PATH`, `TLS_KEY_PATH` | Absolute Nginx certificate/key paths |
| `TURN_DOMAIN` | Public TURN hostname |
| `TURN_CERT_PATH`, `TURN_KEY_PATH` | TURN certificate/private-key paths readable by LiveKit |
| `REDIS_PASSWORD`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET` | Private values used when rendering the LiveKit config |

Load variables from an untracked, permission-restricted environment file, then render Nginx with an **explicit** substitution list so Nginx variables survive:

```sh
envsubst '${MEETING_DOMAIN} ${LIVEKIT_DOMAIN} ${FIREBASE_AUTH_DOMAIN} ${TLS_CERT_PATH} ${TLS_KEY_PATH}' < deploy/nginx.conf.template > /tmp/join-nginx.conf
```

Review the rendered non-secret Nginx config, install it under `/etc/nginx/sites-available`, enable it, run `nginx -t`, then reload. Adapt the FPM socket and checkout paths if your host differs. The template routes `/api`, `/sanctum`, and `/up` to Laravel and SPA routes to React. It includes HTTPS redirect, HSTS, no-referrer, frame restrictions, media permissions, and a Firebase/LiveKit CSP; test Google popups and screen sharing with the deployed headers. Do not enable an unconditional same-origin COOP header that breaks OAuth popups. Add a health monitor for `/up`; independently probe database/Redis/Firebase/LiveKit, since framework health alone does not prove those work.

## LiveKit and network

Prefer the official LiveKit production deployment tooling or a managed deployment. Pin a reviewed server image/version and verify PHP/JS SDK compatibility; the included local image pin is a starting point, not a verified production recommendation. For self-hosting, render `livekit.production.yaml.template` to a root-owned file outside the repository using only its documented variable names. It contains secrets: use `umask 077`, protect the rendered file, and never commit or log it. Bind the internal API port to a private interface or restrict it by firewall. Configure the server service to read this file, advertise the actual external IP, and send signed webhooks to the public API.

Expose only the required ports for the configured topology:

- App HTTPS and LiveKit WSS: TCP 443 through Nginx. TCP 80 only for redirect/ACME.
- LiveKit WebRTC TCP fallback: TCP 7881 directly to the SFU.
- LiveKit WebRTC UDP multiplexing: UDP 7882 directly to the SFU, as configured here. If using a UDP port range instead, open that configured range and remove the mux assumption.
- TURN UDP/STUN: UDP 3478; TURN TLS: TCP 5349 for this template. For networks that only permit 443, dedicate a public IP or official layer-4/TURN deployment to TURN TLS 443; an ordinary Nginx HTTP reverse proxy cannot proxy TURN.
- Keep SQLite private; expose no database port. Keep Redis TCP 6379 private and authenticated. Keep PHP-FPM private.

Do not route media UDP through the Nginx HTTP proxy. Ensure security groups, host firewall, and NAT agree with advertised addresses. Test TLS TURN from a restrictive network, including a relay-only WebRTC client. STUN success alone does not demonstrate TURN works. The local Compose YAML advertises loopback and must not be used as the production media configuration.

Add Redis-backed room routing when scaling LiveKit; keep API and media capacity planning separate. Never assume an inexpensive VPS can host arbitrary meetings. Start with the capacity default only after measuring CPU, memory, ingress/egress, packet loss, and bandwidth cost at realistic resolutions.

## Workers, lifecycle, and monitoring

Install `deploy/join-worker.service`, `join-scheduler.service`, and `join-scheduler.timer` into systemd, adjust paths/users, then enable worker and scheduler timer. Alternatively use one scheduler cron entry; do not run both. Confirm `php artisan meetings:reconcile` succeeds. Reconciliation is necessary for duration expiry and disconnect retries. A video API outage closes future API joins but can delay disconnecting current participants; alert on these failures.

Rotate Laravel, PHP-FPM, Nginx, Redis, and LiveKit logs. Alert on webhook signature failures, webhook silence, 5xx/429 spikes, scheduler failures, queue failures, SQLite lock waits, Redis memory pressure, disk usage, and media transport health. Do not log Firebase tokens, LiveKit JWTs, Admin JSON, chat bodies, or media. Configure a concrete retention policy for account data, participant names and event records before public launch; no automatic deletion policy is claimed in this MVP.

## Backups and rollback

Use SQLite's online `.backup` API/CLI or a coordinated snapshot including WAL; copying only the main database file while writers run is unsafe. Store encrypted, access-controlled backups off-host, keep multiple recovery points, and test restoring to a separate path regularly. Back up `APP_KEY`, environment settings, and service-account access separately in a secrets manager. Do not store backups in Git or the web root. Redis AOF is configured locally; production persistence/restore must match your session/queue durability needs. App rollbacks must be compatible with the migrated schema; back up before migrations and test restores.

## Staging acceptance and load test

1. Complete Google sign-in, logout, expiration, invalid-token and suspended-account checks with the real Firebase project.
2. Host from one device and join as guests on Wi-Fi and cellular. Check admission, reject, lock, screen sharing, camera-off tiles, mute, device unplug/reselect, copied links and reliable chat. Check mobile layout and keyboard navigation.
3. Test a network interruption/reconnect and a relay-only connection through TURN TLS; test host removal and end from every device. Attempt using an already-issued token after end/removal to verify the documented revocation behavior in the selected deployment.
4. Run all API tests against a staging SQLite file; test simultaneous admission/token requests in separate processes to observe busy timeouts, writer-lock latency, and room capacity. The current in-memory API suite does not prove multi-process behavior.
5. Use LiveKit's load-test tooling with realistic publishers/subscribers and simulcast settings, starting below expected beta traffic. Generate tokens through an authorized test setup, never expose admin keys. Monitor CPU, RSS, network, RTT/jitter/loss, freezes, reconnects, and egress per meeting. Increase concurrent rooms gradually; test screen sharing and TURN-heavy traffic. Keep load tests off public users' meetings.
6. Define measurable SLOs and conservative admission ceilings from those measurements. Reduce capacity or add resources if quality degrades. Document the observed maximum, not a hypothetical unlimited capacity.
7. Audit dependencies/secrets, restore a backup, review notices/retention/contact, and resolve strict token revocation before public availability.
