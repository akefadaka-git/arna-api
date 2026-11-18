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
| mases_core/src/Domains/Shareholder/Models/ShareClass.php | b1ed73163a4122b43489429d0f06049f16d39ee3 | Share-class identity and attributes; no voting-right or vote-weight fields currently exist |
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
| M3-02 | Which shareholder categories/classes have voting rights | Current Articles of Association, applicable law and approved election rule set |
| M3-03 | Whether all share classes carry equal votes per share or have different rights | Explicit class-rights schedule and approved vote-weight formula; ShareClass currently has no voting-right fields |
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
**Module 3 A2:** PARTIAL — technical source baseline is reconciled; authoritative record-date, voting-rights and temporal identity inputs remain unresolved.  
**Module 3 A3:** NOT STARTED. No application source, migration, route, permission or test was changed.  
**A5 / production:** NOT AUTHORIZED.

The next permitted work is to complete the Module 3 A2 architecture/schema/rule reconciliation against the authoritative corporate instruments and current repository contracts. Any missing legal input must remain an explicit blocker. A3 implementation requires a separately recorded Owner approval of the Module 3 A2 baseline; no production or deployment action is implied.
