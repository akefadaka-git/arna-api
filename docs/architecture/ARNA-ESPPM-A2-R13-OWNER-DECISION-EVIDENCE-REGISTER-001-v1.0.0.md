# ARNA ESPPM A2 — R13 Owner Decision and Evidence Register

- **Record ID:** ARNA-ESPPM-A2-R13-OWNER-DECISION-EVIDENCE-REGISTER-001
- **Version:** 1.0.0
- **Prepared:** 2026-11-25
- **Stage:** A2 — read-only evidence reconciliation
- **Frozen catalogue:** ARNA-ESPPM-A1-SPEC-002 v1.2.0 (30 domains)
- **Controlling Owner decision:** KEEP A2 OPEN — DO NOT CLOSE (2026-11-24)
- **Repository:** `akefadaka-git/arna-api`
- **Evidence branch:** `docs/esppm-a2-closure-report-001` (existing PR #219 branch)
- **Change class:** Documentation-only; classification and disposition register
- **Supersession:** None. R13 supplements and does not replace R11, R12, R10, or earlier controlled records.

## 1. Purpose and decision boundary

R13 reconciles the remaining A2 exceptions into three evidence classes so that architecture decisions are not confused with legal/corporate proof or with evidence that can only be verified after deployment.

This register records the Owner-approved R13 classification exercise. It is not an adoption of a corporate policy, a legal opinion, an appointment, a delegation, a domain contract approval, an A3 authorization, or an A2 closure decision.

R13 does not change the frozen 30-domain catalogue, any domain disposition, or any historical evidence finding. Where a proposed architecture contract is described, it remains a proposal until its own controlled specification and acceptance process is completed.

## 2. Record lineage and preservation

| Record | Historical role | R13 treatment |
|---|---|---|
| R11 v1.0.2 — Authority-Source and Contract-Exception Reconciliation | Source classification, corporate authority exceptions, contract exceptions, D01/D02 boundary clarification, closure-readiness finding | PRESERVED; not edited or superseded |
| R12 — Repository Evidence Findings (2026-11-25) | Bounded read-only repository search finding that identified no accepted D01/D02 API/event IDs or version, no adopted D04/D05 contracts, no accepted D01–D07 / D01–D23 producer/consumer contract set, and no adopted D15/D17 policy/contract; no status upgrades | PRESERVED AS THE PRIOR R12 RUN FINDING; R13 classifies its unresolved items and does not rewrite its conclusions |
| R13 v1.0.0 | Owner decision/evidence classification and next-gate register | NEW ADDITIVE RECORD |

R12 was a bounded repository-evidence finding recorded in the preceding reconciliation. The repository search performed for R13 did not locate a separate standalone R12 file in the existing evidence branch. This R13 document does not fabricate a missing R12 artifact or claim to reproduce an unlocated document. The R12 finding itself remains historically intact in the reconciliation record.

## 3. Classification A — resolvable through controlled architecture decisions

These items do not require inventing corporate authority. They require explicit, versioned architecture decisions and subsequent contract acceptance. Their classification as architecture-resolvable does not mean they are already resolved.

| ID | Item | Current position | Required controlled resolution | Status |
|---|---|---|---|---|
| ARCH-01 | D01/D02 objective ownership | Catalogue boundary clarified by the existing D01/D02 addendum: D01 owns strategy/planning baseline; D02 owns authoritative enterprise objectives, hierarchy, KPI definitions, targets, scorecards and performance accountability. D01 retains alignment references only. | Preserve the boundary in all derived specifications and consumer contracts; do not create a competing D01 objective system of record. | RESOLVED AT BOUNDARY LEVEL |
| ARCH-02 | D01/D02 API and event contracts | No accepted API/event identifiers, schemas or interface version established. | Produce a versioned contract specification defining stable identifiers, producer/consumer ownership, schemas, lifecycle, tenant/legal-entity context, compatibility and deprecation rules, authorization, idempotency and failure semantics. | OPEN |
| ARCH-03 | D01–D07 and D01–D23 integration contracts | No complete accepted producer/consumer contract set established. | Define each required interaction, authoritative source, consumer responsibility, data ownership, event/API boundary, reconciliation, retry/failure handling and acceptance criteria. Do not assume that a domain relationship implies an implemented integration. | OPEN |
| ARCH-04 | D04/D05 risk and compliance interfaces | Draft PSC/control materials do not establish adopted risk/compliance source contracts. | Define risk, control, compliance-obligation, issue, escalation and evidence interfaces; identify authoritative ownership, lifecycle, access boundary and audit requirements. | OPEN |
| ARCH-05 | Domain ownership register | Domain boundaries can be recorded without claiming current legal office-holder appointments. | Maintain separate fields for accountable domain function, system-of-record ownership, operational custodian, data steward, human office-holder and legally competent approver. Mark unassigned human roles explicitly. | OPEN |
| ARCH-06 | Authority enforcement control design | Fail-closed design is established in the current-main document approval authority matrix (blob `f53446ece9481ddf09759854cf2fd63e4ba20709`). | Preserve deny-by-default enforcement: no authority inferred from title, role slug, uploader identity or permission alone; require applicable current instrument, scope, dates, quorum/resolution and separation-of-duties evidence. | CONTROL DESIGN RESOLVED; BUSINESS AUTHORITY OPEN |

### 3.1 Architecture acceptance conditions

No ARCH item may be marked accepted merely because a document exists. Acceptance requires:
1. a stable record ID and controlled version;
2. named producer, consumer and system-of-record ownership;
3. defined data contract and lifecycle semantics;
4. tenant and legal-entity boundaries;
5. authorization and audit requirements;
6. positive, negative, conflict, retry and failure acceptance criteria;
7. traceability to the frozen catalogue and exact repository baseline;
8. explicit Owner approval where the decision changes enterprise architecture or governance direction.

No API/event identifier or interface version is created by this register.

## 4. Classification B — requires formal corporate or legal evidence

The following exceptions cannot be resolved by architecture design, code, job title, historical founding text, draft policies, repository capability or an inferred approval chain. They remain open until appropriate formal evidence is available and its authenticity, applicability, effective date and supersession status are assessed.

| ID | Required evidence matter | Existing evidence limitation | R13 disposition |
|---|---|---|---|
| AUTH-01 | Current Board membership, appointments and terms | Historical founding rosters only; no current adopted election/appointment resolution, current term register or verified current appointment evidence established. | OPEN — FORMAL EVIDENCE REQUIRED |
| AUTH-02 | Current President/Chair and Secretary | Historical role descriptions do not establish current office-holders. | OPEN — FORMAL EVIDENCE REQUIRED |
| AUTH-03 | Current CEO/Managing Director appointment | Initial historical managing-director statement does not establish a current appointment, term, renewal or continuing authority. | OPEN — FORMAL EVIDENCE REQUIRED |
| AUTH-04 | Current delegation to President or executive | Historical bylaw text describes a possible delegation mechanism; no current specific delegation instrument, scope, limit, effective date or revocation evidence established. | OPEN — FORMAL EVIDENCE REQUIRED |
| AUTH-05 | Current company signing authority | Historical signing provision does not establish named current signatories, joint/single signing rules, scope or monetary limits. | OPEN — FORMAL EVIDENCE REQUIRED |
| AUTH-06 | Competent strategy approval authority | Architecture direction is recorded, but operative legal authority and an adopted current strategy decision remain unverified. | OPEN — FORMAL EVIDENCE REQUIRED |
| AUTH-07 | Procurement and Sales approval thresholds | No adopted Procurement policy, Sales/pricing/discount/credit/refund policy or valid monetary delegation schedule established. Draft PSC/workflow material is not authority. | OPEN — FORMAL EVIDENCE REQUIRED |
| AUTH-08 | Risk appetite and risk acceptance authority | No adopted risk appetite, risk acceptance matrix or verified current competent authority established. | OPEN — FORMAL EVIDENCE REQUIRED |
| AUTH-09 | Compliance/Control Committee appointments and mandate | Control principles do not establish committee appointment, membership, quorum, term, charter or delegated powers. | OPEN — FORMAL EVIDENCE REQUIRED |
| AUTH-10 | Operative governing instrument identity and precedence | The earlier 17-page scan recovery and 22-page DOCX transcription have unresolved identity/version/registration/currentness differences. No legal precedence is inferred. | OPEN — FORMAL EVIDENCE REQUIRED |
| AUTH-11 | Meeting quorum and proxy legal interpretation | Source text and draft election manual expose unresolved statutory and instrument-concordance questions; the manual is not adopted. | OPEN — FORMAL LEGAL RECONCILIATION REQUIRED |
| AUTH-12 | Adoption/ratification of GA Resolution 0001/2019 and current strategic-plan decision | Bounded searches did not locate authenticated minutes/resolution/ratification evidence; a legacy export agenda is not proof of a completed or approved meeting. | OPEN — FORMAL EVIDENCE REQUIRED |

### 4.1 Evidence qualification rules

- Historical founding provisions may support a statement about what a source document says; they do not prove present appointments or authority.
- A draft, proposed, provisional or working document is not an adopted policy.
- A technical role, permission, appointment-record schema or workflow is not proof that a legally valid appointment or delegation occurred.
- A source conflict is not resolved by upload date, filename, transcription length or assumption.
- Where evidence is absent, conflicting, expired, revoked, incomplete or not traceable to the relevant entity and decision, authority remains DENY / NOT ESTABLISHED.
- R13 does not request corporate or financial source-record uploads before ARNA is deployed and running.

## 5. Classification C — deferred until post-deployment verification

The following matters depend on access to actual operational source records and transaction evidence. They are deliberately deferred until ARNA is deployed and running on the server. They are not to be converted into pre-deployment upload requests or impossible pre-deployment evidence gates.

| ID | Deferred verification | Pre-deployment treatment | Post-deployment verification objective |
|---|---|---|---|
| POST-01 | Primary financial source records and transaction evidence | DEFERRED; no upload requested | Authenticate source records and establish completeness, provenance, period, entity and authorized access. |
| POST-02 | Source-to-ledger reconciliation against actual company records | DEFERRED | Reconcile source transactions to ARNA ledger entries, identify unmatched/duplicate/timing items and document approved resolution. |
| POST-03 | Actual financial account ownership and mandate verification | DEFERRED | Verify applicable ownership/mandate evidence and effective authority through controlled, authorized intake. |
| POST-04 | Production operating-control and reconciliation evidence | DEFERRED | Observe actual control execution, exceptions, approvals, reconciliations, audit trails and operating effectiveness. |
| POST-05 | Production source-intake security and records controls | DESIGN REQUIREMENT ONLY | Verify secure upload, identity and authority checks, malware/content handling, retention, access logging, integrity and exception handling after deployment. |

**Timing rule:** Pre-deployment architecture/control readiness and post-deployment source-evidence verification are separate determinations. Deferring source evidence does not authorize unsupported claims of financial/legal verification; it also does not make unavailable production records a pre-deployment blocker.

## 6. Owner-approved architecture direction — preserved, not converted into legal fact

The following architecture direction remains recorded:
- General Meeting of Shareholders is the ultimate authority for corporate strategic direction.
- The Board governs and oversees strategy.
- The CEO executes approved strategy.
- D01 coordinates enterprise strategy.
- D02 owns enterprise performance measurement, including objectives, KPIs, targets, scorecards, review periods and performance reviews.

This is an Owner-approved architecture direction only. It remains subject to A2 legal/bylaws reconciliation and is not a bylaw amendment, legal opinion, verified current appointment, or final determination of statutory authority.

The 30-domain catalogue v1.2.0 remains frozen. No domain status changes are approved by R13.

## 7. Preserved domain dispositions

| Disposition | Domains | Count |
|---|---|---:|
| SUPPORTED | D08, D22 | 2 |
| PARTIAL | D02, D03, D06, D09, D10, D11, D13, D14, D20, D21, D23 | 11 |
| UNVERIFIED | D01, D04, D05, D07, D18, D24 | 6 |
| MISSING | D12, D15, D16, D17, D19, D25, D26, D27, D28, D29, D30 | 11 |
| **Total** | **D01–D30** | **30** |

Specific Owner decisions remain in force:
- D01 UNVERIFIED; D02 PARTIAL.
- D04/D05 UNVERIFIED.
- D12 MISSING.
- D15 and D17 MISSING; proposed upgrades remain rejected.
- No domain upgrade, downgrade or closure is made by R13.

## 8. R13 gate assessment

| Gate | R13 result | Explanation |
|---|---|---|
| Classification of remaining exceptions | PASS | Items separated into architecture, formal corporate/legal and post-deployment evidence classes. |
| D01/D02 ownership boundary | PARTIAL / BOUNDARY RESOLVED | Existing boundary clarification preserved; interface contract and human authority remain open. |
| Accepted cross-domain contracts | OPEN | No accepted complete contract set established by R12/R13. |
| Corporate/legal authority | UNRESOLVED | AUTH-01 through AUTH-12 remain open. |
| Post-deployment evidence timing | PASS — DEFERRED CORRECTLY | No pre-deployment financial source upload requested. |
| Domain dispositions | PASS — PRESERVED | 2 SUPPORTED / 11 PARTIAL / 6 UNVERIFIED / 11 MISSING. |
| A2 closure readiness | NOT READY | Material contract, authority, missing-domain and acceptance evidence remains unresolved. |
| A3 authorization | NOT GRANTED | Separate Owner authorization required. |
| A4 verification | NOT STARTED | A4 is a separate gate. |
| A5 deployment | NOT AUTHORIZED | Separate Owner authorization required. |

**R13 conclusion: CLASSIFICATION COMPLETE; A2 REMAINS OPEN — NOT VERIFIED-CLOSED.**

R13 is not a final A2 closure submission. No claim is made that all A2 exceptions have been resolved.

## 9. Required next actions and sequencing

1. Keep all AUTH-01 through AUTH-12 items open until the relevant formal corporate/legal evidence is available and reconciled.
2. Prepare separate controlled architecture specifications for ARCH-02 through ARCH-05; do not silently assign contract IDs, human approvers or legal authority.
3. Preserve fail-closed authority enforcement while current business authority is unestablished.
4. Keep D15/D17 MISSING and D04/D05 UNVERIFIED until accepted policy/contracts and evidence support a change.
5. Defer POST-01 through POST-05 operational evidence verification until ARNA is deployed and running; design the secure intake path without requesting those records now.
6. Reassess A2 closure only after the applicable criteria have been evidenced and the Owner explicitly decides whether to close A2.

## 10. Change control and verification record

- Target branch: existing `docs/esppm-a2-closure-report-001`; no new branch created.
- Intended change: one additive Markdown register only.
- R11 v1.0.2: preserved; no replacement or edit.
- R12 prior run finding: preserved; not rewritten or represented as a standalone artifact that was not found.
- Application source, routes, models, migrations, schema, seeders, runtime configuration, secrets and production data: OUT OF SCOPE / NOT CHANGED.
- API `main` and App `main`: MUST REMAIN UNCHANGED.
- Tests/builds/CI: NOT RUN; documentation-only record.
- A4 tests and operating-effectiveness checks: NOT RUN.
- Merge: NOT AUTHORIZED.
- A3 implementation: NOT AUTHORIZED.
- A4 verification: NOT STARTED.
- A5 deployment: NOT AUTHORIZED.

The final commit SHA, new file blob SHA, PR head and compare result must be recorded from the successful GitHub write and subsequent live verification. No SHA is pre-assigned by this document.
