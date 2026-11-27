# ARNA Core Namespace and Source-Directory Migration
**Document ID:** ARNA-CORE-NSM-001  
**Version:** 0.3.0-owner-approved-a1  
**Date:** 2026-11-27  
**Status:** A1 OWNER-APPROVED / FROZEN; A2 EXPANDED INVENTORY RECORDED / CLOSURE GATES OPEN; A3 NOT STARTED

## 1. Purpose and decision requested

Remove the legacy MASES product identifier from the active ARNA API Core source architecture. Replace the current source root `mases_core/src/` and PHP namespace `MaaleArna\\MASES\\Core\\` with the canonical ARNA Core identity.

## 2. Recommended canonical convention

| Concern | Current legacy convention | Proposed canonical convention |
|---|---|---|
| Core source root | `mases_core/src/` | `arna_core/src/` |
| PSR-4 namespace | `MaaleArna\\MASES\\Core\\` | `MaaleArna\\Arna\\Core\\` |
| Composer mapping | `"MaaleArna\\\\MASES\\\\Core\\\\": "mases_core/src/"` | `"MaaleArna\\\\Arna\\\\Core\\\\": "arna_core/src/"` |
| Composer package | `maale-arna/mases-api` | `maale-arna/arna-api` (proposed) |
| Core config file | `mases.php` | `arna.php` |
| Config namespace | `mases.*` | `arna.*` |
| Core publish tag | `mases-config` | `arna-config` |
| Core container aliases | `mases.*` | `arna.*` |
| Tenant helper | `mases_tenant_id()` | `arna_tenant_id()` |
| Environment identifiers | `MASES_*` | `ARNA_*` |
| Core test path | `mases_core/src` | `arna_core/src` |

**Rationale:** `MaaleArna\\Arna\\Core\\` keeps the organization/vendor namespace, makes ARNA the explicit product/platform namespace, avoids the overly generic `MaaleArna\\Core\\`, and matches the new `arna_core/src/` filesystem root. The package name `maale-arna/arna-api` aligns with the repository identity and proposed canonical branding.

## 3. Scope of the requested complete rename

The target is not a directory-only move. The migration must cover, at minimum:

1. Every PHP declaration, import, fully-qualified class string, reflection reference, policy registration and service-container binding using `MaaleArna\\MASES\\Core\\`.
2. Composer PSR-4 and files autoload entries; lockfile metadata only if Composer determines it must change.
3. Core and domain service-provider class references, provider registry and migration/config/lang/resource loading paths.
4. All source paths, test suites, PHPUnit XML, Rector configuration, static-analysis configuration, scripts and CI workflows.
5. Config filename and key prefixes, publish tags, container aliases, helper function names, environment variable names and test fixtures that currently contain MASES branding.
6. API package metadata and active project descriptions.
7. App/API integration documentation and active architecture documents that describe current paths or namespaces.
8. Deployment and hosting instructions, release packaging, Composer optimized autoload generation and OPcache/restart requirements.
9. Full-text scan of active source/config/scripts/tests/workflows to establish zero active MASES identifiers after migration.

No database table/column rename is proposed by this document. Any database identifier containing MASES must be inventoried and separately assessed for compatibility before deciding whether it is in scope.

## 4. Read-only A2 evidence collected at API main

**API main inspected:** `9c0ce83afcc084101fb14e6e55d54e1d953c2ffe` (2026-11-27 revalidation).

