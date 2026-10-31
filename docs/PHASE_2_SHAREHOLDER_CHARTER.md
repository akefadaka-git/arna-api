# MASES Phase 2 — Financial Primitives & Shareholder Management Charter (SCAFFOLD)

**Project:** Maale Arna Smart Enterprise System (MASES)
**Organization:** Maale Arna Share Company
**Document type:** Domain Charter (Phase 2) — Scaffold / Decision Register
**Status:** 🔒 DRAFT — NOT APPROVED — Phase 2 implementation BLOCKED
**Version:** 0.4.0 (scaffold; Resolution B recorded for wallet)
**Date:** 2026-09-10
**Precedes:** `docs/PHASE_1_GATE_REPORT.md` (ACCEPTED v2.1.0)

---

## 1. Purpose

This document is the **charter scaffold** for MASES Phase 2. It is a
**decision register**, not a specification.

Phase 2 now covers **three peer domains**:

- **Wallet** — platform financial primitive, modelled as a **statement-of-
  account ledger** (Resolution B).
- **Loans** — platform financial primitive.
- **Shareholder** — business domain that consumes both.

The purpose of this document is to:

1. Record **every business rule that must be resolved** before Phase 2 code
   is generated.
2. Preserve the **architectural constraints inherited from Phase 1**.
3. Identify the **source documents** required to resolve each open decision.
4. Flag **architectural placement questions** and **regulatory concerns**.
5. Provide the **approval gate** that must be passed before any Phase 2 file
   is generated.

This scaffold **deliberately does not contain business rules** beyond the
stakeholder decisions recorded in §4.1.

### 1.1 Pending structural decision — charter layout

Two layouts are on the table:

- **Option X (current)** — one combined charter covering all three domains.
- **Option Y** — three separate charters + `PHASE_2_SEQUENCING.md`.

**Decision: PENDING (A11).** This document is currently formatted as Option X.

---

## 2. Source-of-Truth Hierarchy
Applicable Ethiopian law / regulations
(Commercial Code, tax law, NBE directives, capital-markets regulation)
↓

Maale Arna Articles of Association / Memorandum / bylaws
↓

Approved company policies
↓

MASES Phase 2 Charter (this document)
↓

Database schema + services + authorization
↓

Tests



A conflict is always resolved in favor of the higher layer. Any conflict
discovered after migrations are written requires an explicit charter
amendment (see §13).

---

## 3. Inherited Constraints from Phase 1

Same as v0.3.0 §3.

Additional constraints specific to Phase 2:

| Constraint | Source |
|-----------|--------|
| Wallet, Loans, and Shareholder each own their migrations under `mases_core/src/Migrations/<Domain>/` | Phase 1 Gate Report §9.1 |
| Wallet and Loans are **peer domains**, not Shareholder sub-features | Charter v0.3.0 §4.1 (A1, A2) |
| The Shareholder domain **consumes** Wallet and Loans but does not own them | Charter v0.3.0 §7 |
| Wallet is a **statement-of-account ledger**, not a bank account | Charter v0.4.0 §4.1 (A3) |
| Wallet does **not** hold funds on behalf of shareholders | Charter v0.4.0 §4.1 (A5) |
| Wallet does **not** process outbound transfers | Charter v0.4.0 §4.1 (Q-W2) |

---

## 4. Critical Architectural Questions

### 4.1 Resolved (recorded from stakeholder decisions, 2026-09-10)

