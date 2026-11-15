# AGE A3.10 — Independent append-only receipts and anti-rollback

**Document status:** DESIGN BASELINE — proposed protocol, not deployed  
**Date:** 2026-11-15  
**Parent implementation:** AGE A3.9 signed register attestations, PR #142  
**Design branch:** `docs/age-a3-anti-rollback-design`  
**Scope:** API/service boundary, receipt protocol, cryptographic custody, external retention, replay/rollback detection, recovery and acceptance criteria.

## 1. Decision summary

AGE must not treat an HMAC or receipt stored only in the ARNA application database as independent anti-rollback evidence. The external receipt authority and the independent witness/anchor are separate trust domains. The receipt authority has its own signing key; the witness has a different signing key and independently administered append-only storage. The witness is authoritative for the latest externally anchored head.

The selected design is a **remote append-only receipt authority**:

- ARNA emits a commitment for each accepted voter-register lifecycle transition.
- A separate receipt service authenticates the ARNA workload, assigns the next sequence number, checks the expected previous head, appends the event, and signs a receipt.
- A separate witness verifies each receipt, enforces stream continuity, synchronously stores a checkpoint in WORM storage, and signs the witnessed head using its own key.
- Receipt and witness private keys are held in separately administered KMS/HSM boundaries. ARNA receives only independently provisioned public verification keys.
- Every event is considered anchored only after the witness durably commits it. Periodic archive exports and independently administered backups/checkpoints provide additional recovery evidence.
- Before an election can open voting, ARNA obtains a fresh, nonce-bound signed head response directly from the witness and verifies that its local register state is exactly the latest witnessed head.
- Missing, stale, mismatched, rolled-back, or unverifiable external evidence fails closed.

This is a design decision for A3.10. It does **not** assert that an external service, KMS/HSM, object-lock store, operational team, or production integration currently exists.

## 2. Security objectives and trust boundaries

### 2.1 Objectives

1. Detect replacement of a previously anchored register lifecycle state, including coordinated rollback of the ARNA snapshot, attestation, sequence and receipt rows.
2. Detect replay of an older valid receipt or signed head response.
3. Prevent the application database administrator from rewriting or deleting the independent receipt history.
4. Preserve verifiable history across snapshot supersession, key rotation, service recovery and database restore.
5. Ensure receipt acknowledgements are bound to one tenant/election/register stream and one exact lifecycle event.
6. Avoid exporting voter identities, ballot selections, proxy details or the voter register itself to the receipt service.

### 2.2 Trust domains

| Domain | Holds | Must not control |
|---|---|---|
| ARNA API/application | Register data, current application HMAC keyring, outbox, receipt cache, public receipt verification keys | External receipt private key, WORM retention administration |
| Receipt authority | Stream sequence allocator, append-only event ledger, idempotency registry, receipt signing service | ARNA database credentials, voter/ballot data, witness signing key |
| Independent key custody | Separate Ed25519 receipt and witness signing keys in independently administered KMS/HSM boundaries | Application deployment or database administration |
| Witness/anchor | Receipt verification, monotonic witnessed sequence/hash, signed current-head checkpoints, WORM log | Receipt service database administration and ARNA database administration |
| Independent archive | WORM/retention-locked receipt exports, witness checkpoints and backups | Either signing key or ARNA database |
| Independent verifier/auditor | Public trust roots, verification tooling, read-only receipt exports | Ability to alter either ledger |

At least three separate administrative principals are required: receipt service operator, witness/key custodian, and archive-retention custodian. The ARNA application operator must not be able to shorten retention, disable object lock, delete receipts, or export the private signing key.

## 3. Event model and canonical commitments

### 3.1 Stream identity

One stream represents one election's register lineage. Use a cryptographically random opaque UUID generated at initial preparation and bound into the AGE application HMAC payload. A new register version or legally authorized superseding snapshot remains in the same election stream and is represented by a new event; it does not reset sequence numbers.

A stream is permanently scoped to:
- opaque stream UUID;
- tenant ID;
- election ID;
- initial snapshot UUID.

The external authority rejects attempts to reuse a stream UUID with a different scope. Tenant/election IDs are metadata, not authorization; workload authentication and server-side scope validation remain mandatory.

