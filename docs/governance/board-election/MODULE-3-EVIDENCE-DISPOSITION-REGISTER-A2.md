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
| M3-E01 | Current statutory shareholder register under Commercial Code Article 274 | **MISSING — BLOCKING; acquisition route verified** | The 17-page bylaws recovery supports only the existence of a 29-entry historical schedule and aggregate totals; individual names/allocations are not transcribed. The 100-row application seeder and legacy SQL snapshots are not the statutory register. Article 274(4) allows any person to obtain a copy/extract on payment of the prescribed fee, due within 15 days of the request. No request receipt or current extract is evidenced. | Submit a written, receipted request to the company at its head office under Article 274(4); record delivery date, fee/payment receipt and response deadline. Obtain the current extract and reconcile every holder/address, share number/serial, paid-up amount and register-entry date, plus later changes. If the applicant is a shareholder and the company refuses inspection/copies, separately assess Article 381(1)(g) and 381(2) escalation to the concerned government authority. |
| M3-E02 | Registered Memorandum/Articles and all amendments | **OWNER-REPORTED HELD; NOT ACCESSIBLE FOR DIRECT INSPECTION — BLOCKING** | Owner previously confirmed possession of an image-scanned original; repository status README and historical notes record that the binary was not preserved or verified. The 17-page bylaws scan is a distinct instrument. | Inspect the complete original registered instrument and amendments, verify page completeness, registration/notarial particulars, signatures/seals and provenance; record checksum and reconcile operative clauses on capital, share classes, voting, caps, proxies, quorum and register. Do not substitute bylaws, OCR or historical summaries. No repeated upload request is made while the known upload limitation persists. |
| M3-E03 | Per-holder paid-up capital and unpaid-call evidence | **PARTIAL SECONDARY DATA; AUTHORITATIVE PAYMENT PROOF MISSING — BLOCKING** | Two Library SQL dumps contain the same selected 40 shareholder rows (SH-0001–SH-0040) and 123 transaction rows. The shareholder table totals 210 shares, ETB 1,564,585 paid_value and ETB 535,415 unpaid_amount; the bylaws recovery separately reports 145 shares, ETB 1,023,000 paid and ETB 427,000 unpaid. No row-level join is established. Transaction rows have no populated paid_at/paid_by fields; status labels and journal IDs do not prove cleared bank funds. | Reconcile each holder to the certified Article 274 register, original subscription/call notices, bank statements, receipts, cashbooks, journal postings and audited ledgers. Establish company registration date and the operative payment schedule. Apply Articles 281 and 289(7): the cash-share balance is due within five years from registration unless the Memorandum provides a shorter period, and a shareholder who fails to pay a call when due loses voting rights at shareholder meetings. Do not infer loss of voting rights from an unpaid balance without proving the due date and non-payment. |
| M3-E04 | Signed share-transfer instruments and complete certificate/register history | **UNRESOLVED — BLOCKING; legacy dump contains no transfer transactions** | The two legacy SQL dumps each contain 123 rows, all `Purchase` or `Payment` (54 and 69 respectively); no `Transfer` transaction row exists. A generic `certificates` table schema is present but has no inserted records in the inspected dump; there is no populated dedicated share-certificate history in those dumps. The current API's append-only holding-ledger design is a technical contract, not proof that a legal transfer occurred. | For each transfer, inspect the signed instrument, any Article 276 restriction/pre-emption/consent, certificate delivery/endorsement and the Article 285(2) register entry showing transferor/transferee names and addresses, quantity, transfer date and register-entry date. Reconcile both sides of the movement and any reversal. No transfer is accepted from application ledger labels alone. |
| M3-E05 | Legal form of entity holders and effective representative/mandate authority | **UNRESOLVED — BLOCKING FOR AFFECTED HOLDERS** | The 100-row application seeder classifies SH-0023 (Maale Hibret) and SH-0088 (Sarangi) as `religious_body`; historical notes report a conflicting earlier `cooperative` classification for SH-0023. The legacy SQL shareholder schema has no legal-entity-type or representative-mandate field, and all 40 `type_label` values are null. No constitutive/registration instrument or effective-dated appointment/revocation record was found. | Obtain each entity's current legal-personality/registration instrument under its governing law, competent resolution appointing the representative, and any mandate/revocation/succession documents. Qualified Ethiopian counsel must assess legal capacity and the effect of Commercial Code Article 4's scope exclusions for religious institutions/cooperatives, without assuming that an application enum resolves legal status. Preserve the entity as holder; a representative does not receive an additional personal vote solely by acting for it. |
| M3-E06 | Admission/status evidence for SH-0030–SH-0100 and any other later identities | **UNRESOLVED — NOT INVALIDATED; technical datasets differ** | The current application seeder contains 100 rows. The two identical legacy SQL snapshots contain 40 shareholder rows, SH-0001–SH-0040. The historical bylaws recovery confirms 29 schedule rows but does not transcribe their names or allocation values. These datasets are not an identity crosswalk: the SQL presence of SH-0030–SH-0040 proves only that those IDs existed in that application snapshot, not that they were lawfully admitted; absence of SH-0041–SH-0100 from that snapshot does not prove invalidity. | Reconcile each later identity to the dated statutory register entry, subscription/allotment or registered transfer instrument, share certificate/instrument, paid-up/call record and legal identity evidence. Record admitted, transferred, cancelled, duplicate or unresolved status only from primary records. Do not infer admission from row order, `approved` application status or a seeder record. |
| M3-E07 | Election-specific record date and exact cutoff | **MISSING — BLOCKING; statutory mechanism clarified** | No election-specific resolution or operative Memorandum clause fixing the register cutoff is evidenced. Article 376 permits the Memorandum to determine the period within which registered-share holders must be entered in the register to participate in a meeting; it does not itself provide the company's actual election cutoff timestamp. | Inspect the registered Memorandum/amendments for an Article 376 registration period and obtain the competent election authorization/resolution. Ethiopian counsel must determine the applicable interaction. Record the exact effective date/time, timezone, authority and treatment of same-day entries, late/backdated postings, reversals and corrections. Do not invent a cutoff from the meeting date or system timestamp. |
| M3-E08 | Legally operative share classes, class rights and any lawful voting cap | **PARTIAL STATUTORY BASELINE; COMPANY-SPECIFIC RIGHTS UNRESOLVED — BLOCKING** | Articles 278–279 allow classes with different rights through the Memorandum/amendment, require equal rights within a class, prohibit preference in voting rights, and allow certain preference shares to be restricted to extraordinary-meeting matters subject to the statutory capital limit. Articles 383–384 govern proportional-capital voting and an equal-across-all-shares maximum-vote cap. Articles 289(7), 375 and 385 also create specific voting-right deprivations. No operative company-specific class/cap term is verified. | Inspect the registered Memorandum, all amendments, class resolutions and current register/certificates. Obtain qualified Ethiopian legal review of class rights, any lawful cap, paid-call default and conflict restrictions. Do not infer legal classes or voting caps from `FOUNDERS` / `STANDARD` bootstrap codes. |



