# A4 filesystem transaction boundary: durable immutable evidence capture

## Scope

This note records the filesystem/database boundary for submission-time immutable evidence capture in `DocumentWorkflowAggregateService::transition()`, `DocumentEvidenceCaptureLifecycle`, `ImmutableDocumentBinaryStore` and `DocumentEvidenceCaptureReconciler`.

## Durable capture lifecycle

1. A tenant/file-version capture intent is reserved and committed before the workflow submission transaction.
2. The source File Management row and capture intent are locked while the immutable binary is written and verified.
3. The capture intent and append-only event ledger are committed in the CAPTURED state.
4. The workflow submission transaction locks the intent, inserts or verifies the append-only `document_file_versions` record, attaches the immutable evidence identity to the workflow, and transitions the intent to LINKED in the same transaction.
5. If the workflow transaction rolls back, the evidence-version row and workflow linkage roll back, while the independently committed CAPTURED intent and immutable object remain available for retry or reconciliation.

A retry reuses and verifies the existing content-addressed object. A mismatch for the same File Management version fails closed; it cannot overwrite a sealed binary.

## Cleanup and concurrency controls

- Reconciliation considers only durable CAPTURED intents older than the configured grace period (default 24 hours; minimum one hour).
- The worker locks the intent row, rechecks its status and age, checks the authoritative tenant/file/version evidence reference, then commits a unique CLEANUP_CLAIMED token and lease before filesystem deletion.
- Workflow attachment locks the same intent row and accepts only CAPTURED or already LINKED states. If attachment wins, cleanup observes LINKED; if cleanup wins, attachment fails closed.
- Filesystem deletion uses the same per-version lock as capture and validates the exact tenant/file/version/content-addressed path, SHA-256, size, and symlink boundaries before unlinking.
- A lease-expired cleanup claim can be retried. If the object was already deleted before a worker crash, the missing-object result is treated idempotently and the durable state can be completed.
- A committed evidence reference causes the intent to be reconciled to LINKED; mismatched references, digest mismatches, and unsafe paths are quarantined, never deleted.

The append-only capture event ledger records reservation, capture, linking, cleanup claim/reclaim, deletion and quarantine dispositions. It does not store binary contents, credentials, or raw exception messages.

## Operational controls and limitations

- `arna:documents:reconcile-evidence-captures` is manually invocable and dry-run by default.
- Filesystem deletion requires the explicit `--apply` option. No automatic schedule is enabled by this change.
- The command operates only on durable capture intents. It does not scan or delete legacy/untracked evidence objects.
- Stale RESERVED intents are reported for manual recovery. They are not automatically deleted because a process may have stopped between filesystem capture and the CAPTURED state commit.
- A4 verification must include fresh MySQL migrations, forced submission rollback, storage-integrity checks, cleanup/attachment state-order tests, lease-recovery tests, and the independent-session concurrency regression.
- No production reconciliation, migration deployment, evidence deletion, merge to `main`, or deployment is authorized by this implementation.

## Current disposition

The durable intent and guarded cleanup implementation is present on the integration branch. It remains subject to exact-head A4 verification. P0.2 must remain UNRESOLVED until the required tests pass and the exact tested SHA is recorded.