### 3.2 Events

The minimum event types are:

1. `register.prepared`
2. `register.reconciled`
3. `register.approved_frozen`
4. `register.superseded` (only after a separately authorized correction/supersession workflow exists)
5. `register.voided` (only after a separately authorized void workflow exists)

The service accepts only the first three until supersession and voiding rules have their own approved legal and application contracts. No generic delete, update, overwrite or truncate operation exists.

Each event commits to:
- protocol and schema version;
- stream UUID and snapshot UUID;
- tenant/election scope;
- event type and monotonically assigned sequence;
- prior receipt hash;
- application lifecycle stage;
- prepared/reconciled/approved HMAC key IDs and attestation digests (never the secret keys);
- canonical voter-register content digest and voter count;
- record date, voting-rule version and source-evidence reference digest;
- authenticated actor IDs for the applicable stage;
- application event creation time and unique idempotency key.

Do not send names, addresses, shareholder contact details, proxy credentials, voter-row contents, ballot data or selections to the external service. Evidence references should be represented by a digest or opaque reference, not a document payload.

### 3.3 Canonical encoding and hashes

- Use UTF-8 JSON canonicalized according to RFC 8785 (JCS), with duplicate object keys rejected.
- Hash commitments with SHA-256.
- Domain-separate hashes by protocol, e.g. `arna:age:external-receipt:event:v1\0`.
- Event hash = SHA-256(domain separator || canonical event body excluding service-assigned sequence, previous receipt hash and service signature).
- Receipt hash = SHA-256(domain separator || canonical signed receipt body excluding its own receipt hash and signature).
- The receipt signature is Ed25519 over a domain-separated canonical receipt body including sequence, previous receipt hash, event hash, stream scope, receipt ID, key ID and service timestamp.
- Never trust a client-supplied event hash without recomputing it from the canonical body. Reject duplicate JSON keys, unknown critical fields, invalid UTF-8 and non-canonical encodings.

The external sequence and previous hash are assigned/checked by the service, not trusted from ARNA's database.

## 4. Receipt API protocol

All endpoints require TLS 1.3 or stronger. Workload-to-service calls use mutual TLS with short-lived workload identity credentials and explicit audience/scope. Do not use a long-lived shared bearer token as the sole authentication factor.

### 4.1 Append

`POST /v1/streams/{stream_id}/events`

Required request fields:
- `protocol_version`
- `schema_version`
- `tenant_ref`, `election_ref`, `snapshot_ref`
- `event_type`
- `event_body`
- `event_hash`
- `expected_previous_sequence`
- `expected_previous_receipt_hash`
- `idempotency_key`

Server behavior, in one serializable append transaction:
1. Authenticate workload and authorize the stream scope.
2. Recompute and validate the event hash and schema.
3. Resolve idempotency key. If the same key and same request hash already succeeded, return the original receipt byte-for-byte. If the key is reused with different content, return conflict and raise a security alert.
4. Lock the stream head and compare both expected previous sequence and previous receipt hash with the service's authoritative head.
5. Validate the allowed event transition and actor-stage progression.
6. Allocate sequence = previous sequence + 1 (first event sequence is 1).
7. Build and sign the receipt using the active Ed25519 key in KMS/HSM.
8. Append the event and receipt to the receipt authority's durable log.
9. Submit the signed receipt and expected witness predecessor to the independent witness. The witness verifies the receipt signature and scope, enforces the next sequence and previous hash, writes the receipt/checkpoint to WORM storage, and returns a separately signed witness checkpoint.
10. Return an **anchored** acknowledgement to ARNA only after both the receipt authority and independent witness have durably committed. If witness acknowledgement is unavailable, retain the event as pending and return no anchored receipt.

The server must never accept caller-selected sequence numbers, timestamps, receipt IDs, previous hashes, signatures or signing key IDs.

### 4.2 Read current head directly from the independent witness

`POST /v1/anchors/{stream_id}/head-challenge`

Request includes a cryptographically random 256-bit nonce generated by ARNA and the expected stream scope. The witness response is signed by the witness key (not the receipt-service key) and includes:
- exact nonce echoed;
- stream scope;
- latest witnessed sequence and receipt hash;
- current event type and event hash;
- witness checkpoint hash and witness key ID;
- service timestamp;
- signed checkpoint/response.

