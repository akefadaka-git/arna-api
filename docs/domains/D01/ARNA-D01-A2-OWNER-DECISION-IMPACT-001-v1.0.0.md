# ARNA D01 — A2 Owner-Approved Contract Impact and Legal-Authority Reconciliation Addendum

- **Record ID:** ARNA-D01-A2-OWNER-DECISION-IMPACT-001
- **Version:** 1.0.0
- **Record type:** Controlled A2 read-only reconciliation addendum
- **Prepared:** 2026-11-25
- **Disposition:** **PARTIAL — A2 REMAINS OPEN / DOMAIN UNVERIFIED**
- **Repository:** `akefadaka-git/arna-api`
- **Branch:** `docs/arna-d01-a2-evidence-register`
- **Parent A1 catalogue:** `ARNA-ESPPM-A1-SPEC-002 v1.2.0` — APPROVED/FROZEN; unchanged
- **Approved D01 architecture contract:** `ARNA-D01-CONTRACT-001 v1.0.0`
- **Prior A2 evidence:** `ARNA-D01-A2-EVID-REG-001 v1.0.4`, blob `0d8f95a1aec46b5b3bc65ce202fc377442b4c09b`
- **Prior A2 traceability:** `ARNA-D01-A2-TRACE-001 v1.0.8`, blob `12dbb73cef6fde2d3cf8889a6422dfaef0af6191`
- **Producer source-owner review:** `ARNA-D01-A2-SOR-001 v1.0.0`, blob `756d0343d4de6a4eec928ba691585195fd8979ce`
- **D01 contract commit:** `f9039f52693457110cbcbf7dbbc599ec50630f94`

## 1. Purpose and scope

This addendum records the A2 evidence impact of the Owner-approved D01 architecture contract v1.0.0. It preserves the earlier A1/A2 baselines and distinguishes an approved architecture decision from independently verified legal authority and from evidence that a corporate decision was actually adopted.

This is a documentation-only, read-only A2 reconciliation. It does not amend the frozen 30-domain catalogue, replace prior A2 records, change the 24-requirement checklist, approve the subordinate D01 A1 specification, create an API or event contract, or authorize implementation.

## 2. Owner-approved architecture decisions — accurately classified

The Owner's decisions are recorded as follows:

| Decision | Architecture disposition | A2/legal evidence disposition |
|---|---|---|
| Authority model: GM → Board → CEO → D01 coordination | **OWNER-APPROVED ARCHITECTURE** | Statutory competence, operative instrument hierarchy and current delegation chain **NOT VERIFIED** |
| Annual strategic plan and budget: Board approval | **OWNER-APPROVED ARCHITECTURE**, limited to lawful authority | Whether budget or any plan component is legally reserved to the GM **UNRESOLVED** |
| Major strategic changes: GM approval | **OWNER-APPROVED ARCHITECTURE** for corporate-direction/reserved matters | Exact legal reservation, materiality boundary and delegation rules **NOT VERIFIED** |
| Classification: impact-based | **OWNER-APPROVED ARCHITECTURE** using strategic impact, reserved authority, risk and materiality | Separate policy, thresholds, scoring, examples and competent approval **NOT YET ESTABLISHED** |
| Contract disposition | **APPROVED architecture baseline**, subject to explicit legal exceptions | Does not authenticate a resolution, validate legal authority or close A2 |

The Owner's approval is authoritative for the architecture direction within this project governance. It is not a substitute for statute, certified operative corporate instruments, a competent-organ resolution, a valid delegation or legal advice.

## 3. Reconciliation against the existing A2 requirements

The Owner-approved contract provides a controlled design decision and resolves the need to keep D01's proposed architecture authority model explicit. It does not provide the primary evidence required to verify the following A2 acceptance cases:

| Requirement | Existing A2 evidence requirement | Effect of Owner contract approval | Current A2 conclusion |
|---|---|---|---|
| **01 — operative corporate instruments** | Certified operative MoA/AoA/bylaws, registration identity, amendment chain, custody and applicability | No certified legal instrument or legal hierarchy determination supplied by the approval | **UNRESOLVED** |
| **02 — GA/Board strategy approval** | Certified resolution identifying competent body, exact strategy version, quorum, vote, date, conditions and effective date | Contract defines intended approval routes; it is not a strategy adoption resolution | **MISSING / NOT ESTABLISHED** |
| **03 — authority/delegation matrix** | D03 authority record, source instrument, scope, delegate, effective dates, revocation and segregation-of-duties evidence | Contract states architecture routing; no current legal authority/delegation evidence is authenticated | **PARTIAL / NOT VERIFIED** |
| **04 — accountable executive/planning owners** | Current D03 appointment, D08 assignment, D09 employment/identity and approved responsibility instrument | Contract defines functional accountability; it does not appoint current office-holders | **MISSING / NOT VERIFIED** |
| **05 — strategy versions/multi-year plans** | Immutable strategy version, approval reference, effective period, supersession and evidence | Contract defines lifecycle/version controls; no adopted enterprise strategy/version is authenticated | **MISSING / NOT VERIFIED** |
| **09 — strategy-to-budget/investment** | D11 budget/accounting reference and D25 investment decision/commitment, aligned to entity, currency and period | Board budget route is architecture-approved only; no budget approval or investment authorization is evidenced | **PARTIAL / NOT VERIFIED** |
| **22 — process/templates/consultation** | Approved process/template, consultation, minutes, review, decision and exception records | Contract defines a lifecycle; no separate process/template approval or completed consultation record is established | **PARTIAL / NOT VERIFIED** |
| **23 — security/privacy/SoD** | Approved access/SoD policy and evidence of separation, least privilege and fail-closed controls | Contract states required control principles; no D01-specific approved SoD policy or implementation test is created | **PARTIAL / NOT VERIFIED** |