| Evidence path / search | Observed fact | Risk / required action |
|---|---|---|
| `composer.json` | Package is `maale-arna/mases-api`; description identifies MASES Core; PSR-4 maps `MaaleArna\\MASES\\Core\\` to `mases_core/src/`; helper autoload points to `mases_core/src/Support/Helpers.php` | Package metadata, namespace and helper path all require coordinated update |
| `bootstrap/providers.php` | Actual returned provider array registers Core, Auth, Shareholder, Policy, Wallet, Loan, Governance and Finance providers. `LocalizationServiceProvider` is not registered despite stale comments; `MasesCoreServiceProvider` is explicitly not registered. | Update the eight registered provider FQCNs and stale comments; do not activate dormant providers by assumption. |
| `mases_core/src/Providers/CoreServiceProvider.php` | Declares legacy namespace; loads `mases_core/src/Migrations`; merges `mases.php`; publishes `mases-config`; binds `mases.*` container aliases | Central bootstrap/runtime integration dependency |
| `mases_core/src/Providers/LocalizationServiceProvider.php` | Legacy namespace and references to `mases.localization`; file/class placement warning exists in source comments | Reconcile actual class path and registration before moving; do not blindly preserve or activate a stale provider |
| `phpunit.xml` | Test discovery, source coverage, helper bootstrap and AUTH_MODEL use old path/namespace; environment values include `MASES_*` | Test harness must be migrated with source |
| `rector.php`, `app/Console/Commands/MasesRectorVerifyCommand.php` | Static-analysis and verification paths/command name use MASES identifiers | Update verification tooling and command identity |
| Code search `mases_tenant_id` | 89 indexed matches across helper, policies, controllers, middleware, services and tests | High fan-out helper rename; complete call-site enumeration required |
| Code search `config('mases.` | Initial exact search returned 14 matches; broader `config mases.` search returned 47 distinct paths, including source, tests and documentation | Rename active config namespace and consumers; classify documentation/test-only references separately |
| Code search `mases.localization` | 9 indexed matches | Rename localization alias/config consumers |
| Code search `MASES_ENV` | 2 indexed matches | Rename test/runtime environment keys |
| Code search `mases-config` | 3 indexed matches | Rename publish tags and references |
| Code search for namespace | Matches found in Composer, Core/domain source, tests and docs | Search output is indexed/selective, not a full recursive count |

Additional observed references include `mases_core/src/Config/mases.php`, old `MasesCoreServiceProvider` legacy provider artifacts, `mases_*` helper conventions, source comments, and active docs. The old `MasesCoreServiceProvider` is explicitly described in provider registry documentation as legacy/unregistered due to duplicate bindings and stale migration paths; it must be assessed for references before retirement, not registered as part of the rename.

## 5. Compatibility and migration risks

- **Autoload breakage:** changing namespace and path without updating every class reference causes class-not-found failures, especially during Laravel provider boot.
- **Provider registration:** all registered providers and any dynamically referenced provider class strings must resolve under the new namespace.
- **Helper bootstrap:** Composer `autoload.files` loads the helper file; changing its path/function names requires every call site and test fixture to move together.
- **Config/container compatibility:** config keys, publish tags, facade aliases and service aliases can be consumed indirectly; all consumers must be searched.
- **Migration discovery:** Core migration path loading and any domain provider migration paths must be updated. This is a code-path rename only; do not rerun or rewrite historical migrations as part of namespace refactoring.
- **Test tooling:** PHPUnit, Rector, static-analysis, custom audit scripts and CI path filters can silently omit coverage if paths are not updated.
- **Deployment:** optimized Composer autoload files, cached Laravel config/routes/services, OPcache and long-running workers can retain old references. Deployment requires a separately approved, documented cache/worker refresh sequence.
- **External consumers:** any other repository, deployment script, package consumer or local installation importing the old PHP namespace needs a migration plan. No public compatibility alias is desired in the final architecture, but external impact must be identified before removal.

## 6. Proposed implementation sequence (not authorized)

A3 should be a single isolated refactor branch, sequenced to keep the tree bootable at each commit:

1. Freeze the exact source baseline and produce complete reference/path inventory.
2. Move `mases_core/` to `arna_core/` preserving file contents and history where possible.
3. Change Composer PSR-4 and helper autoload paths.
4. Rename namespace declarations/imports/class strings and provider registry references.
5. Rename Core config, container aliases, publish tags, helpers and environment variables, updating every consumer.
6. Update PHPUnit, Rector, scripts, static analysis, workflows and active docs.
7. Reconcile obsolete/unregistered legacy provider artifacts and stale references; delete only when evidence proves unused and tests cover the replacement.
8. Run full text scans proving zero active references to `mases_core`, `MaaleArna\\MASES\\Core`, `MASES_*`, `mases.*`, `mases_*` and other active legacy identifiers.
9. Run Composer validation/autoload dump, PHP syntax, Laravel bootstrap/provider resolution, PHPUnit suites, static analysis, route/config cache checks and relevant clean-install migration tests in a non-production environment.
10. Record exact test evidence and a rollback plan. Merge and deployment remain separately authorized stages.

## 7. Acceptance criteria

