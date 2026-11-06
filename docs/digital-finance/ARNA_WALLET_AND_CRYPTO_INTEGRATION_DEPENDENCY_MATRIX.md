# ARNA Bank-Connected Wallet and Independent Cryptocurrency — Integration Dependency Matrix

**Status:** Architecture and regulatory dependency assessment; not an authorization, implementation specification, or launch approval  
**Assessment date:** 7 November 2026  
**Repository baseline:** `5a586c811943a2ea2252bc8a14d7599d5eb46dd3`  
**Owner direction recorded:** ARNA Digital Finance is to assess an independent ARNA cryptocurrency using a hybrid conventional-ledger/blockchain architecture. The existing ARNA Wallet is a separate member-position subledger.  
**Change boundary:** Documentation only. No API, database, wallet, payment, token, blockchain, or production configuration changes are authorized by this document.

---

## 1. Executive decision

ARNA must keep three distinct financial concepts separate:

1. **ARNA Finance ledger** — the enterprise accounting system of record, with ETB base currency, multiple legal entities, separate entity accounts, and consolidated reporting.
2. **ARNA Wallet** — currently an internal member-position subledger. Repository review found internal wallet/payment/obligation/allocation records, but no bank adapter, mobile-money adapter, provider webhook, external settlement connector, or bank reconciliation integration.
3. **Independent ARNA cryptocurrency** — a proposed digital asset with its own legal classification, issuance, governance, custody, transfer, reserve/economic and blockchain dependencies. It is not an extension of the ETB Wallet and must not be represented as licensed e-money.

**Current gate:** Do not enable live bank-connected money movement or issue, sell, transfer, custody, redeem, exchange, settle, or facilitate ARNA cryptocurrency until the relevant regulatory perimeter and written authorizations have been confirmed. A general company registration, bank relationship, technology-provider contract, or regulatory sandbox participation must not be treated as a substitute for the specific permission required for the activity.

## 2. Regulatory perimeter and constraints

### 2.1 Bank-connected Wallet

The National Payment Systems framework is the primary regulatory starting point. The National Bank of Ethiopia's Payment Instrument Issuer Directive ONPS/09/2023 was amended by ONPS/10/2025. The amendment:

- sets a minimum paid-up capital of ETB 100 million for a payment instrument issuer under formation;
- requires two-factor authentication for electronic-money-account transactions above ETB 5,000; and
- requires a comprehensive risk-management framework for bank-to-electronic-money-wallet and wallet-to-bank transfers, including two-factor authentication, real-time KYC validation of the recipient's full name, recipient identity visibility, and risk-based transaction limits.

These requirements must be applied to the correct licensed entity and product model. They do **not** automatically mean that a software provider or a bank acting as the regulated issuer must each independently meet the same PII requirements. ARNA must obtain NBE classification of its proposed role before selecting an operating model.

NBE's 27 February 2026 public notice states that Birr-denominated P2P trading or exchange involving cryptocurrencies on trading platforms is not permitted unless expressly authorized. Subsequent reporting of an NBE public notice dated 23 July 2026 describes a broader virtual-asset restriction covering use, purchase, sale, exchange, transfer, trading, settlement, facilitation, custody/administration and issuer-related financial services unless expressly authorized by NBE. Obtain and retain the original NBE notice and written counsel/regulator interpretation in the engagement file before relying on the reported July scope.

