# Shareholder import review and approval boundary

Status: persistent tenant-scoped review workflow implemented. Import execution remains out of scope and is not authorized by this contract.

## Lifecycle

1. `preview_only` — CSV is parsed and structurally validated. The preview adapter does not persist the source file or create shareholder rows.
2. `submitted_for_review` — a clean preview is persisted as a tenant-scoped submission. The validated preview payload, row count, source references and SHA-256 payload digest are retained; the uploaded source file is not.
3. `review_accepted` or `review_rejected` — a different authenticated tenant member reviews every row. Acceptance requires at least one evidence reference for every source row and a review note. Rejection requires an explanation.
4. `approved_for_import` — a third distinct authenticated tenant member approves an accepted review and records an approval-authority reference and note.

A rejected submission is terminal. Corrections require a new preview/submission; prior decisions are not overwritten.

## Persistence and integrity

- `shareholder_import_submissions` stores the current lifecycle state, tenant, actors, version, validated preview payload, source references, evidence and payload digest.
- `shareholder_import_decisions` stores an append-only sequence of submission, review and approval decisions. Each decision captures the tenant, actor, transition, source references, evidence and the same payload digest.
- Decision rows reject Eloquent update/delete operations. The service never updates or deletes a decision row.
- Each transition uses a database transaction, locks the submission row, checks the expected version, updates the current state, appends a decision and writes a centralized `audit_logs` row.
- Audit persistence is mandatory for this service: it writes `AuditLog` directly inside the transaction rather than using the legacy fail-open `AuditService`. If the audit insert fails, the state transition and decision insert roll back.
- Tenant scope is resolved by the caller from trusted middleware context. The service independently requires an active tenant, active actor and active `tenant_memberships` row, then enforces the stage-specific permission through `AuthorizationService`.

## Separation of duties and permissions

- Submitter, reviewer and approver must be three distinct user IDs.
- Stage permissions are separate: `shareholder.import.submit`, `shareholder.import.review`, and `shareholder.import.approve`.
- These permissions are registered in the canonical permission catalogue. No role grants are added by this change; access is denied until an authorized administrator assigns the relevant permission.
- Every row must be reviewed; partial review cannot be accepted.
- Accepted rows must each have one or more evidence references.
- The authority reference is structurally required and stored, but this service does not resolve it against an authoritative instrument repository or determine legal validity.
- A reviewer or approver cannot alter the submitted payload. The digest binds every decision to the submitted preview snapshot.

## Critical boundary

`approved_for_import` is an approval state only. It does not authorize or perform shareholder creation, share allocation, subscription recording, payment posting or any database import writes. Import execution requires a separately designed and separately authorized operation, with its own permission, immutable execution record, idempotency/conflict strategy, source provenance, and rollback/correction contract.

No routes, controllers, upload storage, real corporate records, shareholder data, or import execution are added in this increment. The persistence service is an application-layer contract for a future API boundary; callers must pass tenant context established by trusted middleware, not a client-supplied tenant selector.
