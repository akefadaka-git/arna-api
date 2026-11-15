# AGE A3.10 — Receipt integration implementation specification

**Status:** IMPLEMENTATION SPECIFICATION — not deployed  
**Parent design:** PR #143, independent append-only receipts and anti-rollback  
**Parent attestation implementation:** PR #142, application-held register HMAC attestations  
**Scope:** ARNA API adapter boundary, transactional outbox, receipt/witness verification, retry and recovery semantics  
**Non-goals:** provisioning an external receipt authority or witness, selecting a vendor, generating production keys, changing production data, activating electronic/hybrid voting, or deploying infrastructure.

## 1. Implementation boundary

Implement the ARNA-side integration as a provider-neutral adapter. The application must not contain receipt-authority or witness private keys, must not write directly to either external ledger, and must not treat an unanchored receipt as proof of register finality.

The adapter has four independently testable responsibilities:

1. Build a privacy-minimized lifecycle commitment from the already-attested register state.
2. Persist the commitment and idempotency identity in a transactional outbox in the same ARNA database transaction as the lifecycle transition.
3. Deliver the immutable event to the receipt authority and persist the receipt plus independent witness checkpoint only after both are cryptographically verified.
4. Obtain a fresh, nonce-bound witness head challenge before any high-risk gate (especially voting-open) and compare it with the exact locally expected stream head.

The external provider is an unresolved deployment decision. Production mode must fail closed when endpoint identities, workload identity, trust bundles, freshness policy, retention evidence, or independent custody attestations are absent.

## 2. Required ARNA components

### 2.1 Domain service

Define a narrow `AgeExternalReceiptGateway` interface with operations:

- `append(AppendReceiptCommand): AnchoredReceiptResult`
- `challengeHead(HeadChallengeCommand): VerifiedWitnessHead`
- `verifyHistoricalReceipt(HistoricalReceiptProof): VerificationResult`

The domain service owns event transition validation and expected-head comparison. The HTTP client must not decide whether a register is reconciled, approved, frozen, or eligible to open voting.

### 2.2 Transport adapters

Separate interfaces/adapters are required for:
- receipt-authority append and historical proof retrieval;
- direct witness head challenge;
- workload identity / mutual-TLS credential acquisition;
- receipt and witness public-key trust-bundle resolution;
- clock and nonce generation.

The witness challenge MUST NOT be routed through the receipt authority. Tests must be able to inject deterministic clocks, nonce sources, HTTP transports, and public-key resolvers.

### 2.3 Transactional outbox

The lifecycle transaction writes:
- the lifecycle transition and its existing HMAC attestation;
- one immutable outbox event with a unique event UUID and idempotency key;
- the stream UUID, snapshot UUID, event type, canonical event body, event hash, expected previous sequence/hash, and creation timestamp.

The outbox row is not itself an external receipt and cannot satisfy an anchored-state check. The payload is immutable after insertion. Mutable delivery metadata is kept separately or in explicitly mutable status fields protected from payload rewrite.

Outbox states:
- `pending`: committed locally, not yet submitted;
- `submitted`: request sent; result not yet established;
- `receipt_received`: receipt authority response received but not fully anchored/verified;
- `anchored`: receipt and witness checkpoint independently verified and durably acknowledged;
- `retryable_failure`: transient transport/service failure;
- `quarantined`: integrity, scope, replay, key, predecessor, or protocol violation;
- `permanent_failure`: non-retryable contract rejection requiring operator resolution.

Only `anchored` is terminal success. A worker crash at any point must be recoverable by reloading the same immutable event and reusing the same idempotency key and request bytes.

## 3. Canonical event construction

Use RFC 8785 JSON Canonicalization Scheme (JCS), UTF-8, duplicate-key rejection and SHA-256 with the domain separator specified by PR #143. Never hash ordinary language-runtime serialization output unless it is proven byte-for-byte JCS.

Minimum committed fields:
- protocol and schema versions;
- opaque stream UUID and snapshot UUID;
- tenant/election/snapshot references;
- event type;
- lifecycle state and event time;
- record date, voting-rule version, source-evidence digest;
- canonical register content digest and voter count;
- applicable prepared/reconciled/approved HMAC key IDs and attestation digests;
- applicable authenticated actor IDs;
- event UUID and idempotency key.

Do not export voter rows, names, addresses, contact details, shareholder identifiers, proxy credentials, ballot data, selections, or source documents. Actor IDs are limited to the minimum audit metadata and must be opaque references. A privacy review must approve every field before production integration.

The application computes the event hash from the canonical body. The external authority independently recomputes it. The client-supplied hash is an assertion to compare, never an authoritative input.

## 4. Append protocol and idempotency

For each event, ARNA sends:
- stream and snapshot scope;
- canonical event body and event hash;
- expected previous sequence and receipt hash;
- stable idempotency key;
- protocol/schema version.

