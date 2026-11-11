# ARNA Calendar current-main integration

Status: integration branch created; source reconciliation in progress.

## Canonical base

- Main commit: 1c48e5d413735fded2d438df25b3968da0065991
- Branch: feat/a3-calendar-current-main-integration

## Source lineage

- PR #71: Calendar temporal foundation and core persistence/API.
- PR #73: private-calendar visibility, lifecycle, attendee and permission integration.
- PR #75: recurrence occurrence overrides, reminder dispatch and scheduling.

These PRs are historical source branches only. They are not to be merged directly because their heads are substantially behind current main.

## Integration constraints

1. Reuse the existing EthiopianCalendar and EthiopianDate implementation. Do not introduce a second E.C. conversion engine.
2. Preserve current tenant resolution and membership isolation contracts.
3. Reconcile route additions against current routes/api.php; do not replace the file.
4. Reconcile calendar permission slugs and role grants against the current canonical permission catalogue and grant map.
5. Keep migrations tenant-scoped, ordered, idempotent where supported, and covered by the clean-install MySQL workflow.
6. Preserve calendar privacy, lifecycle, recurrence, and reminder behavior only where source code and tests demonstrate the behavior.
7. Preserve unrelated current-main changes.
8. No production database, deployment, merge, or release operation is authorized by this integration branch.

## Required verification before PR readiness

- PHP syntax and static checks.
- Calendar temporal contract tests.
- Calendar tenant isolation and HTTP authorization tests.
- Lifecycle and audit integrity tests.
- Reservation/concurrency tests using independent MySQL connections where applicable.
- Recurrence and occurrence override tests.
- Reminder idempotency, retry, and scheduling tests.
- Full clean-install MySQL workflow and migration idempotence.
- Exact-head adjacent authorization and tenant-isolation workflows.

## Closure rule

A3.3/A3.4 are not closed by the existence of historical PRs or by branch creation. Closure requires an integrated current-main-based implementation, exact-head CI evidence, and an explicit reconciliation record.