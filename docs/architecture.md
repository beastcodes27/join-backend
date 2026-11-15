# Join Meeting architecture

DevTZ · Connect anywhere. Meet everyone.

Two independent repositories share one API contract. `frontend/` is React, Vite, TypeScript, Tailwind, React Router, TanStack Query, Axios, Firebase browser authentication, and LiveKit components. `backend/` is Laravel 13, Sanctum cookie authentication, Kreait Firebase Admin, and the community LiveKit PHP SDK linked by LiveKit's ecosystem. PHP 8.4 is the documented runtime. SQLite replaces MySQL at the user's request; Redis supports production sessions, queues, cache, and throttling.

## Trust boundaries

1. Google authenticates the user in Firebase. The browser holds the Firebase ID token only in memory and exchanges it over HTTPS.
2. In production Laravel verifies issuer, audience, signature, expiration, revocation, verified email, and Google provider with the Admin SDK. An explicitly enabled local-only mode instead requires Google to accept the exact ID token through its HTTPS account lookup API, then checks the returned UID against its project-bound claims; it does not depend on an Admin key file. Production cannot select this path. Only verified Firebase UID links an account. Google tokens are discarded after exchange.
3. Laravel regenerates an encrypted, HttpOnly session cookie, uses CSRF protection for mutations, and enforces active account status and an absolute two-hour authentication lifetime. Guests also receive a session-bound random identifier. Browser requests include cookies and the XSRF header.
4. All non-hosts require host approval. Approval is scoped to a meeting and a server-derived identity. Host policies run on the server. Display names and client role fields have no authority.
5. Joins take a serialized SQLite write transaction, check lifecycle, lock, admission, removal, capacity, and reserve a slot for token validity plus 15 seconds. LiveKit room capacity adds a second cap. Participant grants never include administration. Tokens expire after 60 seconds and are returned with `Cache-Control: no-store`.
6. LiveKit carries audio, video, screens, and reliable live chat. Media never passes through Laravel. No recordings, persisted chat, or transcription are implemented. Transport encryption is provided by WebRTC; E2EE is not enabled.
7. Signed, body-bound LiveKit webhooks update lifecycle and connection IDs. A unique database event ID prevents replay processing. Event timestamps older than 24 hours are rejected. Delayed participant-left events cannot close a newer connection SID, and events from older room SIDs cannot close the current room. Ending a meeting persists its closed state before attempting room deletion. The minute scheduler retries ended-room disconnections and expires meetings.

## Repository layout

Frontend: `src/components`, `src/lib`, `src/pages`, `tests`, `.env.example`.
Backend: `app/Contracts`, `app/Services`, `app/Policies`, `app/Http`, `app/Models`, `database/migrations`, `tests`, `deploy`, `docs`, `scripts`.

## Milestones and status

1. Foundation: scaffold, schema and SQLite migrations implemented; migrations exercised on SQLite.
2. Authentication: Google/Firebase exchange and secure app sessions implemented. Real Google exchange still requires credentials and SDK installation.
3. Management: create/list, admission, lock, remove, end and capacity enforcement implemented; API boundary tests pass.
4. Live video: SDK integration and minimum token grants implemented; external SDK and real multi-device verification pending.
5. Experience: prejoin/device selection, responsive tiles, screen sharing, live chat, host panels and confirmations implemented; full browser validation pending dependency installation.
6. Verification/deployment: tests and deployment templates provided; VPS execution, TURN and load tests remain required before beta.

## Known security and operational limits

A self-hosted LiveKit token is a bearer credential, valid until expiry. Deleting a room does not intrinsically revoke a previously issued token: it can briefly reconnect or recreate the same room. This implementation blocks new API tokens and removes such connections via signed webhooks and reconciliation; **there is a possible window before the webhook arrives, within the remaining 60-second token lifetime**. Immediate media-level revocation is not proven. Strict immediate revocation is a public-launch blocker: choose and verify a LiveKit deployment with token revocation or a supported synchronous authorization mechanism. Do not claim webhook removal eliminates that window.

SQLite supports one API host with a persistent local filesystem. WAL, foreign keys, a 5-second busy timeout, and IMMEDIATE transactions serialize writes; backend media API calls inside those transactions hold the writer lock. Load-test admission bursts. A shared SQL database is needed before adding independent API nodes. LiveKit and Redis scale separately.

A host role belongs to the authenticated user, not a browser tab. A user has one LiveKit identity per meeting, so joining on a second device replaces that user's existing connection. Guests in a new session must request admission again. Removal revokes the current identity; it is not a permanent device/IP ban.

Keep schema and services neutral for later billing/organization policies. Future features listed in the brief are deliberately excluded. Legal notices are private-beta drafts pending operator contact, retention policy, and jurisdiction review.

## Upstream references

- [Laravel Sanctum](https://laravel.com/docs/13.x/sanctum)
- [Firebase Admin PHP authentication](https://firebase-php.readthedocs.io/en/stable/authentication.html)
- [LiveKit PHP SDK source](https://github.com/agence104/livekit-server-sdk-php)
- [LiveKit webhooks](https://docs.livekit.io/intro/basics/rooms-participants-tracks/webhooks-events/)
- [LiveKit deployment](https://docs.livekit.io/transport/self-hosting/deployment/)

## Local Firebase verification

Set `FIREBASE_LOCAL_TOKEN_LOOKUP=true` and `FIREBASE_WEB_API_KEY` only in the ignored local environment, with the matching `FIREBASE_PROJECT_ID`. The API key and tokens are never logged by the lookup service. Google lookup rejection never creates an application session; connection/configuration errors return actionable 503 responses. No unverified UID or email fallback exists. Do not treat the local lookup as proof of the production Admin SDK revocation behavior.

[Google's account lookup API](https://firebase.google.com/docs/reference/rest/auth#section-get-account-info) documents the end-user ID token request. Local-path tests fake this external boundary and verify application security checks; live Google/browser verification remains separate.