The receipt authority allocates sequence numbers and receipt IDs. ARNA MUST NOT select them. The service checks both predecessor values atomically against its authoritative stream head.

Idempotency behavior:
- same key + same canonical request hash: return the original receipt/checkpoint result;
- same key + different request hash: conflict, quarantine the outbox item and emit a security alert;
- new key + stale predecessor: conflict; do not silently rebase or regenerate the event;
- unknown outcome after timeout: retry the exact same request with the same key; never mint a new key for the same lifecycle event.

No anchored success is recorded until ARNA verifies:
1. receipt signature against the configured receipt trust bundle;
2. receipt stream/snapshot scope, event hash, sequence and predecessor;
3. witness checkpoint signature against a separately configured witness trust bundle;
4. witness checkpoint stream, sequence, receipt hash and predecessor;
5. equality of receipt and witness commitments.

A receipt-authority acknowledgement without a valid independent witness checkpoint remains unanchored.

## 5. Direct witness head challenge

Before voting-open, and after any database restore or detected sequence gap:
1. Generate a cryptographically secure 256-bit nonce.
2. Submit it directly to the configured witness endpoint with the expected stream scope.
3. Verify the witness signature using the witness-only trust bundle.
4. Require exact nonce equality and single-use nonce tracking.
5. Enforce the approved freshness and clock-skew policy.
6. Compare witnessed sequence, receipt hash, event hash, event type and scope with the latest locally anchored event.
7. Verify the local frozen-register content digest and HMAC attestations independently.

Any missing, stale, replayed, malformed, wrong-scope, unverifiable, or mismatching response denies voting-open. Do not fall back to cached receipt-authority state, a receipt-service head, or a stale witness response.

Freshness window, maximum clock skew and nonce expiry remain configuration decisions and MUST NOT be hard-coded as invented policy defaults.

## 6. Failure, retry and quarantine rules

| Condition | Outbox behavior | Register/voting behavior |
|---|---|---|
| Network timeout / 5xx | retry same immutable request and idempotency key with bounded exponential backoff and jitter | no anchored status; voting remains blocked |
| Witness unavailable | preserve pending receipt state; retry witness stage without creating a new event | voting remains blocked |
| 4xx schema/scope rejection | permanent failure or quarantine according to error code | block lifecycle progression |
| Idempotency key reused with changed body | quarantine + security alert | block |
| Stale predecessor / sequence conflict | quarantine; require controlled reconciliation against witness | block; never auto-rebase |
| Invalid signature / unknown key / revoked key | quarantine + security alert | block |
| Nonce mismatch / stale challenge / replay | quarantine challenge result + security alert | deny voting-open |
| Local database restore behind witness | enter recovery hold; preserve restored copy for investigation | deny voting-open until independently reconciled |
| External head ahead of local state | recovery hold; retrieve and verify missing history | deny voting-open |
| External head behind local anchored state | critical integrity incident; do not overwrite either side | deny voting-open |

Retries must be bounded and observable. Exhaustion changes delivery state to operator-visible retryable failure; it does not discard the event. Quarantined events require an audited, separately authorized resolution procedure. No generic “reset head”, “skip sequence”, “force anchor”, or “delete receipt” operation is permitted.

## 7. Key and trust-bundle handling

ARNA stores only public verification material for receipt and witness keys. Trust bundles are separate, versioned, integrity-protected configuration inputs with:
- key ID, algorithm, public key, validity interval and status;
- issuer/custodian identity and signed provenance;
- activation and retirement metadata;
- revocation status and effective time.

Unknown, expired, revoked or out-of-policy keys fail closed. Key rotation permits overlap only where both old and new public keys are independently trusted and the receipt/witness protocol identifies the signing key unambiguously. Historical verification must retain the public keys needed to verify retained receipts. Private-key generation, export, backup, recovery and revocation are controlled by the independent custodians, not this API.

## 8. Persistence and migration constraints

A future implementation migration may add:
- election-to-stream binding;
- outbox event table with immutable payload and unique idempotency key;
- anchored receipt metadata;
- witness checkpoint metadata;
- delivery attempts / operational status;
- nonce replay records with bounded retention.

Before a migration is authored, inspect existing AGE schema and migrations to avoid duplicate stream, outbox, receipt or audit tables. Every new table must have tenant/election foreign-scope constraints, unique stream-sequence and idempotency constraints where applicable, and indexes for pending delivery and recovery scans.

The migration must be reversible without fabricating external acknowledgements or deleting externally anchored evidence. Rollback must refuse to drop locally recorded anchored history unless an explicit, separately approved retention/export procedure proves preservation.

## 9. Authorization and separation of duties

