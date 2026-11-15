# AGE A3.9 — application-held voter-register lifecycle attestations

Status: **IMPLEMENTED — EXACT IMPLEMENTATION-HEAD CI PASS** (2026-11-15). This is a database-only tamper-detection control, not independent security certification or production authorization.

## Threat addressed

The register digest and lifecycle timestamps were previously checked by the application service, while database triggers enforced lifecycle shape and immutability. A database writer able to bypass or disable those triggers could replace draft voter rows and the stored digest together, or fabricate reconciliation/approval actor and timestamp fields.

The database cannot independently prove that a digest was produced by the trusted application. AGE now adds an application-held HMAC keyring so the service can verify that the prepared content and lifecycle claims were attested by an application instance holding a secret that is not stored in the database.

## Attestation design

The application uses HMAC-SHA-256 with canonical JSON, recursive associative-key ordering, and a versioned domain separator. Each lifecycle payload also carries an explicit stage label.

Three independently verified stage attestations are persisted:

1. **Prepared** — binds the snapshot UUID, tenant/election, record date, authoritative source reference, voting-rule version, prepared content digest, voter count, creator/preparer identities, and creation time.
2. **Reconciled** — binds the prepared attestation, snapshot scope and digest, authenticated reconciler identity, reconciliation time, and integrity-verification time.
3. **Approved/frozen** — binds the reconciliation attestation, snapshot scope and digest, authenticated approver identity, approval time, and freeze time.

The prepared HMAC is generated before the snapshot and voter rows are committed in the same database transaction. Reconciliation first verifies that prepared HMAC, then recomputes the canonical voter-row digest and checks it against the signed prepared digest. Only then does it create the reconciliation HMAC.

Approval re-verifies the prepared and reconciliation HMACs, locks and recomputes the voter rows in the freeze transaction, and then creates the approval HMAC. `hasValidSnapshotIntegrity()` now validates the content digest and all HMAC attestations appropriate to the snapshot's lifecycle state. The election lifecycle's voting-open gate already calls this method, so a fabricated frozen state without valid attestations fails closed.

Database triggers add structural enforcement: a new snapshot must contain only its prepared attestation; reconciliation attestation may be added only during `draft → reconciled`; approval attestation may be added only during `reconciled → frozen`; prepared and completed attestations cannot be rewritten through ordinary updates.

## Key configuration and operations

The keyring is read from application configuration, not from database rows:

- `AGE_REGISTER_ATTESTATION_ACTIVE_KEY_ID` selects the key used for new attestations.
- `AGE_REGISTER_ATTESTATION_KEYS` is a JSON object mapping key IDs to base64-encoded secrets of at least 32 random bytes.

Generate a secret with:

```sh
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Example configuration shape (use a newly generated secret; do not copy this placeholder):

```dotenv
AGE_REGISTER_ATTESTATION_ACTIVE_KEY_ID=v1
AGE_REGISTER_ATTESTATION_KEYS='{"v1":"<base64-encoded-random-secret>"}'
```

Retain prior key IDs in the keyring while any snapshot references them. Rotation selects a new active key ID and adds its key; do not remove old verification keys while signed registers still depend on them. No production key has been generated, installed, or disclosed in this PR. With no valid active key configured, register preparation fails closed.

The migration adds nullable attestation fields without fabricating signatures for existing snapshots. Legacy snapshots with null attestations fail integrity verification and cannot authorize voting through the updated gate. No backfill or production data update was performed.

## Security properties and explicit limits

**Covered against a database-only writer who does not possess the application keyring:**
- replacing draft voter rows and rewriting the stored content digest together;
- changing signed governing metadata or the snapshot UUID;
- fabricating reconciler/approver identities or lifecycle timestamps;
- changing a stored attestation or replaying one from a different snapshot UUID/scope.

**Not claimed:**
- HMAC is a symmetric message-authentication code, not a public-key digital signature and not non-repudiation by an individual actor. Actor identity is authenticated by the application and then bound into the HMAC.
- A compromised application process, application host, deployment secret store, or keyring can produce valid HMACs.
- This does not prevent database denial of service or deletion by a sufficiently privileged database administrator.
- Restoring an entire previously valid database snapshot (rollback/replay of a previously signed state) is not detected by an HMAC stored in that same database. Strong anti-rollback requires an independently administered append-only external anchor or receipt service; that is a separate future control.
- HMAC attestation does not independently prove the legal validity of share-class voting rights, source ledger evidence, candidate eligibility, or company authority. LEG-01..LEG-06 remain unresolved.

## Regression coverage

- MySQL 8.0 trigger tests: prepared attestation required on insert; stage-specific signatures required for forward transitions; signed fields cannot be rewritten; existing lifecycle, tenant-scope, frozen-row, concurrency, and migration rollback/reapply tests remain.
- SQLite service tests: three-actor workflow and HMAC lifecycle; prepared row+digest tampering after trigger bypass; fabricated approval actor after trigger bypass; missing key fail-closed; unsigned direct insert rejected; tenant isolation.
- HTTP suite: authenticated separate actors still exercise the dedicated register permission with a synthetic test key.

## Exact-head CI evidence

- Tested implementation SHA: `455ab9ebbb7bb5bf9414a9afa51f64c83342cd7c`
- Workflow run #43732454165: [PASS](https://github.com/akefadaka-git/arna-api/actions/runs/43732454165)
- MySQL 8.0.46 / PHP 8.3.6 / PHPUnit 11.5.56
- MySQL integration: **PASS — 9 tests, 38 assertions**
- SQLite register database: **PASS — 14 tests, 58 assertions**
- Governance election HTTP: **PASS — 5 tests, 22 assertions**
- All workflow steps completed successfully.

## Change control

- Implementation branch: `fix/age-a3-register-signed-attestations`.
- Stacked on the open AGE register HTTP authorization PR #140.
- This change does not merge any AGE PR, configure a production secret, run a production migration, change production database privileges, grant roles, activate voting, or modify production data.
- Full default PHP test suite: **NOT RUN**.
- Independent security review: **NOT RUN**.
- Electronic/hybrid voting remains disabled pending legal authority, independent cryptographic/security review, and operational acceptance.
