# ARNA D01 — A2 Evidence Reconciliation Register

- **Record ID:** ARNA-D01-A2-EVID-REG-001
- **Version:** 1.0.0
- **Stage:** A2 — read-only evidence reconciliation
- **Disposition:** **PARTIAL — NOT VERIFIED-CLOSED**
- **Domain:** D01 — Corporate Strategy & Enterprise Planning
- **Parent catalogue:** `ARNA-ESPPM-A1-SPEC-002 v1.2.0` — frozen 30-domain architecture
- **Subordinate A1:** `ARNA-D01-A1-SPEC-001 v1.0.0` — draft, Owner review pending; PR #222
- **Repository:** `akefadaka-git/arna-api`
- **Branch:** `docs/arna-d01-a2-evidence-register`
- **API main inspected:** `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **App main inspected:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`
- **Prepared:** 2026-11-24
- **Change class:** Documentation-only A2 evidence register
- **A3 implementation / merge / A4 / A5:** NOT AUTHORIZED BY THIS RECORD

---

## 1. Executive disposition

The D01 evidence search retrieved a number of relevant corporate and governance records, but the evidence does not yet establish an approved, current enterprise strategy and planning authority.

**A2 checklist result (24 requirements):**

| Disposition | Count | Meaning |
|---|---:|---|
| PARTIAL | 16 | Relevant evidence exists, but it proves only part of the requirement or remains subject to authority/provenance verification |
| MISSING | 7 | No suitable evidence was located in the inspected repository and accessible Library scope |
| UNRESOLVED | 1 | Material source conflict prevents reliable determination |
| **Total** | **24** | All D01-A1 evidence requirements assessed |

No item is classified as fully closed. In particular:
- No adopted GA/Board decision approving the currently stated 2036 E.C. corporate vision, mission and strategy was located.
- The separate registered Memorandum/Articles source remains unavailable for direct inspection in the repository.
- The current status of the initial Board and initial Managing Director appointment is not established by the historical founding text.
- No approved multi-year strategy, strategic-objective cascade, scenario/baseline register or strategic planning calendar was located.
- No dedicated accepted D01 strategy/planning contract was identified in the inspected API/App mainline.
- D01 remains **UNVERIFIED / OPEN** under the enterprise A2 closure report.

This register records evidence and gaps. It does not authenticate external instruments, create governance authority, appoint officers, approve strategy, accept legal exceptions, or authorize implementation.

## 2. Evidence standard and status definitions

- **VERIFIED — LIMITED FACT:** the exact retrieved source supports the narrow fact stated; this does not certify legal effect beyond the source.
- **PARTIAL:** relevant evidence exists, but completeness, authority, currentness, provenance, adoption or operational proof is missing.
- **MISSING:** the required evidence was not located in the inspected sources. This is not proof that it does not exist elsewhere.
- **UNRESOLVED:** contradictory or insufficient evidence prevents a reliable conclusion.
- **NOT INSPECTED:** source is referenced by another record but its original bytes/pages were not directly inspected in this reconciliation.
- **DRAFT / NOT ADOPTED:** proposal or draft record; it is not an operative corporate decision.
- **NOT RUN:** no test or runtime verification was performed.

A repository path, OCR/transcription, owner-supplied statement, code seeder, draft PR or prior summary is not silently promoted to an adopted corporate resolution.

## 3. Source register — exact retrieved references

### 3.1 Corporate and legal instruments

