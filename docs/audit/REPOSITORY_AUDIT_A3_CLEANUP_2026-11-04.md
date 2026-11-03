# ARNA API — A3 Cleanup Implementation Record

Date: 2026-11-04
Branch: audit/arna-repository-consolidation-2026-11-04
Base: main @ 48542c59132b50d7405adb827afbb897051deb11

## Retired inactive code

The following files were removed from the audit branch after provider registration and repository-reference analysis:

- app/Providers/EventServiceProvider.php
- mases_core/src/Providers/MasesCoreServiceProvider.php
- mases_core/src/Providers/LocalizationServiceProvider.php
- mases_core/src/Localization/EthiopianFormatter.php
- mases_core/src/Localization/LocaleManager.php
- mases_core/src/Localization/LocalizationManager.php
- mases_core/src/Localization/LocalizationFacade.php
- mases_core/src/Localization/EthiopianDateTime.php
- mases_core/src/Localization/EthiopianCalendar.php (legacy class; distinct from Calendar/EthiopianCalendar)
- mases_core/src/Localization/TimeZoneManager.php
- mases_core/src/Middleware/LocaleMiddleware.php

These files were not registered in bootstrap/providers.php or bootstrap/app.php and had no in-repository consumers outside the inactive legacy cluster. The legacy providers referenced obsolete migration/config paths. The active CoreServiceProvider and canonical localization service/middleware/calendar were retained.

## Registry documentation

Added a dated audit disposition to bootstrap/providers.php clarifying that the previous LocalizationServiceProvider narrative is historical, not an active provider registration. The active Core provider and canonical localization stack are explicitly identified.

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

