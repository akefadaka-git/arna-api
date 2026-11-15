# AGE A3 — Anonymous Ballot Security Contract v1.0

Status: **Engineering design baseline — not an authorization to enable electronic voting.** This document is based on the Owner-frozen AGE A1 scope and the A2 repository reconciliation. It is a proposed A3 security contract and requires independent cryptographic/security review before implementation of the credential protocol or any activation.

## 1. Purpose and governing decisions

AGE supports physical, electronic, proxy and hybrid voting under the Owner-approved `share_company` framework. Ballots are secret. Shareholder voting entitlement is share-weighted, not one-person/one-vote. Candidate eligibility may include qualified non-shareholders; that does not make non-shareholders voters.

Electronic and hybrid voting remain disabled until all applicable legal, cryptographic, operational, accessibility and Owner acceptance gates pass. This contract must not be interpreted as legal validation or an enablement decision.

## 2. Existing repository observations

The current A3 foundation contains:

- `governance_election_voting_credentials`, which stores `voter_entitlement_id`, `credential_hash`, `channel` and `used_at`. The entitlement foreign key and credential-use state are in the same row. This is **not acceptable as the final anonymous credential-spend ledger**, because it retains a voter-to-credential-use relationship.
- `governance_election_secret_ballots`, which stores a public UUID, channel, encrypted selections, encryption-key version, optional capture reference and cast time. It has no direct voter ID, but timestamps, capture references, request logs and shared transaction timing can still create correlation risk.
- `governance_election_ballots`, which is an administrative physical-ballot issuance/custody table and includes `voter_id` and `ballot_reference`. It must never be used as the secret vote/selection table.
- `GovernanceElectionPhysicalBallotService`, which issues and receives physical ballot custody records. Its current issuance path checks tenant/election voter-row membership but does not itself prove the associated register snapshot is frozen and integrity-valid. The poll-opening gate must not be the only control protecting this service.

These are implementation observations, not a declaration that the current schema or service is secure enough for a live election.

## 3. Non-negotiable security invariants

1. **Frozen entitlement source:** credential issuance and ballot acceptance require the exact tenant/election register snapshot to be independently approved, frozen, and integrity-valid. Reconstruct voter entitlements server-side from the canonical shareholding ledger and approved voting rules. Never accept voter rows, share quantities, weights, share classes, proxy status or eligibility claims from the client.
2. **One entitlement, one credential issuance:** each frozen voter entitlement may receive at most one credential for the election, after verified proxy/channel authority. A retry must be idempotent without issuing a second credential.
3. **No identity-to-ballot join:** no voter, shareholder, person, user, representative, proxy principal, voter-row ID or credential-issuance ID may be stored on the secret ballot record, encrypted selection payload, ballot public ID, or final tally line.
4. **Blind issuance / anonymous spend separation:** the eligibility service may know which entitlement is being issued a credential, but must not learn the final unblinded credential. The ballot-box service may validate and consume the credential, but must not receive authenticated voter identity or entitlement ID.
5. **No client-supplied voting weight:** share-weighted entitlement must be cryptographically bound to the issued credential through an independently reviewed anonymous-credential / proof-of-eligibility protocol. A client-provided weight is never authoritative. If the proof cannot be validated with a vetted implementation, electronic voting fails closed.
6. **Single-use credential:** a credential can authorize at most one accepted ballot. Credential consumption and acceptance must be atomic; a failed transaction must not burn a credential or leave a ballot without a spent-credential record.
7. **Encrypted ballot payload:** selections are encrypted on the client or trusted capture terminal to the election-specific public key before leaving the controlled ballot-capture boundary. Application servers must not hold the election tally private key.
8. **No plaintext selection logging:** request logs, audit events, exception messages, analytics, traces, backups and diagnostics must never include plaintext selections, credential secrets, blind-signature tokens or private key material.
9. **Election-scoped cryptographic domain:** credentials and ciphertexts bind tenant, election, frozen-register digest, voting-rule version, ballot schema version, allowed channel, expiry and key version. Cross-election replay is rejected.
10. **Independent tally and certification:** tally preparation, verification, certification and ratification remain separate duties. No AI, smart contract or autonomous service may certify or ratify an election.

