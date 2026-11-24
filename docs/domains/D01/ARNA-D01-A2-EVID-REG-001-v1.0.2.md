# ARNA D01 — A2 Evidence Reconciliation Progress Addendum

- **Record ID:** ARNA-D01-A2-EVID-REG-001
- **Version:** 1.0.2
- **Record type:** Controlled successor addendum to v1.0.1
- **Stage:** A2 — read-only evidence reconciliation
- **Disposition:** **PARTIAL — NOT VERIFIED-CLOSED**
- **Prepared:** 2026-11-24
- **Repository:** `akefadaka-git/arna-api`
- **Branch:** `docs/arna-d01-a2-evidence-register`
- **Parent record:** `ARNA-D01-A2-EVID-REG-001 v1.0.1`
- **Parent commit:** `eb622c0a6ddcffedb8819141f02c4e529b7c62bc`

## 1. Purpose and change control

This addendum records further read-only reconciliation of evidence already available in the destination repository. It supplements v1.0.0 and v1.0.1; neither prior version is overwritten.

This update does not depend on new GA/Board records, does not request additional documents or access from the Owner, and does not treat missing GA/Board evidence as a reason to stop unrelated A2 assessment work. It does not change application code, schema, migrations, seeds, permissions, production data or configuration. It does not authorize A3, merge, A4 or A5.

## 2. Additional source review completed

The following repository records were re-fetched and reviewed:

| Source | Ref / blob | A2 use | Limitation retained |
|---|---|---|---|
| D01 subordinate A1 specification | `docs/domains/D01/ARNA-D01-A1-SPEC-001-v1.0.0.md`; blob `c04dc84e21ccc9114d8952ba4d38913e36e58004`; branch `docs/arna-d01-a1-spec-a2-evidence` | Clarifies D01 scope, proposed information model, lifecycle and domain boundaries | DRAFT / Owner review pending; not frozen and not implementation authority |
| Governance provisional control matrix | `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md`; blob `07eb3f567bcc67fe2c7ac8e66c0a993c0d1e69c2`; `main` | Owner-approved limited control principles and explicit fail-closed limitations | Does not establish corporate legal powers, office-holders, quorum, committee appointments, monetary limits or strategy approval authority |
| Document approval authority matrix | `docs/governance/document-approval-authority-matrix.md`; blob `f53446ece9481ddf09759854cf2fd63e4ba20709`; `main` | Default-deny approach where approval authority is not evidenced | General document authority register; not an adopted D01 strategy approval matrix |
| Articles source-status README | `docs/legal/articles-of-association/README.md`; blob `c90f607d818304eaa44921d2bc05188a7e4da878`; `main` | Confirms separate Articles source is identified in the record but original binary is not preserved/verified in repository | Does not authenticate the original or prove its current legal effect |
| Historical Articles reconciliation notes | `docs/legal/articles-of-association/HISTORICAL-RECONCILIATION-NOTES.md`; blob `856cd6afbcfd16f26b26c98d91850ebd82fcd29a`; `main` | Records historical source leads and explicit unresolved shareholder/source discrepancies | Working notes, not legal opinion, verified transcription or proof of original custody |
| P2.5 source/statutory reconciliation | `docs/governance/elections/P2.5-SOURCE-AND-STATUTORY-RECONCILIATION-v1.0.md`; blob `8101ccbd3a9fbdc194c5a51aef34e0e458c11132`; `main` | Adds source classification and statutory context for legal/governance questions | Engineering issue register; not independent registry authentication or Ethiopian legal advice |

## 3. Reconciled findings that can advance independently

### 3.1 D01 domain boundary — clarified, not legally adopted

The D01 A1 draft defines D01 as the owner of the enterprise strategy/planning baseline, strategic themes, multi-year plans, enterprise priorities, strategic objectives, planning cycles, assumptions, scenarios, baselines and traceability.

The draft expressly leaves these matters with their source-owning domains:
- D03: GA/Board authority, resolutions, appointments and delegations;
- D02: KPI definitions, formulas, targets and performance measurement;
- D07: project/programme/portfolio execution;
- D06: legal interpretation and legal obligations;
- D04: enterprise risk appetite and risk treatment;
- D08: organizational structure and effective-dated assignments;
- D25: investment appraisal and capital allocation;
- D26: partnership lifecycle and agreements;
- D23: certified analytics, semantic definitions and reporting.

