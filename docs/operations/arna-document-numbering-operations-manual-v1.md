# የዓርና የሰነድ ቁጥር አሰጣጥ እና መዝገብ አስተዳደር የአሠራር መመሪያ
## ARNA Document Numbering & Registration Operations Manual v1.0

**Document class:** Controlled internal operations manual  
**Policy authority:** ARNA Owner and formally delegated governance authority  
**Language:** Amharic-first; English labels are explanatory  
**Status:** Documentation only; implementation and operational activation pending  
**Related standard:** [ARNA Amharic Document Numbering Standard v1.0](../architecture/arna-amharic-document-numbering-standard-v1.md)

> **Important:** This manual defines the intended operating procedure. It does not establish that the new number format, registries, screens, permissions, QR verification or reports are implemented. Do not use it to issue production numbers until implementation is separately approved and verified.

## 1. Purpose and authority

This manual establishes controls for creating, registering, numbering, issuing, receiving, tracking, correcting, voiding, revoking, archiving and auditing ARNA-controlled documents.

Principles:
1. One authoritative number per officially issued ARNA document.
2. Amharic-first presentation and numeric variable segments.
3. Number assignment only at successful official issuance.
4. Independent counters by tenant, legal entity, issuing unit, document type and full Ethiopian Calendar year.
5. No number reuse, deletion, silent replacement or retrospective renumbering.
6. Separation of duties among creator, reviewer, approver, issuer and records custodian as required by the approved authority matrix.
7. Tenant isolation and least-privilege access at every operation.
8. Append-only evidence for issuance and lifecycle decisions.
9. Incoming sender references remain separate from ARNA-assigned references.
10. Applicable laws, regulator requirements, tax rules, court rules, financial controls and agreements prevail where they impose additional requirements.

This is an ARNA internal standard. It does not claim that the exact ARNA syntax is prescribed by Ethiopian national legislation or is a government-wide format. Legal or regulatory compliance requires review against the current authoritative instrument applicable to the record class and entity.

## 2. Numbering standard at a glance

Canonical format: **ዓርና/BB/TT/SSSS/YY**

Example: **ዓርና/11/03/0001/19**

| Part | Rule | Meaning |
|---|---|---|
| ዓርና | Fixed Amharic literal | ARNA identity |
| BB | Exactly two digits | First digit branch; second digit department |
| TT | Exactly two digits | Controlled document-type code |
| SSSS | Exactly four digits | Sequence, 0001–9999 |
| YY | Exactly two digits | Last two digits of full Ethiopian year |

Slash is the only segment delimiter. The canonical identifier must not contain spaces, Latin letters, alternative punctuation or localized digit substitutions. Amharic labels and explanatory text are used on the document. The canonical ID remains stable across screen, PDF, print, search, export and verification.

The full Ethiopian year (for example, 2019) is stored separately. The visible suffix 19 is not sufficient by itself to identify the century or full date.

## 3. Manual A — Branch and department registration

**Owner:** Organization domain.

The Organization domain is the source of truth for branches, departments and organizational-unit identity. Documents consumes validated registry identifiers and snapshots; it does not create a parallel organization catalogue.

The approved BB segment has exactly two digits:
- Position 1: branch number 1–9.
- Position 2: department number 1–9 within that branch.

Examples: 11, 12, 21, 23.

Assignment procedure:
1. An authorized Organization administrator proposes a branch or department.
2. Record its official Amharic name, parent branch, legal entity, tenant, effective date, status and accountable manager.
3. Validate that the code is unused and resolves to exactly one unit.
4. Obtain authorized approval.
5. Record the code, effective dates and immutable history.
6. Permit issuance only while the unit and code are active.

Do not infer codes from names, accept caller-supplied free text, reuse retired codes, silently change historical snapshots, or issue under inactive/ambiguous units.

**Capacity:** The fixed design permits nine branches and nine departments per branch. Zero is invalid. Exceeding capacity requires an Owner-approved, versioned format revision. Never silently widen BB.

## 4. Manual B — Document-type catalogue administration

**Owner:** Documents domain.

