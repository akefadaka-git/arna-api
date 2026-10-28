# ARNA Document & Records Lifecycle — Implementation Contract

**Status:** Draft implementation contract; Owner decisions required before lifecycle mutations are enabled.
**Repository baseline:** `akefadaka-git/arna-api` main at `ccb2292e1c3374568cc3c3da923c6c103dcbab09`.
**Implementation branch:** `feat/document-records-lifecycle-approval`.
**Scope:** Tenant-scoped formal document records, approval history, controlled publication, and links to the existing centralized file store. This is not a second file-storage subsystem.

## 1. Existing capability confirmed

The current API already provides:
- Central `files` metadata with tenant/user ownership, storage disk/path, MIME type, byte size, localized display names, status, public/private flag, soft deletion, and parent/version relationships.
- File upload, list, metadata read/update, download, soft delete, restore, force-delete, version history, and latest-version routes under authenticated tenant membership.
- File authorization and audit hooks, plus a `FileSecurityTest` suite covering baseline file/tenant security.
- Person-to-file association through `person_document_links`.

The current file layer is a storage/metadata service, not a formal records-management workflow. A file's `published` default does not establish review or approval. The file table does not itself represent document type, official document number, approval authority, approval decisions, publication authorization, retention policy, or an immutable business lifecycle history.

## 2. Required boundary

- `files` remains the canonical binary-storage and version metadata layer.
- A formal document record references a file; it does not copy file bytes or create a second storage path.
- Every document record and approval action is tenant-scoped using validated tenant context. Missing tenant context fails closed.
- Every state-changing action requires an explicit server-side permission and a reason where stated below.
- The frontend may hide actions based on permissions but never grants authority.
- Approval and publication must be separate transitions. A successful upload is not approval, and approval is not automatically public publication.
- Existing People and Governance links remain associations to the same file/document records; neither domain creates an independent document store.
- No production migrations, backfills, deployments, merges, or public releases are included.

## 3. Proposed lifecycle (requires Owner confirmation)

`draft → in_review → approved → published → archived`

Rejection path: `in_review → rejected → draft` after the author makes corrections and resubmits.
Cancellation/archive must be reasoned and audited. Published/approved records must not be hard-deleted through ordinary workflow operations.

Proposed controls:
1. Only an authorized creator/editor can modify a draft.
2. Submit-for-review freezes the submitted revision; edits after submission require a controlled return-to-draft transition.
3. An approver cannot approve a document they authored or last substantively edited (separation of duties).
4. Approval records capture tenant, document, exact file/version, actor, action, reason, and timestamp.
5. Publishing requires a separate `document.record.publish` permission and may only occur after approval.
6. Public visibility is opt-in and only permitted after approval plus publication; confidential/restricted records cannot be made public.
7. Each transition is transactional and writes an append-only decision record.
8. Any future multi-step approval route must be explicitly configured by document type and authority; no implicit role or organizational hierarchy inference.

## 4. Owner decisions required before production lifecycle activation

1. **Document taxonomy:** confirm initial types (e.g. official letter, announcement, memo, legal instrument, policy, certificate, meeting record, report).
2. **Approval authority:** define who may approve each type, whether one or multiple sequential approvals are required, and whether acting/delegated authority may approve.
3. **Separation of duties:** confirm whether self-approval is always prohibited and whether emergency exception handling exists.
4. **Numbering:** choose tenant-scoped document numbering format and whether numbers are allocated at draft creation or at submission/approval.
5. **Publication:** identify which types may be public and whether public publication requires a distinct final approver.
6. **Retention and legal hold:** define retention schedule, archival semantics, legal hold, and whether any record may ever be destroyed.
7. **Confidentiality levels:** confirm permitted labels and who can access each level.

Until those decisions are approved, code may implement persistence and invariant tests, but it must not assume a universal approval matrix or expose a permissive default approver.

## 5. Required schema and API contract

Candidate schema:
- `document_records`: tenant, linked `file_id`, type, localized title, optional document number, confidentiality, lifecycle status, creator/editor, lifecycle timestamps, and optimistic-lock/version field.
- `document_record_actions`: append-only transition history with actor, prior/new state, reason, linked file version, request ID, and timestamp.
- A separate approval-route/step table only after the authority matrix is approved. Do not encode approvers in unvalidated JSON or infer them from unrelated domain tables.

Candidate tenant-scoped endpoints:
- `GET /api/documents` — paginated/filterable list.
- `POST /api/documents` — create a formal record linked to an existing tenant file.
- `GET /api/documents/{id}` — record and authorized file metadata.
- `PATCH /api/documents/{id}` — draft-only metadata edit with optimistic concurrency.
- `POST /api/documents/{id}/submit` — submit an immutable revision for review.
- `POST /api/documents/{id}/approve` and `/reject` — only once approval authority is explicitly resolved.
- `POST /api/documents/{id}/publish` — approved records only, with explicit publication permission.
- `POST /api/documents/{id}/archive` — reason required, audited, no destructive deletion.
- `GET /api/documents/{id}/history` — permission-filtered lifecycle history.

## 6. Acceptance tests required

- Tenant A cannot enumerate, read, transition, approve, download, or infer Tenant B records.
- Missing, inactive, or conflicting tenant context fails closed.
- A file from another tenant or a soft-deleted file cannot be attached.
- Draft-only editing and valid lifecycle transitions are enforced server-side.
- Rejection and archival require a non-trivial reason.
- Unauthorized actors cannot approve or publish.
- Self-approval is rejected if Owner confirms separation of duties.
- Approval targets the exact submitted file version; a version change invalidates stale approval.
- Concurrent duplicate transitions do not produce duplicate actions or skip states.
- Every state transition and decision has an immutable audit entry.
- Public downloads cannot expose drafts, rejected, confidential, or unpublished documents.
- MySQL migration discovery, rollback safety, tenant isolation, and concurrency behavior are verified on the exact candidate SHA before merge.

## 7. Current implementation disposition

- File storage layer: **EXISTS**.
- Version metadata and file-level lifecycle labels: **EXISTS**, but not formal approval semantics.
- Formal document-record entity: **MISSING in inspected source tree**.
- Document approval/action history: **MISSING in inspected source tree**.
- Generic notification database/API: **MISSING in inspected source tree**; the app store is explicitly a client-side proposal pending backend endpoints.
- Accounting general ledger: **MISSING in inspected source paths**; Loan/Wallet are domain subledgers and not a replacement.
- Production readiness: **NOT VERIFIED**. This contract is not a release approval.
