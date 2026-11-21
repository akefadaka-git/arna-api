# AGE v1.0 — Module 3 Evidence Disposition Register

**Stage:** A2 — read-only evidence reconciliation  
**Status:** PARTIAL — evidence gates remain open  
**Repository:** `akefadaka-git/arna-api`  
**A2 branch:** `a2/module-3-evidence-disposition-register`  
**Exact base/current-main SHA:** `0e33e785089792691749b6f1cd10ead7c8697aea`  
**Baseline source:** `docs/governance/board-election/MODULE-3-SHAREHOLDER-REGISTER-A2-DEPENDENCY-BASELINE.md`  
**Implementation:** A3 NOT STARTED  
**Deployment:** A5 NOT AUTHORIZED; separate Owner approval required

---

## 1. Purpose and disposition rules

This register consolidates the evidence needed before Module 3 can freeze its statutory shareholder-register, record-date, and voting-entitlement contract. It distinguishes:

- **AVAILABLE / VERIFIED:** a source or fact was directly inspected and its stated, limited proposition is supported.
- **PARTIAL:** some evidence exists, but it does not prove the full legal or operational proposition.
- **MISSING:** the required authoritative artifact is not present in the accessible repository/evidence workspace.
- **UNRESOLVED:** conflicting or incomplete evidence prevents a reliable conclusion.
- **FORMALLY ACCEPTED UNRESOLVED:** only an explicit Owner disposition, recorded with scope, rationale, residual risk, and required legal review where applicable, may place an unknown in this category.

No missing legal evidence is treated as waived. No formal Owner acceptance of the eight blockers below is evidenced in the reviewed records. The Owner's software-policy decisions are recorded separately in §4 and do not constitute legal waivers.

## 2. Authoritative evidence gates

| ID | Required evidence / decision | Current disposition | Evidence presently available | Closure evidence / acceptance test |
|---|---|---|---|---|
| M3-E01 | Current statutory shareholder register under Commercial Code Article 274 | **MISSING — BLOCKING** | Recovered historical bylaws transcription (secondary source only); static repository seeder definitions (not live database rows); no certified current extract or verified ARNA database records | Obtain a dated, certified/current head-office register extract. Reconcile holder names and addresses, share numbers/serials, paid-up amounts, entry dates, subsequent admissions, transfers, cancellations and changes. Record custodian, certification/provenance and capture date. Do not treat the historical schedule or seed data as a substitute. |
| M3-E02 | Registered Memorandum/Articles and all amendments | **OWNER-REPORTED HELD; NOT ACCESSIBLE FOR DIRECT INSPECTION — BLOCKING** | Owner previously confirmed possession of an image-scanned original; repository status README and historical notes record that the binary was not preserved or verified. The 17-page bylaws scan is a distinct instrument. | Inspect the complete original registered instrument and amendments, verify page completeness, registration/notarial particulars, signatures/seals and provenance; record checksum and reconcile operative clauses on capital, share classes, voting, caps, proxies, quorum and register. Do not substitute bylaws, OCR or historical summaries. No repeated upload request is made while the known upload limitation persists. |
| M3-E03 | Per-holder paid-up capital and unpaid-call evidence | **UNRESOLVED — BLOCKING** | Recovered historical bylaws transcription reports aggregate capital figures, but the original scan has not been independently reverified here. The separate maale_arna_smart_system SQL dump is not the ARNA database and is excluded. The Owner confirms ARNA has not been migrated or seeded; no current ARNA payment balances are available or asserted. | Reconcile per-holder receipts, bank statements/deposit records, accounting journals, payment dates, calls, waivers, forfeitures and current unpaid balances to the statutory register and share/certificate history. Record exceptions; do not infer payment from application status labels. |
| M3-E04 | Signed share-transfer instruments and complete certificate/register history | **UNRESOLVED — BLOCKING** | Application ledger supports signed-quantity entries and reversals, but no complete signed transfer instruments, pre-emption/consent evidence, certificate endorsements/cancellations or paired statutory register history were recovered. | For every relevant transfer, inspect signed instrument, transferor/transferee identity, date, number/class of shares, required notices/pre-emption/consent, certificate endorsement/cancellation, Article 285 register-entry particulars and both sides of the accounting/holding posting. A `transfer_in`/`transfer_out` pair or free-form reference alone is insufficient. |
| M3-E05 | Legal form of entity holders and effective representative/mandate authority | **UNRESOLVED — BLOCKING FOR AFFECTED HOLDERS** | Current application seeder classifies SH-0023 as `religious_body`; historical notes report a prior `cooperative` classification. Current representative links and audit events do not prove legal appointment, scope, effective dates or revocation. | Obtain constitutive/registration documents and competent entity resolutions; verify the legal holder identity and authorized representative mandate, effective period, scope, replacement/revocation and any election proxy. Preserve entity as holder; do not create a second personal vote for its representative. |
| M3-E06 | Admission/status evidence for SH-0030–SH-0100 and any other later identities | **UNRESOLVED — NOT INVALIDATED** | Current application bootstrap dataset contains 100 identities. The inspected historical schedule contains 29 entries; the other 71 do not appear in that schedule. Absence from the historical schedule does not prove invalidity. | Reconcile each later identity to dated statutory register entries, subscription/allotment or transfer instruments, certificates, payment evidence and entity/identity documents. Record valid, invalid, duplicate, superseded or unresolved outcomes only when supported by evidence. |
| M3-E07 | Election-specific record date and exact cutoff | **MISSING — BLOCKING** | No competent election-specific resolution fixing the record date, exact local time, timezone and authority reference is evidenced. Statutory record-date mechanics do not supply the company/election-specific chosen cutoff. | Obtain the valid election authorization/resolution and applicable governing-instrument clause. Record the exact timestamp and timezone, approval authority, notice/meeting linkage and treatment of same-day entries, late postings, reversals and corrections. A date-only value is insufficient. |
| M3-E08 | Legally operative share classes, class rights and any lawful voting cap | **UNRESOLVED — BLOCKING** | Application codes `FOUNDERS` and `STANDARD` and current ShareClass fields are technical/bootstrap data, not proof of legal classes or rights. Current model has no class-specific voting-right or vote-weight fields. Statutory Articles 383–384 provide a baseline but do not establish company-specific terms in the unavailable registered Memorandum. | Verify class creation/terms, nominal capital, issued shares and rights from the registered Memorandum, amendments and valid resolutions. Obtain qualified Ethiopian legal review of the interaction with Commercial Code Articles 383–384, including any lawful cap and equal treatment across shares. No multiplier/cap may be inferred from application codes. |

