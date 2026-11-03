# ARNA API — A2 Usage and Dependency Reconciliation

Date: 2026-11-04
Baseline: main 48542c59132b50d7405adb827afbb897051deb11
Working branch: audit/arna-repository-consolidation-2026-11-04

## Confirmed inactive provider registrations

The active provider registry is bootstrap/providers.php. It registers AppServiceProvider, CoreServiceProvider, AuthServiceProvider, and domain providers. It does not register:
- app/Providers/EventServiceProvider.php
- mases_core/src/Providers/MasesCoreServiceProvider.php
- mases_core/src/Providers/LocalizationServiceProvider.php

CoreServiceProvider explicitly registers the active login/logout audit listeners, authorization listeners and canonical localization services. AuthenticationService dispatches the platform FailedLogin event consumed by CoreServiceProvider.

## Legacy localization dependency cluster

The following files form an internally connected but inactive implementation:
- mases_core/src/Providers/LocalizationServiceProvider.php
- mases_core/src/Localization/EthiopianFormatter.php
- mases_core/src/Localization/LocaleManager.php
- mases_core/src/Localization/LocalizationManager.php
- mases_core/src/Localization/LocalizationFacade.php
- mases_core/src/Localization/EthiopianDateTime.php
- mases_core/src/Localization/EthiopianCalendar.php
- mases_core/src/Localization/TimeZoneManager.php
- mases_core/src/Middleware/LocaleMiddleware.php

Reference analysis:
- LocalizationServiceProvider is not registered.
- LocaleMiddleware is not registered; bootstrap/app.php registers LocalizationMiddleware.
- LocalizationManager, LocaleManager and EthiopianFormatter are referenced only within this inactive cluster and provider.
- LocalizationFacade has no active application call site; its alias is only declared in the inactive provider.
- TimeZoneManager has no in-repository consumer.
- EthiopianDateTime is used only by the inactive formatter/manager cluster.
- The legacy EthiopianCalendar is distinct from the active canonical class at Localization/Calendar/EthiopianCalendar.php.
- The old provider refers to config/localization.php and mases_core/config paths that are not present in the current tree.
- The active LocalizationService uses Localization/Calendar/EthiopianCalendar and is consumed by Shareholder and Governance resources; it is retained.

Disposition: retire the inactive provider, middleware, facade and legacy localization implementation as one bounded deletion. Preserve the canonical LocalizationService, LocalizationMiddleware, Calendar/EthiopianCalendar, EthiopianDate and all active resource integrations.

## Legacy provider duplication

- MasesCoreServiceProvider is not registered and points to obsolete migration/config directories.
- EventServiceProvider is not registered. Its Login/Logout listener registrations duplicate CoreServiceProvider's active registrations. Its Laravel Failed event mapping is superseded by AuthenticationService's platform FailedLogin event and CoreServiceProvider listener.
- Removing these unregistered providers does not alter the current provider registry. Keep current CoreServiceProvider and AppServiceProvider.

## Frontend/API contract issue

The API route table registers GET /api/launcher inside the tenant-scoped authenticated group. The App launcher service still treats a 404 as a successful empty list and has stale comments saying the endpoint is not deployed. This masks route/configuration regressions. A3 should remove the special 404-to-empty conversion; a valid empty catalogue must be represented by HTTP 200 with an empty array.

## Preservation and safety

No migration, schema, seed data, authorization catalogue, audit history, active route, current provider, canonical localization implementation or domain code is classified as removable in this pass.

## A2 status

- Provider registration analysis: VERIFIED by source inspection.
- Legacy localization reference graph: VERIFIED by repository-wide code search; runtime compatibility remains to be tested.
- Canonical localization ownership: VERIFIED by active provider/middleware/resource imports.
- Launcher endpoint route: VERIFIED in routes/api.php.
- Cleanup decisions: bounded and documented.
- Runtime tests: NOT RUN.
- Production/deployment checks: NOT RUN.
