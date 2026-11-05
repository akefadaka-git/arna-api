# ARNA Security — Permissioned Ledger and Cryptographic Evidence Baseline

**Document ID:** ARNA-SEC-BLOCKCHAIN-001  
**Version:** 0.1.0  
**Status:** Implementation baseline — not a production approval  
**Scope:** ARNA platform security evidence, governance integrity and future permissioned-ledger interoperability  
**Language policy:** Amharic-first; English is the technical companion  
**Data policy:** Personal, shareholder, financial and confidential records remain off-chain

## 1. Purpose

Introduce independently verifiable integrity evidence for critical ARNA events. The system must make unauthorized alteration, deletion, reordering and repudiation of important records detectable, while preserving confidentiality, tenant isolation, availability and lawful correction.

Blockchain is not a substitute for identity, authorization, encryption, backups, secure key custody or accurate source records. It proves that a committed event has not changed relative to the trusted commitment; it does not prove that the event was true when entered.

## 2. Architecture decision

Use a **hybrid architecture**:

1. ARNA domain services remain the authoritative systems of record.
2. Sensitive source records remain in tenant-scoped, encrypted relational storage.
3. A cryptographic evidence service canonicalizes approved event metadata, hashes records, signs evidence and builds Merkle batches.
4. A permissioned ledger adapter anchors batch roots and returns independently verifiable transaction receipts.
5. Independent verifiers validate signatures, event chains, Merkle proofs and ledger receipts.

A permissioned network based on Hyperledger Fabric is the preferred proof-of-concept candidate. It is not yet selected for production and no Fabric network, peer, ordering service, certificate authority, channel or chaincode is deployed by this change.

## 3. Trust boundaries and network membership

A distributed ledger provides meaningful independent assurance only when separate organizations or independently administered parties control validation infrastructure.

Candidate membership, subject to written authorization:

- ARNA operating organization;
- independent external auditor;
- independently governed oversight or assurance participant.

Do not count multiple nodes controlled by one administrator as independent trust domains. If no second independent participant is authorized, use signed append-only evidence with periodic independently held Merkle-root anchoring rather than claiming consortium decentralization.

Membership, endorsement policy, node ownership, geographic hosting, legal jurisdiction, incident responsibility and exit procedures require separate approval.

## 4. On-chain and off-chain data boundary

### Permitted ledger payload

- evidence batch identifier;
- Merkle root;
- algorithm and canonicalization version;
- event count and time interval;
- tenant-scoped opaque reference or commitment;
- signer identity/key identifier, not the private key;
- anchor transaction identifier and block reference;
- protocol version.

### Prohibited ledger payload

- shareholder names, addresses or identity numbers;
- KYC, passport, national ID or contact details;
- raw proxy instruments or meeting minutes;
- private voting choices or identifiable ballot selections;
- account numbers, balances, credentials, access tokens or secrets;
- confidential document contents;
- raw personal data or reversible personal-data hashes.

A hash is not automatically anonymous. Use keyed commitments or random opaque identifiers where predictable values could be guessed. Never publish unsalted hashes of low-entropy personal data.

## 5. Critical ARNA event classes

Initial evidence candidates:

| Domain | Evidence event |
|---|---|
| Governance | Electorate snapshot approved, proxy submitted/reviewed/revoked, quorum evaluation, resolution approved |
| Shareholder | Shareholding ledger batch commitment and register snapshot commitment |
| Finance | Selected journal-posting and settlement commitments |
| Documents | Document registration, version replacement and approval commitments |
| Identity and authority | Signing-key issuance, authority grant/revocation and approval evidence |
| Security | Privileged access changes, key rotation and evidence-verification failures |

Do not place all routine reads or every application mutation on a blockchain. Batch only events with a defined integrity, audit or non-repudiation requirement.

## 6. Cryptographic evidence protocol

- Canonical serialization: versioned, deterministic UTF-8 JSON canonicalization; reject ambiguous encodings.
- Hash: SHA-256 initially; algorithm identifier stored with every evidence record.
- Signature: approved asymmetric digital signature algorithm with key identifiers and certificate chain.
- Event chain: each record commits to the previous record hash within its aggregate stream.
- Batch commitment: deterministic Merkle tree over sorted event hashes; odd-leaf handling is versioned and testable.
- Timestamp: record both domain occurrence time and server receipt time; never treat either as proof of legal service.
- Corrections: append a compensating event; never silently rewrite an anchored event.
- Verification: recompute canonical payload hashes, signatures, event-chain links, Merkle proofs and external ledger receipts.

