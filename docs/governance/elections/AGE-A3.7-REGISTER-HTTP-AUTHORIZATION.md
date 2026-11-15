# AGE A3.7 — register HTTP authorization and actor binding

Status: **PASS — scoped MySQL, SQLite, and HTTP suites passed** on 2026-11-15. No production role grants or voting activation are included.

## Authorization mismatch

The register endpoints had two authorization layers:

1. Route middleware required `governance.election.register.manage`.
2. The controller then called `Gate::authorize('update', $election)`, which maps to the broader `governance.election.manage` permission.

This meant the dedicated register permission alone was insufficient, despite an existing `manageRegister` policy ability. It violated least-privilege expectations and made the dedicated permission ineffective as a standalone register authorization.

## Correction

The three register controller actions now use `Gate::authorize('manageRegister', $governanceElection)`:

- prepare voter register;
- reconcile voter register;
- approve and freeze voter register.

The policy's `manageRegister` ability checks the dedicated permission and tenant scope. Route middleware continues to require the same dedicated permission. No broad election-management permission is required for these register-only actions.

No permission was granted to any production role. Production role assignment remains a separate governance decision and review.

## HTTP regression test

`test_register_http_workflow_uses_dedicated_permission_and_authenticated_separate_actors` uses synthetic tenant, user, election, and shareholding data. It verifies:

- unauthenticated request is rejected;
- broad `governance.election.manage` without the dedicated register permission is rejected;
- a register-only reconciler, without broad election permission, can reconcile;
- a distinct register-only approver can approve and freeze;
- persisted `reconciled_by` and `approved_by` match the authenticated request actors;
- a register permission in another tenant cannot cross the election tenant boundary.

The existing nomination HTTP fixture was also corrected to use the current `nominating` election status and an active canonical Person linked to its shareholder identity. This keeps the existing suite aligned with the A1 identity and lifecycle contracts.

## Exact-head verification

- Tested implementation commit: `5a99455139427f192e6dae6da7e0e46317d05de0`
- Workflow run: [#43728070159](https://github.com/akefadaka-git/arna-api/actions/runs/43728070159)
- MySQL 8.0.46 / PHP 8.3.6 / PHPUnit 11.5.56
- MySQL integration: **PASS — 7 tests, 31 assertions**
- SQLite register database regression: **PASS — 10 tests, 50 assertions**
- Governance election HTTP: **PASS — 5 tests, 22 assertions**
- All workflow steps completed successfully.

## Remaining controls

- Production role assignment review: **NOT VERIFIED**; no role grants were made.
- Full default PHP test suite: **NOT RUN**; the scoped workflow ran the three suites above.
- Independent security review: **NOT RUN**.
- Legal blockers LEG-01..LEG-06 remain unresolved.
- Electronic/hybrid voting remains disabled pending legal authority, cryptographic/security review, and operational acceptance.

No merge, deployment, production migration, role grant, voting activation, or production data changes are authorized.