| Source ID | Exact source | Reference / identity | What it supports | Limitation |
|---|---|---|---|---|
| SRC-01 | Owner-confirmed 17-page Amharic bylaws/internal-regulations scan | Filename: `የማሌ_ዓርና_አክሲዮን_ማኅበር_መተዳደሪያ_ደንብ.pdf`; Owner-supplied SHA-256: `8df31ad217b2221f42b3e0e1154007bdd5d2ab003df0e1ad3dc7f17c03b61183`; source record SRC-02 below | Owner confirms it is the latest complete approved bylaws version, including amendments/resolutions, signed by Southern Ethiopia Trade and Market Bureau | Original PDF bytes/pages were not directly retrieved in this pass; source record states checksum/provenance are Owner-supplied and not independently authenticated against Bureau records |
| SRC-02 | P2.5 source/statutory reconciliation | API `main`; `docs/governance/elections/P2.5-SOURCE-AND-STATUTORY-RECONCILIATION-v1.0.md`; blob `8101ccbd3a9fbdc194c5a51aef34e0e458c11132` | Records Owner confirmations, source identity correction, 17-page scan checksum, and limits of provisional transcription | Election/statutory issue register, not a legal opinion or D01 strategy approval |
| SRC-03 | Historical bylaws transcription/recovery | Library file `maale_arna_bylaws_full_recovery.md`; file ID `file_00000000865c82119c8381da1ad22bfc`; version 1 | Page-indexed recovery of 17-page scan, 29-entry schedule and provisional content | Explicitly a working transcription/reconstruction, not certified or legally authenticated; pages 3–8 and 12–17 require human verification |
| SRC-04 | 22-page DOCX titled “የማሌ ዓርና አክስዩን ማኅበር መተዳደሪያ ደንብና መመሥረቻ ጽሑፍ” | Library file ID `file_00000000a2248246b741e6cf3a0a8364`; version 1; created 2026-11-22; 94,295 bytes; SHA-256 computed from retrieved bytes: `3683b521f97b136de5849ef2cfd4ccd9eaf870836605688c1e71058498da9d67` | Contains bylaws text, governance clauses, fiscal-year statement, initial Board/MD text, founder list and a closing statement that a founding instrument was signed 16/12/2016 E.C. | DOCX provenance, relationship to SRC-01, certification, registration and whether it is a transcription/derivative or controlling instrument are not established. Its 54 founder-name entries and share schedule materially differ from the 17-page scan recovery. Treat as a conflicting candidate transcription, not as a substitute for the registered original |
| SRC-05 | Articles of Association source-status record | API `main`; `docs/legal/articles-of-association/README.md`; blob `c90f607d818304eaa44921d2bc05188a7e4da878` | Records Owner-reported possession of a separate image-scanned Articles PDF, historically described as dated 16/12/2016 E.C. and executed/notarized/sealed | Original PDF bytes, filename, page count, checksum and clause text have not been recovered/verified in the repository |
| SRC-06 | Historical Articles reconciliation notes | API `main`; `docs/legal/articles-of-association/HISTORICAL-RECONCILIATION-NOTES.md`; blob `856cd6afbcfd16f26b26c98d91850ebd82fcd29a` | Explicitly distinguishes the Articles from the 17-page bylaws scan; records historical source leads and conflicts | Working notes, not a legal opinion, adopted policy, verified transcription or proof of original custody |
| SRC-07 | Governance provisional control matrix | API `main`; `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md`; blob `07eb3f567bcc67fe2c7ac8e66c0a993c0d1e69c2` | Owner-approved 2026-10-22 control principles: segregation of duties, evidence retention, conflict declarations, bounded delegation, audit trail, tenant scope and denial of unverified authority | Approval is expressly limited to control principles. It does not verify legal powers, current office-holders, committee appointments, quorum, financial limits, bank signatories or strategy approvers |
| SRC-08 | Document approval authority decision register | API `main`; `docs/governance/document-approval-authority-matrix.md`; blob `f53446ece9481ddf09759854cf2fd63e4ba20709` | Enforces default-deny where source authority is not evidenced; confirms business approver assignments remain blocked | General document-approval register, not an adopted D01 strategy approval matrix |
| SRC-09 | Governance / GA A1 design | API `main`; `docs/governance/ARNA-GOV-GA-A1-001.md` | States appointment, removal, delegation, quorum, chair casting vote and reserved-matter powers must follow operative governing sources | Architecture/design evidence; does not itself create or verify those powers |

### 3.2 Owner-provided strategy statements

| Source ID | Exact source | Reference | What it supports | Limitation |
|---|---|---|---|---|
| SRC-10 | Owner-provided vision, mission and strategy content map | App `main`; `docs/public-site/MAALE_ARNA_BYLAWS_CONTENT_MAP.md`; blob `5f37cee13a1b38e3048b3b4db3ef3dd068e18fc2` | Explicitly says vision, mission and strategy were supplied directly by Owner on 2026-11-14 and are not quotations from the bylaws | A3 public-site content map; does not evidence GA/Board adoption as the operative corporate strategy |
| SRC-11 | Corporate site baseline seeder | API `main`; `database/seeders/CorporateSiteBaselineSeeder.php`; blob `63c8399653053e46b6a605da7622c5622d7755bb` | Stores the same 2036 E.C. vision and mission as source-grounded draft corporate content; code sets seeded pages to `draft` | Seeder code is not a corporate resolution, does not prove the seeder ran, and explicitly does not approve or publish content |

