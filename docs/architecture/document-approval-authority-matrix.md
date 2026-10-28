# ARNA Document Approval Authority Matrix — Decision Register

- **Document ID:** ARNA-DOC-AUTH-001
- **Status:** Control matrix established; business authority assignments remain blocked where no approved source instrument is evidenced.
- **Baseline:** `main` at `e853874ee41130d47be07d2ffc097a41ecb69a9b`
- **Branch:** `docs/document-approval-authority-matrix`
- **Purpose:** Prevent document approval, signing, or publication from being assigned to the wrong office, role, or delegated actor.
- **Scope:** Formal documents linked to the existing Core `files` store; not a second binary store.

## 1. Findings that constrain this matrix

1. The Core file layer supports file upload, listing, metadata update, download, soft delete/restore, and version history. It does not itself prove formal business approval.
2. Governance configuration defines document categories including meeting notices, agendas, minutes, resolutions, appointment letters, committee mandates, election records, declarations, action evidence, and governance reports.
3. Governance confidentiality vocabulary includes `public`, `restricted`, `confidential`, and `secret`; access requires both RBAC permission and record-level confidentiality authorization.
4. The implemented Governance authority-grant mechanism is narrow. Its documented actions are appointment management and other-member membership management. It expressly excludes financial approvals, and cannot be treated as a generic document-approval delegation engine.
5. Governance design records some approval authorities as business decisions requiring source instruments. In particular, the source does not establish a universal approver for governance minutes, all resolutions, declarations, or every document category.
6. Therefore, this register separates **safe workflow controls** from **business authority assignments**. It does not fabricate a role-to-document approval map.

## 2. Enforced default-deny rule

A document type may enter the approval workflow, but **approval, signing, and publication remain disabled for that type until an authority rule is recorded with its source instrument and approved by the Owner or competent governance body**.

A user role, department title, uploader identity, document creator, file status, or UI visibility is not sufficient evidence of approval authority. Existing RBAC is necessary but not by itself sufficient when the action requires a legal/organizational authority grant.

For each approved rule, the authorization service must validate:
- tenant and document-type scope;
- canonical active Person/account;
- current office/position/membership and effective dates, where applicable;
- a current appointment or explicit valid delegation whose action and scope match this exact transition;
- any required governing-body resolution, quorum, voting/adoption evidence, amount/value limits, and effective date;
- separation of duties and conflict-of-interest restrictions;
- confidentiality authorization independently of workflow permission;
- publication authority separately from approval authority.

Missing, conflicting, expired, revoked, or untraceable evidence means **DENY** and an auditable unresolved result.

## 3. Document-type authority matrix

The “authority source required” column identifies evidence needed to resolve the rule; it does not pre-assign that authority.

| Document category/type | Prepare / own | Approval authority | Publication / release authority | Status |
|---|---|---|---|---|
| Official outgoing letter | Authorized drafter/owning unit, after its mandate is verified | UNRESOLVED — approved signing/approval delegation or governing policy required | UNRESOLVED — explicit release permission and audience required | BLOCKED |
| Internal memo | Authorized drafter/owning unit | UNRESOLVED — internal delegation/policy required | UNRESOLVED — distribution scope and confidentiality policy required | BLOCKED |
| Public announcement | Authorized drafter/owning unit | UNRESOLVED — communications policy and accountable authority required | UNRESOLVED — distinct release authority required; public status must not be inferred | BLOCKED |
| Policy / procedure | Authorized policy owner | UNRESOLVED — competent approving body and required resolution/version rule required | UNRESOLVED — publication authority and effective-date rule required | BLOCKED |
| Legal instrument / contract | Authorized legal/business owner after mandate is verified | UNRESOLVED — signing mandate, counterparty/execution rules, and any reserved-matter approval required | UNRESOLVED — controlled distribution; no public release by default | BLOCKED |
| Certificate | Authorized registrar/issuer after issuer mandate is verified | UNRESOLVED — issuance authority and evidence required | UNRESOLVED — recipient/revocation and verification policy required | BLOCKED |
| Governance meeting notice / agenda | Authorized secretary or body office only if its mandate is verified | UNRESOLVED — applicable body's rules and notice authority required | UNRESOLVED — recipients, confidentiality, and release timing required | BLOCKED |
| Governance minutes | Authorized minute preparer/secretary, if designated | UNRESOLVED — existing Governance design identifies this as a business decision; governing body's adoption rule must be sourced | UNRESOLVED — approved record publication/distribution rule required | BLOCKED |
| Governance resolution / decision | Authorized recorder after a valid decision process | UNRESOLVED — must be supported by adopted resolution/decision, competent body, quorum/vote and signed record as applicable | UNRESOLVED — effectivity and disclosure rule required | BLOCKED |
| Appointment letter / committee mandate | Authorized Governance/People preparer, based on a valid appointment/mandate record | UNRESOLVED — source appointment/mandate authority and instrument requirements required; do not treat letter approval as authority to create the underlying appointment | UNRESOLVED — issue/delivery scope required | BLOCKED |
| Election record / declaration | Authorized recorder | UNRESOLVED — certification/review authority and access rules must be sourced | UNRESOLVED — disclosure depends on record type and confidentiality policy | BLOCKED |
| Financial report / journal support / financial statement | Finance preparer only after Finance role and entity scope are approved | UNRESOLVED — Finance approval, posting, close, and statement sign-off matrix must be separately approved; Governance grants do not authorize it | UNRESOLVED — financial disclosure/export authority and legal-entity scope required | BLOCKED |
| Operational report / performance plan | Authorized domain owner, once data ownership is verified | UNRESOLVED — report owner and management review policy required | UNRESOLVED — audience, confidentiality and release policy required | BLOCKED |