## 4. Proposed credential protocol boundary

### 4.1 Eligibility issuance service

- Authenticate the shareholder or authorized proxy only at the eligibility/issuance boundary.
- Lock and verify the election, approved frozen snapshot, relevant voter entitlement, share class, weight and proxy authorization.
- Permit one credential issuance per entitlement and election.
- Use an RFC 9474-conformant blind-RSA signature implementation (RSABSSA) or another independently approved anonymous-credential protocol. Do not write a bespoke blind-signature implementation.
- The client generates a high-entropy credential message and blinds it. The issuer signs the blinded message without learning the unblinded credential.
- The signed message must be domain-separated and bind election ID, tenant ID, frozen snapshot digest, voting-rule version, channel, expiry, key version and the approved entitlement attributes/proof.
- The entitlement-to-issuance audit record may store the voter entitlement ID, a digest of the blinded request, issuer, time and issuance status. It must not store the unblinded credential, its final token digest, or a ballot ID.
- A vetted proof mechanism must establish that the blinded credential carries the correct share-weighted entitlement without disclosing the final credential. This is a mandatory unresolved cryptographic design item, not something to approximate with client-supplied values.

### 4.2 Anonymous ballot-box service

- Use a separate stateless/anonymous endpoint that does not require the shareholder's Sanctum session or receive voter identity headers.
- Validate the blind signature and election-scoped claims using the published election verification key.
- Validate the proof of entitlement attributes and allowed channel.
- Compute a one-way digest of the final credential using a server-side keyed digest or approved construction; store only the spent digest, election ID, channel and consumed time in a dedicated spent-credential ledger.
- Do not include voter entitlement ID, issuance ID, shareholder ID, ballot ID, capture reference, client IP or authenticated user ID in the spent-credential ledger.
- Insert the opaque encrypted ballot into the ballot box in the same atomic acceptance operation. Do not persist a join key between spent credential and ballot.
- Return a generic success/already-used response. Do not disclose whether a particular voter entitlement exists.
- Prevent raw credentials and identifying transport metadata from entering application, proxy, CDN, WAF, analytics or error logs. Any unavoidable infrastructure metadata requires a documented privacy review and strict retention controls.

### 4.3 Correlation and mixing

Absence of a voter ID column alone does not prove ballot secrecy. Correlation can arise from timestamps, sequential IDs, capture references, request IDs, transaction logs, queue ordering, IP addresses, device fingerprints and audit metadata.

Before electronic activation, the independent reviewer must assess whether the deployment requires a separate ballot-box service, network privacy gateway, batch collection, randomized release/shuffle, and separate database credentials/administrators. The design must document the residual threat model for small elections and low-volume batches; do not claim cryptographic unlinkability if timing or infrastructure metadata can link issuance and casting.

## 5. Ballot encryption and key custody

- Use a vetted, maintained cryptographic implementation and an election-specific encryption suite approved by independent review. Do not invent algorithms or roll custom envelope encryption.
- Public keys may be published with a signed key manifest containing election ID, key version, algorithm suite, public-key fingerprint, activation time and expiry.
- The election private decryption key must be held outside the application runtime under an independently witnessed key ceremony and multi-person custody. No single application administrator or service account may decrypt ballots.
- The reviewed design must specify threshold decryption or equivalent multi-party control, key backup/recovery, compromise response, rotation, revocation and post-election retention/destruction.
- Key rotation after voting opens must not silently change the active ballot key. A key-version transition requires a documented ceremony and must preserve decryptability of already-cast ballots.
- Ballot ciphertext must be integrity protected and bind the election and ballot schema as associated context. Malformed, cross-election or unsupported ciphertext is rejected without exposing plaintext.

## 6. Physical, proxy and hybrid channel separation

