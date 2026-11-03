# ARNA API Repository Audit — A1 Baseline

Date: 2026-11-04
Repository: akefadaka-git/arna-api
Default branch: main
Baseline commit: 48542c59132b50d7405adb827afbb897051deb11
Audit branch: audit/arna-repository-consolidation-2026-11-04

## Inventory

- Recursive Git tree complete (GitHub reports truncated=false).
- 747 tracked files.
- 526 files under mases_core/src.
- 42 open pull requests and 55 branches at the audit baseline.
- No production files or data have been changed by this audit.

## Exact duplicate blobs

1. AGENTS.md and CLAUDE.md share blob b80472fdb9e718550f48fc9d4aa849f401ea2cc2. These are agent-specific instruction entry points; retain both unless a verified supported shared-file mechanism is adopted.
2. docs/progress/daily/2026-11-03.md and docs/progress/latest.md share blob bea630a8ea352b87175d63fe652b69928283d778. They may represent a dated snapshot and a latest pointer; do not remove either until the documentation contract is confirmed.

## High-confidence legacy candidates — not yet deleted

- mases_core/src/Providers/MasesCoreServiceProvider.php is not in bootstrap/providers.php. Its own implementation loads migration directories under mases_core/src/Migrations/{Platform,Shareholder,Finance,Hr,Arna,Ai,File}, which do not match the current domain-owned migration layout. The active CoreServiceProvider is registered and owns current Core bindings/listeners. Confirm there are no external runtime references before retiring this legacy provider.
- app/Providers/EventServiceProvider.php is not registered in bootstrap/providers.php. It duplicates login/logout audit listener registration that is explicitly handled by the registered CoreServiceProvider. Confirm exact event mappings and tests before deletion.
- routes/web.php exposes the Laravel welcome view at /. This may be an intentional API-root landing route or unused scaffold; preserve pending deployment/route ownership verification.
- resources/views/welcome.blade.php and root index.html require deployment-path review before any removal.

## Confirmed maintenance debt

- ApplicationController and GET /api/launcher are connected; routes/api.php records the historical missing-route defect and its subsequent registration fix. This is not dead code.
- Some historical inline revision comments should be reconciled with current behavior only where they are demonstrably stale; do not remove provenance that explains security or migration decisions.
- Finance, Calendar, Governance, Organization, OCR, Shareholder and Documents work is represented by open PRs/branches. These are not orphaned merely because they are not on main.

## Duplicate/competing implementation groups requiring contract reconciliation

- Admin API PRs #84 and #85 both add central identity/RBAC/tenant directory APIs. They differ in permission model and tenant scoping. PR #85 uses app.admin.access plus tenant-scoped list permissions; PR #84 introduces platform.identity.read, platform.rbac.read and platform.tenancy.read. Do not merge both independently. Reconcile against the approved Admin Console security boundary and select one coherent contract.
- The related App PRs #36 and #37 both add Admin directory UI and overlap in routes. Their permission expectations differ. Reconcile with the API contract before integration.

## A1 disposition

- Baseline inventory: VERIFIED.
- Duplicate blob classification: PARTIAL; duplicates are not automatically dead code.
- Legacy provider candidates: IDENTIFIED; runtime/reference verification remains.
- Open branch/PR dependency reconciliation: IN PROGRESS.
- Deletion/cleanup: NOT RUN.
- Production deployment: NOT RUN.

## Preservation requirements

Keep unrelated branch work, historical migrations, immutable audit evidence, approved architecture boundaries, and all existing production behavior. No bulk branch deletion or mass PR closure is authorized by this audit.

## Current disposition snapshot (2026-11-04)

- Current branches: 56 (includes this isolated audit branch).
- Current open PRs: 42 (includes audit PR #86).
- Superseded historical PR #78 was closed after verifying merged successor #79; its source branch and both historical documents were retained.
- Thirteen branches without an open PR remain untouched pending ancestry/age review.