**Owner-provided statement summary (not an adopted strategic plan):**
- Vision: by 2036 E.C., rank among Ethiopia's ten leading competitive enterprises in technology adoption, economic capacity and accessibility.
- Mission: use evolving technologies and market research to meet community needs through commercially viable business sectors and become a preferred company across its fields.
- Strategy narrative: technology adoption, sound economic strategies, sustainability, innovation, operational efficiency, accessibility and customer satisfaction.
- Values listed in the content map: integrity, excellence, innovation and sustainability.

No strategic pillars with approved owners, measurable strategic objectives, annual targets, approved budgets, scenarios or strategy implementation roadmap were located in these records.

### 3.3 Enterprise A1/A2 and KPI records

| Source ID | Exact source | Reference | What it supports | Limitation |
|---|---|---|---|---|
| SRC-12 | Frozen 30-domain A1 catalogue | API PR #212; branch `docs/esppm-a1-v1.2.0-frozen-catalogue`; head `631b7a0eb7f027c17751b7af39bbc826fd70124e`; blob `e9cd1f4a087a3d7f285ccda7786149d64db6a4bc` | Frozen parent domain architecture and D01 catalogue scope | Draft archive PR #212 is unmerged; it is the established A1 catalogue baseline for this workstream, not corporate strategy approval |
| SRC-13 | Enterprise A2 closure report | API PR #219; branch `docs/esppm-a2-closure-report-001`; head `f306814c05f0f34fd0e2dcf6798adbafb1bd2c42`; blob `49f571fb7797ab8cf7027a5a66f072b0e2d17d80` | D01 A2 disposition UNVERIFIED; no dedicated accepted strategy/planning contract in inspected API/App paths; GAP-001; DEP-01; API/App exact-head baselines | Draft, unmerged A2 report; closure not recommended; does not itself close A2 |
| SRC-14 | D01 subordinate A1 specification | API PR #222; branch `docs/arna-d01-a1-spec-a2-evidence`; commit `cf4486f0c983641bfd3f81ad284b5efe4e57c401`; blob `c04dc84e21ccc9114d8952ba4d38913e36e58004` | Domain scope, exclusions, proposed concepts, 24 evidence requirements and A3 entry gates | Draft / Owner review pending; not frozen and not an approved implementation contract |
| SRC-15 | 30-domain KPI readiness and ownership register | API PR #220; branch `feat/arna-ecc-a3-p0`; commit `8d0e4f236cc23eea21b0c44b4f3571d0710f2120`; blob `f3a8237d6c014aa4cc75506626ef1cf4d2f061f8` | D01 readiness UNAVAILABLE in KPI/provider evidence; no named accountable officer or data steward; no approved KPI | Draft PR branch, not mainline evidence; it is an enterprise KPI governance register, not a D01 strategy document |

## 4. Material source reconciliation and contradictions

### 4.1 The two bylaw-related records are not interchangeable

**17-page scan (SRC-01 / SRC-02):**
- Owner confirms it is the latest complete approved bylaws/internal-regulations version, including amendments/resolutions, signed by the Southern Ethiopia Trade and Market Bureau.
- Owner-supplied SHA-256 is recorded as `8df31ad217b2221f42b3e0e1154007bdd5d2ab003df0e1ad3dc7f17c03b61183`.
- Its title is `የመተዳደሪያ ደንብ` (bylaws/internal regulations).
- The repository evidence record expressly says it is not proven to be the separate statutory Memorandum/Articles.
- The recovered schedule reports 29 listed holders, 145 shares, ETB 10,000 nominal value, ETB 1,450,000 stated capital, ETB 1,023,000 paid and ETB 427,000 unpaid. These are historical source-table figures, not a current register or payment verification.

**22-page DOCX (SRC-04):**
- The title includes both bylaws and founding instrument terminology.
- Pages 1–4 begin with bylaws clauses and include GA/Board provisions.
- Page 17 states the accounting year starts 1 Hamle and ends 30 Sene.
- Pages 18–20 include clauses identifying the GA as the highest authority, Board responsibilities, an initial Board list, and an initial Managing Director / General Manager appointment.
- Page 21 says the founding instrument was signed on 16/12/2016 E.C. in Arba Minch; pages 21–22 list 54 founder-name entries.
- The shareholder/capital schedule in this DOCX has an aggregate different from the 17-page recovery (the visible table total reports 560 shares and ETB 2,800,000; the row count and arithmetic require a separate source-level audit).
- Its provenance, approval/registration, relationship to the 17-page scan, and status as an original versus transcription/derivative are not established.

**Reconciliation outcome:** UNRESOLVED. Do not select the 22-page DOCX as the controlling legal instrument, do not overwrite the 17-page scan record, and do not merge the two shareholder/capital schedules. Obtain the registered Articles/Memorandum and confirm the relationship of both bylaw-related records with the issuing Bureau/company records. This issue is material to the D01 approval-authority determination.

