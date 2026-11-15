# Join Meeting REST API v1

Base path `/api/v1`. JSON requests use `Accept: application/json`. Responses use `{meeting}`, `{meetings}`, `{user}`, `{admission}`, or an explicit message. Validation failures are Laravel 422 responses with `message` and `errors`. Authentication failures are 401; policy/admission denials 403; missing IDs 404; capacity 409; ended/expired 410; locked 423; throttled 429; media-service outage 503.

## Browser sessions

First GET `/sanctum/csrf-cookie`. Send credentials on all API requests, and send the decrypted `XSRF-TOKEN` cookie as `X-XSRF-TOKEN` on mutations. Axios is configured with `withCredentials` and `withXSRFToken`. Use the same origin in production. Guests also need cookies and CSRF; do not strip them in a proxy. No long-lived API bearer token is stored in localStorage.

| Method | Endpoint | Who | Request / response |
|---|---|---|---|
| POST | `/auth/firebase` | Public + CSRF, 10/min/IP | `{id_token}` from Firebase Google sign-in → `{user}` and regenerated session; Admin verification in production, opt-in Google REST verification locally |
| GET | `/auth/me` | Active authenticated session | `{user}` |
| POST | `/auth/logout` | Active authenticated session + CSRF | Invalidates session; 204 |
| POST | `/meetings` | Active authenticated user + CSRF, 20/hour/user | `{title, max_participants?}` → 201 `{meeting}` including `share_url` |
| GET | `/meetings` | Active authenticated session | Latest 30 meetings hosted by caller |
| GET | `/meetings/{code}` | Public link holder | Limited metadata: title, host name, lifecycle, access, capacity, `is_host`, dates, share URL |
| POST | `/meetings/{code}/admission-request` | Guest or signed-in user + CSRF, join limiter | `{display_name}` → own admission status. Repeated requests do not reset rejected/approved status |
| POST | `/meetings/{code}/join` | Host or approved participant + CSRF, join limiter | `{display_name}` → `{token, server_url, identity, meeting}` |
| POST | `/meetings/{code}/leave` | Current session's participant + CSRF | Removes only caller's LiveKit identity |
| GET | `/meetings/{code}/participants` | Host only | `{participants, admissions}` (pending requests) |
| POST | `/meetings/{code}/admit` | Host + CSRF | `{admission_id}` → approved admission |
| POST | `/meetings/{code}/reject` | Host + CSRF | `{admission_id}` → rejected admission |
| POST | `/meetings/{code}/remove` | Host + CSRF | `{identity}` → disconnect and revoke admission for this identity |
| POST | `/meetings/{code}/access` | Host + CSRF | `{locked: boolean}` → lock/unlock new joining |
| POST | `/meetings/{code}/end` | Host + CSRF | Tombstone meeting, delete LiveKit room, block future tokens. 503 means closure persisted but disconnect retry is pending |
| POST | `/livekit/webhook` | Signed LiveKit server only | Raw `application/webhook+json` body, JWT in Authorization. Exempt from browser sessions/CSRF. Signature and body hash verified by SDK; unique event IDs deduplicate |

The meeting path uses the non-guessable 32-character hex code, not the numeric database ID. UUID identifies the internal LiveKit room. Never accept `user_id`, `host_id`, `role`, or guest identity from client input.

Join and admission requests share limits of 30/min/session and 300/min/IP; general browser API traffic is limited to 120/min/user or guest session and 600/min/IP. Admission polling uses 3 seconds. The webhook limit is 1200/min/IP. These defaults are conservative for private beta; test guests behind shared NAT before adjusting them. Cookie-authenticated API mutations all use the Laravel web middleware; webhook alone uses the API middleware.

## Example flow

Host: Google ID token exchange → create meeting → join without waiting → share `share_url` → poll host participant endpoint → approve guests.

Guest: read metadata → request admission with a name → poll the same endpoint for own status → explicitly join after approval → connect LiveKit using returned token. Tokens are held in React memory only. Leaving uses only the session-derived identity.

`created` becomes `active` only after a trusted participant-joined event, and `ended` after host end, room-finished, or expiry reconciliation. The `meetings:reconcile` scheduler must run every minute. Maximum duration starts at creation.