Other requirements retain their prior evidence status and ownership boundaries. In particular, D02 remains the authoritative owner of enterprise objectives, KPI definitions and targets; D01 owns strategy/planning context and alignment references only. No D01 duplicate objective system of record is authorized.

## 4. Legal and authority exception register

The following exceptions remain open and must be carried into every D01 approval workflow design until resolved by authoritative evidence:

- **LE-01 — Competent authority:** statutory and operative-instrument authority for multi-year strategy, annual plan/budget and major strategic changes is not legally verified.
- **LE-02 — Public/internal instrument hierarchy:** the 17-page government/public-use scan and 22-page internal-use instrument have distinct Owner-confirmed purposes. Their registration, formal adoption, amendment history, legal identity and hierarchy remain unresolved.
- **LE-03 — Annual budget reservation:** Board authority over the annual budget, including any matters reserved to the GM, is not verified.
- **LE-04 — Major-change reservation/delegation:** the legal boundary between GM-reserved strategic changes and Board/delegated operational changes is not verified.
- **LE-05 — Current appointments/delegations:** current Board, CEO, corporate secretary, planning-role holders and their mandates are not established by authenticated appointment/delegation records.
- **LE-06 — Adopted strategy baseline:** no certified resolution and exact approved multi-year strategy version/effective date have been authenticated in the inspected A2 evidence.

**Fail-closed rule:** where competence, instrument applicability, appointment, delegation, quorum, scope or effective date is missing, conflicting or ambiguous, the affected approval is UNVERIFIED/UNAVAILABLE and must not be treated as legally effective merely because the D01 architecture workflow permits a proposed route.

## 5. Major strategic change classification — A2 boundary

The approved impact-based approach is suitable as an architecture direction. The following are intentionally **not approved or established** by this addendum:
- numerical ETB or percentage thresholds;
- scoring weights or an automatic score-to-approver mapping;
- a complete list of legally reserved GM matters;
- a Board delegation schedule;
- authority to treat an operational adjustment as a major or non-major change without documented criteria.

These matters require a separate controlled policy and legal/governance review. Until approved, uncertain or potentially reserved changes must be escalated to the competent organ for determination. No numeric threshold is to be invented in D01.

## 6. Baseline and status preservation

- Parent A1 catalogue v1.2.0 remains **APPROVED/FROZEN** and unchanged.
- D01 contract v1.0.0 remains **OWNER-APPROVED architecture baseline**, subject to its explicit legal exceptions.
- D01 subordinate A1 specification remains at its previously recorded status; this addendum does not freeze or silently revise it.
- D01 enterprise A2 remains **UNVERIFIED / OPEN**.
- The controlling prior checklist remains **16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED**. This addendum does not upgrade any requirement solely because the Owner approved the architecture contract.
- Prior evidence v1.0.4, traceability v1.0.8 and source-owner review v1.0.0 remain preserved historical A2 records.
- No external legal evidence, certified instrument, corporate resolution, appointment, delegation or new source-owner attestation was received or authenticated during this addendum.
- No tests, builds, workflows or production checks were run; status remains **NOT RUN** where applicable.

## 7. Gate and authorized next work

The next A2 work is limited to traceable reconciliation of the existing evidence against LE-01 through LE-06 and the 24 acceptance requirements, using authoritative evidence if and when it becomes available. Missing evidence must remain explicitly missing; no substitute is to be inferred from application code, role names, organizational assignments, draft minutes, calendar entries or Owner architecture approval.

**A3 implementation: NOT AUTHORIZED. A4: NOT STARTED. A5: NOT AUTHORIZED. Merge: NOT AUTHORIZED. Deployment: NOT AUTHORIZED.**

This addendum is documentation-only on the existing branch. No new branch, runtime change, application source change, schema, migration, seeder, permission, production data change, merge or deployment is authorized or performed.