ARNA calls the witness directly (not through the receipt authority), verifies the signature against its separately provisioned witness trust bundle, verifies exact nonce equality, checks timestamp/freshness against an approved clock-skew policy, and compares the returned sequence/hash/event with its locally expected state. The nonce is single-use and expires after a short protocol-defined interval. A previously captured signed response cannot answer a new challenge.

A head response signed only by the receipt authority is insufficient: its own database could be rolled back. A plain cached `GET head` is insufficient for a high-risk voting-open decision because it can be replayed. A read-only historical receipt endpoint may exist for auditors, but it is not accepted as proof of currentness.

### 4.3 Historical proof and audit export

`GET /v1/streams/{stream_id}/receipts/{sequence}` returns the signed receipt and inclusion proof.  
`GET /v1/streams/{stream_id}/audit-export` returns a signed, verifiable stream export with all receipts and a signed current-head checkpoint.

The witness's primary append record is itself stored under WORM/retention lock before an event is acknowledged as anchored. The independent archive additionally exports receipt batches plus signed witness checkpoints to a separate retention-locked storage account. Export is verified independently and cannot be rewritten by the receipt service's ordinary application role. Export is verified independently and cannot be rewritten by the receipt service's ordinary application role. Hash-chain verification detects missing, reordered or modified records; independent archive controls prevent a compromised service database from silently replacing history.

## 5. ARNA integration and consistency model

### 5.1 Transactional outbox

Do not make a network call to the external service inside the ARNA database transaction.

For every lifecycle transition:
1. ARNA transaction writes the lifecycle state, HMAC attestation, and a unique outbox event in the same database transaction.
2. Outbox worker submits the exact event with a stable idempotency key.
3. Receipt service appends and returns a signed receipt.
4. ARNA stores the receipt, verification result, service sequence/hash and acknowledgement timestamp, and marks the outbox item delivered.
5. Retries reuse the same idempotency key and exact request body. A changed body requires a new event and explicit transition; never mutate a pending event.

The outbox is delivery state, not the authoritative receipt history. Restoring or deleting the ARNA database must not affect the external ledger.

### 5.2 Pending and anchored states

A locally committed event without a valid receipt-service signature and independent witness checkpoint is `anchor_pending`, not anchored. The application may display and reconcile the pending state, but must not treat it as externally verified.

A register may be used to authorize voting only if:
- application HMAC integrity passes;
- the approval/frozen event has a valid receipt-service signature and independent witness checkpoint;
- a fresh challenge response obtained directly from the witness confirms that exact receipt remains the latest witnessed stream head;
- the stream is not superseded, voided, revoked or in a security hold;
- all existing legal, security and operational gates pass.

External unavailability, timeout, invalid signature, stale nonce, sequence gap, previous-hash mismatch, archive-integrity alarm or stream-state disagreement fails closed for voting-open and any operation requiring a current anchor. Do not fall back to a locally cached head.

### 5.3 Recovery and reconciliation

After database restore or suspected rollback:
1. Stop voting-open and register mutation paths.
2. Authenticate to the receipt service and obtain a fresh signed head challenge.
3. Download the full signed stream or the required checkpoint/inclusion proofs.
4. Verify signatures, key status, sequence continuity, hash chain, scope and event transition order.
5. Compare the externally authoritative head with the restored local snapshot, HMAC attestations, outbox and receipt cache.
6. If local state is behind, rebuild local receipt/outbox projections from the external log; do not recreate or re-sign old lifecycle events.
7. If local state is ahead, has conflicting event content, or the external history has a gap, quarantine the stream and require independent incident review.
8. Re-enable only after a recorded reconciliation decision and fresh head verification.

The independent witness log is authoritative for whether an event was anchored and which event is the latest witnessed state; the canonical shareholding ledger and approved legal evidence remain authoritative for voter entitlements. The receipt log does not replace those sources.

## 6. Cryptographic key custody and lifecycle

### 6.1 Receipt signing key and independent witness key