The TT segment is a controlled numeric code, not a free-form label. Each catalogue entry must define:
- Two-digit code;
- Amharic name and optional secondary-language name;
- Definition, purpose and inclusion/exclusion rules;
- Permitted direction: incoming registration, outgoing issuance, or both;
- Required metadata and attachments;
- Creator, reviewer, approver and issuer authority actions;
- Approval and publication requirements;
- Confidentiality defaults and permitted levels;
- Retention category and archival trigger;
- External legal/financial numbering implications;
- Effective dates, status, owner and approval history.

Procedure for a new type:
1. Submit a documented request.
2. Check for an equivalent existing type.
3. Define its authority matrix, lifecycle, retention and regulatory implications.
4. Obtain Owner/governance approval.
5. Assign an unused numeric code.
6. Activate on a stated effective date.
7. Preserve the code permanently for historical interpretation.

A used code must never be reassigned. Retired types remain resolvable for historical records. Code 03 in the sample number is illustrative; it is not an approved type mapping until the controlled catalogue is approved.

## 5. Manual C — Number allocation and counter management

The independent counter scope is exactly:
- Tenant;
- Legal entity;
- Issuing Organization unit (BB);
- Document type (TT);
- Full Ethiopian Calendar year.

Governance position and individual issuer are authority metadata, not counter dimensions. A change of incumbent or acting issuer does not reset the unit/type/year sequence.

Counter rules:
- First issued number in a new scope is 0001.
- Each successful issuance advances by one.
- 9999 is the maximum.
- Counter allocation is atomic with document creation and issuance-event persistence.
- A unique scope key and proven database concurrency control are required.
- Never calculate a sequence using an unprotected MAX(sequence)+1.
- A failed transaction rolls back document, event and counter advancement together.
- At 9999, issuance fails closed; no wraparound, truncation or reuse.

Every issue command requires a tenant-scoped idempotency key:
- Same key and same canonical payload: return the original persisted issuance.
- Same key and different payload: reject as conflict.
- Retry must never allocate a second number for one successful request.

No manual spreadsheet or paper register may independently allocate official numbers while the electronic counter is active. During an outage, provisional references may be recorded only under the contingency procedure; they are not official ARNA numbers. Preallocated number blocks require a separate Owner-approved design with custody, reconciliation and unused-number controls.

## 6. Manual D — Outgoing document issuance

Before issue, verify:
- Correct tenant and legal entity;
- Active and correct issuing unit;
- Active document type and valid effective date;
- Correct final file/version and tenant ownership;
- Required approvals and separation-of-duties checks;
- Current type-specific Governance authority and tenant-scoped permission;
- Required recipient, subject, date, attachments and confidentiality metadata;
- Any applicable Finance, tax, legal or regulator identifier separately recorded.

Issuance procedure:
1. Author creates a draft without an official number.
2. Draft is submitted for review.
3. Reviewer checks content, classification, recipient, entity, authority and attachments.
4. Required approver(s) approve the exact submitted version.
5. Authorized issuer invokes the issue action.
6. Server captures one issuance timestamp in Africa/Addis_Ababa.
7. Server derives the full Ethiopian year using the approved calendar service.
8. Server revalidates tenant, entity, unit, type, issuer and authority inside the transaction.
9. Server atomically advances the correct counter.
10. Server formats ዓርና/BB/TT/SSSS/YY and stores the full structured values and immutable document UUID.
11. Document, counter advancement and issuance event commit atomically.
12. System returns the number and official issue timestamp.
13. Final rendered document shows the Amharic number label and full Ethiopian date.
14. Dispatch and delivery evidence is recorded separately.

If any check fails, no number is issued. An approved but not yet issued document must not be represented as issued.

## 7. Manual E — Incoming correspondence registration

Incoming correspondence is not an ARNA-issued letter. Preserve the sender's reference exactly as received.

At intake:
1. Record received date/time using Ethiopia local time and Ethiopian-calendar display.
2. Record sender, sender organization, recipient office, subject, channel and received-by officer.
3. Capture sender reference exactly, including punctuation and language.
4. Preserve the received original, envelope or delivery evidence where applicable.
5. Assign an internal incoming-registration reference only through a separately approved incoming-register procedure.
6. Route to the responsible unit and record custody/assignment history.
7. Apply confidentiality, access and retention classification.

