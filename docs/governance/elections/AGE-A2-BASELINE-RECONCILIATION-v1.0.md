# ARNA Governance & Elections (AGE)
## A2 Baseline Reconciliation Addendum — v1.0

**Owner:** Akefa Ayele Daka  
**Baseline date:** 15 November 2026  
**Stage:** A2 — Read-only reconciliation  
**Status:** Owner-directed repository record; controlling engineering baseline  
**Implementation status:** A3 implementation not authorized by this record

## 1. Authority and controlling baseline

The Owner-approved **AGE A1 v1.0**, recorded on 15 November 2026, is the controlling requirements baseline.

Older repository specifications, draft pull requests, branch documentation and implementation assumptions are subordinate to A1 v1.0 wherever they conflict.

The following decisions are binding:

1. Legal framework: Ethiopian share-company framework (`share_company`).
2. Candidate eligibility: shareholders and qualified non-shareholders.
3. Ballot secrecy: secret ballot.
4. Voting methods: physical, electronic, proxy and hybrid.
5. Scope: complete governance and elections system, including board governance and meetings.

## 2. Repository reconciliation decisions

| Existing repository position | Controlling A1 requirement | Reconciliation |
|---|---|---|
| Physical-only voting | All four voting methods | Superseded |
| Electronic voting disabled | Electronic voting included in scope | Must be implemented and legally validated before activation |
| Shareholder-centric candidates | Qualified non-shareholders also eligible | Expand eligibility model |
| Existing voter snapshot | Independently reconciled, approved and frozen register | Strengthen lifecycle and integrity controls |
| Existing proxy foundations | Election-specific proxy verification and authorization | Complete and test |
| Existing secret-ballot table | End-to-end unlinkability and cryptographic integrity | Security verification required |
| Existing appeals structures | Independent, authorized adjudication and certification | Complete governance controls |
| Existing shareholding projections | Authoritative historical shareholding ledger | Reuse canonical source; do not duplicate |

## 3. Existing implementation evidence

The following open pull requests were identified during read-only discovery:

### API
- PR #77 — `feat/board-election-a3`
- Head: `63e61e17e4cfa1669fd8ef7b7e070f6f8f27b187`
- PR #52 — `feat/governance-meetings-foundation`
- Head: `2907f3f4129476cd47d6c1c8d056c0259cba26ee`

### App
- PR #31 — `feat/board-election-a3`
- Head: `f4f53b6e3d4d2a30fada64b48903c5b91a65dd54`
- PR #7 — `feat/governance-meetings-ui`
- Head: `2c79eedb69c39419d6eadfde6c2486e38a793fb2`
- PR #32 — merged; provides meeting attendance, proxy, correction and dispute foundations.

These are discovery references, not acceptance evidence. CI status for the inspected API #77 and App #31 exact heads was not verified. Recheck all PR heads and CI before implementation or merge decisions.

## 4. Mandatory A3 corrections

A3 must address:

1. Voter-register reconciliation, independent approval, integrity verification and controlled freeze.
2. Non-shareholder candidate eligibility and statutory board-composition limits.
3. End-to-end secret-ballot unlinkability, cryptographic integrity and key management.
4. Complete physical, electronic, proxy and hybrid voting workflows.
5. Election-specific proxy verification, authorization, revocation and duplicate-voting prevention.
6. Share-based voting entitlement reconstructed from the canonical shareholding ledger.
7. Separation of election administration, ballot custody, counting, adjudication and certification.
8. Independent appeals and auditable certification.
9. Bilingual, mobile-first frontend accessibility and usability.
10. Security, migration, feature, integration and acceptance testing.

## 5. Legal and governance blockers

The following remain unresolved and must not be invented or silently assumed:

- **LEG-01:** Current signed Memorandum and Articles, including amendments.
- **LEG-02:** Verified share-class voting rights and any applicable voting caps.
- **LEG-03:** Company-specific proxy rules.
- **LEG-04:** Candidate qualification and disqualification rules.
- **LEG-05:** Competent appeal authority and procedure.
- **LEG-06:** Legal Advisor's voting authority and role in the election committee.

These require authoritative company documents and/or qualified Ethiopian legal review. This addendum is an engineering baseline record, not a legal opinion.

## 6. Fail-closed requirements

Until the relevant legal and technical controls are verified:

- Do not activate electronic or hybrid voting.
- Do not freeze a voter register without independently authorized reconciliation and approval.
- Do not expose voter identities to ballot choices.
- Do not allow administrators to alter voting weights without an authorized, auditable process.
- Do not certify results solely on the basis of an automated tally.
- Do not treat an open pull request or an existing database table as proof of operational readiness.

## 7. A2 evidence disposition

| Item | Status |
|---|---|
| A1 Owner baseline | FROZEN |
| Repository discovery | COMPLETED |
| Existing architecture inventory | COMPLETED |
| Implementation gap mapping | COMPLETED |
| Baseline conflict identified | COMPLETED |
| Reconciliation addendum | RECORDED IN REPOSITORY |
| Exact-head CI verification | NOT VERIFIED |
| External legal validation | UNRESOLVED |
| A2 engineering reconciliation | CLOSED WITH EXPLICIT EXTERNAL BLOCKERS |
| A3 implementation | NOT STARTED — SEPARATE AUTHORIZATION REQUIRED |

## 8. Gate decision and limitations

This addendum records AGE A1 v1.0 as the controlling engineering baseline and resolves the conflict between the Owner-approved requirements and older repository assumptions for future engineering work.

A2 engineering reconciliation is closed on that basis. This does **not** mean that legal validation, CI verification, feature acceptance, security review or operational readiness has passed. The six LEG blockers remain open and must be resolved before the affected legal rules are finalized or voting methods are activated.

No merge, deployment, migration, production activation or A3 implementation is authorized by this document. A3 requires a separate explicit Owner authorization.

---
**Record:** AGE-A2-BASELINE-RECONCILIATION-v1.0  
**Repository:** `akefadaka-git/arna-api`
