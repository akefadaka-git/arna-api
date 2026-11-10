# ARNA API — A5 production environment and operations baseline

**Status:** implementation guidance and preflight tooling; not evidence of a configured or deployed production server.  
**API origin:** `https://api.maalearna.com.et`  
**Frontend hosts:** `app`, `portal`, `admin`, `www`, and other explicitly approved ARNA subdomains.

## 1. Environment provisioning

1. Copy `.env.production.example` to the server as `.env`; do not commit the completed file.
2. Generate the application key on the target server using `php artisan key:generate`. Never reuse a test key or publish the generated value.
3. Replace every `CHANGE-ME` value. Use a unique database account with only the privileges required by the application; do not use MySQL root.
4. Use a production database with TLS where the provider supports it. Configure `MYSQL_ATTR_SSL_CA` to the provider CA file when required.
5. Configure SMTP using a verified sender and credentials stored only on the server.
6. Set `APP_DEBUG=false`, `APP_ENV=production`, HTTPS `APP_URL`, encrypted secure HTTP-only session cookies, and the exact approved Sanctum stateful host list.
7. Keep `config/cors.php`'s explicit origins aligned with deployed browser origins. Do not introduce wildcard origins while credentialed requests are enabled.
8. Restrict filesystem permissions: `.env` readable only by the application/deployment identity; `storage/` and `bootstrap/cache/` writable by the runtime identity. Deny web access to source, `.env`, vendor metadata, logs, and backups.

## 2. Read-only preflight

Run after provisioning and before cache generation or migration:

```bash
php scripts/production-env-preflight.php .env
php artisan about --only=environment
php artisan migrate:status
```

The preflight validates production mode, HTTPS API URL, disabled debug, MySQL selection, non-placeholder database and SMTP credentials, Laravel key format, secure session cookie settings, required SPA hostnames, and the required PHP extensions loaded by the PHP runtime executing the script. It never prints secret values or modifies the environment file.

**Runtime distinction:** CLI preflight reports the CLI PHP runtime. Plesk may run the website through a separate PHP-FPM handler/configuration. CLI PASS is not proof of PHP-FPM extension availability.

## 3. Temporary PHP-FPM runtime diagnostic (mobile-friendly)

The file `scripts/a5-php-runtime-diagnostic.php` reports only PHP version, SAPI, and loaded/missing status for the required extensions. It does not read `.env`, inspect filesystem paths, connect to the database, or change server state.

When the Owner is ready to perform this check:

1. Upload the diagnostic file temporarily into the API document root, using a hard-to-guess filename such as `_a5-check-<random>.php`. Do not place it in a public repository deployment or leave it online.
2. Open that exact HTTPS URL once in a browser. The result should identify `fpm-fcgi` (or the provider's equivalent FPM SAPI) and report extension status.
3. Record only the PHP version, SAPI, PASS/FAIL, and missing extension names. Do not share credentials or unrelated server details.
4. Immediately delete the temporary PHP file in Plesk File Manager and verify the URL no longer serves it.

The diagnostic is intentionally temporary and unauthenticated, so it must not be left on the public host. Do not upload it until the Owner is ready to perform the short check. If the output reports a non-FPM SAPI, it did not verify the web PHP-FPM runtime.

## 4. Release sequence

1. Confirm a recoverable database backup and record its timestamp and restore owner.
2. Stage the exact reviewed API artifact and record its commit SHA.
3. Run the read-only preflight and confirm the target PHP version/extensions match the tested runtime.
4. Put the application into maintenance mode only if the release plan requires it and an outage window is approved.
5. Apply migrations only after backup verification and explicit release authorization. Never run `migrate:fresh` or destructive seeders in production.
6. Run `php artisan optimize:clear`, then rebuild Laravel caches using the approved production procedure.
7. Verify `/up`, authenticated login/logout, CSRF cookie issuance, tenant resolution, and a least-privilege Finance denial/allow test using designated non-production test identities where possible.
8. Confirm logs contain no secrets, SQL bindings, tokens, or personal data beyond approved retention.
9. Remove maintenance mode and monitor error rates, queue depth, database connections, and authentication failures.

## 5. Backup, rollback and recovery

- Keep encrypted, off-host database backups with retention and access controls.
- Before each migration, verify a recent backup and periodically perform a restore drill into an isolated database.
- Keep the previous application artifact and its exact SHA available for rollback.
- Do not assume code rollback reverses database migrations. Every migration must be assessed for backward compatibility and rollback safety.
- Prefer expand/contract migrations: deploy additive schema first, deploy compatible code, migrate data, and remove obsolete schema only in a later separately approved release.
- Define rollback triggers, decision owner, maximum recovery time, and communication channel before release.

## 6. Runtime operations

- Configure process supervision for queue workers and scheduler execution; confirm restart behavior after deployment.
- Configure log rotation, disk-space alerts, uptime checks, TLS expiry alerts, database backup alerts, and queue failure alerts.
- Health endpoint `/up` is a process-level liveness check, not proof that database, cache, mail, or all business workflows are healthy.
- Keep administrative access, database credentials, backups, and deployment secrets outside the public web root.
- Run a periodic dependency/security review and restore drill.

## 7. A5 evidence required for clearance

Attach exact commit SHA, environment preflight output (redacted of all values), PHP CLI and PHP-FPM runtime inventories, migration status, backup/restore evidence, TLS/DNS checks, CORS/CSRF browser evidence, authentication/tenant/Finance acceptance results, monitoring logs, rollback rehearsal, and Owner release authorization.

**No deployment is authorized by this document.** Production configuration, migrations, grants, release, and publication remain separate approval gates.