### 4.2 Governance authority inferred only within the limits of the retrieved text

The 22-page DOCX contains the following text-level propositions:
- Clause 11.1: the General Assembly is described as the highest authority.
- Clause 12.2: the Board controls company administration and carries out measures to achieve company objectives under General Assembly direction; it appoints certain officers and may submit investment proposals to the GA.
- Clause 12.7: the Board has residual company powers unless reserved or assigned elsewhere by law, the founding instrument, bylaws or GA resolution.
- Clauses 12.8–12.9: delegation/signing powers are described.
- Clauses 13.1–13.3: the Board selects a managing director/general manager; the text names the initial office-holder.

These are provisional propositions from SRC-04, not a legal finding that the DOCX is current, registered or controlling. They do not by themselves prove who currently approves enterprise strategy or who currently holds the named office. The provisional governance control matrix and document approval matrix continue to require exact authority instruments and current adopted resolutions.

### 4.3 Fiscal year and meeting cadence are not the strategic planning calendar

SRC-04 states:
- Accounting year: 1 Hamle to 30 Sene.
- Annual General Assembly: once each year and not later than the end of September, in the visible clause 4.1 text.
- GA notice: 30 days before the meeting, in clause 5.1.

These clauses are useful planning constraints if confirmed against the controlling instrument. They do not establish:
- the strategy horizon or strategy review cycle;
- annual corporate planning submission dates;
- quarterly/monthly planning cycles;
- objective and budget cascade milestones;
- scenario/baseline approval dates; or
- the E.C./G.C. display and conversion rules for every planning period.

D01-A2-06 therefore remains PARTIAL.

## 5. D01 24-item evidence checklist — reconciled dispositions

