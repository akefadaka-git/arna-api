# ARNA Mobile Device Session API — A3 Technical Contract

Document: ARNA-API-ANDROID-SESSION-001 | Version 1.0 | 9 November 2026
Status: Owner-approved implementation contract; implementation and verification in progress.

## 1. Authority and compatibility
The canonical API remains authoritative for identity, tenant membership, MFA, permissions, and resource policies. Android uses HTTPS bearer-token authentication. Browser SSO remains cookie/CSRF based and is not reused by Android.

A mobile session represents one registered installation on one account. A Sanctum personal access token is the credential for that session. Session ownership and token ownership are enforced server-side.

Existing web login and SSO behavior must remain compatible. Do not silently convert browser sessions to device sessions or revoke legacy tokens during mobile refresh.

## 2. Proposed persistence model
Add a dedicated auth_device_sessions table (exact naming to be reconciled against migration conventions) with:
- primary key and opaque UUID/public identifier;
- user morph type and user ID;
- tenant ID nullable only where existing auth permits a non-tenant session;
- Sanctum personal access token ID, unique and nullable during transactional creation;
- stable client-generated installation identifier stored as a keyed hash, not raw;
- platform and app version;
- bounded human-readable device label, treated as untrusted input;
- created, last-seen, expires, and revoked timestamps;
- last IP and bounded/redacted user-agent metadata only if permitted by audit and retention policy;
- revocation reason/code and timestamps.

Add indexes for user ownership plus revocation state, unique token ID, and unique user-plus-installation hash only if policy requires one active session per installation. Match FK behavior to the polymorphic user and Sanctum schema; do not assume a direct FK to the user table.

Create session and token transactionally. Create session, issue scoped Sanctum token, bind token ID, and commit as one unit. Roll back on failure so no orphan active session or token remains.

## 3. Token policy
- Use a named mobile token, not the generic web token name.
- Use least-privilege mobile abilities; do not issue wildcard abilities for mobile tokens.
- Bind the token to an opaque session reference and validate it on every authenticated mobile request through dedicated middleware.
- Bound token expiry by configured Sanctum expiry and device-session expiry; whichever occurs first wins.
- Never store plaintext bearer tokens in the session table.
- Hash installation identifiers with a server-side keyed HMAC. The identifier alone is not proof of identity.

Finalize exact ability strings and middleware placement against the installed Sanctum version and current middleware stack before coding.

## 4. Endpoint contract
All routes require HTTPS in production and use the canonical success/data and failure/error envelope.

### Mobile login and session establishment
Extend login with an explicit mobile client discriminator and bounded metadata: client type android, device installation ID, device label, and app version.

For MFA-enabled accounts, bind the challenge server-side to resolved user, selected tenant, client type, and installation hash. Create no bearer token or active session until the challenge succeeds.

Successful mobile login returns user/profile data, selected tenant, bearer token, token type, expiry, and opaque session ID. Do not return raw installation ID or internal token row ID.

### List sessions
GET /api/auth/sessions
Return only sessions belonging to the authenticated user: opaque session ID, device label/platform/app version, created/last-seen/expiry, and current-session indicator. Never return bearer tokens, raw installation IDs, token hashes, or another user's session data.

### Revoke one session
DELETE /api/auth/sessions/{sessionId}
Resolve by opaque public ID and authenticated ownership. Revoke linked Sanctum token and mark session revoked atomically. Use generic not-found/denied behavior that does not disclose another user's session existence.

### Refresh current session
POST /api/token/refresh
Require a valid current mobile session. Revoke only the incoming token and rotate only the token bound to that same session. Preserve all other sessions. Use transaction/locking or equivalent concurrency control so concurrent refresh cannot leave multiple active credentials for one session. Keep session public ID stable and update token linkage/expiry safely.

### Logout current session
POST /api/logout
For a mobile session, revoke only the current session and its token. Preserve other device sessions. Preserve documented legacy web behavior until a separately verified compatibility migration is approved.

## 5. Middleware and authorization
Dedicated mobile-session middleware must distinguish mobile tokens from legacy/browser token paths; verify ability, session existence, token/session linkage, owner linkage, non-revoked state, and expiry; reject stale or mismatched credentials; update last-seen with throttling; and fail closed when a mobile token has no valid session.

Tenant membership and canonical permission middleware remain mandatory. Device sessions do not replace tenant authorization. Never accept a caller-supplied user ID to select session ownership.

## 6. Security and privacy
Redact bearer tokens, refresh material, MFA challenge tokens, OTP/recovery codes, and raw installation identifiers. Store mobile tokens only in Android Keystore-backed encrypted storage; exclude secrets from backups and crash reporting. Rate-limit login, MFA completion, refresh, listing, and revocation. Audit creation, refresh, revoke, failed binding, and suspicious reuse without recording credentials. Use generic errors for foreign session IDs. Define retention for revoked session metadata. Bound and validate all device metadata.

## 7. Required tests
1. Device A refresh does not revoke Device B.
2. Device B revoke does not revoke Device A.
3. Current-session logout affects only current device.
4. Foreign-user session IDs cannot be listed, revoked, or distinguished by response.
5. Missing, revoked, expired, or token/session-mismatched credentials fail closed.
6. MFA challenge is bound to intended device and tenant.
7. Concurrent refresh has one deterministic valid token outcome per session.
8. Tenant membership and permission checks still apply.
9. Existing web login, SSO, logout, and refresh retain documented compatibility.
10. Transaction rollback leaves no orphan token or session.

## 8. Implementation sequence
- API-A3.1: migration/model and indexes.
- API-A3.2: session service and atomic token issuance.
- API-A3.3: login/MFA device binding.
- API-A3.4: session middleware and list/revoke endpoints.
- API-A3.5: device-scoped refresh/logout with concurrency controls.
- API-A3.6: feature tests and security regression suite.
- Android-A3.1: native project scaffold and Amharic-first shell.
- Android-A3.2: secure credential storage and typed API client.
- Android-A3.3: login/MFA/tenant selection and session lifecycle UI.
- Android-A3.4: integration tests and device verification.

No migration or code is deployed from this branch without A4 verification and separate merge/deployment approval.
