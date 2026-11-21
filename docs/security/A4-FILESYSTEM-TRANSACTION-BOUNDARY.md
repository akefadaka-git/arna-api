# A4 filesystem transaction boundary: immutable evidence snapshots

## Scope

This note records the filesystem/database atomicity boundary in the formal Documents workflow. It applies to submission-time immutable evidence capture in `DocumentWorkflowAggregateService::transition()` and `ImmutableDocumentBinaryStore::capture()`.

## Observed behavior

1. The workflow transition opens a database transaction.
2. The evidence binary is copied to a content-addressed, write-once filesystem object before the corresponding `document_file_versions` row and workflow event are committed.
3. A later database exception rolls back the workflow state, evidence metadata row and event rows.
4. The filesystem is not enlisted in the SQL transaction. A successfully captured object can therefore remain on disk without a committed database reference.

The feature regression test `test_filesystem_snapshot_can_remain_orphaned_when_submit_database_transaction_rolls_back` deliberately reserves the next workflow-event revision, causes the final event append to fail, and asserts both database rollback and the surviving immutable object.

## Security and retention implications

- This orphan is not an issued or accepted formal evidence record: without the committed tenant/file/version metadata and workflow linkage, the workflow remains unsubmitted.
- The object remains content-addressed and read-only. Do not overwrite it or infer authority from its presence.
- Do not automatically delete an object merely because a single transaction failed. An identical content-addressed object may already be referenced by another committed row or be the result of a concurrent retry.
- Production cleanup must be a separate, audited reconciliation process: enumerate candidate objects, verify that no committed evidence row references the exact disk/path/digest, apply a conservative age/grace period, recheck under a per-version lock, and record a tamper-evident deletion disposition.
- Until that reconciler and its race/concurrency tests exist, filesystem orphan cleanup is **UNRESOLVED** and production evidence-storage readiness remains blocked under A4-R02.

## Current disposition

The regression test documents the present behavior; it does not claim filesystem rollback or automatic cleanup. Database transaction atomicity and filesystem object lifecycle are distinct controls. No production storage, evidence object, key, database or deployment is changed by this test/documentation work.