| # | Question | Decision | Notes |
|---|----------|----------|-------|
| **A1** | Where does the personal wallet live? | **Option C — own domain** (`domains/Wallet/`) | Platform financial primitive. |
| **A2** | Where do loans live? | **Option C — own domain** (`domains/Loans/`) | Platform financial primitive. |
| **A3** | Wallet regulatory model | **W1 + Resolution B — Merchant Payments Model** | Statement-of-account ledger. No custody. Inbound payments only, for specific obligations. |
| **A4** | Meaning of "crypto styled wallet" | **C3 + UI** — crypto-style wallet addresses (identification only), crypto-style UI. **No blockchain. No crypto assets.** | See §6.4 (D107). |
| **A5** | Does the wallet hold funds on behalf of shareholders? | **No.** | Funds are received into a MASES merchant account and immediately attributed to a specific obligation. No spendable balance is created. |
| **A6** | Does the wallet process external money movement itself? | **Inbound only.** | The wallet accepts inbound payments (mobile money, bank transfer) attributed to specific obligations. It does **not** execute outbound transfers. |
| **Q-W1** | Where do incoming funds land? | **MASES merchant account**, immediately attributed to a specific obligation. | Not a "deposit" — an attributed payment. |
| **Q-W2** | Who executes outgoing transfers? | **W2-c — wallet does not support outgoing transfers.** | Outbound payments (dividends, loan proceeds) are executed by MASES externally and recorded in the ledger after settlement. |

### 4.2 What Resolution B means — the wallet's contract

The wallet **is:**

- A **statement-of-account ledger** recording the shareholder's financial
  relationship with MASES.
- A **receipt channel** for inbound payments attributed to specific
  obligations (share subscription, transfer payment, loan repayment, fees).
- A **historical record** of transactions — inbound and outbound.
- A **display surface** with a crypto-style UI (addresses, transaction list,
  optional running totals).
- A **linkage** between the shareholder, MASES's merchant account, and the
  obligations being paid.

The wallet **is not:**

- A bank account.
- A deposit-taking instrument.
- A spendable balance.
- A payment initiation channel.
- A channel for outgoing transfers.
- A holder of funds.
- A cryptocurrency wallet.
- A MASES liability that can be demanded back on demand.

### 4.3 Still pending

| # | Question | Options | Decision |
|---|----------|---------|----------|
| A7 | Is AML/KYC compliance in scope for Phase 2? | Yes / No / Under legal review | **PENDING** — Resolution B reduces the burden but does not necessarily eliminate it for large inbound payments. |
| A8 | Is the wallet expected to have an interest-bearing savings balance? | Yes / No / Under legal review | **PENDING** — Resolution B implies **No** (no balance to pay interest on). |
| A9 | Are loan interest calculations performed by MASES or provided externally? | MASES / External / Mixed | **PENDING** |
| A10 | Is loan collateral (including share pledge) modelled in Phase 2? | Yes / No / Under legal review | **PENDING** |
| A11 | Charter layout — Option X (combined) or Option Y (split)? | X / Y | **PENDING** |

---

## 5. Revised Phase Structure

Phase 2 is structured into **six sub-phases** across three peer domains.

| Sub-phase | Domain | Scope | Depends on |
|-----------|--------|-------|-----------|
| **2A** | Shareholder | Registry, share classes, holdings, certificates | This charter (partial) |
| **2B** | Wallet | Statement-of-account ledger, addresses, obligation attribution, UI primitives | This charter + A3, Resolution B |
| **2C** | Loans | Bidirectional loan domain, products, terms | This charter + A2, A9, A10 |
| **2D** | Shareholder | Transfers, dividends, meetings, voting, elections, ballots | 2A, 2B (for dividend recording) |
| **2E** | Shareholder | Governance & board (nomination, rating, committees, UBO, secretary, succession, disputes) | 2D |
| **2F** | Shareholder | Communications & self-service (notices, statements, portal, mobile) | 2A–2E |

**Suggested build order:** 2A → 2B → 2C → 2D → 2E → 2F.

**Rationale for starting with 2A:** Registry is the irreducible core and
does not depend on Wallet or Loans. It gives Wallet and Loans a known
consumer when they are built.

Alternative order (2B → 2C → 2A) is possible if Wallet/Loans have higher
business priority. To be confirmed by the Product Owner.

Each sub-phase is delivered **one file per response**, with stop-and-wait.

---

## 6. Decision Register

Every decision is `PENDING` unless marked otherwise.

### 6.1 Shareholder Registry & Capital (Phase 2A)