- [ ] All Core source files reside under `arna_core/src/`; no active source file remains under `mases_core/src/`.
- [ ] All Core PHP symbols use `MaaleArna\\Arna\\Core\\`; no active PHP symbol retains the old namespace.
- [ ] Composer PSR-4 and helper autoload resolve from the new root.
- [ ] Laravel provider registration, boot, config, localization, policies, events, migrations and routes resolve.
- [ ] All old MASES-specific active config keys, aliases, publish tags, helper names, env keys and scripts have ARNA replacements and zero active consumers.
- [ ] PHPUnit, Rector, static-analysis, CI and custom verification cover the new paths.
- [ ] Full repository scan classifies every remaining MASES occurrence as either removed or explicitly approved archival/historical evidence; no unexplained active occurrence remains.
- [ ] All required automated checks pass on the exact implementation SHA.
- [ ] External consumers and deployment requirements are documented.
- [ ] No database migration/schema/production data change is made as part of this code refactor.
- [ ] A4 verification is completed and reported separately; A5 deployment is separately approved.

## 8. Governance and status

This document is a draft proposal and read-only assessment. It does not freeze A1, close A2, authorize A3 code changes, authorize PR merge, or authorize A5 deployment. The user's requested outcome is captured as the target direction; the exact migration plan and any archival exceptions remain subject to Owner review.

**Current status:** A1 OWNER-APPROVED / FROZEN / A2 PARTIAL — expanded API/App inventory recorded, closure gates remain open / A3 NOT STARTED / A4 NOT RUN / A5 NOT AUTHORIZED.


## 9. Expanded A2 evidence and path inventory — 2026-11-27

The first targeted assessment has been extended with a recursive tree and cross-repository consumer review. The detailed path-by-path record is maintained separately in:

- [ARNA Core A2 reference inventory](ARNA-CORE-NSM-001-A2-REFERENCE-INVENTORY-2026-11-27.md)

Verified baselines:
- API main: `9c0ce83afcc084101fb14e6e55d54e1d953c2ffe`
- App main: `71bed2158894e3ce9cf507e8bf70492ac1f9dfd4`
- API tree: 1,046 tracked files, recursive response not truncated.
- Core tree: 642 files, including 636 PHP files.
- Test tree: 156 PHP test files (64 in Core domain tree, 92 under `tests/`).
- CI: 39 workflows, of which 27 contain old Core path/test/seeder references.
- Script inventory: two of five tracked scripts contain legacy Core namespace/path references.
- API code-search results include 89 distinct paths for `mases_tenant_id`, 47 paths for broad `config mases.`, 9 for `mases.localization`, 5 for `mases_localization`, 4 for `mases_locale`, 3 for `mases-config`, and 18 for `MasesCoreSeeder`.

The expanded inspection also found:
- Sixteen global `mases_*` functions across the Core helper file and `bootstrap/app.php`.
- Eighteen unique `MASES_*` environment variable names across Core config, app config and PHPUnit.
- Active Core localization session/cookie key `mases_locale`.
- A direct external integration consumer, `akefadaka-git/arna-app`. It does not import the PHP namespace or Composer package, but it contains four legacy browser-storage migration keys and an active `--mases-*` CSS token substrate (273 legacy token definitions and 273 ARNA aliases, 242 of which reference the legacy variables).
- The App's current brand object and build names already use ARNA; historical docs and explicit migration compatibility remain.

A2 is **not marked VERIFIED-CLOSED**. The recursive tree and targeted reference inventories are recorded, but broad namespace code search is capped at 100 results, the live Plesk environment is not accessible, and external consumers outside connected repositories/local installations cannot be verified. The App's CSS-token and browser-state compatibility scope also requires explicit A1 treatment before implementation.

**A1 DRAFT / A2 PARTIAL-OPEN / A3 NOT STARTED / A4 NOT RUN / A5 NOT AUTHORIZED.**


## 10. Owner approval — 2026-11-27

The Owner approved the complete ARNA naming scope: remove MASES from active API Core and App product identifiers, including every applicable directory/file path, namespace, function, provider, configuration key, environment variable, alias, seeder, command, script, CI reference, CSS custom property and browser-storage/session/cookie key. The App's old CSS-token and browser-storage compatibility fallbacks are included in the removal scope.

**A1 is OWNER-APPROVED / FROZEN.** Canonical names are `arna_core/src/`, `MaaleArna\\Arna\\Core\\`, Composer package `maale-arna/arna-api`, and `arna.*` configuration. Historical records and commit history remain historically accurate; active application identifiers and operational documentation are renamed. Neutral domain names are not gratuitously renamed. Database schema/data changes are outside this authorization.

A1 approval does not close A2. The full content scan, live deployment/runtime consumer assessment and unconnected external consumer review remain A2 closure gates. **A3 NOT STARTED** until A2 is completed and closed. A4 NOT RUN; A5 NOT AUTHORIZED.
