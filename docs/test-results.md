# Verification results

Executed in the implementation workspace. These results distinguish application-boundary tests from real service and browser verification.

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