D1–D20 — unchanged from v0.3.0 §6.1.

### 6.2 Transfers, Dividends, Meetings, Voting (Phase 2D)

D21–D44 — unchanged from v0.3.0 §6.2.

### 6.3 Governance & Board (Phase 2E)

D57–D104 — unchanged from v0.3.0 §6.3.

### 6.4 Wallet (Phase 2B) — updated for Resolution B

| # | Decision point | Required source | Status |
|---|----------------|-----------------|--------|
| D105 | Wallet regulatory model | Regulatory opinion + NBE directives | **RESOLVED: W1 + Resolution B** |
| D106 | Wallet provisioning rules | Business policy | PENDING |
| D107 | Wallet identifier format (crypto-style algorithm choice) | Architecture decision | PENDING |
| D107.1 | Specific crypto-style algorithm: UUIDv4 / ULID / NanoID / base58check / other | Architecture decision | PENDING |
| D107.2 | Address prefix / display format | Architecture decision | PENDING |
| D107.3 | Address checksum algorithm (if any) | Architecture decision | PENDING |
| D107.4 | Address validation rules | Architecture decision | PENDING |
| D107.5 | Whether addresses are user-shareable | Business policy | PENDING |
| D107.6 | Whether addresses can be regenerated / rotated | Business policy | PENDING |
| D108 | Wallet currency support (inbound payment currency) | Business policy + Finance | PENDING |
| **D109** | **Obligation types that accept inbound payments** | **Business policy** | **PENDING — new under Resolution B** |
| D109.1 | Share subscription payments | Business policy | PENDING |
| D109.2 | Share transfer payments | Business policy | PENDING |
| D109.3 | Loan repayments | Business policy | PENDING |
| D109.4 | Fees (which fees — annual, KYC, statement, other) | Business policy | PENDING |
| D109.5 | Any other obligation types | Business policy | PENDING |
| **D110** | **Payment attribution rules** | **Business policy** | **PENDING — new under Resolution B** |
| D110.1 | How each payment is attributed to an obligation (reference code, amount matching, manual entry, QR code) | Business policy | PENDING |
| D110.2 | Whether a single payment can be split across multiple obligations | Business policy | PENDING |
| D110.3 | Whether partial payments are accepted | Business policy | PENDING |
| D110.4 | Whether overpayments are accepted and how they are handled | Business policy | PENDING |
| **D111** | **Unmatched payment handling** | **Business policy** | **PENDING — new under Resolution B** |
| D111.1 | What happens when an inbound payment cannot be attributed to any obligation | Business policy | PENDING |
| D111.2 | Where unmatched payments are held (suspense account?) | Business policy + Finance | PENDING |
| D111.3 | Who reconciles unmatched payments and by when | Business policy | PENDING |
| D111.4 | What the shareholder sees on their wallet for an unmatched payment | Business policy | PENDING |
| **D112** | **Outbound payment recording (dividends, loan proceeds)** | **Business policy + Finance** | **PENDING — new under Resolution B** |
| D112.1 | What information is captured for each outbound payment (amount, method, reference, settlement date, settlement proof) | Business policy | PENDING |
| D112.2 | Whether outbound payments require approval before recording | Business policy | PENDING |
| D112.3 | What state a payment is in before settlement is confirmed (pending / completed) | Business policy | PENDING |
| D112.4 | How external settlement proof (bank slip, mobile money receipt) is stored | Business policy + Files | PENDING |
| **D113** | **Wallet statement model** | **Business policy** | **PENDING — new under Resolution B** |
| D113.1 | Whether the wallet displays a "balance" at all, or only a transaction list | Business policy | PENDING |
| D113.2 | If a balance is shown, what it represents (cumulative inbound − cumulative outbound) | Business policy | PENDING |
| D113.3 | Whether obligations owed **to** the shareholder (declared dividends, approved loans) are displayed | Business policy | PENDING |
| D113.4 | Whether obligations owed **by** the shareholder (unpaid subscriptions, loans) are displayed | Business policy | PENDING |
| D113.5 | Whether a "net position" is computed and displayed | Business policy | PENDING |
| D113.6 | Statement generation frequency and format | Business policy | PENDING |
| D113.7 | Statement download format (PDF, CSV, other) | Business policy | PENDING |
| **D114** | **Mobile money integration** | **Business policy + Regulatory** | **PENDING** |
| D114.1 | Which mobile money providers are supported | Business policy | PENDING |
| D114.2 | Which merchant account receives each provider's payments | Business policy + Finance | PENDING |
| D114.3 | What webhook / callback contract is used for payment confirmation | Architecture decision | PENDING |
| D114.4 | Idempotency and replay-protection rules for webhooks | Architecture decision | PENDING |
| D114.5 | What happens if mobile money confirms a payment after the wallet has been closed or the shareholder suspended | Business policy | PENDING |
| **D115** | **Bank transfer integration** | **Business policy + Regulatory** | **PENDING** |
| D115.1 | Which banks are supported | Business policy | PENDING |
| D115.2 | How bank transfers are matched to shareholders (account number, reference code) | Business policy | PENDING |
| D115.3 | What reconciliation process aligns bank statements with wallet records | Finance policy | PENDING |
| **D116** | **Wallet closing / dormancy rules** | **Business policy + Regulatory** | **PENDING** |
| D117 | Wallet fee structure | Business policy + Regulatory | PENDING |
| D118 | Wallet interest rules on savings | NBE directives | **RESOLVED: No interest** — no balance exists under Resolution B |
| D119 | Wallet overdraft rules | Business policy | **RESOLVED: No overdraft** — no balance exists under Resolution B |
| D120 | Wallet negative-balance handling | Business policy | **RESOLVED: N/A** — no balance exists under Resolution B |
| D121 | Wallet freezing / suspension rules | Business policy + Regulatory | PENDING |
| D122 | Wallet reconciliation with Finance (accounting integration) | Finance policy | PENDING |
| D123 | Wallet audit trail requirements | Regulatory opinion + Policy | PENDING |
| D124 | Wallet data retention | Policy | PENDING |
| D125 | Wallet UI requirements (crypto-style look) | Business policy | PENDING |
| D126 | Whether wallet addresses support P2P internal transfers between shareholders | Business policy | **PENDING — but strongly constrained by Resolution B.** Internal P2P transfers would move funds between shareholders; this is likely out of scope. |
| D127 | Whether wallet supports scheduled/recurring payments | Business policy | PENDING |
| D128 | Whether wallet supports payment requests (shareholder requests payment from another shareholder) | Business policy | PENDING |