| Evidence ID | Required evidence | Status | Exact source evidence / result | Remaining closure evidence |
|---|---|---|---|---|
| D01-A2-01 | Operative bylaws, constitutive instruments and certified amendments | **UNRESOLVED** | SRC-01/SRC-02: Owner-confirmed 17-page scan and checksum; SRC-03: working transcription; SRC-04: conflicting 22-page DOCX; SRC-05/SRC-06: separate Articles source not preserved/verified in repository | Directly inspect registered Articles/Memorandum and amendments; reconcile SRC-01 and SRC-04 against issuer/company records; confirm registration, signatures, stamps, dates, completeness and current status |
| D01-A2-02 | Current GA/Board resolutions approving strategy, strategic direction or planning mandates | **MISSING** | No adopted strategy-approval resolution located in inspected API/App or accessible Library results. SRC-10 is Owner-provided statements, not a resolution | Retrieve signed/adopted resolution and minutes with decision number, date, competent body, quorum/vote, exact approved strategy/version, effective date and supersession |
| D01-A2-03 | Board/GA authority, delegation and approval matrix | **PARTIAL** | SRC-04 clauses 11.1, 12.2, 12.7–12.9 are candidate text; SRC-07 is Owner-approved provisional controls only; SRC-08 has default-deny rule and blocked business approver assignments; SRC-09 requires operative sources | Verify registered clauses and current adopted Board/GA rules, delegation, reserved matters, approval thresholds, quorum, amendment/publication authority and current appointments |
| D01-A2-04 | Current org chart and formal accountable-executive/planning appointments | **PARTIAL** | SRC-04 lists an initial Board and initial MD/GM (clauses 12.2 and 13.3); SRC-07/08 reject inferred appointments; KPI register SRC-15 shows no verified D01 accountable officer or steward | Obtain current org chart, current appointment/election resolutions, effective dates, terms, acceptance/cessation records, strategy-process-owner appointment and objective-owner assignments |
| D01-A2-05 | Existing approved strategy, multi-year plans, annual plans and prior versions | **PARTIAL** | SRC-10 contains Owner-provided vision/mission/strategy dated 2026-11-14; SRC-11 stores draft baseline text; no approved plan/version or adoption resolution located | Retrieve adopted strategy document(s), version history, themes/pillars, horizon, annual plans, approval and effective/supersession records |
| D01-A2-06 | Approved planning calendar, fiscal/planning-year rules and cycle milestones | **PARTIAL** | SRC-04 clause 8 states accounting year 1 Hamle–30 Sene; clause 4.1 states annual GA before end of September; clause 5.1 gives 30-day GA notice | Confirm source instrument; retrieve strategy/planning calendar, fiscal/planning-year conventions, submission/review/approval dates, E.C./G.C. semantics and planning-cycle cadence |
| D01-A2-07 | Strategic hierarchy and objective cascade | **MISSING** | No approved strategic themes-to-objectives-to-domain plans cascade or objective IDs located in the inspected evidence | Approved hierarchy, objective register, parent/child links, accountable role/domain, effective period and domain-plan traceability |
| D01-A2-08 | Scenarios, assumptions, baselines, versioning and change records | **MISSING** | No approved scenario register, planning assumptions, baseline records or change/supersession log located | Approved scenario methodology, assumption owner, baseline source/date, version policy, approval and change history |
| D01-A2-09 | Strategy-to-budget/investment decisions and capital approvals | **PARTIAL** | SRC-04 clause 12.2 contains Board investment-proposal language to GA; SRC-13 identifies D25 as separate capital/investment owner | Adopted capital-allocation resolutions, investment cases and approvals linked to strategy objectives; confirm D01/D25 boundary and no implied spending authority |
| D01-A2-10 | Strategy-to-project/portfolio intake and handoff | **MISSING** | SRC-13 identifies D07 as a consumer of D01 strategy; no approved intake or traceable handoff examples located | Approved D01 objective references in D07 intake/business cases, priority decision, delivery owner, benefits handoff and lifecycle references |
| D01-A2-11 | D02 KPI dictionary, target/formula catalogue and objective-to-KPI mapping | **PARTIAL** | SRC-13 establishes D02 as the KPI/performance consumer; SRC-15 records no approved D01 KPI and only D09/D11 candidate providers in enterprise register | Approved D02 KPI dictionary and objective mappings with formulas, units, target versions, owners, periods, source lineage and approval |
| D01-A2-12 | D23 certified BI datasets, semantic definitions and lineage | **PARTIAL** | SRC-13 identifies D23 as a consumer; no D01-certified dataset, semantic model or strategy-to-report lineage was located | Certified D23 data/semantic contract, source objective IDs, lineage, freshness, quality controls and access/approval evidence |
| D01-A2-13 | D04 risk appetite, enterprise risk register and strategic risk links | **MISSING** | No accepted D04 risk appetite/register or D01 strategic risk linkage located; draft PSC controls are not a risk domain contract | Approved risk appetite, taxonomy, register, risk owners, strategic risk/assumption mapping and escalation/acceptance decisions |
| D01-A2-14 | Legal/regulatory obligations and legal advice relevant to strategy | **PARTIAL** | SRC-01/SRC-02 and SRC-13 provide bylaw/statutory source leads; SRC-05/SRC-06 record missing separate Articles and unresolved legal-source identity | Certified governing instruments, applicable legal obligations register, current legal advice, licensing constraints and competent legal review for material strategic commitments |
| D01-A2-15 | Exact-main API D01 source inspection | **PARTIAL** | API main SHA `6e274c25ecc55b84e026e879d735b77f0e51592f`; SRC-13 reports no dedicated accepted D01 strategy/planning contract in inspected paths; targeted search found no dedicated D01 strategy module/contract | Complete path/tree inventory and record exact negative-search scope; identify any hidden/shared implementation, routes, models, policies, migrations, events, tests and owners; distinguish absent from not indexed |
| D01-A2-16 | Exact-main App D01 feature/UI inspection | **PARTIAL** | App main SHA `ffe0495c0eb7c8b5d2013419123aac5b68893d51`; SRC-13 reports no dedicated accepted D01 contract; SRC-10 is public-site content only, not an internal strategy workspace | Complete feature/route/navigation/permission/test inspection; identify any executive planning UI and its API contract; confirm no duplicate or public-content-only substitute |
| D01-A2-17 | D01 branches, PRs and workflow evidence | **PARTIAL** | PR #219 is draft A2 report; PR #220 is draft KPI register; PR #222 is draft D01 A1 spec. All are documentation proposals; no D01 A3 implementation PR or D01-specific CI acceptance was evidenced | Re-fetch current PR/branch heads and Actions; identify all D01-related branches and workflows; require exact-SHA D01 acceptance evidence only after A3 authorization |
| D01-A2-18 | Canonical identity, tenant, RBAC and authorization conventions | **PARTIAL** | SRC-07/08 require fail-closed authority, tenant scope and distinct workflow/authority checks; enterprise A2 report records canonical platform RBAC baseline; no D01-specific access contract located | Exact current schema/policy/test inspection; D01 tenant/entity scope, role mapping, action permissions, delegation and cross-domain access rules; no wildcard/inferred grants |
| D01-A2-19 | Audit, evidence retention, records and legal-hold policy | **PARTIAL** | SRC-07 approves audit/evidence-retention control principles; SRC-08 describes document/records and legal-hold evidence requirements; no D01-specific retention schedule located | Approved records-retention schedule, audit event taxonomy, immutable decision evidence, custodians, legal-hold rules, archival/destruction and access log policy |
| D01-A2-20 | Amharic-first terminology, E.C. calendar and accessibility | **PARTIAL** | Parent A1 architecture requires Amharic-first and E.C. default with G.C. preference; SRC-10 includes Amharic and English content; SRC-04 has E.C. fiscal-year text | D01 glossary/translation approval, calendar behavior tests, period/date storage semantics, screen-reader/accessibility acceptance and bilingual meaning review |
| D01-A2-21 | Data dictionary, lineage, reference-data and freshness rules | **MISSING** | No approved D01 data dictionary, strategy-objective identifier standard, source lineage or freshness/quality contract located | Approved canonical IDs, definitions, source lineage, data ownership, effective dating, quality rules, freshness and downstream reference contract |
| D01-A2-22 | Strategy process maps, templates, consultation records and review minutes | **MISSING** | No approved strategy formulation, annual planning, consultation, review or exception process pack located | Approved process maps, templates, planning instructions, consultation records, review minutes, challenge/approval workflow and exception register |
| D01-A2-23 | Security/privacy classification, access matrix and segregation of duties | **PARTIAL** | SRC-07 control principles; SRC-08 default-deny authority and separate confidentiality authorization; no D01-specific data classification or approved approver/access matrix | Approved strategy confidentiality classes, access matrix, proposer/reviewer/approver separation, conflict rules, privacy assessment, privileged action controls and publication permissions |
| D01-A2-24 | Accepted D01 dependencies and producer/consumer contracts | **PARTIAL** | SRC-13 DEP-01: D01 → D02, D07 and D23; related D03 authority, D04 risk, D06 legal, D08 structure, D25 investment and D26 partnership boundaries recorded in D01 A1 draft | Approved interface contracts, stable IDs/version semantics, source ownership, effective-date rules, error handling, security, lineage and Owner-accepted exceptions for each dependency |

## 6. Current API/App source inspection

### 6.1 Exact repository baselines

| Repository | Ref | Exact SHA | Result |
|---|---|---|---|
| `akefadaka-git/arna-api` | `main` | `6e274c25ecc55b84e026e879d735b77f0e51592f` | Current sampled HEAD re-fetched; no later commit returned by recent commit search |
| `akefadaka-git/arna-app` | `main` | `ffe0495c0eb7c8b5d2013419123aac5b68893d51` | Current sampled HEAD re-fetched; no later commit returned by recent commit search |

### 6.2 API finding

The enterprise A2 report at SRC-13 explicitly records D01 as UNVERIFIED and says no dedicated accepted strategy/planning contract was identified in the inspected API paths. The identified D01 gap is strategic hierarchy, planning cycle, scenario/baseline controls, approval authority, strategic objective ownership and traceability to performance measures.

The existence of Governance, Organization, Finance, People, Documents or other domain roots does not establish D01 implementation. No D01 runtime model, migration, route, policy, service, event, provider or test is accepted by this register as a complete strategy contract.

### 6.3 App finding

The enterprise A2 report at SRC-13 records no dedicated accepted D01 contract in inspected App paths. The App's bylaws content map is a corporate/public-content artifact. It does not prove an internal strategy workspace, strategic planning workflow, objective cascade, or API integration.

### 6.4 PR and branch status

| PR | Purpose | Branch / commit | State | A2 interpretation |
|---|---|---|---|---|
| #219 | Enterprise 30-domain A2 closure report | `docs/esppm-a2-closure-report-001` / `f306814c05f0f34fd0e2dcf6798adbafb1bd2c42` | OPEN / DRAFT / UNMERGED | Evidence summary; recommends A2 remain PARTIAL |
| #220 | KPI readiness and ownership register | `feat/arna-ecc-a3-p0` / `8d0e4f236cc23eea21b0c44b4f3571d0710f2120` | OPEN / DRAFT / UNMERGED | D01 KPI readiness UNAVAILABLE; no named officer/steward |
| #222 | D01 subordinate A1 specification | `docs/arna-d01-a1-spec-a2-evidence` / `cf4486f0c983641bfd3f81ad284b5efe4e57c401` | OPEN / DRAFT / UNMERGED | Draft A1 specification and evidence checklist only |

No PR above is a D01 A3 implementation. No D01 workflow/test result is represented as PASS. No application changes were made during this A2 reconciliation.

