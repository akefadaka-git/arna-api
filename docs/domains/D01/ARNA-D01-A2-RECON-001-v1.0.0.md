# ARNA D01 — A2 Requirement Status Consistency and Owner-Decision Crosswalk

- **Record ID:** ARNA-D01-A2-RECON-001
- **Version:** 1.0.0
- **Record type:** Controlled A2 read-only reconciliation clarification
- **Prepared:** 2026-11-25
- **Disposition:** **CONSISTENCY RECONCILED — D01 A2 REMAINS UNVERIFIED / OPEN**
- **Repository:** `akefadaka-git/arna-api`
- **Branch:** `docs/arna-d01-a2-evidence-register`
- **Frozen parent catalogue:** `ARNA-ESPPM-A1-SPEC-002 v1.2.0` — APPROVED/FROZEN; unchanged
- **Controlling original checklist:** `ARNA-D01-A2-EVID-REG-001 v1.0.0`, blob `fe0dddff1268e5b1d7e8c19c4b3012cd1c7c4920`
- **Later evidence-register addendum:** `ARNA-D01-A2-EVID-REG-001 v1.0.4`, blob `0d8f95a1aec46b5b3bc65ce202fc377442b4c09b`
- **Traceability baseline:** `ARNA-D01-A2-TRACE-001 v1.0.8`, blob `12dbb73cef6fde2d3cf8889a6422dfaef0af6191`
- **Producer source-owner review:** `ARNA-D01-A2-SOR-001 v1.0.0`, blob `756d0343d4de6a4eec928ba691585195fd8979ce`
- **Owner-approved architecture contract:** `ARNA-D01-CONTRACT-001 v1.0.0`, commit `f9039f52693457110cbcbf7dbbc599ec50630f94`
- **Owner-decision impact addendum:** `ARNA-D01-A2-OWNER-DECISION-IMPACT-001 v1.0.0`, blob `c71828ebc9c6c0f84f7ffbf03f48d1cedb9716f0`

## 1. Purpose and control boundary

This record resolves a status-consistency discrepancy identified by comparing the original 24-item A2 evidence register v1.0.0 with the later Owner-decision impact addendum v1.0.0 and the current traceability/source-owner records.

This is documentation-only A2 reconciliation. It does not replace, rewrite or silently supersede any prior A1/A2 baseline. It does not add evidence, change a requirement's acceptance criteria, create a D01 interface contract, authorize implementation, or establish legal authority.

The original v1.0.0 register is the controlling source for the baseline status tally unless a separately controlled requirement reclassification is supported by new qualifying evidence and explicitly recorded. Later addenda may explain impact, but must not imply a changed status while also claiming the original tally is unchanged.

## 2. Discrepancy identified

The Owner-decision impact addendum v1.0.0 records the following current conclusions:
- Requirement 04 — **MISSING / NOT VERIFIED**;
- Requirement 05 — **MISSING / NOT VERIFIED**;
- Requirement 22 — **PARTIAL / NOT VERIFIED**.

The original evidence register v1.0.0 records:
- Requirement 04 — **PARTIAL**, based on historical initial Board/MD-GM text and supporting records, while current appointments and planning-owner assignments remain unverified;
- Requirement 05 — **PARTIAL**, based on Owner-provided vision/mission/strategy statements and a draft baseline, while an adopted strategy and approved version history remain missing;
- Requirement 22 — **MISSING**, because no approved strategy process/template/consultation pack was located.

These later labels cannot be reconciled with the addendum's statement that the controlling tally remains 16 PARTIAL / 7 MISSING / 1 UNRESOLVED. The addendum did not identify new primary evidence sufficient to reclassify these requirements. The Owner-approved architecture contract defines intended controls and lifecycle, but it does not authenticate appointments, adopt a strategy, or approve a process/template pack.

**Correction:** preserve the original status for Requirements 04, 05 and 22. Treat the later addendum's labels for those rows as impact commentary that does not change baseline disposition. No historical file is edited by this clarification.

## 3. Controlling 24-requirement status crosswalk

