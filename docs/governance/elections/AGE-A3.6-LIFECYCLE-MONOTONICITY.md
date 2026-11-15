# AGE A3.6 — voter-register lifecycle monotonicity

Status: **PASS — MySQL and SQLite regression suites passed on the implementation head** on 2026-11-15. This is focused database-trigger evidence, not independent security certification.

## Problem addressed

The prior database guard enforced the initial prepared-draft insert and the final freeze transition, but did not fully constrain intermediate snapshot updates. A direct database update could attempt to fabricate reconciliation metadata, move a reconciled register backward, or rewrite governing snapshot metadata before freeze.

## Lifecycle contract

The only permitted forward status transitions are:

`draft → reconciled → frozen`

The database rejects skipped, backward, and unknown transitions.

### Draft → reconciled

Requires:
- a reconciler identity;
- reconciler differs from the preparer;
- reconciliation timestamp and integrity-verification timestamp;
- approval and freeze metadata remain unset.

### Reconciled → frozen

Requires:
- the original reconciler identity, reconciliation timestamp, and integrity-verification timestamp to remain unchanged;
- an approver identity distinct from both preparer and reconciler;
- approval and freeze timestamps.

Lifecycle metadata cannot be altered while status remains unchanged.

## Governing metadata immutability

After snapshot creation, the following values cannot be changed:

- tenant and election identifiers;
- record date;
- authoritative source reference;
- voting-rule version;
- canonical content digest;
- voter count;
- creator and preparer identities;
- creation timestamp.

The database trigger applies equivalent structural rules in MySQL and SQLite.

## Integrity boundary — important

The lifecycle trigger enforces state transitions and metadata immutability. It does **not** independently prove that the application recomputed the SHA-256 digest correctly at reconciliation.

The application service recomputes and verifies the digest during reconciliation and again during approval. A database writer with permission to alter draft voter rows and set lifecycle fields could still fabricate verification timestamps. Preventing that threat requires database privilege separation or a stronger database-controlled verification design, plus independent security review. This PR does not claim cryptographic integrity against a privileged database writer.

## Exact-head verification

- Tested implementation commit: `6effe065bca974fe296cc39e9fdc687538a7acc8`
- Workflow run: [#43726464014](https://github.com/akefadaka-git/arna-api/actions/runs/43726464014)
- MySQL 8.0.46 / PHP 8.3.6 / PHPUnit 11.5.56
- MySQL integration: **PASS — 7 tests, 31 assertions**
- SQLite database regression: **PASS — 10 tests, 50 assertions**
- All workflow steps completed successfully.

MySQL coverage includes valid forward transitions, same-actor reconciliation rejection, backward-transition rejection, governing metadata immutability, frozen-state protections, tenant/election reference checks, concurrency serialization, and migration rollback/reapplication.

SQLite coverage includes the service lifecycle, direct invalid transition attempts, metadata immutability, backward-transition rejection, freeze guards, tenant isolation, and prior ledger/projection integrity regressions.

## Remaining verification

- Full default PHP suite: **NOT RUN** by this scoped workflow.
- Full application migration ordering/rollback: **NOT VERIFIED**.
- HTTP authorization integration and permission-role assignment: **NOT VERIFIED**.
- Production MySQL configuration and database privilege separation: **NOT VERIFIED**.
- Independent security review: **NOT RUN**.
- Legal blockers LEG-01..LEG-06 remain unresolved and are not addressed here.

No merge, deployment, production migration, role grant, voting activation, or production data change is authorized.