- Algorithm: Ed25519; receipt and witness private keys are generated and retained in separate KMS/HSM or equivalent non-exportable signing boundaries, with separate administrative principals.
- No private key in ARNA config, source control, CI secrets, application database, logs, backup files or developer workstations. The receipt service cannot export or use the witness key, and the witness cannot use the receipt signing key.
- Signing operations are exposed only through narrowly scoped KMS/HSM identities.
- Key generation, activation, signing policy changes, disablement, rotation and destruction produce immutable KMS audit records.
- Separate principals approve key creation/rotation and administer archive retention. No single ARNA operator can do both.

### 6.2 Public-key distribution and trust

Maintain two separately rooted trust bundles: one for receipt-signing keys and one for witness/checkpoint keys. The witness verifies receipt signatures using the receipt trust bundle; ARNA verifies current-head responses using the witness trust bundle. A compromised receipt service must not be able to issue a valid witness checkpoint.

ARNA pins an independently provisioned trust bundle containing:
- key ID;
- public Ed25519 key;
- validity start/end;
- status (active, verify-only, revoked);
- algorithm and protocol version;
- root-signed bundle version and expiry.

The trust bundle is signed by a separate offline root or organization-controlled trust root, distributed through a controlled deployment/configuration channel, and versioned. A key ID in a receipt is never sufficient to trust that key. Unknown, expired or revoked keys fail verification.

### 6.3 Rotation and compromise

- Planned rotation: publish and distribute the next public key before activation; new receipts use the new key; old public keys remain verify-only for historical receipts.
- Emergency compromise: revoke the affected key in a root-signed trust-bundle update; pause receipt acceptance and voting-open until all affected streams are independently reconciled.
- Never rewrite historical receipts with a replacement signature. If required, append a signed key-compromise/revocation event and a new checkpoint that references the affected range.
- Preserve old public keys and trust-bundle versions for the full retention period.
- Recovery of KMS/HSM availability must not require exporting the old private key.

### 6.4 Custody and operational separation

Minimum roles:
- Receipt Service Operator: deploys service, no KMS key admin or retention admin.
- Key Custodian: manages KMS/HSM policy, no ARNA database admin.
- Archive Custodian: manages object lock/retention, no signing authority.
- Independent Auditor: read-only access to signed exports, key audit evidence and incident records.

Two-person approval is required for key rotation, key revocation, retention-policy changes, disabling append-only controls and disaster-recovery restore. Emergency access is time-limited, recorded, reviewed and cannot delete historical receipts.

## 7. Replay, rollback and abuse controls

| Attack | Required detection/control |
|---|---|
| Replay an old append request | Stable idempotency key; identical retry returns same receipt; changed body under same key conflicts |
| Append event against stale parent | Compare expected sequence and previous receipt hash under stream-head lock |
| Reuse valid receipt for another election | Signature binds stream, tenant, election, snapshot, event and sequence |
| Replay old head response | Fresh 256-bit nonce, exact nonce binding, expiry and signature validation |
| Roll ARNA DB back to prior frozen snapshot | External fresh head sequence/hash is ahead; fail closed and reconcile |
| Delete local receipt/outbox rows | External stream remains authoritative; rebuild local projection from signed export |
| Rewrite external service database | Independent WORM archive/checkpoint and audit reconciliation reveal divergence |
| Compromise ARNA DB credentials | Credentials cannot authenticate as receipt workload or sign receipts; mTLS workload identity is separately issued |
| Compromise ARNA application host | Outside HMAC trust boundary; workload credential revocation, external anomaly detection and incident hold required |
| Compromise receipt signing key | KMS/HSM audit, root-signed revocation, pause, append incident event and re-anchor under a new key |
| Restore old external archive | Retention lock, independent replicas and externally published signed checkpoints; restore procedure verifies latest checkpoint |

### 7.1 Residual limitations

No protocol can guarantee availability if the independent receipt service is unavailable. The correct safety behavior is to block voting-open, not to bypass the anchor.

A receipt hash chain alone does not prevent a privileged administrator from replacing the entire chain with a newly fabricated chain. The independent witness must synchronously persist every acknowledged checkpoint to WORM storage and sign it with a separate key. ARNA must query this witness directly for currentness; periodic archive copies alone are insufficient for live anti-rollback. Additional externally held checkpoints and independently controlled retention are mandatory.