| Req. | Requirement | Controlling baseline status | Effect of Owner-approved contract / later addendum | Current disposition |
|---|---|---|---|---|
| 01 | Operative bylaws, constitutive instruments and certified amendments | **UNRESOLVED** | Owner clarified public vs internal purpose; legal identity, registration, adoption, hierarchy and applicability remain unresolved | **UNRESOLVED** |
| 02 | Current GA/Board resolutions approving strategy or planning mandates | **MISSING** | Architecture approval routes are not an adopted strategy resolution | **MISSING** |
| 03 | Board/GA authority, delegation and approval matrix | **PARTIAL** | Architecture route is approved; statutory competence, operative clauses, current delegation and reserved matters remain unverified | **PARTIAL** |
| 04 | Current org chart and formal accountable-executive/planning appointments | **PARTIAL** | Historical initial office-holder evidence remains partial; current appointments and planning-owner authority are not authenticated | **PARTIAL** |
| 05 | Existing approved strategy, multi-year plans, annual plans and prior versions | **PARTIAL** | Owner-provided statements and draft baseline remain relevant partial evidence; contract lifecycle concepts do not prove adoption/version approval | **PARTIAL** |
| 06 | Approved planning calendar, fiscal/planning-year rules and cycle milestones | **PARTIAL** | Contract lifecycle does not establish an approved planning calendar, cycle or milestone schedule | **PARTIAL** |
| 07 | Strategic hierarchy and objective cascade | **MISSING** | D02 ownership boundary is clarified; no approved objective hierarchy/cascade or canonical objective records are evidenced | **MISSING** |
| 08 | Scenarios, assumptions, baselines, versioning and change records | **MISSING** | Contract describes version/change lifecycle but no approved scenario/baseline register or records are evidenced | **MISSING** |
| 09 | Strategy-to-budget/investment decisions and capital approvals | **PARTIAL** | Board annual-plan/budget route is architecture-approved only; no approved budget, D11 reference or D25 investment decision is evidenced | **PARTIAL** |
| 10 | Strategy-to-project/portfolio intake and handoff | **MISSING** | No approved D01-to-D07 intake/handoff evidence added | **MISSING** |
| 11 | D02 KPI dictionary, target/formula catalogue and objective-to-KPI mapping | **PARTIAL** | D02 ownership boundary is clarified; no accepted D02 producer contract or approved mapping added | **PARTIAL** |
| 12 | D23 certified BI datasets, semantic definitions and lineage | **PARTIAL** | No D23 certified strategy dataset/lineage evidence added | **PARTIAL** |
| 13 | D04 risk appetite, enterprise risk register and strategic risk links | **MISSING** | No D04 risk source or accepted strategy-risk link added | **MISSING** |
| 14 | Legal/regulatory obligations and legal advice relevant to strategy | **PARTIAL** | Legal-source leads and explicit LE-01–LE-06 exceptions are documented; legal identity/applicability and competent advice remain unresolved | **PARTIAL** |
| 15 | Exact-main API D01 source inspection | **PARTIAL** | Documentation contract/addenda do not complete exhaustive source/tree/route/test inventory or create D01 runtime implementation | **PARTIAL** |
| 16 | Exact-main App D01 feature/UI inspection | **PARTIAL** | No D01 feature/UI contract or exact-head exhaustive frontend acceptance evidence added | **PARTIAL** |
| 17 | D01 branches, PRs and workflow evidence | **PARTIAL** | Documentation PR evidence is traceable; no D01 implementation acceptance workflow or A3 evidence is authorized or claimed | **PARTIAL** |
| 18 | Canonical identity, tenant, RBAC and authorization conventions | **PARTIAL** | Contract reinforces fail-closed and tenant/entity controls; no D01-specific approved access matrix or implementation tests added | **PARTIAL** |
| 19 | Audit, evidence retention, records and legal-hold policy | **PARTIAL** | Contract reinforces traceability/retention principles; no approved D01 retention schedule or verified implementation evidence added | **PARTIAL** |
| 20 | Amharic-first terminology, E.C. calendar and accessibility | **PARTIAL** | Parent architecture requirements remain; no D01-specific glossary, planning-date test vectors or accessibility acceptance evidence added | **PARTIAL** |
| 21 | Data dictionary, lineage, reference-data and freshness rules | **MISSING** | No approved D01 dictionary, stable strategy/objective IDs or accepted freshness/lineage contract added | **MISSING** |
| 22 | Strategy process maps, templates, consultation records and review minutes | **MISSING** | Contract describes a conceptual lifecycle but does not constitute an approved process map/template pack or completed consultation evidence | **MISSING** |
| 23 | Security/privacy classification, access matrix and segregation of duties | **PARTIAL** | Contract states control principles; no D01-specific approved SoD/access policy or implementation test is established | **PARTIAL** |
| 24 | Accepted D01 dependencies and producer/consumer contracts | **PARTIAL** | Ownership and interface gaps are more explicit; producer approvals, accepted IDs/versions and tested contracts remain absent | **PARTIAL** |