## 7. D01 dependency and ownership findings

| Dependency | Producer / authority | Consumer / relationship | Status |
|---|---|---|---|
| DEP-01 | D01 — approved strategy versions, objectives, priorities and planning baselines | D02 EPM, D07 Portfolio, D23 BI | Identified in enterprise A2; detailed interface contracts not accepted |
| DEP-02 | D02 — KPI definitions, formulas, targets and calculations | D23 BI; D01 consumes approved performance evidence | Boundary identified; no D01 objective-to-KPI map located |
| DEP-03 | D03 — governance decisions and delegated authority | D01 strategy approval, amendments and publication | Authority source must be verified; no inferred approval |
| DEP-04 | D06 — legal obligations and certified legal instruments | D01 strategic constraints and regulated commitments | Legal evidence partial; source identity unresolved |
| DEP-05 | D08 — organizational structure and effective-dated assignments | D01 objective accountability and domain planning | Organization contract exists; no D01 objective owner assignments |
| Capital relationship | D25 owns capital allocation and investment approval | D01 supplies strategic alignment and priority context | No D01 priority is a capital authorization |
| Partnership relationship | D26 owns partnership lifecycle and agreements | D01 supplies strategic alignment context | No D01 priority is a partner approval |

**Ownership gap:** The enterprise A2 report's GAP-001 proposes “Chief Executive / Strategy Executive” as an accountable role. This is a proposed role label, not evidence of a current appointment. The KPI readiness register reports no verified named D01 accountable officer or data steward. No role-holder is assigned by this A2 record.

## 8. Unresolved decisions and blockers

| Decision ID | Required decision / evidence | Decision authority to be verified | Status / consequence |
|---|---|---|---|
| D01-DEC-01 | Determine the controlling relationship among the 17-page bylaws scan, 22-page DOCX and separate registered Articles/Memorandum | Owner to obtain source custody evidence; legal/company secretary or competent authority to confirm legal identity | UNRESOLVED; blocks legal authority closure |
| D01-DEC-02 | Confirm the competent body and procedure for approving the enterprise strategy, strategic themes, material amendments and publication | GA/Board authority under the registered instruments and applicable law | MISSING; strategy approval workflow must remain fail-closed |
| D01-DEC-03 | Determine whether the 2026-11-14 Owner-provided vision/mission/strategy statements are adopted corporate strategy or remain proposed corporate statements | Competent corporate authority | PARTIAL; do not label as adopted strategy without resolution |
| D01-DEC-04 | Confirm current Board composition, current MD/CEO appointment, effective terms and delegated powers | Competent GA/Board authority under certified instruments | UNRESOLVED; historical initial appointment is not proof of current office |
| D01-DEC-05 | Appoint/confirm D01 accountable executive and strategy-process owner; assign objective owners | Owner/competent governance body under verified authority | MISSING; no named D01 officer/steward may be inferred |
| D01-DEC-06 | Approve strategic planning horizon, planning cadence, fiscal/planning-year boundary, E.C./G.C. semantics and annual planning calendar | Competent strategy authority | PARTIAL; fiscal year and GA timing alone do not complete planning calendar |
| D01-DEC-07 | Approve strategy hierarchy, themes, objective taxonomy, objective IDs, alignment and cascade rules | Competent strategy authority | MISSING |
| D01-DEC-08 | Approve scenario, assumption, baseline, strategy version, amendment and supersession controls | Competent strategy authority with D03 governance | MISSING |
| D01-DEC-09 | Approve D01/D02/D07/D23 interface contracts and D03/D04/D06/D08/D25/D26 dependency boundaries | Respective domain authorities through governed contract approval | PARTIAL; no accepted contracts |
| D01-DEC-10 | Approve D01 confidentiality classes, access matrix, retention, audit and publication authority | Competent authority with D05/D06/D20 input | PARTIAL; control principles do not assign approvers |
| D01-DEC-11 | Resolve the difference between the historical founder/share schedules and establish which legal source is current | Competent legal/company authority; not a D01 business decision alone | UNRESOLVED; do not use inconsistent schedules to infer strategy authority |

### 8.1 Known non-equivalences

The following must remain distinct:
1. Bylaws/internal regulations are not automatically the registered Memorandum/Articles.
2. An Owner-provided vision statement is not automatically a GA/Board-adopted strategy.
3. A public-site draft or seeder is not proof of approved corporate policy.
4. The initial Board/MD text in a founding-era document is not proof of current appointment or term.
5. A fiscal year and annual GA date do not constitute an approved strategic planning calendar.
6. A strategic objective is not a D02 KPI definition, D07 project approval, D25 capital approval or D26 partnership approval.
7. A system role or administrator permission does not confer corporate strategy approval authority.

## 9. A2 acceptance criteria and next evidence actions

D01 A2 cannot be accepted until the following evidence is retrieved, verified and reconciled, or the Owner explicitly accepts a scoped residual exception with rationale, risk, owner and due date:

1. Directly inspect the separate registered Articles/Memorandum and all amendments; reconcile the two bylaw-related records without overwriting either source.
2. Obtain signed/adopted GA and Board minutes/resolutions that establish strategy approval authority and, if available, approve the current strategy baseline.
3. Verify current Board and executive appointments, effective dates and delegated authority.
4. Obtain the approved multi-year strategy, annual planning instructions/calendar, strategic hierarchy, objective cascade, scenario and baseline records.
5. Confirm D01 accountable executive, process owner, and objective owners through formal appointments or an explicit accepted exception.
6. Reconcile D01 with D02 KPI definitions and target ownership, D07 portfolio intake and D23 certified reporting/lineage.
7. Verify legal, risk, organization, capital and partnership constraints through D03/D04/D06/D08/D25/D26 source evidence.
8. Complete exact-current-head API/App path and contract inventory; identify implementation gaps without modifying runtime code.
9. Confirm tenant, RBAC, segregation of duties, audit, confidentiality, retention, privacy, Amharic-first, E.C. and accessibility evidence.
10. Produce a D01 A2 contract/gap register and submit the disposition for explicit Owner decision.

**A2 remains read-only.** Any future implementation requires the D01 A1 specification to be approved/frozen, D01 A2 to be accepted or residual exceptions explicitly accepted, and a separate bounded A3 authorization.

## 10. Final status

| Work item | Status |
|---|---|
| Search accessible Library for D01-related source documents | PASS — relevant records located |
| Retrieve repository governance/legal records | PASS — exact paths and blobs recorded |
| Inspect 22-page DOCX text at key governance/fiscal/signature pages | PASS — content inspected; provenance unresolved |
| Authenticate 17-page scan and separate Articles | PARTIAL / NOT DIRECTLY AUTHENTICATED |
| Reconcile strategy approval authority | NOT MET |
| Verify current accountable officer and process owner | NOT MET |
| Locate approved strategy and objective cascade | NOT MET |
| Reconcile all 24 A2 evidence requirements | PASS — dispositions recorded |
| D01 A2 acceptance | **NOT MET** |
| D01 implementation authorization | **NO** |
| Merge / deployment authorization | **NO** |

**Final disposition: D01 A2 PARTIAL — NOT VERIFIED-CLOSED.** The most important blocker is not a software gap: it is the absence of a verified, current strategy approval authority and an adopted enterprise strategy baseline, compounded by unresolved legal-source identity and current appointment evidence.

---

## Appendix A — source links

- P2.5 source/statutory reconciliation: https://github.com/akefadaka-git/arna-api/blob/6e274c25ecc55b84e026e879d735b77f0e51592f/docs/governance/elections/P2.5-SOURCE-AND-STATUTORY-RECONCILIATION-v1.0.md
- Governance provisional control matrix: https://github.com/akefadaka-git/arna-api/blob/6e274c25ecc55b84e026e879d735b77f0e51592f/docs/governance/ARNA-GOV-MAT-PSC-001-AM.md
- Document approval authority matrix: https://github.com/akefadaka-git/arna-api/blob/6e274c25ecc55b84e026e879d735b77f0e51592f/docs/governance/document-approval-authority-matrix.md
- Articles source-status record: https://github.com/akefadaka-git/arna-api/blob/6e274c25ecc55b84e026e879d735b77f0e51592f/docs/legal/articles-of-association/README.md
- Historical Articles reconciliation: https://github.com/akefadaka-git/arna-api/blob/6e274c25ecc55b84e026e879d735b77f0e51592f/docs/legal/articles-of-association/HISTORICAL-RECONCILIATION-NOTES.md
- App bylaws content map: https://github.com/akefadaka-git/arna-app/blob/ffe0495c0eb7c8b5d2013419123aac5b68893d51/docs/public-site/MAALE_ARNA_BYLAWS_CONTENT_MAP.md
- Enterprise A2 closure report PR #219: https://github.com/akefadaka-git/arna-api/pull/219
- D01 subordinate A1 PR #222: https://github.com/akefadaka-git/arna-api/pull/222
- KPI readiness register PR #220: https://github.com/akefadaka-git/arna-api/pull/220

**End of register.**
