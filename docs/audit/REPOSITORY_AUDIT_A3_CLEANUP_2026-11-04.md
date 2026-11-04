# ARNA API — A3 Cleanup Implementation Record

Date: 2026-11-04
Branch: audit/arna-repository-consolidation-2026-11-04
Base: main @ 48542c59132b50d7405adb827afbb897051deb11

## Retirement candidates — preserved after current-main reconciliation

The original audit branch proposed removing legacy provider and localization files. That removal set is **not applied** in the reconciled tree.

Reconciliation against current `main` at `5aaac2bb77f58140d8954dbc79611c32f95867e2` found that the current provider registry registers `LocalizationServiceProvider`, and current localization services, facade/manager classes and regression tests still reference the localization cluster. Removing those files or restoring the stale provider registry would risk breaking supported Ethiopian-calendar and localization behavior.

The following candidate paths are therefore retained pending a separate, complete runtime and reference audit:

- `app/Providers/EventServiceProvider.php`
- `mases_core/src/Providers/MasesCoreServiceProvider.php`
- `mases_core/src/Providers/LocalizationServiceProvider.php`
- `mases_core/src/Localization/EthiopianFormatter.php`
- `mases_core/src/Localization/LocaleManager.php`
- `mases_core/src/Localization/LocalizationManager.php`
- `mases_core/src/Localization/LocalizationFacade.php`
- `mases_core/src/Localization/EthiopianDateTime.php`
- `mases_core/src/Localization/EthiopianCalendar.php`
- `mases_core/src/Localization/TimeZoneManager.php`
- `mases_core/src/Middleware/LocaleMiddleware.php`

The canonical provider registry from current `main` is preserved. No localization/provider deletion is represented as completed cleanup.

## Registry documentation

No stale provider-registry rewrite is applied. The current `main` registry, including its active localization provider registration, remains authoritative.

## Preserved

- CoreServiceProvider, AppServiceProvider, AuthServiceProvider and domain providers.
- Canonical LocalizationService, LocalizationMiddleware and Localization/Calendar/EthiopianCalendar.
- EthiopianDate and active Shareholder/Governance localized-date resource integrations.
- All migrations, seeders, finance, governance, people, shareholder, wallet, loan, documents, calendar and OCR code.
- Historical docs and all unrelated branches/PRs.

## Verification status

- Source-reference/provider audit: PASS (static repository inspection).
- Runtime PHP lint/tests: NOT RUN.
- Fresh database migration: NOT RUN.
- API route/security regression: NOT RUN.
- Production deployment: NOT RUN.

This is a proposed cleanup branch, not a production release. Merge remains gated on exact-head verification.

## Dependency security remediation

- Composer audit initially identified advisories in league/commonmark and symfony/mime.
- Updated the lockfile to league/commonmark 2.10.7 and symfony/mime 7.4.22 without changing composer.json constraints.
- The post-update Composer audit reported no remaining advisories.
- The clean-install workflow now runs PHP syntax lint over every tracked PHP file, Composer audit, and the canonical Ethiopian localization regression before database and domain verification.

## A4 verification

- Exact cleanup/dependency code SHA: 1e5277d9fbfc871668f1447091215e5e6b7baed9 (later audit-record commits are documentation-only).
- Clean-install MySQL verification run 41730034005: PASS.
- PHP syntax lint: PASS.
- Composer validation and security audit: PASS; no remaining advisories.
- Canonical LocalizationService regression: PASS.
- Fresh MySQL migration and migration idempotence: PASS.
- Finance, Loan, People, Organization, Governance and Shareholder focused regression suites: PASS.
- Tenant-isolation and shareholder seed-number regressions: PASS.
- Production deployment: NOT RUN.


## Current-main reconciliation addendum

- Reconciled against API `main` SHA `5aaac2bb77f58140d8954dbc79611c32f95867e2`.
- Preserved current provider registration and all disputed localization/provider candidates; the original deletion list is superseded by this addendum.
- Combined current-main error-diagnostics workflow paths and PHP test/lint/Pint checks with the audit branch's full PHP lint, Composer security audit, and Ethiopian localization regressions.
- Removed the broad automatic merged-branch deletion job from this workflow. No unrelated branch may be deleted by this audit workflow.
- Exact-SHA CI must be rerun on the reconciled commit before PR merge.
