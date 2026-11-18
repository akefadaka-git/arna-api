# AGE v1.0 — Module 3 Shareholder Register and Voting Entitlement
## A2 Read-only Dependency Baseline and Reconciliation

**Status:** A2 baseline established — read-only; A3 NOT STARTED  
**Repository:** ake fadaka-git/arna-api  
**A2 branch:** a2/module-3-shareholder-register-read-only-baseline  
**Base commit:** 612bb5618bc0d5b16edce93e2b67cd1c972f853a (Module 2 closure-record commit; documentation-only change after the verified implementation SHA)  
**Module 1 verified implementation:** 9fe56f3c56da163c3da9dcd3c12a69adfb02b251  
**Module 2 verified implementation:** 2ed14eae55604337518b17ce62b572b275e4e8d3  
**Module 2 A4 evidence:** https://github.com/akefadaka-git/arna-api/actions/runs/44344856098 — SUCCESS  
**Current main observed during A2:** d978ba742de7859405bfe48a219df9d96b1f778a  
**Scope:** Official shareholder register snapshot, record-date holdings reconstruction, entitlement calculation contract, reconciliation, approval and immutable freeze  
**Excluded:** Ballot issuance/custody, channel activation, voting, counting, objections, certification, ratification, deployment/A5

---

## 1. A2 control statement

Module 2 is VERIFIED-CLOSED at implementation SHA 2ed14eae55604337518b17ce62b572b275e4e8d3, supported by the exact-SHA full MySQL workflow and the independent A4 closure record at docs/governance/board-election/MODULE-2-A4-VERIFICATION-CLOSURE.md.

This Module 3 baseline is read-only. It records the exact upstream dependencies and existing shareholder/holding source contracts before any register implementation. It does not modify shareholder source code, migrations, election code, Module 2 code, permissions, tests or main.

Module 3 is dependent on the verified Module 1 and Module 2 baselines. Its branch is deliberately stacked from the Module 2 closure-record commit; PR #165 remains open/draft and unmerged. The Module 3 work must not be rebased onto main alone or merge an older voter-register implementation wholesale.

## 2. Verified source-of-truth baseline

The following six shareholder-domain source files were compared at current main SHA d978ba742de7859405bfe48a219df9d96b1f778a and Module 2 implementation SHA 2ed14eae55604337518b17ce62b572b275e4e8d3. Each pair has the same Git blob SHA, so the reviewed source contract is identical at both refs.

| Source | Blob SHA | Contract / disposition |
|---|---|---|
| mases_core/src/Domains/Shareholder/Models/Shareholder.php | c2bd05abab1364537ea6531916daa28f3ddb3f0c | Canonical holder identity in the official shareholder register; individual and legal-entity types; direct user vs authorized representative are distinct; soft deletion preserves identity history |
| mases_core/src/Domains/Shareholder/Models/ShareClass.php | b1ed73163a4122b43489429d0f06049f16d39ee3 | Share-class identity and attributes; no class-specific voting-right or vote-weight fields currently exist |
| mases_core/src/Domains/Shareholder/Models/ShareHolding.php | f3bb01411824ce95a7c2e25111b84671b8ada9fe | Current-state projection of one shareholder/share-class pair; not the authoritative historical source |
| mases_core/src/Domains/Shareholder/Models/ShareHoldingEntry.php | 7c30f03dffaf6635128f7d03aed06231b9030f9a | Append-only signed share-quantity ledger; carries before/after balances, entry type, business time, references and reversal link |
| mases_core/src/Domains/Shareholder/Services/ShareHoldingService.php | 02de3196184f310afc81da219043d61cf057bb80 | Sole supported holding writer; transactions, row locks, append-only entries and reversal operations |
| mases_core/src/Domains/Shareholder/Database/Migrations/2026_09_11_000004_create_share_holding_entries_table.php | 0a983117b1c0c12c802ef378a500447128f26c90 | Ledger schema has immutable rows, no updated_at, explicit occurred_at DATETIME and reversed_entry_id |

Related shareholder migrations currently present:

- 2026_09_11_000001_create_share_classes_table.php
- 2026_09_11_000002_create_shareholders_table.php
- 2026_09_11_000003_create_share_holdings_table.php
- 2026_09_11_000004_create_share_holding_entries_table.php
- 2026_09_11_000005_create_share_certificates_table.php
- 2026_10_21_000001_reconcile_existing_shareholder_numbers.php

### 2.1 Binding source rules already encoded

1. Shareholder is the holder identity. It is not a holding balance.
2. A holder may be an individual or a legally recognized entity. The current type vocabulary includes individual, company, trust, cooperative, government, and religious_body.
3. For non-individual holders, the designated human representative is an agent of the entity, not the shareholder/owner. A representative must not receive an additional personal vote merely by representing an entity.
4. ShareHolding.current_quantity is a current projection for a shareholder/share-class pair. It is not sufficient proof of holdings at a past record date.
5. ShareHoldingEntry is the authoritative append-only ledger. Its quantity is signed; the invariant is balance_before + quantity = balance_after.
6. Reversals are new ledger rows linked to the original entry; the original entry is not overwritten.
7. The business-effective timestamp is occurred_at, distinct from row creation time created_at. The ledger can represent backfilled historical business events.
8. Current ShareHoldingService accepts whole-number share magnitudes; stored quantity columns are DECIMAL(20,4). Module 3 must not silently introduce fractional-share entitlement.

### 2.2 Legal-source and statutory baseline found during A2

Current main contains two relevant A2 evidence records, inspected at main SHA d978ba742de7859405bfe48a219df9d96b1f778a:

- docs/governance/elections/P2.5-SOURCE-AND-STATUTORY-RECONCILIATION-v1.0.md — blob 8101ccbd3a9fbdc194c5a51aef34e0e458c11132. This is an A2 source/statutory issue register, not a legal opinion.
- docs/legal/articles-of-association/HISTORICAL-RECONCILIATION-NOTES.md — blob 856cd6afbcfd16f26b26c98d91850ebd82fcd29a.
- docs/legal/articles-of-association/README.md — blob c90f607d818304eaa44921d2bc05188a7e4da878. It records that the separate original Articles of Association PDF has not been preserved or verified in the repository.

The P2.5 record documents an Owner-confirmed 17-page scanned Amharic bylaws/internal-regulations source named የማሌ_ዓርና_አክሲዮን_ማኅበር_መተዳደሪያ_ደንብ.pdf, with Owner-supplied SHA-256 8df31ad217b2221f42b3e0e1154007bdd5d2ab003df0e1ad3dc7f17c03b61183. It is reported as the latest complete approved version, signed by ደቡብ ኢትዮጵያ ንግድ እና ገበያ ቢሮ. The original PDF is not committed in this repository; the P2.5 document is a redacted evidence index and provisional transcription summary. This bylaws scan is distinct from the separate statutory Articles/Memorandum and must not be substituted for them.

P2.5 records statutory research on Commercial Code Proclamation No. 1243/2021:
- Article 383(1): ordinary/dividend-share voting weight is proportional to the amount of capital represented.
- Article 383(2): absent legal deprivation, each share carries at least one vote.
- Article 384: the Memorandum may limit the maximum votes exercisable at meetings; the limit must be equal for all shares without distinction of class.