### 6.5 Loans (Phase 2C)

D129–D151 — renumbered from v0.3.0 D133–D155. All still PENDING.

### 6.6 Communications & Self-Service (Phase 2F)

D152–D165 — renumbered from v0.3.0 D156–D169. All still PENDING.

### 6.7 Cross-cutting

D166–D177 — renumbered from v0.3.0 D170–D181. All still PENDING.

---

## 7. Relationship to MASES Core

| Constraint | Detail |
|-----------|--------|
| Wallet, Loans, Shareholder are peer domains | Each has its own `domains/<Domain>/` and `mases_core/src/Migrations/<Domain>/`. |
| Shareholder consumes Wallet | Dividend payments are recorded in the wallet ledger as outbound transactions after external settlement. |
| Shareholder consumes Loans | Shareholder loans are provisioned through the Loans domain, attributed via polymorphic link. |
| Wallet consumes FileManagement | Settlement proofs, statements, and receipts stored via `FileService`. |
| Wallet consumes Audit / SecurityEvent | Every payment recording emits audit + security events. |
| Core must not be modified for Phase 2 | Any Core change requires a separate Phase 1 amendment. |

---

## 8. Migration Architecture

**Single authority:** `mases_core/src/Migrations/`.

Phase 2 adds three subdirectories:
