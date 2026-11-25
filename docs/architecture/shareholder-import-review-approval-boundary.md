# Shareholder import review and approval boundary

Status: implemented as a non-persistent policy contract. No import execution or corporate-record ingestion is authorized by this document.

## Lifecycle

1. \`preview_only\` — CSV parsed and structurally validated. No source file or row is persisted.
2. \`submitted_for_review\` — a clean preview is submitted by an authenticated actor. The submission decision records submitter and row count.
3. \`review_accepted\` or \`review_rejected\` — a different authenticated actor reviews every row. Acceptance requires at least one evidence reference for every source row and a review note. Rejection requires a reason.
4. \`approved_for_import\` — a third, distinct actor approves an accepted review and supplies an approval-authority reference.

A rejected submission is terminal. Corrections require a new preview/submission revision; a rejected or approved decision must not be overwritten.

## Separation of duties

- Submitter, reviewer and approver must be three distinct authenticated users.
- Every row must be reviewed; partial review cannot be accepted.
- Accepted rows must each have one or more source-evidence references.
- Review and approval decisions require human-readable notes/references.
- The policy emits decision packets only; it does not authenticate users or establish their legal authority.

## Authorization integration requirements (not yet implemented)

The future controller/service boundary must:
- resolve tenant context server-side and scope every submission to that tenant;
- enforce separate tenant-scoped permissions for submit, review and approve through Laravel Gate/AuthorizationService;
- reject self-review and self-approval using persisted actor IDs, not client-supplied identity;
- resolve the approval-authority reference against a verified instrument/authority record;
- persist each state transition and its audit evidence atomically; fail closed if mandatory audit persistence fails;
- use optimistic locking or row locking to prevent concurrent review/approval races;
- prevent a reviewer or approver from changing the submitted payload; any changed payload requires a new preview revision and review cycle.

## Critical boundary

\`approved_for_import\` is an approval state only. It does not authorize or perform database writes. Import execution must be a separately designed and separately authorized operation, with its own permission, immutable execution record, idempotency/conflict strategy, source provenance, and rollback/correction contract.

The current implementation does not register permissions, routes, controllers, migrations, audit listeners, or a persistence model. It does not claim that legal authority or supporting documents have been verified. No real shareholder data is included or imported.