- Lifecycle actors remain distinct under PR #142's prepared/reconciled/approved HMAC contract.
- Outbox workers use a dedicated service identity, not an interactive election-manager permission.
- Operators may inspect delivery health but cannot alter event payload, signatures, sequence, predecessor or anchored state.
- Quarantine resolution requires a separate permission and a reasoned, immutable audit record; no role grants are made by this specification.
- Tenant/election scope is derived and checked server-side on every command and response.

## 10. Observability and operational evidence

Emit structured, privacy-minimized events for append attempt, retry, anchor success, challenge success/failure, quarantine, key change and recovery hold. Include correlation ID, opaque stream ID, event UUID, idempotency key digest, attempt number, error class and timestamps. Never log private keys, credentials, nonce secrets beyond the required challenge audit reference, voter rows, ballot data or full sensitive payloads.

Operational dashboards must distinguish:
- pending age and retry count;
- receipt-authority availability;
- witness availability and lag;
- oldest unanchored event;
- signature/key verification failures;
- replay/predecessor conflicts;
- challenge freshness failures;
- recovery holds and unresolved quarantines.

Alert on any unanchored frozen register, any attempted voting-open without a fresh successful witness challenge, any key revocation affecting active verification, and any external/local head mismatch.

## 11. Required conformance test suites

1. Canonicalization: deterministic JCS bytes, duplicate keys, invalid UTF-8, unknown critical fields, numeric/string ambiguity.
2. Outbox atomicity: lifecycle rollback leaves no outbox event; successful lifecycle commit creates exactly one immutable event.
3. Delivery recovery: crash before send, after send/before response, after receipt response, after witness commit/before local acknowledgement.
4. Idempotency: exact retry returns byte-identical original receipt; changed body under same key conflicts.
5. Sequence integrity: concurrent append, stale predecessor, duplicate sequence, wrong previous hash, out-of-order event.
6. Cryptographic verification: wrong key, unknown key, revoked key, altered receipt, altered checkpoint, scope substitution.
7. Witness independence: receipt authority cannot satisfy head challenge; challenge is sent directly to witness.
8. Replay/freshness: nonce mismatch, repeated nonce, expired nonce, stale signed response, clock skew outside approved bound.
9. Rollback/recovery: restore local DB to older valid state, witness ahead, local ahead, missing receipt, archive gap.
10. Privacy: no voter-row, identity/contact, proxy credential or ballot fields in outbound commitment or logs.
11. Authorization: cross-tenant stream access, worker identity, operator cannot mutate payload, quarantine permission separation.
12. Lifecycle gate: voting-open denied unless register HMAC, frozen state, local receipt chain and fresh independent witness head all agree.

Tests must run against the real database engine used in deployment for locking, uniqueness, transaction boundaries and concurrent append behavior. SQLite tests are supplemental and do not prove MySQL concurrency semantics. Cryptographic tests must use generated ephemeral test keys only; never production keys.

## 12. Stage gates and acceptance

### A3.10 implementation-ready gate
- [ ] Existing schema and migration inventory confirms no duplicate domain tables.
- [ ] Receipt and witness wire contracts are versioned and validated against PR #143 schemas.
- [ ] Error taxonomy, retry state machine and quarantine semantics are implemented and tested.
- [ ] Provider-neutral interfaces and deterministic test doubles exist.
- [ ] No production provider, key or endpoint is assumed.
- [ ] Legal retention, residency, RPO/RTO, SLA, custodians and cost are recorded as explicit deployment decisions.

### A3.10 integration gate
- [ ] Transactional outbox is atomic with each lifecycle transition.
- [ ] Real receipt authority and independently administered witness are deployed in isolated test environments.
- [ ] Receipt and witness keys are independently provisioned and public trust bundles verified.
- [ ] All required conformance tests pass on the deployment database engine.
- [ ] Independent security review validates trust separation, rollback detection, replay protection and recovery.
- [ ] External archive/WORM retention and restore drills are evidenced.

### A4 acceptance gate
- [ ] Exact-head CI and database integration results are attached to the implementation PR.
- [ ] Outage, replay, key rotation/revocation and database-restore drills pass.
- [ ] Voting-open denial tests prove fail-closed behavior.
- [ ] Independent reviewer signs the evidence record.
- [ ] LEG-01 through LEG-06 are resolved by authoritative company documents/legal decisions before any voting method is enabled.

## 13. Explicit unresolved decisions

The following remain open and cannot be inferred by implementation:
- external receipt/witness provider and hosting jurisdiction;
- workload identity provider and mTLS certificate lifecycle;
- KMS/HSM products, key custodians and independent administrators;
- WORM/archive product, retention duration and legal hold authority;
- nonce expiry, freshness window and clock-skew tolerance;
- service SLA, RPO/RTO, replication and disaster-recovery objectives;
- data residency and privacy/legal approval;
- operating cost and service ownership;
- production role grants and operational runbooks.

**Current disposition:** specification only. No external service exists by implication; no production integration, secret, migration, deployment, merge, or voting activation is authorized by this document.
