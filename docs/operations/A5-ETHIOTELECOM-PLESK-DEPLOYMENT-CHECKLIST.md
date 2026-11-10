# ARNA A5 — Ethiotelecom Plesk deployment checklist

**Purpose:** server-specific execution checklist for the existing ARNA A5 production gate.  
**Status:** prepared from previously supplied hosting details; no live hosting inspection or change has been performed.  
**Access constraint:** current Owner access to Plesk File Manager is unavailable. This document is for the next authorized access window.

## 1. Confirmed hosting facts

| Item | Confirmed value |
|---|---|
| Provider / control panel | Ethiotelecom shared Linux hosting / Plesk |
| Plesk portal | `https://lin5.ethiotelecom.et:8443/smb/web/view` |
| API hostname | `api.maalearna.com.et` |
| API document root | `/api.maalearna.com.et/public` |
| PHP version previously selected | `8.3.35` |
| PHP handler | FPM application served by Apache |
| Laravel Toolkit | Installed |
| SSH | Not available / forbidden |
| File Manager | Previously available; currently unavailable |
| Plesk Git deployment | Prior clone attempt timed out; not verified |

These are historical user-supplied settings, not a live check of their current state. Reconfirm them in Plesk before any production change.

## 2. Host and artifact map

| Hostname | Application | Build output | Plesk document root |
|---|---|---|---|
| `api.maalearna.com.et` | Laravel API | API source/release; web root must be Laravel `public/` | Previously supplied: `/api.maalearna.com.et/public` |
| `app.maalearna.com.et` | Workspace | `dist/` | Not yet verified |
| `portal.maalearna.com.et` | Shareholder portal | `dist-portal/` | Not yet verified |
| `admin.maalearna.com.et` | Admin console | `dist-admin/` | Not yet verified |
| `www.maalearna.com.et` | Public website | `dist-public/` | Not yet verified |

Do not assume that all four frontend hostnames are already created or point to the intended directories. Record the actual Plesk document root for each before upload.

## 3. API runtime checks — read-only first

- [ ] Confirm API subdomain exists and its document root is exactly the Laravel `public/` directory.
- [ ] Confirm PHP handler remains FPM served by Apache and PHP version is 8.3.x (previously 8.3.35).
- [ ] Confirm the PHP-FPM runtime loads: `ctype`, `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, and `xml`.
- [ ] Do not treat CLI extension output as proof of PHP-FPM extensions.
- [ ] If the temporary runtime diagnostic is used, upload it only for the one-time HTTPS check under a random filename, record version/SAPI/extension status, delete it immediately, and verify the URL no longer serves it.
- [ ] Confirm Laravel `storage/` and `bootstrap/cache/` are writable by the application runtime, while `.env`, source, vendor metadata, logs, and backups are not web-accessible.
- [ ] Confirm the production `.env` is outside the public document root and is never committed or uploaded into a public static directory.

**Do not upload the temporary diagnostic while access is unavailable. Do not leave it online.**

## 4. Production environment contract

The API hardening PR currently defines the initial cookie-authenticated host set as:

- Sanctum stateful hosts: `app.maalearna.com.et`, `portal.maalearna.com.et`, `admin.maalearna.com.et`
- CORS origins: `https://app.maalearna.com.et`, `https://portal.maalearna.com.et`, `https://admin.maalearna.com.et`
- API origin: `https://api.maalearna.com.et`

The public `www` site is not included in the initial cookie-authenticated allow-list. Add any additional hostname only after its API and cookie-authentication requirement is reviewed and the CORS/Sanctum configuration is updated together.

Before configuration:
- [ ] Create a least-privilege database account; do not use database root.
- [ ] Confirm database host, database name, username, password, and supported TLS settings with the provider.
- [ ] Confirm a verified SMTP sender and provider SMTP settings.
- [ ] Generate a unique Laravel `APP_KEY` on the target server; never reuse a test key or share it in chat.
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, exact HTTPS `APP_URL`, secure/encrypted/HTTP-only cookies, and the matching CORS/Sanctum lists.
- [ ] Do not copy placeholder values from the example template into a live environment unchanged.

No database credentials, SMTP secrets, APP_KEY, or completed `.env` values belong in this checklist or GitHub.

## 5. Static frontend release requirements

Each frontend must be deployed to its own confirmed document root. Never copy one build output over another.

- [ ] Obtain the exact reviewed build from the approved App repository commit.
- [ ] Verify each artifact maps to the intended hostname and API origin.
- [ ] Preserve the generated `.htaccess` / host rules; verify actual Apache response headers and SPA fallback behavior.
- [ ] Disable directory listing and deny access to dotfiles, repository metadata, backups, and deployment archives.
- [ ] Use HTTPS and confirm certificates cover each hostname.
- [ ] Use long-lived immutable caching for fingerprinted assets and short/no-cache behavior for `index.html`.
- [ ] Keep the previous complete release available until acceptance passes.

**Artifact delivery gap:** the current App A5 workflow builds and verifies the four output directories but does not upload downloadable workflow artifacts. A separate Owner-approved change is required before adding artifact publication/retention to CI. Do not assume a downloadable ZIP is currently available from the successful workflow run.

## 6. Deployment sequence — approval required

1. Reconfirm the current Plesk values and all document roots.
2. Confirm database backup completion and restore ownership.
3. Confirm the exact reviewed API and App commit SHAs.
4. Stage versioned API and frontend releases without overwriting the live release.
5. Run read-only preflight and inspect PHP-FPM runtime.
6. Review migration status; do not run migrations until backup and release authorization are confirmed.
7. Obtain explicit Owner approval for any production configuration change, migration, DNS/TLS change, upload, or cutover.
8. Perform the approved cutover and run API/frontend acceptance checks.
9. Keep the previous release and rollback path intact until acceptance is complete.

Never run `migrate:fresh`, destructive seeders, or unreviewed migrations against production.

## 7. Acceptance evidence required

- [ ] Plesk screenshot or written record of current PHP-FPM version/handler and extensions.
- [ ] Actual document roots for all five hosts.
- [ ] DNS and HTTPS certificate checks for each hostname.
- [ ] Redacted preflight result; no secret values.
- [ ] Database connection and migration-status evidence.
- [ ] CORS/CSRF cookie and login/logout browser tests.
- [ ] Tenant resolution and authorization tests, including Finance allow/deny cases.
- [ ] Backup timestamp and successful isolated restore evidence.
- [ ] Static route refresh, CSP/security headers, and asset-cache checks.
- [ ] Rollback rehearsal and monitoring readiness.
- [ ] Separate Owner release authorization.

**Release status: NOT AUTHORIZED.** This checklist does not approve merge, deployment, production configuration, DNS changes, migrations, or publication.
