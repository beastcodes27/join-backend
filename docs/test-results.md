# Verification results

Executed in the implementation workspace. These results distinguish application-boundary tests from real service and browser verification.

## Initial implementation checks

| Check | Result |
|---|---|
| Laravel migrations on SQLite in memory | Passed |
| Current migrations on a file-backed SQLite database | Passed; WAL journal mode confirmed |
| Laravel API and security suite | 35 tests: **33 passed**, **2 skipped**, **118 assertions** |
| Frontend meeting-code validation | **3 passed**, including malformed input and URL extraction |
| TypeScript syntax and unused-declaration checks with an available compiler | Passed; **not** a complete SDK typecheck |
| CSS parse | Passed, 464 rules |
| Laravel route registration and route-cache generation | Passed |
| Composer manifest validation | Passed |
| Laravel Pint | Applied successfully |
| PHP source syntax | Passed |
| Credential scans across both reachable Git histories | Passed; common patterns and sensitive filenames checked, without printing secret values |
| Full Composer dependency install | Blocked: package-registry DNS unavailable. Cached framework/Sanctum dependencies supported API tests; Firebase Admin and LiveKit SDKs are not installed |
| npm dependency install | Blocked: registry DNS `EAI_AGAIN`; offline install also lacks required packages |
| Frontend lint / production build / Playwright | Attempted but blocked by uninstalled dependencies. No passing build or browser test is claimed |
| Docker Compose services | Not started: Docker daemon socket access denied |
| Google sign-in with real Firebase | Not verified; requires installed Admin SDK and configured Firebase project/service account |
| Real LiveKit media / screen sharing / chat / TURN | Not verified; requires installed SDKs, reachable server, and multiple devices/networks |
| VPS/Nginx/HTTPS/systemd templates | Provided, not executed on a VPS |

The two skipped tests exercise real LiveKit JWT grants/signature and webhook body-integrity verification. API tests instead mock the identity-verifier and media-service boundaries; they verify account synchronization, sessions, CSRF, CORS origin restrictions, meeting creation, admissions, host authorization, capacity reservations, ended/expired meetings, throttling, service outages, webhook idempotency, and stale room events. They do not prove Firebase cryptography, media quality, or synchronous token revocation.

A complete backend Composer lock and frontend npm lock could not be generated with full integrations. Resolve the declared packages on a network-enabled host, commit reviewed lockfiles, run dependency audits and all checks, then follow the staging acceptance/load-test instructions in [deployment.md](deployment.md). The installed cached vendor directory is ignored and is not part of the source deliverable.

Read the stale-token limitation in [architecture.md](architecture.md). A still-valid self-hosted LiveKit token may reconnect briefly after room deletion before a webhook removes it. This is not verified immediate revocation, and must be resolved for strict public-service requirements.

Both source repositories were pushed successfully to the requested GitHub remotes on `main`. Credential scans passed before pushing. GitHub Actions workflows are included; their remote execution results were not inspected in this workspace.

## Latest local Firebase correction

The backend now supports explicitly enabled Google REST token lookup for local development without service-account credentials or the Admin package. Production still requires the Admin SDK with revocation checks, regardless of the local setting.

- Full backend suite: **65 tests, 63 passed, 2 skipped, 219 assertions**.
- New Firebase tests: **30 passed, 101 assertions**. They exercise the real auth endpoint and local verifier with Google's external HTTP response faked; no application-auth mock substitutes for the verifier.
- Cases include exact token submission, account/session creation, profile synchronization, invalid/expired tokens, project/issuer/UID binding, timestamps, Google provider and verified email, malformed responses, Google rejection/outages, missing configuration, and production isolation.
- Pint and Composer manifest validation passed. Public web keys remain in ignored local environment files.
- Frontend build, lint, and its three validation tests were subsequently verified after local dependencies became available; an omitted icon import was corrected.
- No real Google popup/login exchange or cross-network meeting is claimed as verified in this restricted shell. A normal browser/server with internet access is needed for that acceptance check.

## Local service startup follow-up

The local Redis publish port is now configurable through `JOIN_REDIS_PORT` (default 6387), with the same value written to Laravel's ignored `REDIS_PORT`. The rendered Compose configuration was validated as loopback-only `127.0.0.1:6387` to container port 6379, with matching application credentials. Existing services on port 6379 are not managed by this launcher.

After the SDK dependencies became available, the suite reports **65 tests: 64 passed, 1 skipped, 231 assertions**. Real LiveKit token permission/signature checks pass. The real webhook fixture was corrected to include a non-joining grant and to send its Authorization header through the raw HTTP test request. Its full protobuf body-integrity check remains skipped because this PHP runtime lacks BCMath. The startup script now explicitly requires BCMath before starting services. Container recreation and live media still require the user's regular terminal with Docker access.

## Custom PHP launcher correction

BCMath was installed successfully, but the user-selected PHP wrapper forced a custom INI scan directory that omitted its configuration and loaded mysqli before mysqlnd. The local launcher now generates an ignored, project-scoped PHP wrapper and module configuration, preserving the custom SQLite extension paths while loading installed BCMath and ordering MySQL dependencies correctly. System and user-wide PHP files are unchanged.

With that project runtime: **65 backend tests passed, 235 assertions, no skips**. BCMath, mysqli, mysqlnd, PDO and pdo_sqlite load without startup warnings. Real LiveKit token grants and signed/tampered webhook checks now run successfully. This verifies SDK integration boundaries; live browser media and Docker startup remain separate checks requiring an unrestricted terminal.

## Chat, local connection recovery, scheduling and reminders

- Local Compose rendering validates Linux host networking, enabled loopback candidates and loopback webhook routing for the SFU. The user's local LiveKit image selection was preserved.
- The client uses a stable room instance and serialized SDK connection work. Metadata refresh errors no longer unmount a connected call. Disconnect reasons offer appropriate recovery without host-removal/ended-room bypasses.
- Backend tests cover resuming an authorized connection after the initial token lease, while rejecting expired never-joined reservations, removed/revoked/long-departed participants, and stale departure races.
- Scheduling tests cover UTC/timezone handling, future validation, host automatic booking, duplicate booking, capacity and reserved seats, cancellation, early joining, one-hour/late reminder dispatch, recipient privacy, mail failures/retries, no duplicate in-app reminders, and user-scoped notification reads.
- Full backend suite: **93 passed, 360 assertions**.
- Frontend Node suites: **25 passed** across meeting-code, chat, connection recovery and scheduling helpers. Production build and lint pass.
- Real two-browser call/chat and scheduling acceptance specs are included but require actual services and local authenticated test-host state. Full browser media and actual email transport delivery have not been verified in the restricted agent runtime. Unit reminder tests fake notification delivery; Compose validates configuration without starting Docker.

## Continued guest connection investigation

The latest local audit records show guest approval and token issuance followed by LiveKit `participant_connection_aborted` events about 15 seconds later; no successful guest join was recorded. The root transport failure remains unconfirmed without the LiveKit runtime logs.

Removed the forced loopback `rtc.node_ip` so LiveKit can discover the host interfaces while keeping loopback candidates. Initial client join failures now retain the signaling/media distinction and token rejection instead of being masked by a generic disconnected event. Host removal, room closure, and duplicate identity handling remain explicit. Compose configuration validation, **27 frontend tests**, lint, and production build pass. The restricted shell cannot access Docker or open local network sockets; a real host/guest call has not been verified after this change.