Do not place the sender's number in the outgoing ARNA-issued-number field or rewrite it to fit ARNA syntax. If the incoming-reference format is not approved/implemented, retain a separate internal record ID; do not invent a number pattern.

A response is a new outgoing document and receives its own ARNA number linked to the incoming record.

## 8. Manual F — Internal memoranda and controlled records

Formal internal documents receive the same canonical ARNA number at official issuance. The issuing unit owns the record; TT identifies its approved category.

Draft notes, working copies, chat messages and informal files do not receive official numbers unless an approved records classification requires registration. Authors cannot choose an arbitrary type code.

## 9. Manual G — Corrections, voids, revocations and supersession

**Before issuance:** Draft edits follow draft permissions and version controls. No official number has yet been allocated.

**After issuance:** Never overwrite the issued artifact or mutate its number. Create a controlled corrected/amended version or a new linked document under the applicable type policy. Preserve original number, timestamp, file/version, correction reason, requester, approvals, new record identity and relationships.

**Void:** A controlled declaration that an issued record should no longer be relied upon. Requires authorized reason and approval. The number remains permanently reserved.

**Revoke:** Withdraws continuing validity/effect. It does not delete the record, recycle the number or erase issuance history. Record reason, effective timestamp, actor and authority.

**Supersede:** Replacement is a new record with a new number. Link old and new records in both directions. Do not overwrite either.

**Cancel before issuance:** A draft/pending request may be cancelled under workflow rules. Since no official number was allocated, no number is consumed. Preserve the cancellation event.

## 10. Manual H — Ethiopian Calendar and time

- Server captures one authoritative issuance instant.
- Business time zone is Africa/Addis_Ababa.
- Approved Ethiopian Calendar service derives the full Ethiopian year/date.
- Client cannot submit or override the authoritative year.
- Store a timezone-aware timestamp and full Ethiopian year/date fields.
- Display the full Ethiopian date on the document; the number suffix displays only the final two year digits.
- Never derive the Ethiopian year by subtracting a fixed number from Gregorian year.
- Year-boundary issuance uses the same captured instant for display and counter scope.
- Verify Ethiopian New Year and leap-year behavior before activation.

## 11. Manual I — Authority, access and separation of duties

The visible number does not confer authority.

- Finance owns legal-entity identity.
- Organization owns branch/department units.
- Governance owns positions, grants and acting assignments.
- Documents owns type catalogue, counters and issuance lifecycle.
- Platform identity/security owns tenant membership and RBAC evaluation.

Fail closed for missing tenant context, ambiguous registry matches, inactive units, revoked grants, expired acting assignments or mismatched entity. Acting issuers require explicit effective authority for the exact document action; no implicit inheritance.

Permissions should be distinct for create/edit, submit, review, approve, issue, view, incoming registration, void, revoke, publish, catalogue administration and audit/export. High-risk actions require reason capture and append-only audit. An administrator role alone does not confer issuing authority.

Separation-of-duties requirements are defined per type. The approved matrix should prevent an author from approving their own document where required. Emergency exceptions require explicit approval, reason and audit.

## 12. Manual J — Confidentiality, publication and QR verification

- Public visibility is opt-in and separate from issuance/approval.
- Private/restricted documents resolve through authenticated routes and re-check tenant membership and current permissions.
- Public QR verification discloses only an approved minimal verification projection.
- QR codes never encode raw storage paths, private file URLs, credentials or sensitive personal data.
- A signed URL protects URL/query integrity; it does not prove legal authorship or constitute a legally recognized digital signature.
- Verification checks current publication and revocation state, not stale cached status.
- Strong byte integrity requires a server-controlled SHA-256 digest for the exact issued file version.
- QR verification and public download are independently permissioned, rate-limited and audited.
- Private QR may lead to login, but authorization is still required after authentication.

Public verification, QR rendering, cryptographic signatures and SHA-256 verification must not be represented as available until separately implemented and verified.

## 13. Manual K — Retention, archive, legal hold and disposal