- The physical issuance/custody register may identify who received a paper ballot for operational accountability. That record is not the ballot and must never contain a vote choice.
- A serial or control number may appear on a detachable issuance stub only. It must not appear on the ballot paper that enters the sealed box, nor on a count-sheet line that can reconstruct voter identity.
- Physical count batches record polling station, sealed-box reference, count-sheet reference, received/valid/rejected counts, witnesses and evidence. They must not record voter names or voter IDs beside choices.
- Proxy authority is checked against the frozen entitlement and applicable governing instrument before issuance. The proxy principal and authorized representative must not be encoded in the secret ballot.
- Hybrid coordination prevents one entitlement from voting in multiple channels. Cross-channel duplicate prevention must not create a persistent join between a voter identity and a secret ballot.
- Physical/electronic reconciliation may compare aggregate eligible, issued, received, spent-credential, accepted-ballot and rejected counts. Any discrepancy is quarantined and independently resolved; no automatic deletion or silent adjustment is permitted.

## 7. Required schema direction (not yet implemented)

The current `governance_election_voting_credentials` table must not be treated as the final anonymous credential design.

Proposed logical separation:

- **Credential issuance audit:** election ID, frozen snapshot digest, voter entitlement ID, blinded-request digest, channel authorization, issued-at, issuer and issuance status. No final credential digest and no ballot reference.
- **Spent anonymous credentials:** election ID, one-way credential digest, channel, consumed-at and minimal non-identifying metadata. No voter/entitlement/issuance ID and no ballot ID.
- **Secret ballot box:** ballot public UUID, election ID, channel, opaque encrypted selections, encryption key version, ballot schema version and cast/accepted time. No credential digest, voter ID, issuance ID or identity-bearing capture reference.
- **Physical custody ledger:** voter/entitlement-to-paper-issuance custody information only; no selections and no serial shared with the ballot placed in the box.
- **Tally evidence:** aggregate counts and independently reproducible evidence. Individual plaintext vote records must not be persisted after the controlled tally process.

Any migration must be additive and reversible only before live use. Once credentials or ballots exist, rollback must fail closed and must never fabricate, reassign or erase election evidence.

## 8. Required verification before security acceptance

- Property and integration tests for one issuance per frozen entitlement, idempotent retries, credential replay, cross-tenant/election replay, expiry, channel restriction, invalid signature, invalid entitlement proof and invalid weight.
- Concurrency tests proving two simultaneous credential uses cannot both be accepted and that a failed ballot insert rolls back credential consumption.
- Database tests proving no schema-level or ORM-level path can attach voter identity or credential digest to secret ballots.
- Log/trace/analytics tests proving secrets, identities and plaintext selections are redacted.
- Independent cryptographic protocol review, implementation review, threat model and penetration test.
- Independent key-ceremony rehearsal, key recovery/rotation test, tally reproducibility and discrepancy/appeal exercise.
- Accessibility, device security, low-connectivity/offline and Amharic-first/English acceptance.
- Legal confirmation for electronic/proxy/hybrid authority, share-class voting rights, proxy rules, retention and appeal authority.

## 9. Explicit fail-closed gates

Until every applicable gate passes:

- `electronic_voting_authorized = false`;
- electronic and hybrid credential issuance/casting routes are not exposed or return a fail-closed unavailable response;
- no production key is generated or provisioned;
- no live voter credential or ballot is created;
- no role grants, production migration, merge or deployment are implied by this design.

Legal blockers LEG-01 through LEG-06 remain open: current signed governing instruments, share-class voting rights/caps, proxy rules, candidate qualification/disqualification, appeal authority/procedure, and Legal Advisor / committee authority.

## 10. Immediate implementation sequence

1. Add a service-level frozen-register integrity gate to physical ballot issue/receive operations.
2. Add explicit separation tests proving physical custody records cannot be used as secret ballot records.
3. Implement only non-cryptographic interface contracts and disabled-by-default route guards until the blind-credential and share-weight proof design is independently approved.
4. Select and pin a vetted RFC 9474 / anonymous-credential implementation; do not custom implement.
5. Add the separate issuance, spent-credential and ballot-box schema only after the selected protocol and data-retention model are reviewed.
6. Run exact-head MySQL/SQLite, concurrency, authorization and privacy regression suites.
7. Obtain independent security review and Owner acceptance before any activation.

**Disposition:** design baseline only. Electronic/hybrid voting remains disabled. No merge, deployment, production migration, production key provisioning, role grant, or live voting activation is authorized.
