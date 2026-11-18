# Join Meeting API · DevTZ

Laravel REST API for **Join Meeting — Connect anywhere. Meet everyone.** The paired frontend is [join-meet-](https://github.com/beastcodes27/join-meet-). This repository uses SQLite following the project owner's preference. It is implementation work for a private beta, not a production-readiness claim.

## Prerequisites

PHP 8.4+ with SQLite/PDO SQLite, mbstring, XML, curl, openssl, sodium, bcmath, zip, and Redis extensions; Composer 2; Node 22.19+ (24 recommended) for the paired frontend. Docker is optional for local Redis and LiveKit. Real Google sign-in needs a Firebase project with Google enabled. Local development can validate tokens through Google REST using the existing web API key, without an Admin key file. Real media needs a reachable LiveKit server and server credentials.

## Start all local services

Once the frontend checkout is beside this backend and the ignored app environments are configured, run this in a regular terminal with Docker access:

```sh
bash scripts/start-local.sh
```

It generates matching local Redis/LiveKit credentials in ignored, permission-restricted environment files, installs missing packages, migrates SQLite, starts Compose, and launches the API, frontend, scheduler and queue worker. It refuses to replace a custom LiveKit deployment or mismatched credentials. Set `JOIN_FRONTEND_DIR` if the frontend directory is elsewhere. Frontend and API use localhost ports 5173 and 8000; the API binds all local interfaces so Docker's signed webhook can reach it. Logs are under `storage/logs/local-*.log`. Ctrl+C stops only app processes launched by the script; use the printed Compose command to stop the containers. The helper does not verify a real video call.

Project Redis uses host port `6387` to avoid occupying another application's default Redis port. Set `JOIN_REDIS_PORT` in the ignored `deploy/.env` to choose another port; the helper synchronizes Laravel's `REDIS_PORT`. Redis keeps its internal container port `6379`. After a port-allocation error, rerun the startup script to recreate this project's Redis container with the corrected mapping.

The launcher creates a project-scoped PHP configuration under ignored `storage/app/private/php-cli`, preserving the selected PHP runtime's extensions (including custom SQLite modules), ordering `mysqlnd` before `mysqli`, and loading an installed BCMath module. It does not change system PHP files or your user-wide launcher. The launcher checks for PHP BCMath, which LiveKit's protobuf messages require. If missing on Ubuntu with PHP 8.4, install `php8.4-bcmath` and enable it for the CLI and any PHP-FPM runtime before restarting the launcher.

## Local setup

1. Clone this repository beside the frontend checkout.
2. `composer install` (full SDK resolution requires network access; see verification note below).
3. `cp .env.example .env` and `php artisan key:generate`.
4. Create `database/database.sqlite` and run `php artisan migrate`. Leave `DB_DATABASE` empty for the default file. Never serve this directory publicly.
5. Configure Firebase and LiveKit as described below. Set `FRONTEND_URL` to the exact browser origin.
6. Optional: `cp deploy/.env.example deploy/.env`, fill independent generated Redis and LiveKit credentials, then `docker compose --env-file deploy/.env -f deploy/compose.yaml up -d`.
7. Start Laravel with `php artisan serve --host=0.0.0.0 --port=8000`. Binding to all interfaces is required for the local Docker webhook to reach the host; use a trusted local network. Start the frontend on localhost:5173 with its Vite proxy.
8. Run `php artisan schedule:work` in another terminal. If using queued jobs, run `php artisan queue:work` too. Sessions/cache can stay file-backed in local development.

The local LiveKit config uses Linux host networking and enabled loopback ICE candidates for same-machine browsers, avoiding Docker bridge NAT for media. For different devices and networks, use HTTPS/WSS and a public LiveKit/TURN deployment, or LiveKit Cloud. Do not deploy the local YAML publicly. Room events must reach `/api/v1/livekit/webhook`; lifecycle and stale-join removal rely on these trusted events.

## Firebase setup

Enable Google in Firebase Authentication, configure allowed browser domains, and use its web-app settings in the frontend's untracked `.env`.

For local development, set `FIREBASE_LOCAL_TOKEN_LOOKUP=true`, `FIREBASE_WEB_API_KEY` to your Firebase web API key, and `FIREBASE_PROJECT_ID` to the same project as the frontend. This mode sends the submitted ID token to Google's fixed HTTPS `accounts:lookup` endpoint before trusting any identity. The returned account must match the token subject, configured project/issuer, valid timestamps, Google provider, and verified email. Laravel creates a session only after those checks. The backend needs internet access and a key whose API/application restrictions permit the server request. No service-account file or Admin package is needed for this local-only path.

Production always uses the Admin SDK and revocation checks, even if the local flag is accidentally left enabled. For production, store the Admin service-account JSON outside the checkout and outside any public root, with restrictive file permissions. Set `FIREBASE_CREDENTIALS` to its absolute path and `FIREBASE_PROJECT_ID` to the same project as the frontend. Enable the relevant APIs for checking revoked sessions. Never put Admin credentials or a service-account JSON into Vite variables.

## Environment variables

| Variable | Purpose |
|---|---|
| `APP_NAME` | Application label |
| `APP_ENV` | `local` or `production` |
| `APP_KEY` | Generated by `artisan key:generate`; encrypts cookies/session data. Never rotate without a migration plan |
| `APP_DEBUG` | False for deployments; true only for private local debugging |
| `APP_URL` | Public API origin; production uses the frontend origin |
| `FRONTEND_URL` | Exact CORS origin and base for shareable meeting links |
| `DB_CONNECTION` | `sqlite` |
| `DB_DATABASE` | Absolute production file path; empty selects `database/database.sqlite` |
| `SESSION_DRIVER` | `file` locally; `redis` in production |
| `SESSION_LIFETIME` | Inactivity timeout in minutes; absolute auth expiry is also enforced at two hours |
| `SESSION_ENCRYPT` | Encrypt session payloads |
| `SESSION_SECURE_COOKIE` | True for HTTPS production; false only for local HTTP |
| `SESSION_HTTP_ONLY` | True; JavaScript cannot access app session cookies |
| `SESSION_SAME_SITE` | `lax`; keep frontend and API on the same origin |
| `CACHE_STORE` | `file` locally; `redis` in production for reliable rate limits |
| `QUEUE_CONNECTION` | `database` locally; `redis` in production |
| `REDIS_CLIENT` | `phpredis`, matching the installed PHP extension |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Redis location and secret; do not expose Redis publicly |
| `LOG_CHANNEL`, `LOG_LEVEL` | Operational log destination and verbosity; never log tokens or media |
| `FIREBASE_CREDENTIALS` | Server-only absolute path to service-account JSON |
| `FIREBASE_PROJECT_ID` | Verified Firebase project/audience |
| `FIREBASE_LOCAL_TOKEN_LOOKUP` | Opt-in Google REST validation in `APP_ENV=local` only; default false; ignored in production |
| `FIREBASE_WEB_API_KEY` | Existing Firebase web key used only by the local token lookup; keep the actual value in ignored `.env` |
| `LIVEKIT_URL` | Browser-reachable WSS endpoint in production |
| `LIVEKIT_HTTP_URL` | Server-reachable HTTPS or private HTTP room API |
| `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET` | Server-only signing and room-management credentials |
| `MEETING_MAX_PARTICIPANTS` | Capacity ceiling, default 12; validate actual media resources |
| `MEETING_MAX_MINUTES` | Lifetime from creation, default 60; scheduler enforces expiry |

All credential fields in tracked examples are empty. `deploy/.env` uses the Redis/LiveKit variables above. Production deployment-template variables are documented in [deployment.md](docs/deployment.md).

## Verification

- `php artisan test --compact`
- `vendor/bin/pint --dirty --format agent`
- `python3 scripts/check-secrets.py` (scans reachable Git history without printing secret values)
- `php artisan route:list --path=api`
- `composer validate` and `composer audit` once full dependencies can be installed

See [test-results.md](docs/test-results.md) for actual results and blocked checks. Firebase and LiveKit integration packages were unavailable in the restricted build environment. The cached framework was used to test application boundaries with mocks; this does not verify live sign-in or media. The complete Composer manifest declares both SDKs. A complete dependency lock could not be generated; resolve, audit, and commit it before deployment.

See [architecture](docs/architecture.md), [API contract](docs/api.md), and [VPS deployment](docs/deployment.md). Review the documented stale-token revocation window before any public launch.

## Local connection troubleshooting

After changing the Compose media network, stop the launcher with Ctrl+C and rerun `bash scripts/start-local.sh` so Compose recreates LiveKit. The local configuration is for Linux and same-computer testing; another device needs a reachable HTTPS/WSS deployment and suitable media routing/TURN. The browser now shows explicit disconnect reasons and a retry action for recoverable failures. Duplicate tabs sharing the same signed-in identity still replace one another; use a separate browser profile/private window as a guest for a two-participant check.

The optional frontend `npm run test:call` exercises two real browser contexts, host admission, bidirectional chat, unread messages, retained drafts, and connection continuity through repeated metadata polls. It requires a signed-in test-host storage state and running services. Backend reconnect tests cover expired initial leases and reject removed, revoked, or long-departed identities.