### 2.1 No blocker is formally accepted as unresolved

**FORMALLY ACCEPTED UNRESOLVED: NONE EVIDENCED.**

The repository records the gaps and proposed acquisition routes. It does not record a specific Owner decision accepting any of M3-E01–M3-E08 as a permanent or election-specific exception. A statement that an item is unavailable, or that A2 may continue, is not a waiver of the legal evidence requirement.

If the Owner later elects to proceed with a bounded unknown, the disposition must identify the exact item, intended scope/election, rationale, residual risk, compensating controls, expiry/review point and qualified Ethiopian legal opinion where required. It must not be generalized to other elections or future register snapshots.

## 3. Verified historical and technical evidence (limited scope)

| Evidence item | Result | Limitation |
|---|---|---|
| Original 17-page Amharic bylaws/internal-regulations scan | **SOURCE INTEGRITY VERIFIED** against SHA-256 `8df31ad217b2221f42b3e0e1154007bdd5d2ab003df0e1ad3dc7f17c03b61183` | Integrity against supplied checksum does not authenticate registration, legal effect, amendments or current status; it is not the registered Memorandum/Articles. |
| Historical bylaws recovery | Reports a 29-entry schedule and aggregate figures; original PDF bytes/pages were not independently reverified in this pass | SECONDARY HISTORICAL LEAD ONLY; NOT CURRENT REGISTER |
| Static repository seeder | PHP source defines bootstrap entries; Owner confirms ARNA database has not been migrated or seeded | SOURCE CODE ONLY; NO LIVE DATABASE ROWS CONFIRMED |
| Legacy SQL dump named maale_arna_smart_system | External legacy database artifact, not ARNA database | EXCLUDED FROM ARNA EVIDENCE; ALL DUMP-DERIVED FIGURES WITHDRAWN |
| Six canonical Shareholder source files | **UNCHANGED** on the refreshed Module 3 source comparison; blob SHAs are recorded in the A2 baseline §16.2 | Technical contracts only; they do not establish the legal register or historical legal status. |
| Current holding ledger | Append-only signed quantity entries with `occurred_at`, before/after balances and reversal links | Historical quantity source only; transfer instrument, paired transfer aggregate and temporal legal-register status remain separate requirements. |
| Generic versioned policy infrastructure | **FOUND / PARTIAL** | Existing registry lacks the complete authority, approval, evidence-hash and consumer-schema contract needed for an approved voting policy. |

## 4. Owner software-policy decisions — recorded, not legal evidence