A full compromise of both the receipt authority and its key/archive custodians remains a catastrophic trust-domain compromise. Mitigation requires independent copies/checkpoints and incident procedures outside both systems.

## 8. Retention, availability and recovery objectives

The following are mandatory decisions before production selection; values are deliberately **not invented**:

- Legal retention duration and legal-hold policy for election evidence.
- Receipt service availability target and maintenance window.
- Maximum acceptable head-challenge age and clock-skew allowance.
- RPO for acknowledged receipts (target must be zero for acknowledged append events).
- RTO for receipt service and archive restoration.
- Archive replication geography and data-residency approval.
- Cost and operator ownership for KMS/HSM, WORM storage, monitoring and independent audit.

Safety invariant: an append is not acknowledged as anchored until the receipt authority has committed the signed receipt and the independent witness has synchronously committed the matching checkpoint to WORM storage. The archive replication SLA must be explicitly approved; it cannot be silently equated with primary append durability.

## 9. Monitoring and incident response

Alert and place the affected stream in a security hold for:
- idempotency-key body mismatch;
- expected-head mismatch;
- sequence gap or previous-hash mismatch;
- repeated nonce or expired challenge;
- invalid, unknown or revoked signature key;
- local/external head disagreement;
- archive checkpoint missing or hash mismatch;
- unexpected key policy or retention configuration change;
- unusual append volume, denied workload identity or repeated authorization failures.

Incident response must preserve the external stream, KMS audit log, archive object versions, ARNA outbox, deployment identity logs and relevant database audit records. Never "repair" a mismatch by overwriting the external head or deleting a receipt. Resolution is a new, independently reviewed append-only incident/reconciliation event.

## 10. Acceptance test contract

The complete test matrix is in `AGE-A3.10-ANTI-ROLLBACK-ACCEPTANCE.json`. All tests below are required before production use. Tests against a mock receipt server are useful for protocol logic but do not prove KMS/HSM custody, independent administration, WORM retention or disaster recovery. Those require a separately operated staging service and evidence.

### 10.1 Required acceptance groups

- Canonicalization and hash/signature verification.
- Append ordering, atomicity, idempotency and concurrent writers.
- Scope binding and authorization.
- Nonce freshness and replay rejection.
- Database restore/rollback and local receipt deletion recovery.
- Key rotation, revocation and historical verification.
- WORM archive tamper/deletion/retention controls.
- Receipt service outage, fail-closed behavior and disaster recovery.
- Independent operator and audit evidence.

### 10.2 Exit criteria

A3.10 implementation may be proposed for A4 only when:
1. All protocol/unit/integration acceptance tests pass on an exact commit SHA.
2. A real independent staging receipt service passes end-to-end tests; a mock does not count.
3. KMS/HSM non-exportability, key policy, audit logging and dual-control evidence are inspected.
4. WORM retention, independent archive account, restore test and external checkpoint verification pass.
5. Database rollback simulation demonstrates detection and fail-closed voting-open.
6. Independent security review accepts the threat model and residual risks.
7. Legal retention, availability, RPO/RTO, residency and operational ownership are approved.

## 11. Current implementation status

| Item | Status |
|---|---|
| A3.9 application HMAC attestation | Implemented in PR #142; scoped CI passed at implementation SHA documented there |
| External receipt protocol design | Defined in this document |
| Machine-readable acceptance matrix | Added alongside this document |
| Receipt service implementation | NOT IMPLEMENTED |
| KMS/HSM key custody | NOT PROVISIONED / NOT VERIFIED |
| Independent WORM archive | NOT PROVISIONED / NOT VERIFIED |
| ARNA transactional outbox and receipt verifier | NOT IMPLEMENTED |
| Live nonce-bound head verification | NOT IMPLEMENTED |
| End-to-end anti-rollback test | NOT RUN |
| Independent security review | NOT RUN |
| Production authorization | NOT GRANTED |

**No production receipt key, external service, storage account, secret, migration, or data change is created by this design.** Electronic/hybrid voting remains disabled. Existing LEG-01 through LEG-06 legal blockers remain unresolved.