This manual defines minimum controls, not retention durations. Exact periods must be approved in an ARNA retention schedule and reconciled with applicable Ethiopian legal, tax, employment, corporate, financial, court and sector requirements.

Minimum controls:
1. Preserve issued record, number, final file/version and lifecycle history together.
2. Assign retention category and trigger date by document type.
3. Keep records readable, searchable and exportable.
4. Link original and amended versions.
5. Apply legal hold when litigation, investigation, audit, dispute or regulatory inquiry is anticipated or notified.
6. Suspend routine destruction while legal hold is active.
7. Require authorized approval, schedule eligibility and destruction certificate before permitted disposal.
8. Do not hard-delete issued records through ordinary operations.
9. Preserve metadata and audit history according to the approved schedule.
10. Record archive transfer, custody, access and authorized disposal.

No retention duration or disposal authority is invented here. The schedule and accountable custodian must be approved before disposal is enabled.

## 14. Manual L — Registers, search, reports and reconciliation

Permission-filtered registers should cover:
- Issued outgoing documents;
- Incoming correspondence;
- Internal controlled documents;
- Draft/pending issuance;
- Voided, revoked and superseded records;
- Counter scopes and utilization;
- Type catalogue and organization-code history;
- Access, verification and lifecycle audit events.

Search should support exact number, full E.C. year, unit, type, subject, sender/recipient, issuer, status, legal entity and date range, subject to authorization.

Periodic reconciliation verifies:
- Every issued document has one canonical number and issuance event.
- Counter values agree with committed allocations and retained void/revoked numbers.
- No duplicate exists within a tenant.
- No number is silently reused or gap-filled.
- Full E.C. year matches the captured timestamp.
- Every number resolves to one immutable record.
- Retired codes remain resolvable for history.
- Unauthorized/manual allocations are investigated.

Reports distinguish sequence values allocated from documents currently active. Voided and revoked records remain part of issued-number history.

## 15. Manual M — Outage and business continuity

During an outage:
1. Do not invent official numbers or bypass controls.
2. Designated records manager opens an outage log with outage ID, start time, affected units, authorizing officer and custodian.
3. Provisional records are marked provisional and are not represented as final ARNA numbers.
4. After restoration, reconcile entries in receipt/issuance order.
5. Platform allocates the next valid official number under normal controls.
6. Retain provisional outage references as cross-references.
7. A second authorized officer reviews reconciliation.
8. Pre-numbered stock or reserved sequence blocks require a separate Owner-approved procedure covering custody, physical security, reconciliation and unused-number disposition.

## 16. Manual N — Audit, incidents and exceptions

Capture, as applicable:
- Tenant, legal entity, document UUID and canonical number;
- Previous/new state;
- Full E.C. year and issuance timestamp;
- Actor identity and authority evidence;
- Action, reason and request/correlation ID;
- Counter scope and sequence;
- Source registry IDs and issuance-time snapshots;
- File/version identity and integrity digest if implemented;
- Approval, dispatch, receipt, void, revocation and supersession references.

Audit records are append-only to ordinary users. Corrections are new events, not edits to old events.

Suspected duplicate numbers, unauthorized issuance, counter tampering, wrong-tenant disclosure, altered issued artifacts or missing audit history are records/security incidents. Suspend affected issuance scope where necessary, preserve evidence, notify accountable officers and follow incident response. Never edit counters or renumber records to conceal an incident.

Exceptions must identify the rule, business/legal reason, affected scope, risk and compensating controls, named approver, effective period/expiry, audit reference and post-exception reconciliation. No exception may permit number reuse, silent mutation, cross-tenant access or audit deletion.

## 17. Manual O — Printed layout and handling

Official outgoing documents should show:
- Approved Amharic letterhead and organizational identity;
- ቁጥር፦ followed by the canonical number;
- Full Ethiopian-calendar date;
- ለ፦ recipient, ከ፦ issuing office and ጉዳዩ፦ subject;
- Authorized signatory and role/position;
- Required attachments and page numbering;
- QR mark only where an authoritative verification URL is supplied and the route is correctly configured.

The number must be legible and identical on every page, copy, scan, export and system record. Reprinting does not allocate a new number. Duplicate/certified-copy marking is separate from changing the original record.