These provisions narrow the statutory baseline but do not establish the operative registered Memorandum, any company-specific vote cap, class-specific rights, record date, or current electorate. The current ShareClass model has no fields for voting rights or vote multipliers. Do not invent a class multiplier or maximum-vote cap. The final rule must reconcile the current registered Memorandum, operative bylaws, applicable law and election-specific resolution with qualified Ethiopian legal review.

The same P2.5 source record provisionally reports shareholder rights, a written/dated/signed proxy clause, secret ballot, separate votes for each director where multiple directors are elected, a three-year term, and a 3–8 board-size clause. Exact Amharic wording and legal identity/effect remain subject to source verification. The possible one-share director qualification remains unverified and is primarily a candidate-eligibility issue, not a voter-register default.

Historical register/capital notes are also explicitly provisional:
- The 17-page bylaws schedule is reported as 29 listed holders, 145 shares, ETB 10,000 nominal value per share, ETB 1,450,000 stated capital, ETB 1,023,000 paid and ETB 427,000 unpaid. The arithmetic reconciles, but does not prove current ownership, paid-up status, share classes or current voting entitlement.
- The Owner previously reported official shareholder-register pages 9–11 as uploaded in an earlier conversation; those page images were not recovered or inspected in this repository A2 task.
- Historical application seed data was reported as 100 entries across FOUNDERS and STANDARD. Those are application records, not the official legal register.
- Shareholder SH-0023 was reported as religious_body in one seed context and cooperative in another; the conflict remains unresolved.

Accordingly, the source evidence narrows the legal framework but does not close record-date, register identity, paid-up capital, classification, voting cap, or temporal-history blockers. Do not treat OCR, seed data, the bylaws summary or historical arithmetic as the operative statutory register.

## 3. Module 1 and Module 2 dependency contracts

### Module 1 — election aggregate

Module 1 supplies the canonical tenant-scoped election aggregate and lifecycle, with authority/rule evidence, guarded transitions, optimistic versioning and durable history. Module 3 must attach a register to one authorized election and must not create a parallel election or lifecycle aggregate.

### Module 2 — candidate intake and eligibility

Module 2 supplies candidates, nomination receipts, consent, evidence and human eligibility decisions. Candidate status is not shareholder status and does not establish voting entitlement. A non-shareholder candidate may be eligible to stand if the authoritative rules permit, but candidacy does not grant a vote. Module 3 must not use nomination submissions, candidate records or candidate eligibility as the electorate source.

### Upstream dependency baseline

| Dependency | Exact baseline | State |
|---|---|---|
| Committee security foundation | Merged API main history; verified in Module 2 workflow | VERIFIED |
| Module 1 election foundation/lifecycle | 9fe56f3c56da163c3da9dcd3c12a69adfb02b251 | VERIFIED |
| Module 2 candidate intake/eligibility | 2ed14eae55604337518b17ce62b572b275e4e8d3 | VERIFIED-CLOSED |
| Module 2 closure record | 612bb5618bc0d5b16edce93e2b67cd1c972f853a | RECORDED |
| Shareholder identity and holding ledger | Blob SHAs in §2, identical on main and Module 2 baseline | RECONCILED |

## 4. Module 3 required domain outcome

Module 3 must produce a reproducible, authorized and immutable shareholder register snapshot for a specific election and authoritative record date. It must distinguish:

1. Holder identity — the canonical shareholder register row.
2. Record-date holdings — each holder's quantity by share class as reconstructed from ledger evidence at the approved cutoff.
3. Voting entitlement — whether that holder may vote under the operative law, articles and election rules.
4. Vote weight — the approved share-based weight calculation, with class-specific rights only when supported by authoritative rules.
5. Register freeze — an immutable, versioned electorate snapshot and its source manifest, approvals and digest.

The register must count eligible shares, not people, user accounts, representatives or candidate records. One holder with multiple share classes may have multiple holding lines but must not be duplicated as multiple persons or accounts. An entity and its representative remain one holder identity for entitlement purposes.

## 5. Record-date reconstruction contract — proposed for A2

### 5.1 Source data

- Use canonical shareholders for holder identity and share_holding_entries for historical share-quantity movements.
- Do not calculate past entitlement from share_holdings.current_quantity alone.
- Do not treat share certificates as a substitute for the authoritative shareholder register or ledger.
- Do not write directly to share_holdings or share_holding_entries; use the Shareholder domain's services for any approved corrections.

### 5.2 Effective-time reconstruction

A proposed record-date holding calculation replays ledger entries with business-effective time occurred_at <= approved_record_date_time, in deterministic order (occurred_at, id), applying signed quantities and linked reversals. It must verify each included entry's before/after balance invariant and reconcile its result against the authoritative ledger/projection as of the relevant evidence point.

The cutoff must be an explicit timestamp with a defined timezone and approved authority reference. A date-only value is insufficient where a transfer, posting or record change can occur on the same date.

### 5.3 Late/backdated ledger entries

Because occurred_at can represent a backfilled event, a pure occurred_at <= record_date query is not reproducible after later entries are posted. The frozen register must therefore persist an exact source manifest containing the IDs and digests of every ledger entry actually included, the cutoff, the source capture boundary, the calculation/rule version and a manifest digest.

A later backdated entry must not silently rewrite a frozen register. It requires an explicit, authorized reconciliation/correction decision and, if accepted, a new register version that preserves the old snapshot and records the supersession reason and evidence. A simple MAX(id) watermark alone is not accepted as a sufficient source manifest unless its transaction/commit semantics are independently proven safe.

### 5.4 Snapshot immutability

The frozen snapshot should retain at least:

- tenant ID and election ID;
- record-date timestamp and timezone;
- authority reference and source document digest;
- register/rule-set version and digest;
- shareholder ID and immutable shareholder number;
- shareholder type and the legally relevant identity/representation references;
- one line per shareholder/share-class pair with the included source ledger entry IDs/digests, computed quantity and reconciliation state;
- approved voting-entitlement outcome and rationale/reference;
- approved vote-weight calculation inputs and output;
- source manifest digest, snapshot digest, prepared/reconciled/approved/frozen actors and timestamps;
- version, state transitions, objections/correction references and supersession history.

The exact schema is not frozen by this baseline. It must be reconciled with the existing tenant/election composite-key conventions before A3.

## 6. Material unresolved rule and data gaps

These are blockers to final legal entitlement, not permission to invent defaults.