Hash-chain integrity alone is not independent immutability. A privileged database operator could rewrite an entire unanchored chain. Periodic external anchoring and independent copies are required for stronger assurance.

## 7. Key management

- Private signing keys must not be stored in application tables, source code, repository secrets or ordinary configuration files.
- Production signing keys require HSM-backed or equivalent managed key custody.
- Separate keys by environment, tenant or signing authority as risk assessment requires.
- Require documented issuance, activation, rotation, revocation, backup/recovery and compromise procedures.
- Enforce least-privilege signing: application runtime keys cannot impersonate Owner, Board, auditor or GA signatories.
- Require explicit signer identity and authority validation before accepting a signature.
- Lost or compromised keys must be revocable without rewriting historical evidence.

## 8. Authorization and approval controls

- Evidence creation is not evidence approval.
- A user cannot approve their own submission.
- Governance approval and independent legal verification are separate workflow states.
- Owner authority remains governed by ARNA's signed approval model.
- Tenant scope is checked before reading or committing any event.
- The ledger adapter cannot grant application permissions or execute business actions.
- No blockchain transaction can bypass domain validation, legal approval or the application authorization layer.

## 9. Availability, privacy and recovery

- Application transactions must not wait synchronously for external ledger consensus.
- Use a transactional outbox for pending evidence batches; retry idempotently.
- If the ledger is unavailable, retain signed pending evidence locally and expose an explicit ANCHOR_PENDING state.
- Never convert an unanchored event into an anchored or verified event.
- Back up source records, signing metadata, certificates, evidence batches and recovery instructions.
- Test node loss, network partition, delayed anchoring, duplicate submissions, key compromise and disaster recovery.
- Retention, erasure, lawful disclosure and dispute holds must be designed before any immutable external commitment is made.

## 10. GA-specific safeguards

- The electorate snapshot is built from the shareholder register and reconciled shareholding ledger at a defined cut-off.
- Voting weights and quorum thresholds require an applicable, approved rule; no default is inferred.
- A BLOCKED quorum evaluation cannot be upgraded by a ledger transaction.
- Store only commitments to electorate and voting evidence on the ledger, never the private ballot selections or identity-bearing voter records.
- Publicly verifiable result commitments must not enable reconstruction of individual choices.
- A ledger receipt does not itself make a meeting, proxy, vote or resolution legally valid.

## 11. Delivery gates

| Gate | Deliverable | Exit evidence |
|---|---|---|
| B1 | Architecture and trust-boundary baseline | Owner-approved architecture |
| B2 | Threat model, privacy impact and legal/jurisdiction review | Reviewed risks and accepted mitigations |
| B3 | Cryptographic evidence service, canonicalization, signatures, Merkle batching and outbox | Exact-SHA tests and independent verification |
| B4 | Permissioned-ledger proof of concept | Two independently controlled test organizations, endorsement and recovery evidence |
| B5 | GA integration | Snapshot, proxy and quorum commitments verified end-to-end |
| B6 | Finance, Documents and authority integrations | Domain-owner acceptance |
| B7 | Independent security assessment and production decision | Signed assessment and separate Owner deployment approval |

## 12. Explicit non-goals

This baseline does not authorize cryptocurrency, tokens, speculative assets, public-chain publication, transfer of shareholder ownership through blockchain, automated legal decisions, production network deployment, external data disclosure or replacement of ARNA's relational systems of record.

## 13. Current implementation evidence

The GA feature branch is introducing a per-proxy SHA-256 event chain and a verification endpoint. This is a local tamper-evidence foundation only. It is **not** a blockchain, external anchor, digital signature or independent validation network. Those capabilities require B2–B4 implementation and evidence.

## 14. Open decisions

- Independent ledger participants and their legal authority.
- Hosting and data-residency jurisdiction.
- Fabric versus an alternative permissioned ledger after proof-of-concept comparison.
- HSM/key custody provider and signing algorithm policy.
- Endorsement threshold and consortium governance.
- Evidence event retention, batching interval and anchor service-level objective.
- Privacy and lawful correction/erasure treatment for commitments.