**A2 finding:** this boundary is sufficiently described for continued architecture assessment. It does not establish that the draft itself is Owner-approved or frozen. No runtime implementation is inferred.

### 3.2 Owner-approved governance controls — limited scope confirmed

The governance matrix records an Owner approval event dated 2026-10-22 for control principles: segregation of duties, evidence retention, conflict declarations, bounded delegation, audit trail, tenant scope and denial of unverified authority.

The same matrix expressly states that this limited approval does not verify legal powers, committee appointments, quorum, financial limits, bank signatories, penalty authority or formal corporate approval. Formal organizational approvals remain subject to the registered instruments, applicable law and separately evidenced GA/Board decisions.

**A2 finding:** these control principles may be treated as an Owner-approved design/control baseline within their stated scope. They cannot be used to identify a strategy approver or current office-holder. Fail-closed treatment remains mandatory.

### 3.3 Statutory context — recorded without overclaim

The P2.5 reconciliation records the following Commercial Code Proclamation No. 1243/2021 context:
- Article 296: general director-count range and allowance for a limited proportion of non-shareholder directors;
- Article 297: director qualifications, including additional qualifications potentially established by the Memorandum or law;
- Article 298: subsequent director appointment by GA and a three-year term subject to the Memorandum;
- Article 300: chairperson must be a shareholder;
- Articles 383–384: shareholder voting weight and possible Memorandum-based vote limits.

These are source-reported statutory research points in an engineering issue register. They do not decide the legal effect of the company's specific clauses, prove the current Board composition, or establish strategy approval authority. The possible one-share director qualification remains unverified against the exact registered instrument and requires competent Ethiopian-law review before operational use.

**A2 finding:** statutory context is better indexed and the relevant questions are more precisely framed. Legal-source identity and company-specific application remain unresolved.

### 3.4 Corporate strategy content — provenance remains distinct from adoption

The 2026-11-14 Owner-provided vision, mission and strategy statements remain attributable to the Owner. The corporate-site content map and seeder are draft/public-content evidence, not a GA/Board resolution. No adopted strategy version, effective date or supersession record is established by these sources.

**A2 finding:** preserve the statements as Owner-provided proposed strategy content. Do not publish or model them as adopted corporate strategy without evidence of competent approval.

## 4. Non-blocking A2 workstream disposition

The following A2 activities can proceed or be treated as sufficiently assessed without the missing GA/Board evidence:

| Workstream | Current disposition | Why it can proceed independently | Remaining work |
|---|---|---|---|
| D01 scope and domain boundaries | **ASSESSED — PARTIAL** | D01 A1 draft defines included capabilities and exclusions | Owner review/freeze of subordinate A1 remains separate |
| D01 conceptual record classes and lifecycle | **ASSESSED — PROPOSED** | Conceptual classes and lifecycle controls are documented without schema commitments | Validate against accepted A1 and later A3 design; no tables/routes authorized |
| Governance control principles | **OWNER-APPROVED — LIMITED SCOPE** | Matrix explicitly records approval and limitations | Do not infer legal powers or named approvers |
| Default-deny approval control | **SUPPORTED** | Document approval matrix explicitly withholds unverified business approvers | D01-specific strategy authority remains unresolved |
| Domain dependency map | **IDENTIFIED — PARTIAL** | D01 draft and enterprise A2 identify D02/D07/D23 consumers and D03/D04/D06/D08/D25/D26 interfaces | Formal producer/consumer contracts, ownership and version semantics remain open |
| Amharic-first / E.C. localization requirement | **IDENTIFIED — PARTIAL** | Frozen parent architecture and D01 draft identify the requirement | D01 glossary, calendar semantics and acceptance evidence remain open |
| Existing application capability inventory | **PARTIAL** | Existing A2 source inspection reports no accepted D01 strategy contract in sampled API/App paths | Complete exact-head path/route/policy/test inventory remains an A2 task |
| Strategy process, objective cascade and planning calendar | **NOT ESTABLISHED** | Requirements can be decomposed and assessed without knowing who approves them | Approved process/calendar/objective records still needed |
| Legal source identity and current office-holders | **UNRESOLVED** | These findings are isolated and can remain separately flagged | Do not use as assumptions in unrelated architecture assessment |