| ID | Unresolved input / gap | Required authoritative evidence or control |
|---|---|---|
| M3-01 | Operative record date and exact cutoff time | Election authorization/resolution and applicable governing instrument |
| M3-02 | Operative voting entitlement and any company-specific vote cap/class rights | Commercial Code Articles 383–384 provide a statutory baseline; registered Memorandum, current bylaws and election resolution must confirm any lawful deprivation, maximum-vote cap or class-specific rule |
| M3-03 | Whether any class-specific rights or different nominal capital affect the vote calculation | Current ShareClass has no voting-right fields; statutory proportional-capital rule and equal-across-classes cap rule must be reconciled with the registered Memorandum and class instruments |
| M3-04 | Treatment of treasury/buyback-held shares, pledged shares, disputed or suspended holdings | Authoritative company/legal rule and source evidence; do not infer from is_treasury_eligible |
| M3-05 | Shareholder active/deleted status as-of the historical record date | Durable temporal status history or a formally approved current-register cut; current is_active/soft-delete state alone cannot prove historical status |
| M3-06 | Representative authority as-of record date for non-individual holders | Authoritative appointment/mandate and effective-time history; current representative link alone is not historical proof |
| M3-07 | Transfer effective-time and late-posting policy | Approved transfer instruments, effective date rule and ledger correction controls |
| M3-08 | Share class and rights changes over time | Effective-dated class-rights history and governing approvals; current class state alone is not enough for past dates |
| M3-09 | Disputed ownership, joint ownership, trust/nominee/beneficial ownership and pending transfers | Governing legal rules and an approved exception/adjudication process |
| M3-10 | Register objections, correction authority, deadlines and appeal route | Competent authority, approved procedure and later objections/appeals module contract |

### 6.1 Critical temporal-data limitation

The inspected Shareholder model exposes current is_active, soft-delete and representative fields; the reviewed source does not establish a complete effective-dated history for those identity/authority changes. Consequently, a historical record-date register cannot safely infer the status of a shareholder or representative from today's row state. Module 3 must either consume an authoritative temporal register/history or explicitly scope the record-date cut to a formally attested register state captured at the time. This is an A2 design decision, not an A3 coding assumption.

## 7. Required Module 3 controls and test plan

| Control area | Required verification |
|---|---|
| Tenant/election isolation | Composite tenant/election FKs; cross-tenant shareholder/ledger references rejected |
| Ledger replay | Positive/negative entries, transfers, multiple classes, zero balances, reversal rows, deterministic (occurred_at,id) ordering |
| Reconciliation | Every ledger entry satisfies balance_before + quantity = balance_after; derived balances reconcile to ledger and current projection where comparable |
| Record-date cutoff | Before/at/after cutoff tests, timezone/DATETIME handling, backdated postings and late-entry correction behavior |
| Holder identity | Individual and all legal-entity types; representative is not a second holder; soft-deleted/inactive/disputed records fail closed absent historical authority |
| Voting rights | No default assumption of equal class rights; missing class rule blocks final entitlement |
| Snapshot freeze | Concurrent preparation/freeze, immutable source manifest, stable digest, exact snapshot replay |
| Corrections | New version only; prior frozen register and approvals remain inspectable |
| Authorization | Separate prepare, reconcile, approve, freeze and audit permissions; separation of duties |
| Auditability | Actor, reason, authority, evidence, timestamps, rule version, source manifest and digest |
| Scale/performance | Large ledger replay and snapshot generation without loading all rows into memory; indexed bounded queries |

## 8. Historical voter-register work

Historical PR #128 (feat/age-a3-voter-register-approval, head 1a157aaa1a06788e33dca53c4333b8abad36127f) is preserved as a reference only. Its register implementation is stacked on the older election/candidate branches and must not be merged wholesale or treated as a verified dependency. Its useful requirements and test ideas may be reconciled individually against the current Module 1/2 and shareholder-ledger contracts.

## 9. A2 disposition and next gate

**Module 2:** VERIFIED-CLOSED at implementation SHA 2ed14eae55604337518b17ce62b572b275e4e8d3.  
**Module 3 dependency baseline:** ESTABLISHED — read-only source and dependency reconciliation recorded here.  
**Module 3 A2:** PARTIAL — technical source baseline and statutory voting framework are reconciled; the registered Memorandum, record-date authority, current official register, paid-up/capital reconciliation and temporal identity inputs remain unresolved.  
**Module 3 A3:** NOT STARTED. No application source, migration, route, permission or test was changed.  
**A5 / production:** NOT AUTHORIZED.

The next permitted work is to complete the Module 3 A2 architecture/schema/rule reconciliation against the authoritative corporate instruments and current repository contracts. Any missing legal input must remain an explicit blocker. A3 implementation requires a separately recorded Owner approval of the Module 3 A2 baseline; no production or deployment action is implied.


---

## 10. Supplemental A2 reconciliation — statutory register, record date, capital, transfers and historical identity

**Assessment date:** 2026-11-18  
**Current main inspected:** d978ba742de7859405bfe48a219df9d96b1f778a  
**Disposition:** technical/legal framework reconciled; current legal facts and original instruments remain evidence-blocked. This section supersedes any broader reading of the provisional gaps in §§5–6.

### 10.1 Authoritative shareholder-register source — hierarchy resolved; current extract missing

**Controlling rule.** Commercial Code Article 274(1)–(2) requires every share company to keep a durable paper or electronic shareholder register at its head office. It must contain each shareholder's name and address, share numbers and numeration, paid-up amount, and date of entry. Article 274(4) provides for a person to obtain a copy or extract within fifteen days of request. Article 274(5) allows the concerned government authority to order rectification of an error. Article 285(1) makes the person registered in the head-office register the holder of a registered share, subject to contrary law.

**Recoverable historical source.** The Owner-confirmed 17-page signed/stamped source is:
- Title: `የማሌ ዓርና አክሲዮን ማኅበር — የመተዳደሪያ ደንብ`
- Source filename recorded in the evidence index: `የማሌ_ዓርና_አክሲዮን_ማኅበር_መተዳደሪያ_ደንብ.pdf`
- Owner-supplied SHA-256: `8df31ad217b2221f42b3e0e1154007bdd5d2ab003df0e1ad3dc7f17c03b61183`
- Owner-confirmed provenance: latest complete approved bylaws version, reported as approved/signed by the South Ethiopia Trade and Market Bureau. This is Owner-confirmed provenance, not an independent Bureau authentication.
- Repository evidence index: `docs/governance/elections/P2.5-SOURCE-AND-STATUTORY-RECONCILIATION-v1.0.md`, blob `8101ccbd3a9fbdc194c5a51aef34e0e458c11132`.
- Full Amharic working transcription: `docs/governance/elections/source-records/MAALE-ARNA-BYLAWS-APPROVED-TEXT-TRANSCRIPTION.md`, blob `58c76b8621bdb48d54049e9446220c2bd043b46a`.
- Library recovery derivative: `maale_arna_bylaws_full_recovery.md`, 13,859 bytes, created 2026-11-17 21:48 UTC. It explicitly says it is not a certified transcription and that the original scan controls.

The recovered transcription identifies printed pages 9–10 as a 29-entry founder/shareholder schedule and page 11 as a 29-row share allocation/capital table. However, the original PDF bytes and page images were not available for direct inspection in this A2 pass. The transcription does not provide a verified row-by-row extraction of the 29 names, individual share/payment values, certificate serials, dates of register entry, or subsequent transfers.

**Legal classification.** The 29-entry schedule is evidence of the founding shareholder allocation recorded with the bylaws. It is not, without a current certified register extract and subsequent entry history, proof of the current statutory register under Article 274. The current register maintained at the company's head office is the controlling source for the present registered holders. The schedule may be used as an opening historical reconciliation source, not as a frozen election electorate.