### 2.2 Statutory rule refinement verified against the Commercial Code text

The following provisions materially affect the entitlement calculation and must be carried into the A2 contract. They are a statutory baseline, not a company-specific legal opinion:

- **Article 274(2)–(4):** the head-office register must record shareholder names/addresses, share numbers and numeration, amounts paid up and dates of entry. Shareholders and concerned government authorities may inspect without charge; any person may obtain a copy/extract on payment of the prescribed fee, which the company must provide within 15 days of the request.
- **Article 276:** the Memorandum or an extraordinary general meeting resolution may restrict transfers; a board-consent restriction is effective only with specified pre-emption rights and conditions and cannot prevent assignment or seriously damage a shareholder.
- **Article 278:** the Memorandum/amendment may create classes with different rights; shares within the same class must have the same par value and rights, and changes to class rights require a special class meeting.
- **Article 279:** voting-preference shares are prohibited. The Memorandum may restrict certain preference shares to extraordinary-meeting matters, subject to the statutory limit that those restricted-voting shares represent less than half the capital.
- **Article 281:** cash shares are paid up on subscription to at least one-quarter of par value (or a greater amount in the Memorandum); the balance is due within five years of company registration unless the Memorandum sets a shorter period.
- **Article 285:** for registered shares, ownership belongs to the person registered in the head-office register; transfer requires delivery and registration to the transferee, with the required transferor/transferee, address, quantity, transfer-date and register-entry details.
- **Article 289(7):** a shareholder who fails to pay calls when they become due loses voting rights in shareholder meetings. An unpaid balance alone is not enough to apply this rule without proving the call, due date and non-payment.
- **Article 375:** company-redeemed shares carry no voting rights.
- **Article 376:** the Memorandum may set the period within which registered-share holders must be entered in the register to participate in a meeting. This does not supply an election-specific timestamp without the operative instrument and election decision.
- **Article 383:** ordinary/dividend-share vote weight is proportional to the amount of capital represented; absent legal deprivation, every share carries at least one vote.
- **Article 384:** a Memorandum vote cap must apply equally to all shares without class distinction.
- **Articles 385–386:** conflict-of-interest restrictions may deprive a shareholder of voting on the matter; agreements restricting the free exercise of voting rights are invalid.