## 4. Reconciled status totals

| Status | Count |
|---|---:|
| PARTIAL | 16 |
| MISSING | 7 |
| UNRESOLVED | 1 |
| VERIFIED-CLOSED | 0 |
| **Total** | **24** |

The original status distribution is preserved. This record does not upgrade, downgrade or close any requirement.

## 5. Architecture decisions versus acceptance evidence

The Owner-approved D01 contract is an architecture baseline subject to explicit legal exceptions. Its authority model, annual-plan/budget route, major-change route and impact-based classification are project architecture decisions. They are not substitutes for:
- certified operative corporate instruments and legal hierarchy determination;
- current competent-organ resolutions and valid authority/delegation records;
- current appointments and effective-dated planning-owner assignments;
- an authenticated adopted strategy/version and effective date;
- approved process maps, templates, consultation records and review minutes;
- approved D01 security/privacy/SoD policies and exact implementation tests.

D02 remains the authoritative owner of enterprise objective records/hierarchy, KPI definitions, formulas, targets and scorecards. D01 owns strategy/planning context and alignment references only. No duplicate D01 objective system of record is authorized.

## 6. Legal exceptions and fail-closed disposition

LE-01 through LE-06 remain OPEN:
1. competent legal authority for strategy, annual plan/budget and major changes;
2. legal identity/hierarchy of the 17-page public/government-use and 22-page internal-use instruments;
3. annual budget reservation;
4. major-change reservation and delegation;
5. current appointments and delegations;
6. authenticated adopted strategy baseline.

Where instrument applicability, competent authority, appointment, delegation, quorum, scope or effective date is missing, conflicting or ambiguous, the affected decision remains UNVERIFIED/UNAVAILABLE. No architecture workflow state may be represented as proof of legal effectiveness.

## 7. Baseline preservation and stage gate

- Parent A1 catalogue v1.2.0 remains APPROVED/FROZEN and unchanged.
- Original D01 A2 evidence register v1.0.0 and later controlled addenda remain preserved.
- Traceability v1.0.8 and source-owner review v1.0.0 remain preserved.
- Owner-approved D01 architecture contract v1.0.0 remains an architecture baseline subject to LE-01–LE-06.
- D01 A2 remains **UNVERIFIED / OPEN**; checklist remains **16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED**.
- No tests, builds, workflows, legal verification or production checks were run by this clarification; **NOT RUN**.
- A3 implementation: **NOT AUTHORIZED**. A4: **NOT STARTED**. A5: **NOT AUTHORIZED**.
- Merge: **NOT AUTHORIZED**. Deployment: **NOT AUTHORIZED**.
- Documentation-only change on the existing branch; no new branch, code, schema, migration, seeder, permission, runtime configuration or production data change.

## 8. Next A2 action

Continue the remaining read-only source and evidence reconciliation requirement-by-requirement. Any future status change must identify the new primary evidence, exact source identity, authority/provenance, requirement acceptance condition satisfied, and explicit controlled reclassification. Until then, retain the status table in §3 as the controlling crosswalk.