Official references:
- [NBE — ONPS/10/2025 amendment](https://nbe.gov.et/wp-content/uploads/2025/05/ONPS102025-Oversight-of-the-Natioanl-Payment-System-Licensing-and-Authorization-of-Payment-Instrument-Issuer-Amendment-Directive.pdf)
- [NBE — payment instrument issuers and system operators](https://nbe.gov.et/payment-instrument-issuers-system-operators/)
- [NBE — 27 February 2026 Birr-paired P2P notice](https://nbe.gov.et/nbe_news/public-notice-notice-on-illegal-birr-paired-peer-to-peer-p2p-transactions-via-trading-platforms/)
- [Reporting on the 23 July 2026 NBE virtual-asset notice](https://www.thereporterethiopia.com/51969/)

### 2.2 Independent ARNA cryptocurrency

The proposed cryptocurrency must be treated as a separate regulatory workstream. NBE's reported July 2026 position is prohibition-first unless expressly authorized. Its described scope includes custody/administration and services connected with an issuer's offer or sale, not merely exchange trading. No activity should be assumed permissible merely because the asset is called a utility token, community token, reward, coin, or blockchain asset.

ECMA's Capital Market Service Providers Licensing and Supervision Directive defines digital assets as things in digital form with value, ownership and usage rights, and states that digital assets are also regarded as securities in line with Article 2(62)(g) of the Capital Market Proclamation. The same Directive prohibits regulated capital-market activities without the applicable ECMA service licence. Public offer and trading of securities are also subject to the applicable registration framework and exemptions.

This means NBE and ECMA classification may be cumulative, not alternative. A token that represents ownership, debt, profit, investment or another financial right may raise securities questions in addition to NBE virtual-asset/payment questions. Obtain written classification from both authorities where the proposed design engages both perimeters.

Official references:
- [ECMA — Capital Market Service Providers Licensing and Supervision Directive](https://ecma.gov.et/cmsp/)
- [ECMA — laws and regulations, including Capital Market Proclamation No. 1248/2021 and Public Offering and Trading of Securities Directive No. 1030/2024](https://ecma.gov.et/laws-regulation/)

This document is a product and engineering dependency assessment, not Ethiopian legal advice. Counsel must verify the current consolidated laws, amendments, commencement dates, exemptions, regulator notices and exact applicability to ARNA's proposed corporate structure and product design.

## 3. Integration dependency matrix

| ID | Dependency | Bank-connected Wallet | Independent ARNA cryptocurrency | Required evidence / exit gate |
|---|---|---|---|---|
| D-01 | Product and role classification | Decide whether ARNA is software/technical service provider, agent, payment system operator, payment instrument issuer, or another permitted role. | Define token rights, transferability, payment/investment use, issuer obligations, custody and settlement functions. | Written NBE classification; ECMA classification where relevant; Ethiopian counsel opinion. |
| D-02 | Legal entity and licensing | Select the contracting/regulated entity. If ARNA itself issues a payment instrument/e-money, determine PII licence, capital, governance and operational obligations. | Determine whether the issuer, exchange, custodian, broker, administrator or other participant needs NBE authorization and/or ECMA service licence/registration. | Approved entity chart, licences/authorizations, regulator correspondence and fit-and-proper evidence. |
| D-03 | Banking partner | Select a licensed Ethiopian bank; agree settlement accounts, API/host-to-host channel, account ownership, safeguarding, settlement windows, fees and service levels. | No crypto settlement or conversion through a bank until NBE explicitly authorizes the relevant activity and the bank confirms its permitted role. | Executed bank agreement, technical specification, bank confirmation and NBE no-objection/authorization as applicable. |
| D-04 | Payment rails and PSPs | Choose bank rails, a licensed PII/PSO, mobile-money provider or EthSwitch route. Confirm each partner's current licence and permitted product scope. | Do not route crypto purchase, sale, exchange, transfer or settlement through Wallet, bank, mobile money or switch rails absent express authorization. | Partner due diligence, current NBE licence status, integration approval and end-to-end sandbox evidence. |
| D-05 | Customer funds and safeguarding | Establish whose bank account legally holds funds, reconciliation ownership, segregation/safeguarding, trust/beneficial ownership and insolvency treatment. | Define whether any reserves or backing assets exist and who holds them. Do not describe reserves as safeguarded customer funds without the legal structure and authorization. | Legal funds-flow diagram, bank account evidence, safeguarding opinion, reserve mandate and independent verification plan. |
| D-06 | KYC, AML/CFT and sanctions | Define onboarding, identity validation, beneficial-owner checks, risk scoring, PEP/sanctions screening, transaction monitoring, suspicious reporting, record retention and escalation. | Separate token onboarding and transaction monitoring design; wallet/address screening, source-of-funds and sanctions controls may be required if authorized. | AML/CFT risk assessment, approved policies, control testing and regulator/FIC reporting responsibilities. |
| D-07 | Authentication and limits | Implement strong authentication; ONPS/10/2025 specifies 2FA for e-money-account transactions above ETB 5,000 and risk controls for bank-wallet transfers. | Key custody and signing controls are not a substitute for customer authentication or regulatory authorization. | Security design, threshold/limit tests, step-up authentication and independent penetration test. |
| D-08 | API and provider adapter | Build provider-specific adapters only after a signed contract: initiation, status inquiry, reversal/refund, idempotency, callback signature verification, replay protection and timeout handling. | No token transfer or custody API until NBE authorization and any ECMA permissions are established. | Provider API specification, sandbox credentials, mTLS/signature rules, contract tests and security review. |
| D-09 | Ledger and accounting | Keep ARNA Wallet as a subledger; map confirmed bank movements to balanced, immutable internal journal entries. Distinguish pending, settled, failed, reversed and disputed states. | Keep token supply, token-holder positions, fees, reserves and treasury distinct from ETB cash and Wallet balances. No silent conversion between ledgers. | Approved chart-of-accounts mapping, posting rules, event model, reconciliation invariants and audit sign-off. |
| D-10 | Reconciliation and disputes | Daily three-way reconciliation: ARNA transaction ledger, provider settlement/transaction file, and bank statement. Include unmatched items, chargebacks, refunds, duplicate callbacks and ageing. | If authorized, reconcile chain events, internal token subledger, custodian records and reserves; define finality/reorg and disputed-transfer treatment. | Reconciliation runbooks, exception queues, evidence retention and tested break-resolution workflow. |
| D-11 | Data protection and records | Minimize data exchanged with banks/PSPs; encrypt data in transit/at rest; control retention, access, incident notification and cross-border processing. | Do not place personal data, KYC documents, account identifiers or confidential business data on an immutable public chain. | Data-flow inventory, privacy impact assessment, retention schedule, access review and incident plan. |
| D-12 | Availability and resilience | Provider outage handling, queueing, retry limits, circuit breakers, fail-closed controls, recovery objectives, backups and manual reconciliation fallback. | Chain outages, congestion, forks, validator compromise, bridge/oracle failures and key compromise need explicit response plans. | Tested business-continuity and disaster-recovery plans; recovery evidence; incident exercises. |
| D-13 | Security and key management | Secure API secrets in a managed secret store; least privilege, rotation, mTLS, HSM/KMS where appropriate, independent access review. | Define key generation, HSM custody, multisig threshold, signer separation, rotation, recovery, emergency pause, upgrade governance and key-loss handling. | Threat model, key ceremony, custody policy, independent audit and recovery drill. |
| D-14 | Economic and treasury model | Define fees, float, settlement liquidity, prefunding, bank charges and reconciliation of fees. Avoid promising yield or credit from an unlicensed wallet balance. | Specify supply cap/issuance, mint/burn authority, allocation, vesting, treasury, reserve policy (if any), valuation, redemption rights, volatility and market integrity. | Board-approved economic model, independent financial review, legal classification and transparent disclosure. |
| D-15 | Cross-border and FX | Restrict cross-border flows to permitted banking/FX channels and partner-approved corridors. | Do not use a token as a workaround for FX, remittance, capital-flow or payment restrictions. | FX legal review, permitted-corridor matrix, bank approval and written regulator authorization. |
| D-16 | Audit and reporting | Preserve transaction-level audit trail, user consent, provider acknowledgements, settlement files, exceptions and accounting evidence. | Add chain transaction evidence, smart-contract versions, supply attestations, custody events, governance approvals and reserve attestations if applicable. | Independent financial, security and compliance audits; regulator reporting calendar and records-retention controls. |

## 4. Architecture separation rules

1. **Wallet is not the bank account.** The bank or licensed issuer is the legal holder/issuer of the regulated money instrument unless the approved legal structure says otherwise. ARNA's ledger must not imply it accepts deposits or issues e-money when it does not.
2. **Finance is the accounting system of record.** Bank-confirmed settlement and approved Wallet subledger events must reconcile into Finance using explicit source references and balanced journal postings. Do not create opening balances until source balances are verified.
3. **Crypto is not ETB.** Do not store token quantities in ETB wallet balances or report a token as Birr-equivalent cash. Keep token units, valuation methodology and any reserve assets separate.
4. **No implicit bridge.** No Wallet-to-token or token-to-Wallet conversion, swap, redemption or settlement bridge without express authorization and a separate approved design.
5. **Conventional ledger remains authoritative for enterprise accounting.** Blockchain components, if authorized, provide only the specific issuance/transfer/attestation functions approved in the architecture. The conventional ledger records accounting, legal ownership mapping, fees, exceptions and reconciliation.
6. **No personal data on-chain.** Store only the minimum cryptographic references required; keep identity, KYC and business records in controlled off-chain systems.
7. **No live value during pre-authorization development.** Engineering may use synthetic test fixtures and non-value testnet experiments only where counsel confirms that the activity itself does not constitute prohibited virtual-asset dealing. Do not issue transferable value or invite customers to participate.

## 5. Recommended staged delivery

| Stage | Work | Gate |
|---|---|---|
| G0 — Freeze current boundary | Keep Wallet as an internal subledger; no external payment adapters or crypto functionality in production. | Owner confirms scope and no live-money activity. |
| G1 — Regulatory classification | Submit NBE request for Wallet operating model and separate virtual-asset/cryptocurrency concept; submit ECMA request if token rights or services may constitute securities/capital-market activity; obtain counsel review. | Written classification and explicit authorization path documented. |
| G2 — Partner and operating model | Select bank/PII/PSO partners for Wallet; establish legal funds flow, responsibilities, KYC/AML and settlement arrangements. | Signed conditional agreements and partner sandbox approval. |
| G3 — Detailed technical design | Specify adapters, ledger posting, reconciliation, idempotency, callback security, limits, audit, incident response and data protection. Design crypto architecture separately. | Architecture and threat-model approval; no production enablement. |
| G4 — Synthetic sandbox verification | Test bank/PSP sandbox with synthetic accounts and amounts; test crypto components only in a non-value environment with legal approval. | Test evidence, independent security review and compliance sign-off. |
| G5 — Controlled authorization and launch | Activate only the expressly authorized Wallet scope. Crypto remains separately gated by NBE and ECMA decisions and all required licences. | Written regulatory permission, board/Owner approval, production readiness and deployment approval. |

## 6. Immediate actions and owners

| Priority | Action | Responsible party | Status |
|---|---|---|---|
| P0 | Obtain original NBE 23 July 2026 virtual-assets notice and written interpretation. | Legal / regulatory lead | OPEN |
| P0 | Submit separate NBE classification requests for bank-connected Wallet and independent ARNA cryptocurrency. | Owner + Ethiopian counsel | OPEN |
| P0 | Request ECMA classification for token rights, public offering and any custody/trading/intermediation model. | Owner + Ethiopian counsel | OPEN |
| P1 | Define whether ARNA or a licensed partner is the payment instrument issuer and legal holder of customer funds. | Legal + Finance | OPEN |
| P1 | Obtain bank and PSP technical/settlement requirements and sandbox access. | Finance + engineering | NOT STARTED |
| P1 | Prepare Wallet funds-flow, ledger and reconciliation design. | Finance + engineering | NOT STARTED |
| P1 | Prepare separate token economic, governance, custody and threat-model specifications for regulator review only. | Finance + security + legal | NOT STARTED |
| P2 | Conduct production configuration review using a dedicated read-only hosting account and redacted evidence. | Infrastructure owner + security | ACCESS NOT AVAILABLE |
| P2 | Run exact-SHA API verification using the existing manual workflow and synthetic CI database. | Engineering / CI operator | DISPATCH BLOCKED |

## 7. Current implementation evidence and limitations

- The existing Wallet PaymentService records internal member payment activity and Wallet ledger entries. This is not evidence of a bank settlement or mobile-money integration.
- The inspected API repository contained no bank adapter, mobile-money adapter, provider webhook verification, external settlement connector or blockchain adapter.
- The API's manual clean-install MySQL workflow accepts an exact `target_sha`, checks out that SHA, verifies it, and runs the Loan and broader API regression suites against disposable CI infrastructure. It has not yet been dispatched for the pinned API baseline.
- The production hosting configuration has not been inspected. Repository `.env.example` values are development templates, not proof of production values.
- No cryptocurrency has been implemented or issued under this assessment. No production Wallet integration has been enabled.

## 8. Acceptance criteria

This matrix may be marked **VERIFIED** only when all applicable items have evidence:

- NBE and ECMA classifications are documented in writing, including the exact permitted activities and any conditions.
- The regulated entity, licensed partner, customer-funds ownership, safeguarding and contractual responsibilities are explicit.
- Wallet and crypto have separate product boundaries, ledgers, permissions, funds flows, disclosures and audit trails.
- Bank/PSP integrations have sandbox evidence for idempotency, callback authentication, retries, reversals, settlement and reconciliation.
- AML/CFT, KYC, sanctions, privacy, cybersecurity, continuity and independent audit requirements have named accountable owners and tested controls.
- Production configuration has been reviewed read-only against the approved deployment baseline.
- Exact-SHA tests have completed with the expected SHA, workflow URL, job logs and exit codes.
- Owner has separately approved implementation, production enablement and any launch.

**Overall status: PARTIAL — dependency matrix prepared; no regulatory authorization, provider agreement, production review, or live integration has been evidenced.**