**Material schema mismatch.** The inspected current application schema does not yet model all mandatory Article 274 register particulars:
- the `shareholders` table stores holder type, names, user/representative links, active state, soft-delete and timestamps;
- a later migration adds a tenant-scoped `shareholder_number`, but that is a business holder identifier, not the statutory serial/numeration of each share;
- the inspected shareholder table has no shareholder address, paid-up amount, date-of-entry field, or per-share serial-number register;
- share holdings store quantity by holder and class; certificates are separate records, but their existence does not establish the complete statutory register;
- the current `ShareClass` model and schema do not encode class voting rights or a vote cap.

Therefore **the application shareholder table, seed file and holding projection are not the statutory register**. A3 must not treat them as the authoritative source until the missing Article 274 fields and legal reconciliation controls are designed.

### 10.2 Separate registered Memorandum — Owner possession recorded; binary not available for inspection

The separate Articles/Memorandum source-status records were checked:
- `docs/legal/articles-of-association/README.md`, blob `c90f607d818304eaa44921d2bc05188a7e4da878`;
- `docs/legal/articles-of-association/HISTORICAL-RECONCILIATION-NOTES.md`, blob `856cd6afbcfd16f26b26c98d91850ebd82fcd29a`.

They record that the Owner possesses a separate image-scanned original, historically reported as supplied in a ChatGPT conversation on 2026-09-11 and described as dated 16/12/2016 E.C., executed, notarized and sealed. The Owner also reported that upload through ChatGPT was unavailable. The original binary, filename, page count, checksum, registration particulars and page-by-page content are not recovered in the repository or current Library evidence. Do not repeatedly request re-upload while that limitation persists.

Historical summaries attribute to the separate Articles/Memorandum an Article 2 capital/share provision and an Article 9 15-day certificate-deposit/registration reference. Those summaries are explicitly unverified and cannot establish the actual wording, whether the instrument is the registered Memorandum, whether it was amended, or the legal effect of the 15-day rule.

**Resolved distinction:** the 17-page bylaws scan is not the separate registered Memorandum. Its introduction says the bylaws form part of the founding documentation, but this does not independently prove that the bylaw is the registered Memorandum or that each provision is a Memorandum term for Articles 276, 278, 281, 284 or 376.

### 10.3 Record-date rule — statutory mechanism established; company-specific cutoff unresolved

Commercial Code Article 376 does not prescribe a universal number of days. It permits the Memorandum to determine the period within which holders of registered shares must be entered in the register to participate in a shareholder meeting; it also addresses deposit of pre-Code bearer shares.

The working transcription of the separate bylaws reports a 15-day pre-meeting registration/deposit clause in Article 9(3). A historical summary of the separate Articles also reports an Article 9 15-day certificate-deposit reference. These are useful corroborating leads, but neither is direct inspection of the registered Memorandum. The exact Amharic wording, whether it applies to registered shares, bearer shares or both, and whether it is operative under Article 376 remain unverified.

The bylaws transcription also reports 30 days' notice for a general meeting. Commercial Code Article 370 sets twenty-four days' notice for the first general or special meeting. Notice lead time and shareholder-register record-date cutoff are different legal controls and must not be conflated. The company-specific 30-day notice clause requires legal reconciliation; it does not itself establish a record date.

No election-specific resolution or authorization in the inspected repository sets an actual meeting date, register cutoff date/time, timezone, source capture boundary or competent approving actor. Consequently:
- **No operative AGE election record date is established.**
- A3 may implement an explicit record-date field and evidence reference, but it must not default to 15 days or derive the cutoff from meeting notice.
- The register freeze must bind the cutoff to the verified Memorandum clause and the election-specific competent resolution.

### 10.4 Share capital and paid-up evidence — aggregate arithmetic reconciled; payment and ownership not proven

The recovered page-11 schedule records:
- 29 listed founders;
- 5 shares per listed founder;
- 145 total shares;
- ETB 10,000 nominal value per share;
- ETB 1,450,000 total stated/subscribed capital;
- ETB 1,023,000 paid amount shown;
- ETB 427,000 unpaid/remaining amount shown.

Arithmetic checks:
- 29 × 5 = 145 shares;
- 145 × ETB 10,000 = ETB 1,450,000;
- ETB 1,023,000 + ETB 427,000 = ETB 1,450,000.

This reconciles the table's aggregate arithmetic only. It does not prove the actual payments were received, each holder's current paid-up balance, current ownership, or whether a shareholder has subsequently transferred shares.

Commercial Code Article 281(1) generally requires cash subscriptions to be paid at subscription to at least one-quarter of par value, or a greater amount specified in the Memorandum. Article 281(2) generally requires the balance within five years of company registration unless the Memorandum provides a shorter period. Article 273(6) requires share certificates to state the paid-up amount or that the share is fully paid. Article 274(2) requires the register to record the paid-up amount.

The aggregate ETB 1,023,000 figure exceeds one-quarter of ETB 1,450,000, but an aggregate comparison cannot prove that each subscriber met the required amount at subscription. The company registration date, per-holder/per-share payment schedule, original subscription records, bank/deposit receipts, cashbook/ledger postings, share certificates and subsequent capital resolutions were not recovered for this pass.

**Disposition:** historical stated capital arithmetic = RECONCILED; current paid-up capital and outstanding calls = UNRESOLVED. No unpaid amount may be treated as paid, forgiven, overdue or voting-disqualifying without the governing instruments, dates and payment evidence.

### 10.5 Share classes and voting entitlement — application categories are not legal share classes

The current application configuration/seeder uses `FOUNDERS` and `STANDARD` as two technical share-class codes. The seeder documentation describes FOUNDERS as the 29 founding shareholders and STANDARD as the default class for new shareholders. The recovered page-11 founder schedule shows 145 founder shares, but does not establish that FOUNDERS and STANDARD are distinct legal classes created by the registered Memorandum. The application class model has no legal voting-rights, class-rights or vote-cap fields.

Commercial Code Articles 278–279 permit classes with different rights subject to law, require equal rights within a class, prohibit issuing shares with preference as to voting rights, and permit the Memorandum to restrict voting for certain profit/capital-priority shares only within the statutory limits. Articles 383–384 govern capital-proportional voting and a lawful Memorandum vote cap. Article 275(2) suspends voting rights on shares acquired by the company itself. Article 385 addresses conflict-related voting restrictions.

Therefore no final vote-weight calculation can yet be approved from the two application codes or the historical capital table. A3 must require verified legal class instruments and explicit legal disposition of company-owned/redeemed shares, restricted-vote shares, conflicts, pledges and usufructs.

### 10.6 Share transfers and ledger — statutory transfer rule identified; actual instruments and paired transfer evidence missing

For registered shares, Commercial Code Article 285(2) requires delivery and registration in the transferee's name. The register entry must show transferor and transferee names and addresses, number of shares transferred, transfer date and register-entry date. Article 276 permits certain transfer restrictions or board-consent requirements only under the statutory conditions, including pre-emption rights and specified conditions/pricing.

The application ShareHolding domain has a current projection and an append-only signed-quantity ledger:
- `ShareHolding.current_quantity` is a projection;
- `ShareHoldingEntry.quantity` is signed;
- entries record `balance_before`, `balance_after`, `occurred_at`, references and reversal links;
- the service supports `transfer_in` and `transfer_out` entry types, transactions and per-holding row locks;
- the service exposes `recordEntry`, `recordEntryFor`, `reverseEntry`, `findOrCreateHolding`, `currentQuantity` and `entriesFor`; it does not expose a single atomic transfer operation pairing the transferor debit and transferee credit under one transfer identity and one transaction.

The ledger's `occurred_at` is business time and can differ from `created_at`; backdated business events are representable. A free-form `reference_type/reference_id` is not, by itself, proof of the signed transfer instrument or a complete paired transfer. No signed transfer agreements, transfer notices, pre-emption evidence, board-consent resolutions, certificate endorsements/cancellations, or complete transferor/transferee register history were recovered.

**Disposition:** ledger mechanics = TECHNICALLY RECONCILED; legal transfer chain and current beneficial/registered ownership = UNRESOLVED. A3 must not infer a valid transfer solely from `transfer_in/out` rows or reconstruct ownership from current balances. A proper transfer operation needs a paired immutable transfer aggregate, legal-instrument evidence, both register changes and atomic debit/credit.

### 10.7 Historical identity and representative authority — current state and audit trail are not a complete legal temporal register

Current Shareholder records contain a current `shareholder_type`, name fields, `user_id`, `representative_user_id`, `is_active`, soft-delete timestamp and ordinary timestamps. A later migration adds `person_id` to connect a shareholder to the canonical People domain. These are current-state relationships, not effective-dated shareholder-status or legal-mandate histories.

The ShareholderService supports registration, update, deactivate/reactivate and representative assignment/clearing. The ShareholderUpdated event and AuditShareholderEvent listener are designed to record old/new changed fields, including `is_active` and `representative_user_id`, in audit rows with `created_at`. However, the listener is dispatched after the business transaction commits and explicitly catches audit failures without rethrowing (fail-open). The audit row records system event time, not a legally attested effective date. It therefore may corroborate historical changes where the audit records exist and are complete, but it cannot by itself prove that the legal entity appointed the representative, the mandate's scope/duration, or the effective date of a status change. No audit-log export or independent completeness proof was available in this pass.

The current seeder file `database/seeders/Data/ShareholderRegister.php` contains 100 application rows (SH-0001 through SH-0100). It currently classifies SH-0023 (Maale Hibret) and SH-0088 (Sarangi) as `religious_body`; the seeder's own change notes state that these rows previously carried an invalid generic `organization` type. Historical reconciliation notes separately report a prior SH-0023 `cooperative` classification. The current repository code resolves what the current seed says, not the legal identity of either entity. No registration certificate, constitutive document or competent entity resolution was recovered to resolve SH-0023's legal classification. The 100 seeded application rows are not the 29-entry founding schedule and are not the statutory register.

**Representation law boundary:** Article 270(2) requires joint owners to appoint a representative for the rights attached to jointly owned shares. Article 271 addresses voting rights where shares are pledged or usufructed and requires registration of pledgee/usufructuary voting rights. Articles 373 and 377 govern a shareholder's meeting proxy and its form/deposit timing. Article 296(4)'s permanent-representative rule applies where a legal-person entity itself is appointed as a director; it is not a general rule validating every legal-entity shareholder's day-to-day representative. Article 299 concerns representation of different share classes on the board; it is not a substitute for an entity's own mandate. The bylaw Article 8 proxy clause is reported to require a written, dated, shareholder-signed authorization. No current or historical corporate mandate, entity resolution, proxy, revocation, replacement or effective-period evidence was recovered.

**Disposition:** current technical identity fields = RECONCILED; historical legal identity, entity classification, active status, representative mandate and voting proxy as-of any record date = UNRESOLVED.

### 10.8 Final evidence register

| Reconciliation item | Finding | Status |
|---|---|---|
| Statutory authoritative source | Article 274 head-office register is controlling; current certified extract absent | RULE RESOLVED / SOURCE MISSING |
| 17-page bylaws schedule | Owner-confirmed source and SHA; 29-entry founder schedule; not proven current statutory register or Memorandum | HISTORICAL SOURCE IDENTIFIED |
| Separate registered Memorandum | Owner says original exists; binary not recovered or inspected | MISSING FROM ACCESSIBLE EVIDENCE |
| 29 founders / 145 shares | Historical schedule aggregate; arithmetic reconciles | AGGREGATE RECONCILED |
| Paid ETB 1,023,000 / unpaid ETB 427,000 | Source-table totals only; no receipts/ledger proof or current per-holder reconciliation | UNRESOLVED |
| Legal share classes / vote cap | Application FOUNDERS/STANDARD are not legal evidence; Memorandum absent | UNRESOLVED |
| Record date | Article 376 allows Memorandum-set registration period; 15-day clause is only provisional | RULE MECHANISM RESOLVED / COMPANY CUTOFF UNRESOLVED |
| Transfer history | Article 285 formalities identified; no transfer instruments or complete paired register history | UNRESOLVED |
| Historical status and representatives | Current fields and fail-open system audit trail do not prove legally effective history | UNRESOLVED |
| SH-0023 classification | Current seeder says religious_body; historical note says cooperative; primary entity evidence absent | UNRESOLVED |
| Current 100 seeded rows | Application reference data only; not statutory register | NON-AUTHORITATIVE |

## 11. Revised A2 disposition and required gate

**Completed in this A2 pass:**
- statutory register hierarchy and mandatory Article 274 data fields;
- registered-share transfer formalities under Article 285 and statutory restrictions under Article 276;
- record-date mechanism under Article 376;
- capital-payment and certificate particulars under Articles 273 and 281;
- voting/class rules under Articles 270–279 and 383–385;
- reconciliation of the 29/145 and ETB 1,450,000 / 1,023,000 / 427,000 historical aggregate arithmetic;
- exact current application-schema and seed-data limitations;
- audit/event-history limitations and legal-entity proxy boundaries.

**Still blocked by unavailable authoritative evidence:**
1. Separate registered Memorandum and all amendments/registration particulars.
2. Current certified Article 274 shareholder-register extract, including names, addresses, share serials, paid-up amounts and entry dates.
3. Per-holder capital payment evidence, registration date and current unpaid calls.
4. Signed transfer instruments and complete transfer/endorsement/register-entry history.
5. Effective-dated legal identity/status and representative appointment/revocation instruments.
6. Election-specific competent resolution fixing the record date and exact cutoff.

**Module 3 A2: PARTIAL — technical/statutory reconciliation completed; authoritative company-specific facts remain unresolved.** This is not an A2 closure. A3 remains NOT STARTED and requires a separately approved, evidence-backed schema and rules contract. No code, migration, test, seed data, production data, main branch, merge or deployment was changed by this A2 work.

**Evidence acquisition route:** under Commercial Code Article 274(4), request a copy/extract of the current shareholder register from the company within the statutory fifteen-day response period. The separate registered Memorandum must be inspected from the Owner-held original or obtained from the registering authority. Payment, transfer, entity and mandate evidence must be reconciled to those primary records, not to seed data or OCR alone.

## 11. Owner design constraint — database-backed register and policy-driven entitlement

**Owner direction:** Module 3 must be database- and policy-driven. It must not hard-code shareholder identities, register numbers, share quantities, ownership, representatives, eligibility, voting weights, or record-date outcomes in application logic.

### 11.1 Distinguish the repository seeder from the live register

The repository contains `database/seeders/Data/ShareholderRegister.php`, a PHP data class with 100 keyed entries (SH-0001 through SH-0100), and `database/seeders/ShareholderSeeder.php`, which writes the seed data into database rows and derives user identifiers. The data class is a static, version-controlled bootstrap dataset. Its own documentation calls it the register, but that label does not make the PHP constant the runtime source of truth or independently establish that it is the statutory head-office register under Commercial Code Article 274.

For Module 3, treat this seeder as a **legacy/bootstrap source dataset to be reconciled and, if approved, imported**. Once records are persisted, all operational reads and decisions must use the database-backed domain model and approved policy version. Do not make runtime eligibility or voting decisions by reading `ShareholderRegister::ROWS`, SH-ID ordering, or any other PHP constant. Do not assume rerunning the seeder is a safe way to update a live statutory register.

### 11.2 Required data architecture

1. **Database-backed master records:** shareholder identity, legal form, address/contact particulars, entity identity, authorized representative relationship, share certificates, share classes, current holdings, and historical holding entries must be persisted in normalized domain tables with stable internal identifiers.
2. **Temporal history:** changes to names, addresses, legal status, representatives/mandates, share ownership, certificate status, and register particulars must be recorded as effective-dated, attributable events. Preserve both business-effective time and system-recorded time; never overwrite history needed to reconstruct an earlier record date.
3. **Policy-driven rules:** record-date selection, holder qualification, share-class rights, voting entitlement, vote weighting/caps, proxy/representative treatment, suspension/disqualification, and meeting-specific cutoffs must be resolved from explicit, versioned policy records and governing-document parameters—not shareholder-specific branches or constants.
4. **Policy provenance:** every policy version must identify its authority (statute, registered Memorandum, approved bylaw, or valid meeting resolution), effective period, approval/evidence reference, and supersession status. Where the legal source is unresolved, the policy must remain blocked/unapproved rather than silently defaulting.
5. **Reproducible election snapshot:** an approved record-date run must persist the selected record date/time and timezone, input ledger watermark, policy-version identifiers, source/evidence references, calculated per-holder/per-class quantities and entitlement outcomes, exceptions, and a content hash. Later changes must not mutate a frozen snapshot; corrections require a separately authorized superseding snapshot with a traceable reason.
6. **Auditable operations:** register amendments, transfers, reversals, certificate issue/cancellation, mandate changes, policy approvals, and snapshot approvals must be attributable to an authenticated actor and committed atomically with their audit evidence. Failure to persist mandatory audit evidence must fail the operation closed.
7. **Seeding and imports:** seeders may create controlled bootstrap/test fixtures or perform an explicitly approved, validated initial import. They must be idempotent, detect conflicts, preserve provenance, and must not silently overwrite operational records or fabricate legal evidence. Production corrections use governed application workflows.

### 11.3 Separation of concerns and non-negotiable invariants

- **Facts belong in data:** who the shareholders are, what they own, and who represents them are database facts with evidence and history.
- **Rules belong in policy:** how those facts produce meeting-specific voting rights is a versioned, reviewable policy decision.
- **Code enforces invariants:** authorization, transaction atomicity, ledger integrity, immutable history/snapshots, validation, and statutory constraints are enforced by domain services and database constraints. Code must not encode this company's changing register contents or substitute an undocumented assumption for a missing legal rule.
- **No inferred rights:** a seeded role, login account, entity representative link, current balance, or application share-class label does not by itself prove legal ownership, mandate, class rights, or voting entitlement.
- **No hard-coded cohort:** no list of SH-IDs, names, founder identities, fixed shareholder count, fixed holding quantities, or per-holder voting exception may appear in runtime business logic, authorization policies, or election calculations.

### 11.4 A2 reconciliation and A3 gate

A2 may inspect and document the existing schema, seeder, migrations, services, policy/configuration mechanisms, and evidence gaps. It must not alter runtime code or data. The current 100-row seeder must be reconciled against the recovered 29-entry founding schedule and available primary evidence; differences must be classified, not automatically “fixed” by editing the seeder.

Before A3 implementation, the design must specify the normalized database schema and migration plan, effective-dated register/mandate history, policy model and authority hierarchy, transfer/certificate evidence contracts, deterministic record-date calculation, immutable snapshot contract, audit/authorization boundaries, and migration/import strategy. A3 must not begin until the separate registered Memorandum and unresolved company-specific legal rules have been inspected or explicitly recorded as blocking unknowns under Owner direction.

**Disposition:** Owner architecture direction RECORDED. Existing seeder confirmed as static bootstrap data, not accepted as the live runtime source of truth. Database/policy-driven implementation is a binding A3 design constraint. No runtime code, migration, seed data, production data, main branch, merge, or deployment is changed by this A2 documentation amendment.

## 12. A2 technical inspection — database, configuration, and policy gaps

This inspection was performed against the A2 branch after the Owner's database/policy-driven direction. It is read-only. It identifies which existing capabilities can be reused and which current implementation details must not be carried forward as authoritative Module 3 rules.

### 12.1 Existing database-backed capabilities

| Existing component | Verified capability | Module 3 assessment |
|---|---|---|
| `shareholders` / `Shareholder` | Persisted holder identity, type, individual/entity names, user and representative links, active flag, soft-delete timestamps | Reuse as an identity foundation, but it is not yet a complete Article 274 register |
| `share_holdings` / `ShareHolding` | Current quantity projection per shareholder and share class | Reuse only as a current-state projection |
| `share_holding_entries` / `ShareHoldingEntry` | Append-only signed quantity ledger, before/after balances, business-effective `occurred_at`, references and reversal link | Reuse as historical quantity source, subject to transfer-operation gap below |
| `share_certificates` | Certificate number, status, issue/cancellation times and links to holder/class/holding | Reuse as certificate lifecycle foundation; it does not itself preserve certified quantity or primary certificate evidence |
| `versioned_policies` / `VersionedPolicyRegistry` | Append-only, immutable policy versions with type, version, effective window and opaque JSON payload | Potential policy storage foundation; not yet a complete approved legal-policy registry |

The shareholder identity table currently has no direct address field, share serial/numeration field, paid-up amount, or register-entry date. Its present columns therefore do not independently satisfy the full Article 274 register particulars. Current holdings and ledger rows are separate from identity, as they should be.

### 12.2 Existing configuration and seeder hard-coding that must be removed from legal decision paths

The current shareholder domain configuration is not yet fully database/policy-driven:

- `Config/shareholder.php` declares `FOUNDERS` and `STANDARD` class codes, a default class, and a founder class code.
- The same config declares ETB 10,000 par value and ETB currency; six shareholder types; representative requirements and a one-active-representative cap; quantity/fractional-share rules; and ledger entry-type vocabulary.
- The configuration's explanatory text embeds the 29-founder / 145-share figure as a seed/reference fact.
- `ShareClassSeeder.php` contains a PHP constant with the two class codes and bilingual labels, then applies the configured par value/currency to both rows.
- `ShareholderRegister.php` contains the 100 static identity rows, and `ShareholderSeeder.php` materializes them and derives usernames/emails.

These files are legitimate places to hold bootstrap fixtures and technical enum/validation vocabulary where appropriate. They are **not** acceptable authorities for current holder facts, registered share-class rights, par-value history, founder eligibility, ownership, or election entitlement. Module 3 must not treat `FOUNDERS` as proof of a legally distinct class or infer a voting right from the seed label. Legal/economic parameters must be loaded from approved, effective-dated database records whose provenance points to the governing instrument. Where the instrument is unavailable, the rule remains unresolved and entitlement must fail closed.

### 12.3 Existing generic policy capability is insufficient without extension/contract

The current `versioned_policies` table stores `policy_type`, `version`, `effective_from`, `effective_until`, and opaque `payload`; its migration documents the currently declared types as `kyc`, `interest`, and `allocation`. `VersionedPolicyRegistry` creates append-only versions and resolves temporal applicability, while the model prevents updates/deletes.

This is useful infrastructure, but it does not currently establish a shareholder-voting policy contract. The table has no first-class authority instrument, clause/reference, approval actor/body, approval timestamp/resolution, evidence attachment/hash, supersession reason, or publication state. The registry also does not validate a consumer-specific payload schema. A future voting policy must therefore add a validated domain contract and auditable approval/provenance layer; it must not accept an arbitrary JSON payload as legally approved policy.

### 12.4 Additional domain integrity gaps relevant to the target architecture

1. **Transfers:** the ledger service posts a single holding entry per call. `transfer_out` and `transfer_in` are valid entry types, but the inspected public API does not expose one atomic transfer aggregate that debits the transferor and credits the transferee under one immutable transfer identity and one transaction. A shared free-form reference is not proof of a valid transfer instrument or paired posting.
2. **Representatives:** the shareholder row stores only the current representative user link. The event/audit mechanism is not a temporal mandate register and does not itself establish appointment authority, effective dates, revocation or replacement evidence.
3. **Audit failure mode:** `AuditShareholderEvent::handle()` catches `Throwable`, logs, and returns after the business transaction has committed. This is fail-open. It is not sufficient as the sole mandatory evidence trail for legal-register amendments, transfers, mandate changes, policy approval or election snapshot approval.
4. **Share classes and rights:** the current ShareClass model stores code, bilingual labels, par value, currency, active and treasury flags; no class-specific voting rights, vote multipliers, caps or restrictions are represented.
5. **Certificates:** the certificate table records lifecycle metadata but has no quantity snapshot or explicit cryptographic/evidence-verification contract. A certificate row alone cannot prove a signed original instrument.
6. **Temporal register facts:** `is_active` and soft deletion are current-state fields, not effective-dated legal status history. Historical record-date reconstruction cannot rely on these fields alone.

### 12.5 Reconciliation of the 100 application rows to the historical 29-person schedule

The 100-row application dataset is confirmed at `database/seeders/Data/ShareholderRegister.php` (blob `8e387599ab75db23d58db963b56a96df5d6c67b5`). It contains 98 individual rows and two entity rows (SH-0023 Maale Hibret and SH-0088 Sarangi), each with identity/name and free-form address data. It does not carry per-holder share quantity, share class, paid-up amount, certificate, register-entry date, or transfer history.

The recovered 17-page bylaws transcription records 29 entries on printed pages 9–10 and aggregate figures on page 11 (29 holders, 5 shares each, 145 shares, ETB 10,000 nominal value, ETB 1,023,000 paid and ETB 427,000 unpaid). However, that transcription explicitly records the 29 names and individual allocation rows as **not transcribed**; the original scanned pages remain necessary for exact matching. The available text therefore supports an aggregate comparison only, not a defensible 29-to-100 identity join. Do not infer matches from similar names, signature lists, seeder ordering, or the first 29 SH-IDs.

**Current reconciliation result:** 100 application identity rows FOUND; 29 historical schedule rows COUNT/AGGREGATES FOUND; exact identity crosswalk NOT POSSIBLE from currently recovered text; per-holder share/payment match NOT POSSIBLE; additional 71 rows are not thereby proven later shareholders or invalid holders. The statutory head-office register and original Memorandum remain independent evidence requirements.

### 12.6 Required A3 design consequences (not implementation authorization)

The eventual design must:
- make the database register the only operational source for holder facts and ownership;
- store share classes, economic terms, legal rights, and meeting-specific rules as governed records, with policy versions and evidence-backed approvals;
- model shareholder address and all Article 274 particulars, certificate particulars, effective-dated identity/status/mandate events, and full transfer instruments;
- implement paired transfer postings atomically, with one transfer aggregate, immutable instrument evidence, and ledger reconciliation;
- provide a fail-closed mandatory audit/outbox or same-transaction evidence mechanism for legally material operations;
- validate policy payloads against a versioned shareholder-voting schema and require authority/evidence/approval provenance before a policy can be applied;
- calculate entitlement from the approved policy plus database facts at a defined record date, then persist an immutable, reproducible snapshot and exceptions;
- isolate seeders to fixtures or explicitly approved import paths, with no silent overwrite of live legal records.

**A2 disposition:** existing database foundations FOUND; full Article 274 register schema PARTIAL; generic versioned-policy infrastructure FOUND but voting-policy governance MISSING; hard-coded bootstrap/config values IDENTIFIED and must not govern legal/election outcomes; 29-to-100 identity crosswalk UNRESOLVED because source names/rows are not transcribed; transfer pairing, effective-dated mandate history and fail-closed legal audit MISSING. No runtime code, migration, test, seed data, production data, main branch, merge or deployment was changed by this inspection or this documentation update.



## 13. A2 source recovery — original 29-holder schedule and capital allocations

This section supersedes the earlier provisional statements in §12.5 that the 29-holder identity crosswalk and individual allocation transcription were not possible. The original scan was subsequently located in the Owner's Library and inspected directly.

### 13.1 Original source identity and integrity

- Original file: `maale_arna_bylaws_original_scan.pdf` (17-page Amharic scanned bylaws/internal-regulations document).
- Printed pages inspected: 9, 10 and 11; a separate three-page extract of those exact pages is also preserved in the Library.
- SHA-256 independently computed from the original PDF bytes: `8df31ad217b2221f42b3e0e1154007bdd5d2ab003df0e1ad3dc7f17c03b61183`, matching the Owner-supplied checksum recorded in the P2.5 evidence index.
- This confirms byte-integrity against the supplied checksum. It does not independently authenticate the issuing authority, signatures, registration, legal effect, or whether later amendments exist.
- The document title is `የመተዳደሪያ ደንብ` (bylaws/internal regulations). It is not the separate registered Memorandum/Articles and must not be represented as such.

### 13.2 Identity crosswalk to the repository seeder

Visual comparison of the 29 schedule rows on printed pages 9–10 against `database/seeders/Data/ShareholderRegister.php` at main blob `8e387599ab75db23d58db963b56a96df5d6c67b5` establishes an ordered identity crosswalk:

- Historical schedule rows 1–29 correspond, in sequence, to application seed IDs SH-0001 through SH-0029.
- The Amharic personal-name and parent-name components match across the schedule and the corresponding first 29 seeder rows, subject to abbreviation/orthographic variation in the entity row and minor spelling variation in some names.
- Schedule row 23 is the Maale Hibret church-union/entity entry corresponding to SH-0023. The identity match does not resolve the entity's legal classification or whether the correct legal form is cooperative or religious_body.
- The 29-row schedule contains no application SH-ID field; the crosswalk is based on name sequence and visible name components, not on a shared unique legal identifier. It is therefore a strong documentary identity match, not a substitute for a current certified register or primary identity documents.
- The remaining 71 seeder identities (SH-0030–SH-0100) do not appear in this 29-row schedule. Their later admission, transfer, validity, current status, and legal ownership are neither proved nor disproved by this comparison.

No shareholder names, signatures, identity-document numbers, or per-holder financial amounts are copied into this public engineering record.

### 13.3 Individual allocation schedule — manual transcription and arithmetic

The page 11 table was manually inspected at high resolution. All 29 individual rows state five shares at ETB 10,000 nominal value per share, giving ETB 50,000 subscribed allocation per row. The individual paid and unpaid amounts were read from the original image, not inferred from OCR.

Independent arithmetic over all 29 rows gives:

- 29 × 5 shares = 145 shares.
- 145 × ETB 10,000 = ETB 1,450,000 subscribed/stated capital.
- Sum of the 29 paid cells = ETB 1,023,000.
- Sum of the 29 unpaid cells = ETB 427,000.
- Paid plus unpaid = ETB 1,450,000.

All four source-table totals reconcile exactly. This establishes what the historical bylaws schedule records. It does not establish receipts, bank deposits, later payments, calls, forfeitures, transfers, or present unpaid balances.

The per-holder values are retained in the original source table and the Owner-facing A2 reconciliation, not duplicated in this public repository document because they are personal financial data linked to identifiable shareholder rows.

### 13.4 Cross-check against the separate legacy SQL export

A separate Library artifact, `maale_arna_smart_system(1).sql`, was inspected as a secondary historical application-data lead. Its header identifies a phpMyAdmin dump generated 1 September 2026 from database `maale_arna_smart_system`; its SHA-256 is `19092485d36469b836ac43225b683525984b565037904f7b0d4e1d2611d67d7e`.

The dump is not a certified Article 274 extract and its provenance does not establish it as the current statutory register. It nevertheless exposes material internal conflicts:

- Its legacy `shareholders` table contains 40 rows (SH-0001–SH-0040), including share quantity, paid value and unpaid amount.
- For the first 29 IDs, 27 of the 29 summary rows disagree with the original bylaws schedule on at least one of share quantity, paid value or unpaid amount. Only two rows match all three numeric fields.
- Its `share_transactions` table contains 123 rows: 54 Purchase and 69 Payment entries. Of the 29 Purchase entries associated with SH-0001–SH-0029, only 9 agree with the bylaws schedule on all of share quantity, paid amount and unpaid amount; 20 conflict.
- The legacy summary and transaction tables also disagree with each other for multiple holder IDs. Transaction status labels include pending and partial entries; these are database status values, not bank receipts or proof of cleared funds.
- The export therefore cannot be used to overwrite the historical schedule or certify current holdings. It is a reconciliation exception source requiring transaction-level accounting evidence, receipts/bank statements, journal records, transfer instruments and a current statutory register.

### 13.5 Reconciliation disposition

| Item | Finding | Status |
|---|---|---|
| Original 17-page bylaws scan | Located; 17 pages inspected for identity; checksum matches Owner-supplied SHA-256 | SOURCE INTEGRITY VERIFIED |
| Historical schedule count | 29 rows | VERIFIED FROM SCAN |
| Identity crosswalk | Schedule rows 1–29 map in order to SH-0001–SH-0029 | MATCHED — UNIQUE-ID LIMITATION |
| Per-holder historical allocations | 29 rows manually inspected; all 5 shares / ETB 50,000 nominal each | TRANSCRIBED |
| Aggregate subscribed capital | ETB 1,450,000 | RECONCILED |
| Aggregate paid/unpaid schedule | ETB 1,023,000 / ETB 427,000 | RECONCILED AS HISTORICAL TABLE ONLY |
| Seeder rows SH-0030–SH-0100 | Not present in this 29-row schedule | UNRESOLVED — NOT INVALIDATED |
| Legacy SQL summary | 27/29 first-row numeric mismatches | CONFLICTING SECONDARY DATA |
| Legacy SQL purchase entries | 20/29 numeric mismatches | CONFLICTING SECONDARY DATA |
| Current ownership and paid-up status | No certified current register or cleared-payment proof | UNRESOLVED |

## 14. Statutory register and separate Memorandum — evidence resolution

### 14.1 Commercial Code Article 274 register

The legal source hierarchy is resolved: the company's shareholder register maintained at its head office under Commercial Code Article 274 is the controlling statutory register. The historical bylaws schedule and application seeder are not substitutes for it.

The located bylaws pages 9–11 provide a historical 29-holder schedule and capital allocations, but do not establish the current Article 274 register particulars for every holder, including current names/addresses, share numbers and numeration, paid-up amount, and date of entry. They also do not establish post-schedule admissions, transfers, cancellations, changes of address, legal status, or current registered ownership.

**No head-office Article 274(4) request or delivery receipt is present in the accessible evidence.** The fifteen-day period is the company's response period after a request for a copy/extract; it is not a statutory record-date cutoff. The current statutory extract therefore remains MISSING. The earlier request instruction is recorded, but this A2 inspection has not sent a request or claimed that one was sent.

### 14.2 Separate registered Memorandum / Articles

The original Memorandum/Articles remains Owner-reported as an image-scanned document historically described as dated 16/12/2016 E.C., executed, notarized and sealed, and reportedly supplied in a prior ChatGPT conversation on 11 September 2026. The accessible Library catalog and destination repository were searched; no matching original binary or verifiable registered copy was located. Existing repository legal-source notes likewise record the original as not preserved or inspected.

The bylaws PDF inspected in §13 is a different document and cannot cure this gap. Historical summaries attributing capital, register, proxy or fifteen-day clauses to the Memorandum remain provisional until the actual registered instrument and amendments are inspected. No Memorandum clause, registration number, notarial authentication, amendment history, or operative legal effect is verified by this A2 pass.

No request is made for another upload. The source remains Owner-held/reported but unavailable for direct inspection in the accessible workspace.

### 14.3 What is now resolved, and what is not

**Resolved in A2:** historical 29-row identity crosswalk to SH-0001–SH-0029; individual historical allocation transcription; aggregate capital arithmetic; original bylaws file checksum; identification of material conflicts in the separate legacy SQL export; legal hierarchy identifying the Article 274 head-office register as controlling.

**Still unresolved / blocking final register and entitlement:** current certified Article 274 extract; registered Memorandum/Articles and amendments; current per-holder cleared payment evidence; transfer and certificate instruments/history; legal form and authority for entity shareholders and their representatives; status of the additional 71 seeder rows; election-specific record date and exact cutoff; any company-specific share class/voting restrictions.

**A2 remains PARTIAL — not closed.** The 29-row historical schedule is now reconciled to the first 29 seeder identities and its individual allocation totals. Current statutory ownership, current paid-up status and operative company-specific voting rules remain unverified. A3 remains NOT STARTED. No application code, schema, migration, test, seed data, production data, main branch, merge or deployment was changed by this reconciliation.
