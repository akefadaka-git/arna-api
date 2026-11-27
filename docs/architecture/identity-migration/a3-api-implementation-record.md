# ARNA API Core Identity Migration — A3 Implementation Record

**Status:** IMPLEMENTED ON ISOLATED BRANCH — A4 NOT RUN  
**Repository:** `akefadaka-git/arna-api`  
**PR:** https://github.com/akefadaka-git/arna-api/pull/244  
**Branch:** `a3/arna-core-identity-migration`  
**Frozen base:** `9c0ce83afcc084101fb14e6e55d54e1d953c2ffe`  
**A3 head:** `176617f5e8d3f7d8d656e3840fea401a362aa592`

## Scope delivered

- Moved 642 Core files from `mases_core/src/` to `arna_core/src/`.
- Migrated PHP namespaces to `MaaleArna\\Arna\\Core\\`.
- Updated Composer package/autoload declarations, configuration naming, container aliases, publish tags, environment names, helpers, seeder, command, factory path, localization key, PHPUnit/Rector configuration, scripts, documentation and 27 affected CI workflow files.
- Renamed the dormant Core service provider and kept it unregistered.
- Kept the dormant localization provider/cluster unregistered.
- Manually corrected standalone legacy config aliases and resource/migration/view paths in the Core service providers.
- Removed the temporary migration workflow from the final branch tree.

## Verification evidence

- Static residual scan: **PASS**.
- `git diff --check`: **PASS**.
- CI workflow legacy-name recheck: **PASS** (zero old-name matches).
- PHP lint, Composer validation, PHPUnit, static analysis and clean-install tests: **NOT RUN — A4**.

## Boundaries

No database schema/data changes, migration execution, migration-history changes, PR merge, main-branch modification or production deployment were performed. Historical records remain governed by the A2 reconciliation rules. The branch is isolated and the PR remains draft/open/unmerged.

## Next gate

Run A4 against the exact head SHA above. A4 must complete before any merge decision. A5/deployment remains a separate Owner-controlled stage.