## 5. D01 24-item checklist — status preservation

The v1.0.0 checklist dispositions are preserved; this addendum does not falsely convert supporting design evidence into corporate approvals or verified operational records.

| Status in v1.0.0 | Count | v1.0.2 treatment |
|---|---:|---|
| PARTIAL | 16 | Retain PARTIAL; supporting controls and domain boundaries are better indexed, but acceptance evidence is still incomplete |
| MISSING | 7 | Retain MISSING; no new adopted planning/process records were located |
| UNRESOLVED | 1 | Retain UNRESOLVED; source identity and conflicting historical records remain unresolved |
| VERIFIED-CLOSED | 0 | No checklist item is promoted to VERIFIED-CLOSED by this addendum |

**Checklist total: 24/24 assessed; 0/24 VERIFIED-CLOSED.** Assessment completion is not evidence closure.

## 6. Outstanding matters — isolated, not global blockers

The following questions remain unresolved and must stay fail-closed where they affect the relevant decision. They do not prevent completion of unrelated A2 architecture and evidence work:

1. **D01-A2-01 / D01-DEC-01:** controlling relationship among the registered MoA/AoA, 17-page bylaws scan and 22-page DOCX; conflicting founder/share schedules must not be merged.
2. **D01-A2-02 / D01-DEC-02:** competent GA/Board authority and adopted resolution approving strategy or strategic direction.
3. **D01-A2-03:** current adopted delegation/reserved-matter/approval matrix; the provisional control matrix is not a substitute.
4. **D01-A2-04 / D01-DEC-04:** current Board and executive appointments, effective dates, terms and delegated powers.
5. **D01-A2-05 / D01-DEC-03:** whether the Owner-provided 2036 E.C. vision/mission/strategy statements have been formally adopted.
6. **D01-A2-06 / D01-DEC-06:** complete approved strategy/planning calendar; fiscal-year and GA timing clauses alone are insufficient.
7. **D01-A2-07 and 08:** approved objective cascade, scenario/assumption/baseline and version/supersession records.
8. **D01-A2-09 through 14:** strategy-to-budget, portfolio, KPI, BI, risk and legal interface evidence.
9. **D01-A2-15 through 24:** complete exact-current-head API/App inspection, operational authorization, audit/retention, localization, data lineage, process pack, security/privacy and accepted interface contracts.

No additional document upload is requested from the Owner in this addendum. Findings are limited to evidence actually retrieved and previously recorded. A missing source in the inspected repository is not proof that the source does not exist elsewhere.

## 7. Stage and authorization decision

- Parent enterprise A1 v1.2.0: remains frozen.
- D01 subordinate A1 v1.0.0: remains DRAFT / Owner review pending.
- D01 A2: **ACTIVE — PARTIAL — NOT VERIFIED-CLOSED**.
- D01 A3: **NOT AUTHORIZED**.
- A4: **NOT STARTED**.
- A5: **NOT AUTHORIZED; separate gate preserved**.
- Strategy approval workflow: **FAIL-CLOSED** until competent authority and adopted strategy evidence are established.
- Merge/deployment: **NOT AUTHORIZED** by this addendum.

No source code, database schema, migrations, seeds, runtime configuration or production data are changed by this record.

## 8. Immediate next A2 work

Continue the independent, read-only workstreams:
1. Complete a bounded exact-current-head API inventory for D01-related routes, models, services, policies, migrations, events, tests and shared contracts.
2. Complete the corresponding App inventory for features, routes, navigation, permissions, forms and API integrations.
3. Reconcile D01 dependency contracts and ownership boundaries against the frozen 30-domain catalogue.
4. Build a traceability matrix from each D01 conceptual capability to existing evidence, missing evidence and later acceptance criteria.
5. Keep legal identity, strategy adoption and current appointments explicitly unresolved; do not block the above assessment work on those questions.
6. Submit the updated A2 evidence/gap assessment for Owner review before any A3 authorization.

**Final disposition: D01 A2 remains PARTIAL — NOT VERIFIED-CLOSED.** This addendum advances the evidence map and separates independent A2 work from specific governance/legal uncertainties; it does not claim those uncertainties have been resolved.

**End of v1.0.2 successor addendum.**