The Article 274(4) request route is now legally identified, but no request has been delivered and no 15-day response period has begun. For a shareholder's refusal-based document-access escalation, Article 381(1)(g) and 381(2) separately provide an inspection/copy route through the concerned government authority.

### 2.1 No blocker is formally accepted as unresolved

**FORMALLY ACCEPTED UNRESOLVED: NONE EVIDENCED.**

The repository records the gaps and proposed acquisition routes. It does not record a specific Owner decision accepting any of M3-E01–M3-E08 as a permanent or election-specific exception. A statement that an item is unavailable, or that A2 may continue, is not a waiver of the legal evidence requirement.

If the Owner later elects to proceed with a bounded unknown, the disposition must identify the exact item, intended scope/election, rationale, residual risk, compensating controls, expiry/review point and qualified Ethiopian legal opinion where required. It must not be generalized to other elections or future register snapshots.

## 3. Verified historical and technical evidence (limited scope)

| Evidence item | Result | Limitation |
|---|---|---|
| Original 17-page Amharic bylaws/internal-regulations scan | **OWNER-REPORTED SOURCE HASH; BINARY NOT AVAILABLE FOR INDEPENDENT VERIFICATION IN THIS REVIEW** — the 17-page working recovery is available in the Library, but the original PDF bytes were not recovered there or in the repository. | The recorded SHA-256 `8df31ad217b2221f42b3e0e1154007bdd5d2ab003df0e1ad3dc7f17c03b61183` is Owner-supplied provenance only in this review. The Markdown is a derivative transcription/recovery, not the legal original or a certified translation. |
| Historical schedule | **29 rows visually identified; aggregate totals only** — 145 shares, ETB 10,000 nominal value per share, ETB 1,450,000 stated capital, ETB 1,023,000 paid and ETB 427,000 unpaid. | The recovery record explicitly says the 29 names on pages 9–10 and individual allocation/payment rows on page 11 were not transcribed. This is a historical bylaws schedule, not the current Article 274 register. |
| Historical capital arithmetic | **RECONCILED:** ETB 1,450,000 stated/subscribed; ETB 1,023,000 recorded paid; ETB 427,000 recorded unpaid | Does not prove cleared payments, current unpaid calls or present ownership. |
| Historical identity crosswalk | **NOT ESTABLISHED** | No reliable row-level join exists between the 29 historical schedule rows and SH-0001–SH-0029: the names/allocation rows were not transcribed, and no shared unique legal identifier was verified. Do not infer matches from seed order or similar names. |
| Application bootstrap data | 100 identity rows (SH-0001–SH-0100) found | Not the statutory register; no current ownership or entitlement can be derived from seeder ordering. |
| Legacy SQL snapshots (two Library files) | **SAME SELECTED DATA; 40 shareholder rows and 123 transaction rows in each**. Shareholder table: 210 shares, ETB 1,564,585 paid_value, ETB 535,415 unpaid_amount; all 40 are `founder` share_type and `type_label` is null. Transactions: 54 `Purchase`, 69 `Payment`; no `Transfer` rows; no populated `paid_at` or `paid_by`. | The two dumps have identical selected shareholder/transaction fields and are not independent corroboration. Their aggregate values differ from the 29-row bylaws schedule, but no identity join is established. They are legacy application data, not bank proof, statutory register evidence or proof of lawful admission. The transaction-row `unpaid_amount` field is not summed because repeated per-transaction balances can double-count. The generic `certificates` table has a schema but no inserted rows; no dedicated populated share-certificate table is present in these dumps. |
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
