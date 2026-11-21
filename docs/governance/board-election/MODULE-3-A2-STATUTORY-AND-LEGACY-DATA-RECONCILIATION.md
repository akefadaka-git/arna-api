# AGE v1.0 — Module 3 A2 Statutory and Legacy-Data Reconciliation

**Stage:** A2 — read-only evidence reconciliation  
**Repository:** `akefadaka-git/arna-api`  
**Branch:** `a2/module-3-statutory-data-reconciliation`  
**Base/current-main SHA:** `2ec844aaef7891271a1e1fd27a8aabdad94cd443`  
**Companion correction:** `docs/governance/board-election/MODULE-3-EVIDENCE-DISPOSITION-REGISTER-A2.md`  
**Implementation:** A3 NOT STARTED  
**Deployment:** A5 NOT AUTHORIZED

---

## 1. Material correction to the previous A2 evidence register

The earlier Module 3 evidence register overstated the historical identity evidence. It claimed that the 29 schedule rows had been matched in sequence to SH-0001–SH-0029 and that a 27/29 row-level SQL conflict analysis had been completed. Those claims are withdrawn.

The Library working recovery `maale_arna_bylaws_full_recovery.md` says:
- pages 9–10 contain 29 register rows, but individual names and values were not transcribed;
- page 11 contains 29 allocation rows, but individual allocations were not transcribed;
- page 8 and pages 15–16 contain signature tables, but names/signatures were not machine-transcribed;
- only aggregate capital figures are readable in the recovery.

There is therefore no defensible row-level crosswalk from the 29 historical schedule entries to SH identifiers. Similar names, sequence position and seeder ordering are not sufficient. The previous crosswalk and its derived 27/29 and 20/29 conflict percentages must not be relied upon.

## 2. Private legacy SQL dump analysis (aggregates only)

Two SQL files were found in the user's Library:
- `maale_arna_smart_system.sql` (1,160,215 bytes)
- `maale_arna_smart_system(1).sql` (2,495,210 bytes)

The files were materialized for local, read-only analysis. They contain personal data and are not copied into this public repository. Only aggregate, non-identifying findings are recorded here.

### 2.1 Shareholder table

Both dumps contain the same selected shareholder records:

| Measure | Result |
|---|---:|
| Rows | 40 |
| Shareholder-number range | SH-0001–SH-0040 |
| Share type | All 40 marked `founder` |
| Current application status in dump | All 40 marked `approved` |
| Aggregate shares | 210 |
| Aggregate `paid_value` | ETB 1,564,585 |
| Aggregate `unpaid_amount` | ETB 535,415 |
| `type_label` | NULL for all 40 in the larger dump |

The legacy shareholder schema has no explicit legal-entity type or representative-mandate field. The `approved` status and `founder` share-type label are application data, not proof of statutory admission, legal ownership or cleared payment.

### 2.2 Share transaction table

Both dumps contain 123 transaction rows with identical selected transaction fields:

| Transaction field | Result |
|---|---:|
| `Purchase` | 54 |
| `Payment` | 69 |
| `Transfer` | 0 |
| `paid_at` populated | 0 |
| `paid_by` populated | 0 |

The shareholder-level `paid_value` aggregate equals ETB 1,564,585. Transaction-row `paid_amount` also sums to ETB 1,564,585, but those are legacy application fields, not bank confirmation. Transaction rows have no populated `paid_at` or `paid_by`; no receipt/bank evidence was recovered in these dumps.

The transaction table has an `unpaid_amount` field per transaction. Its row-wise sum is not treated as a company or shareholder unpaid-capital balance because the field's transaction-level semantics and possible repeated outstanding balances have not been independently validated.

### 2.3 Certificate evidence in the dumps

The larger dump defines a generic `certificates` table with a `share` certificate type, but no certificate rows are inserted. Neither dump contains a dedicated `share_certificates` table with certificate records. This does not prove that no paper certificates exist; it means these database snapshots do not evidence certificate issuance, number, serial range, endorsement, cancellation or custody.

### 2.4 Relationship between the datasets

The two SQL dumps have identical selected shareholder and transaction fields. They are not independent corroboration.

Their shareholder table has 40 rows and 210 shares, while the bylaws recovery reports 29 historical schedule rows and 145 shares. This is a material aggregate discrepancy between two secondary/application datasets. Because the 29 names and individual allocations were not transcribed, no per-holder comparison or row-level discrepancy percentage can be responsibly stated.

The current GitHub seeder contains 100 application identities. That third dataset is also not a statutory register. The 40-row SQL snapshot, 100-row seeder and 29-row bylaws schedule must remain separate until primary register and admission evidence establish their relationship.

## 3. Bylaws scan and separate registered Memorandum/Articles

### 3.1 Bylaws/internal-regulations scan

The Owner-supplied provenance record identifies a 17-page scanned Amharic document titled `የመተዳደሪያ ደንብ` (bylaws/internal regulations) and records the Owner-supplied SHA-256:

`8df31ad217b2221f42b3e0e1154007bdd5d2ab003df0e1ad3dc7f17c03b61183`

The original PDF bytes were not recovered in the Library or repository during this review. The available Library artifact is a working Markdown recovery, not the original PDF, certified transcription or legal opinion. The checksum is therefore recorded as Owner-supplied provenance; it was not independently recomputed from the original bytes in this review.

The recovery supports aggregate historical figures only: 29 listed holders, 145 shares, ETB 10,000 nominal value per share, ETB 1,450,000 stated capital, ETB 1,023,000 paid and ETB 427,000 unpaid. Names and individual allocations are not transcribed. It is a historical bylaws schedule, not proof of the current Article 274 register.

### 3.2 Registered Memorandum/Articles

The separate Articles/Memorandum status record says the Owner previously reported possessing a separate image-scanned original, historically described as dated 16/12/2016 E.C., executed, notarized and sealed. Its binary, filename, page count, registration particulars, amendments and full text were not available for direct inspection in this review. The 17-page bylaws scan must not be substituted for this separate instrument.

## 4. Commercial Code provisions confirmed from the English text

The English text of Commercial Code Proclamation No. 1243/2021 was inspected. The following provisions refine the Module 3 evidence requirements; this is statutory source research, not qualified Ethiopian legal advice.

| Article | Rule relevant to Module 3 |
|---|---|
| 274(1)–(2) | Every share company keeps a durable head-office shareholder register recording names/addresses, share numbers/numeration, paid-up amounts and dates of entry |
| 274(3)–(4) | Shareholders and concerned authorities may inspect free of charge; any person may obtain a copy/extract on payment of the prescribed fee, which the company must provide within 15 days of the request |
| 274(5) | A concerned government authority may order register rectification on request of an interested party or shareholder |
| 276 | Memorandum or extraordinary general meeting resolution may restrict transfer; board-consent restriction requires specified pre-emption rights and conditions and cannot prevent assignment or seriously damage a shareholder |
| 278 | Memorandum/amendment may establish classes with different rights; same-class shares must have the same par value and rights; changes require a special class meeting |
| 279(2)–(4) | Preference in voting rights is prohibited; certain preference shares may be restricted to extraordinary-meeting matters if the Memorandum provides, subject to the statutory capital ceiling |
| 281 | Cash shares are paid up on subscription to at least one-quarter of par value (or a greater amount in the Memorandum); balance is due within five years of company registration unless a shorter period is provided |
| 285 | For registered shares, ownership belongs to the person in the head-office register; transfer requires delivery and registration with transferor/transferee, addresses, quantity, transfer date and register-entry date |
| 289(7) | A shareholder who fails to pay calls when due loses voting rights at shareholder meetings; unpaid balance alone does not establish a due call or voting-right deprivation |
| 375 | Company-redeemed shares carry no voting rights |
| 376 | Memorandum may determine the period within which registered-share holders must be entered in the register to participate in a meeting |
| 383 | Ordinary/dividend-share vote weight is proportional to capital represented; absent legal deprivation, each share carries at least one vote |
| 384 | Memorandum may limit the maximum votes exercisable at meetings, but the limit must apply equally to all shares without class distinction |
| 385–386 | Conflict-of-interest voting restrictions apply to specified matters; agreements restricting the free exercise of voting rights are invalid |