**Interpretation:** the preparer column describes a constrained candidate workflow role, not a new permission grant. The matrix intentionally assigns no actual approver, signer, or publisher until the authoritative source has been inspected and approved.

## 4. Evidence record required to activate any matrix row

Each row must be backed by a versioned authority-rule record containing:
- unique rule ID and version;
- tenant and document type/category;
- action(s): approve, sign, publish, archive, certify, or issue — each is separate;
- authoritative source type and exact source reference (Articles/bylaws, Board/General Assembly resolution, approved policy, or explicit delegation);
- approving body/office/position and required sequence or quorum, if applicable;
- permitted acting/delegated authority, scope, non-delegability constraints, and effective dates;
- separation-of-duties and conflict-of-interest rules;
- confidentiality classification and eligible audience;
- effective-from/until dates, superseding rule reference, Owner/competent-body acceptance evidence, and audit provenance.

A free-form role slug, unvalidated JSON approver list, or UI setting without source evidence is not an acceptable authority record.

## 5. Safe lifecycle contract pending authority resolution

Recommended lifecycle:
`draft → in_review → approved → published → archived`
Rejection returns a record to controlled revision; it must not erase prior decisions.

Controls that can be designed independently of business-role assignments:
1. Approval and publication are distinct transitions.
2. The submitted file/version is immutable for the decision; a new version invalidates pending approval and requires resubmission.
3. Record and action history are tenant-scoped; actions are append-only and include actor, action, reason, source version, request/correlation ID and timestamp.
4. Draft editing, submit, approve, reject, sign, publish, archive, retention hold and destructive disposal are separate permissions.
5. Self-approval is denied as the safe default unless a formally approved policy defines a narrowly scoped exception; no exception is enabled by this document.
6. Published/approved records are not hard-deleted by ordinary workflow operations. Legal hold and retention policy must be enforced before any authorized disposal.
7. Public exposure is denied unless both the document type is approved for public release and the actor has the separate publication authority.
8. Every state change is transactional and guarded against stale revision/concurrent duplicate requests.
9. Confidentiality restrictions are evaluated in addition to workflow permissions.
10. No fallback approver, implicit hierarchy inheritance, or “tenant admin can approve everything” rule.

## 6. Owner / governance decisions required

| ID | Decision | Evidence required | Current state |
|---|---|---|---|
| DOC-A01 | Initial canonical document taxonomy and mapping from Governance categories | Approved taxonomy and category owner | PARTIAL: Governance vocabulary exists; enterprise-wide taxonomy unresolved |
| DOC-A02 | Competent authority for each document type and action | Articles/bylaws, approved Board/General Assembly resolution, or policy | UNRESOLVED |
| DOC-A03 | Whether approval is one-step, sequential, committee/quorum-based, or multi-signature | Approved authority matrix and voting/meeting rules | UNRESOLVED |
| DOC-A04 | Acting/delegated authority eligibility and exclusions for document actions | Approved delegation policy; existing grant service does not cover document approvals | UNRESOLVED |
| DOC-A05 | Self-approval and conflict-of-interest policy | Approved policy; proposed default is deny | DEFAULT-DENY PROPOSED |
| DOC-A06 | Numbering, reservation, cancellation, and non-reuse rules | Approved numbering policy | UNRESOLVED |
| DOC-A07 | Which types can be public and who may release them | Publication/disclosure policy | UNRESOLVED |
| DOC-A08 | Confidentiality audience per level | Approved access classification policy | PARTIAL: labels exist; complete audiences not verified |
| DOC-A09 | Retention, archival, legal hold, and lawful disposal | Retention schedule and legal policy | UNRESOLVED |
| DOC-A10 | Signing method, signature evidence, and execution date semantics | Approved signing/execution policy | UNRESOLVED |
| DOC-A11 | Canonical authority source and controlled policy/version registry | Approved source-of-truth decision | UNRESOLVED |

## 7. Implementation gate

**A1:** this control matrix and unresolved decision register are ready for Owner/governance review.

**A2:** collect and inspect authoritative organizational/legal instruments and the current Governance authority/appointment/delegation records. Build a source-cited row for each document type; no role assignments by inference.

**A3:** only after the authority matrix is approved, implement document-type rules, permission checks, and persisted approval-route snapshots. Keep file bytes/version metadata in Core `files`; do not create another file store.

**A4:** verify tenant isolation, inactive/expired/revoked authority denial, exact submitted-version binding, separation of duties, quorum/sequence where required, confidentiality, publication gating, append-only audit, and concurrent idempotency on the exact candidate SHA.

**A5:** production migration/deployment and any public release require separate authorization.

**Current disposition:** business approval authority is **not yet resolved** because the necessary authoritative instruments are not evidenced in the inspected source. The safe matrix is resolved to **deny until evidence-backed authority rules are approved**. No approval or publication rights are granted by this document.