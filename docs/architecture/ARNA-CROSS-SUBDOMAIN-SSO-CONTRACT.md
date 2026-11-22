# ARNA cross-subdomain SSO contract

## Objective

A user who authenticates on any approved ARNA first-party SPA can open another approved platform on a sibling maalearna.com.et subdomain without entering credentials again. The API remains stateless: SSO transports the existing revocable Sanctum personal-access token in a browser-managed cookie; it does not create a Laravel web-guard session.

## Browser flow

1. The SPA obtains the Sanctum CSRF cookie from GET /sanctum/csrf-cookie.
2. The SPA posts credentials to POST /api/login (or completes MFA on the same endpoint).
3. On a successful response from a trusted first-party origin, the API sets arna_sso_token with Domain=.maalearna.com.et, Path=/, Secure, HttpOnly, and SameSite=Lax. Its lifetime matches SANCTUM_TOKEN_EXPIRATION.
4. A sibling SPA starts normally and calls GET /api/user with credentials. The custom MASES auth middleware accepts the cookie only when the request Origin is present in both cors.allowed_origins and sanctum.stateful, then presents it to Sanctum as a bearer token.
5. Sanctum token lookup and the MASES revoked-token blacklist remain authoritative. Logout revokes tokens through the existing service and expires the shared cookie. Refresh rotates the token and replaces the cookie.

The bearer token remains available in the login response for existing clients. The cookie is HttpOnly and is never read by frontend JavaScript.

## Required production configuration

Set these values on the API host (Plesk environment file):

- SESSION_DOMAIN=.maalearna.com.et
- SESSION_SECURE_COOKIE=true
- SESSION_SAME_SITE=lax
- SANCTUM_STATEFUL_DOMAINS=app.maalearna.com.et,portal.maalearna.com.et
- CORS_ALLOWED_ORIGINS=https://www.maalearna.com.et,https://auth.maalearna.com.et,https://app.maalearna.com.et,https://portal.maalearna.com.et,https://finance.maalearna.com.et,https://admin.maalearna.com.et,https://arna.maalearna.com.et,https://ai.maalearna.com.et,https://file.maalearna.com.et

Only `app` and `portal` are currently cookie-authenticated SPA hosts and belong in `SANCTUM_STATEFUL_DOMAINS`. The broader nine-host CORS allow-list permits approved browser origins but does not make those hosts stateful or SSO-enabled. A host must be present in both lists to use the shared SSO cookie. Add another host to both lists only after approving it as a cookie-authenticated SPA. Credentialed CORS must remain enabled, without wildcards. All browser/API traffic must use HTTPS.

## Security invariants

- No server-side web session is created by API login; this preserves the stateless authentication decision made to prevent a session from bypassing token revocation.
- A cookie alone is insufficient: a request must carry an approved Origin in both allow-lists.
- An unapproved sibling subdomain cannot use the parent-domain cookie.
- The cookie is HttpOnly, Secure, SameSite=Lax, parent-domain scoped, and expires with the token.
- Existing bearer-token clients remain compatible.
- Logout and refresh continue to use existing token revocation paths. The shared cookie does not introduce a second independent credential lifecycle.
- MFA challenge responses do not issue an SSO cookie; only the successful post-MFA response does.

## Deployment verification checklist (read-only until separately authorized)

- Confirm live API PHP version and Laravel release.
- Confirm SESSION_DOMAIN, SESSION_SECURE_COOKIE, SESSION_SAME_SITE, and SANCTUM_STATEFUL_DOMAINS on the production API host without exposing secret values.
- Confirm CORS origin allow-list and supports_credentials=true.
- In a browser, verify Set-Cookie on successful login, then verify /api/user returns 200 on a sibling SPA without an Authorization header.
- Verify logout on either SPA expires the shared cookie and causes /api/user to return 401 on both.
- Verify an unapproved sibling Origin cannot authenticate.
- Confirm database application grants, migration state, and backup/restore evidence independently. Passing CI does not establish these production facts.