**Implications:**
- Do not treat unpaid balances as an automatic voting disqualification. Establish the call, due date, notice and non-payment under Articles 281 and 289.
- Do not implement a class multiplier or vote cap from bootstrap codes. Verify the Memorandum and valid class resolutions under Articles 278–279 and 383–384.
- The precise election cutoff must be reconciled with the Memorandum's Article 376 registration-period provision and the competent election authorization. Article 376 does not itself provide ARNA's election-specific timestamp.
- A registered-share transfer is not complete merely because an application ledger has a `Transfer` label; Article 285 register entry is central to ownership.
- For SH-0023 and SH-0088, counsel must determine legal personality, capacity and representative authority under each entity's applicable law. Commercial Code Article 4's scope exclusions mean an application enum such as `religious_body` or `cooperative` is not a sufficient legal classification.

## 5. Updated eight-gate disposition

| Gate | Updated result | What has actually advanced |
|---|---|---|
| M3-E01 Current Article 274 register | **MISSING** | Statutory copy/extract route verified; no request delivered and no current extract received |
| M3-E02 Registered Memorandum/Articles | **MISSING FROM ACCESSIBLE EVIDENCE** | Distinctness from bylaws confirmed; original binary and amendments remain inaccessible |
| M3-E03 Paid-up capital and calls | **PARTIAL / UNRESOLVED** | Two duplicate legacy dumps provide a 40-holder application snapshot; no cleared bank evidence, call notices or current statutory balances |
| M3-E04 Transfers and certificates | **UNRESOLVED** | Legacy dumps contain no transfer transactions or certificate rows; signed instruments and Article 285 register entries absent |
| M3-E05 Entity and representative authority | **UNRESOLVED** | SH-0023 classification conflict and SH-0088 legal authority remain unsupported by primary instruments |
| M3-E06 SH-0030–SH-0100 admissions/status | **UNRESOLVED / NOT INVALIDATED** | 40-row SQL snapshot, 100-row seeder and 29-row schedule are distinct; no lawful admission evidence established |
| M3-E07 Election record date/cutoff | **MISSING** | Article 376 mechanism identified; no operative Memorandum clause or election-specific cutoff resolution inspected |
| M3-E08 Share classes/rights/voting cap | **PARTIAL STATUTORY BASELINE / COMPANY TERMS UNRESOLVED** | Articles 278–279 and 383–386 clarified; registered class terms/cap and legal review remain absent |

**FORMALLY ACCEPTED UNRESOLVED: NONE EVIDENCED.** No evidence gap is waived by this report.

## 6. Immediate evidence acquisition sequence

1. Prepare and deliver a written Article 274(4) request to the company at its head office, requesting a current copy/extract and paying the prescribed fee. Preserve a dated receipt or other reliable proof of delivery. The 15-day statutory period starts from the request date; it has not started yet.
2. If the company refuses a shareholder's inspection/copy request under Article 381(1)(g), preserve the refusal and assess the Article 381(2) application route to the concerned government authority.
3. Obtain the separate registered Memorandum/Articles and all amendments from the Owner-held original or competent registering authority; record registration particulars and a verified checksum.
4. Obtain company registration date, subscription/call notices, bank/receipt and journal evidence, transfer instruments and certificate/register records.
5. Obtain entity registration and representative mandate evidence for SH-0023 and SH-0088.
6. Obtain qualified Ethiopian counsel's written opinion on Articles 4, 276, 278–281, 285, 289, 376 and 383–386 against the actual company instruments.

No request has been sent, no delivery receipt is claimed, no statutory response period has started, and no external authority has been contacted.

## 7. Scope and privacy boundary

This is a documentation-only A2 reconciliation. The private SQL dumps were not committed or reproduced; no shareholder names, phone numbers, emails, identity numbers, addresses or individual balances are included. No application code, schema, migrations, seeders, production data, election settings or `main` branch were changed by the A2 evidence review.

A3 implementation remains NOT STARTED and separately gated. A5 deployment remains a separate Owner approval and is NOT AUTHORIZED.