## 18. Manual P — Required forms and controlled registers

Required templates before production activation:
1. Branch/department code request: name, parent, entity, tenant, proposed code, justification, effective date, owner and approval.
2. Document-type registration request: numeric code, Amharic title, definition, authority matrix, lifecycle, confidentiality, retention and regulatory implications.
3. Outgoing issuance checklist: completeness, approvals, authority, unit/type validity, final version, recipient, timestamp and result.
4. Incoming correspondence register: received timestamp/date, sender, sender reference, recipient unit, subject, channel, received-by, evidence and routing.
5. Void/revocation request: number, reason, evidence, effective date, authority, approval and event ID.
6. Correction/supersession register: original number, replacement number, reason, relationship and approvals.
7. Outage provisional register: outage ID, provisional reference, date/time, unit, custodian, record description and final official number.
8. Counter reconciliation report: scope, full E.C. year, last sequence, counts, anomalies, reviewer and resolution.
9. Retention/legal-hold register: class, trigger, schedule, hold basis, custodian, release authority and disposal decision.
10. Exception register: deviation, risk, approval, expiry and closure evidence.

Templates must live in the approved workflow or controlled document repository. A spreadsheet is not an authoritative numbering engine.

## 19. Roles and accountability

| Role | Accountability |
|---|---|
| Owner | Approves policy, format revisions, material exceptions and production activation |
| Documents policy administrator | Maintains standard, type catalogue and numbering controls |
| Organization administrator | Maintains branch/department registry and code history |
| Finance administrator | Maintains legal-entity registry and financial identifier controls |
| Governance authority | Approves positions, grants, acting assignments and issuing authority |
| Author | Prepares accurate draft and evidence |
| Reviewer | Checks content, classification and completeness |
| Approver | Approves exact revision under the approved matrix |
| Issuer | Executes authorized issuance; does not choose number |
| Incoming registrar | Records received correspondence and sender reference |
| Records manager/custodian | Maintains registers, retention, archive and custody |
| Auditor/security officer | Reviews logs, counter reconciliation, access and incidents |

Multiple roles may be held by one person only where the approved authority matrix permits and separation-of-duties controls remain effective.

## 20. Readiness checklist

- [ ] Owner approves this manual and related standard.
- [ ] Applicable Ethiopian legal/regulatory and sector requirements are reviewed by a qualified accountable reviewer.
- [ ] Branch/department codes and capacity are reconciled with Organization.
- [ ] Numeric type catalogue and Amharic labels are approved.
- [ ] Approval/issuing authority matrix is approved by Governance.
- [ ] Incoming registration reference procedure is separately approved.
- [ ] Counter scope matches tenant + legal entity + unit + type + full E.C. year.
- [ ] Formatter, parser, constraints, idempotency and counter transaction align.
- [ ] Calendar conversion and New Year boundary behavior are verified.
- [ ] Void, revoke, correction and supersession controls are verified.
- [ ] Tenant isolation, access, audit, QR/public/private verification and file integrity are verified where in scope.
- [ ] Retention schedule, legal hold and disposal authorities are approved.
- [ ] Registers, reports, outage procedure and reconciliation are operational.
- [ ] Roles are assigned and staff training completed.
- [ ] Runtime verification is complete on the exact candidate commit.
- [ ] Separate Owner approval is recorded before production activation.

## 21. Document hierarchy and conflict resolution

Hierarchy:
1. Applicable Ethiopian law and binding regulator requirements;
2. ARNA Owner-approved policy and domain authority decisions;
3. ARNA Amharic Document Numbering Standard v1.0;
4. This operations manual and controlled forms;
5. Domain technical contracts and implementation notes;
6. Local work instructions.

If ARNA documents conflict, stop the affected operation and refer the conflict to the Owner/policy authority. The v1.0 format is the target for future implementation. It does not retroactively alter issued numbers or prove that source code has changed.

## 22. Revision history

| Version | Date | Change | Status |
|---|---|---|---|
| 1.0 | 2026-10-30 | Initial numbering and registration operations manual | Documentation only; implementation pending |

**Implementation status:** NOT IMPLEMENTED / NOT RUNTIME VERIFIED.