1. **Voting:** a locally registered ARNA user with the Shareholder role may vote only where the authoritative register and approved election-specific snapshot independently establish entitlement.
2. **Weight:** Owner direction is one vote per eligible share (1 share = 1 vote), subject to applicable law, the registered governing instruments and valid election-specific restrictions. It is not an activated legal rule until M3-E02, M3-E07 and M3-E08 are reconciled and reviewed.
3. **Nomination:** shareholder status is not a software prerequisite for candidate nomination. Candidacy does not create, modify or imply ownership or voting entitlement.
4. **Separation:** voter entitlement and candidate eligibility remain separate decisions, records and audit trails.

These are Owner-directed product requirements. They do not prove ownership, payment, class rights, representative authority or candidate qualification under law.

## 5. Technical constraints to carry into any later A3 design

The following are design constraints, not permission to implement before the A3 gate:

- Use the database-backed shareholder domain as the operational source for holder facts; do not read the PHP seeder constant at runtime for ownership or voting decisions.
- Treat `ShareHolding.current_quantity` as a projection. Reconstruct historical quantities from the append-only ledger with deterministic ordering and explicit cutoff.
- Preserve both business-effective time and system-recorded time. Current `is_active`, soft-delete and representative fields do not constitute a complete temporal legal register.
- Do not infer a legally valid transfer from ledger entry types or free-form references. A later transfer implementation requires a paired, immutable transfer aggregate and atomic debit/credit, backed by the instrument and statutory register changes.
- Do not rely on the current fail-open shareholder audit listener as the sole legal evidence trail for register changes, transfer, mandate, policy approval or snapshot freeze.
- Treat current `FOUNDERS` / `STANDARD` codes and ETB 10,000 config defaults as bootstrap/configuration values, not legal share-class or voting authorities.
- Require a versioned, schema-validated voting policy with authority, clause, effective period, approval, evidence digest and supersession provenance.
- A frozen election register must preserve exact source manifest, ledger-entry identities/digests, rule version, record-date timestamp/timezone, exceptions, approvals and snapshot digest. Corrections create a superseding version; they do not rewrite the prior snapshot.
- Any later migration/import must be explicitly approved, idempotent, conflict-detecting and provenance-preserving. No seeder rerun may silently overwrite operational register facts.

## 6. A2 exit criteria and stage decision

A2 can be recommended for closure only when the evidence and rule contract is complete enough to make the register and entitlement calculation reproducible and legally supportable, or when each remaining unknown has a specific, explicit Owner disposition and the required qualified legal review. The current record does not meet that condition.

| Stage | Status | Basis |
|---|---|---|
| A1 | **PARTIAL / source requirements recorded** | Owner policy and domain requirements are recorded; legal instruments and current register are not fully verified. |
| A2 | **PARTIAL — OPEN** | Technical/statutory reconciliation is documented; M3-E01–M3-E08 remain open. |
| A3 | **NOT STARTED / NOT AUTHORIZED** | No implementation branch or schema/rules freeze authorized by this register. |
| A4 | **NOT RUN** | No Module 3 implementation exists to verify. |
| A5 | **NOT AUTHORIZED** | Deployment is a separate Owner approval stage. |

**Immediate next evidence action:** obtain the current certified Article 274 register extract through the Company's register custodian and inspect the Owner-held registered Memorandum/Articles and amendments when an accessible inspection route is available. In parallel, reconcile the per-holder payment and transfer records to those primary sources. No request is claimed to have been served and no statutory response period is claimed to have started.

## 7. Change-control statement

This commit is documentation-only on the isolated A2 branch. It does not change application code, migrations, schema, routes, permissions, tests, seed data, production data, `main`, merges or deployment. The branch is based exactly on current `main` SHA `0e33e785089792691749b6f1cd10ead7c8697aea`.

**Deployment remains outside this work and requires separate explicit Owner approval.**


## 8. Owner correction — no ARNA database migration or seeding verified

The Owner has clarified that the ARNA database has not yet been migrated or seeded. This A2 review did not connect to or query a live ARNA database. Therefore:

- No current ARNA shareholder, payment, transaction, transfer or status rows are verified.
- Repository seeder definitions are static code, not persisted database rows.
- The separate `maale_arna_smart_system` SQL dump is not the ARNA database. All values and comparisons derived from it are withdrawn from ARNA findings and excluded.
- Historical bylaws recovery material is a secondary document lead, not a live database or certified current statutory register.
- The prior 27/29 and 20/29 legacy-SQL conflict figures and any claim of a database-level identity crosswalk are withdrawn.

**No migration, seed, database write, or production-data operation was performed.** All eight statutory evidence gates remain open. A2 remains PARTIAL; A3 NOT STARTED; deployment NOT AUTHORIZED.
