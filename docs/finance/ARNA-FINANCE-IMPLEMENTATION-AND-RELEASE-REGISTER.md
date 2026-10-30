# ARNA Finance & Accounting — Implementation and Release Register

**Register version:** 1.1.0  
**Status:** Phase 0 architecture baseline closed; implementation and release remain in progress.  
**Chart of accounts:** v1.0.0; 79 canonical accounts.  
**Owner direction:** continue code generation; external Ethiopian tax/accounting review deferred for now.  
**Canonical architecture:** [ARNA Finance General Ledger Architecture Contract](../architecture/financial-accounting-domain-contract.md), v1.1.0.

## 1. Phase 0 — architecture baseline

**Phase 0: CLOSED — architecture baseline only.**

Phase 0 is closed for architecture and technical-contract purposes. The canonical contract now defines:

- Finance ownership of the authoritative GL and operational source-domain boundaries.
- Tenant + legal-entity isolation and canonical identity/tenant-context rules.
- Separate legal-entity books, ETB baseline, and read-model-only consolidation boundary.
- Exact decimal arithmetic and the current `DECIMAL(20,4)` ledger storage baseline.
- Journal balance, posting, immutability, reversal, idempotency, period, and audit invariants.
- Canonical CoA and control-account governance boundaries.
- No-opening-balance rule and no automatic adapter activation without verified source contracts.
- Reporting limitations and explicit later-phase policy/activation gates.

**Phase 0 closure does not certify runtime implementation, test results, tax compliance, accounting-policy approval, opening balances, production readiness, or deployment.**

## 2. Exact-head review record — PR #43

Reviewed destination repository: `akefadaka-git/arna-api`.

- PR: [#43 — feat(finance): complete financial statements and cash-flow reporting](https://github.com/akefadaka-git/arna-api/pull/43)
- PR state at review: OPEN, DRAFT, UNMERGED.
- Base branch: `feat/finance-accounting-completion`.
- Base SHA at review: `8adb090c70158f8d4b22b1a28050722cffcac447`.
- Head branch: `feat/finance-accounting-completion-reporting`.
- Exact head reviewed: `f5359467d131aebbfc11c6806793b1e77cfad97d`.
- Diff: 17 files, 748 additions, 3 deletions; 32 commits.
- Review type: bounded source/diff inspection through destination GitHub. Local tests and CI were not run.

### Review disposition

- **VERIFIED (source presence):** income statement, balance sheet, cash-movement service, tenant-scoped routes, permission middleware, explicit cash-flow classification, and feature-test additions are present.
- **VERIFIED (safety boundary):** cash-equivalent accounts default false; cash-flow activity is explicit; missing/invalid classification is exposed as undetermined; no code/name inference is used.
- **PARTIAL:** report services and controller authorization were inspected at source level; runtime query behavior, database compatibility, and full integration are not established.
- **DEFECT FOUND:** the register's reporting section and some verification rows contained literal `\\n` sequences rather than real Markdown line breaks. Corrected in this register.
- **NOT RUN:** local tests, CI/workflows, migration execution, seeder execution, production posting, database mutation, and deployment.
- **UNRESOLVED:** full IAS 7 cash-flow statement, opening/closing cash reconciliation, FX presentation, consolidated reporting, and statutory compliance remain outside the reporting extension's current claim.

## 3. Implemented source inventory

The Finance implementation branch contains source for legal entities, ledger accounts, accounting periods, journal lifecycle, reversals, source-to-legal-entity mappings, subledger posting contracts, trial balance, income statement, balance sheet, explicit cash-movement reporting, HTTP requests/controllers/resources, authorization policies, service-provider bindings, audit lifecycle events, English/Amharic validation resources, migrations, and feature/unit tests.

The canonical chart contains 79 accounts with hierarchy, bilingual labels, type, normal balance, posting eligibility, control-account designation, domain mapping, and provisional tax-routing category. The opt-in seeder is intentionally not registered in `DatabaseSeeder`.

This is an inventory of source artifacts, not a claim that runtime behavior has been verified.

## 4. Control-account accountability

The ownership registry maps 36 canonical control accounts to a proposed accountable function, contributing domains, reconciliation frequency, evidence requirements, and independent reviewer role.

- Named accountable people remain `null`.
- Each entry remains `PENDING_OWNER_DESIGNATION`.
- Every control-account adapter remains blocked until an accountable person is formally designated and the reconciliation procedure is approved.
- A role label is not a personnel appointment.
- Control ownership must retain preparer/reviewer separation.

## 5. Tax defaults — deferred, not approved as legal advice

The Owner has deferred independent tax/accounting review for the current code-generation phase. Account-level tax categories remain routing defaults only. They are not tax rates and are not final Ethiopian legal classifications.

Transaction-level tax treatment must be determined from applicable rules, supply facts, counterparties, effective date, tax code, and supporting evidence. An `undetermined` category must not silently become zero tax, exempt, or zero-rated treatment.

No code in this branch represents a professional tax opinion. Production tax calculations and statutory reporting remain gated on later policy approval.

## 6. Remaining implementation register

| ID | Phase | Required work | Status / acceptance evidence |
|---|---|---|---|
| FIN-1.01 | 1 — GL integrity | Enforce source-event uniqueness by tenant + legal entity + source domain + immutable event ID + version, with canonical payload hash | IMPLEMENTED IN SOURCE — nullable legacy-compatible `source_event_id`, `source_event_version`, and `source_event_hash`; external sources require an aggregate `source_id` plus immutable event ID and version; unique tenant/entity/type/event-ID/version index; canonical payload hash excludes request idempotency key; same event/payload returns persisted journal; changed payload conflicts. Tests added; NOT RUN |
| FIN-1.02 | 1 — GL integrity | Prove atomic posting, audit persistence, posted-header/line immutability, and reversal linkage | IMPLEMENTED IN SOURCE — audit listener enforces same active transaction connection; posting/reversal audit-failure rollback tests added; MySQL + SQLite database triggers protect posted headers and line insert/update/delete. Tests and migration NOT RUN |
| FIN-1.03 | 1 — GL integrity | Verify concurrent duplicate requests, period close/post, and direct line insert/post races on MySQL | SOURCE LOCKS PRESENT; BOUNDED VERIFICATION DESIGN DOCUMENTED — three two-connection barrier-controlled scenarios and assertions specified below. NOT EXECUTED; MySQL concurrency tests NOT RUN |
| FIN-1.04 | 1 — GL integrity | Verify tenant/entity isolation for all reads, writes, account references, periods, reversals, and reports | PARTIAL — composite constraints and scoped queries exist; adversarial tests not run |
| FIN-1.05 | 1 — GL integrity | Confirm journal numbering uniqueness, date/period validation, and database precision boundaries | PARTIAL — source and constraints exist; full boundary verification pending |
| FIN-1.06 | 1 — GL integrity | Revalidate legal-entity active status and every journal-line account's active/postable status inside the locked posting transaction, not only when the draft is created | IMPLEMENTED IN SOURCE — posting locks and rechecks the tenant-scoped legal entity, period, and sorted same-entity account rows; rejects inactive/non-postable accounts and cash-flow classification after cash-equivalent status changes. Tests added; NOT RUN |
| FIN-2.01 | 2 — CoA | Verify all 79 accounts, parent hierarchy, type/normal-balance consistency, and posting eligibility | SOURCE PRESENT — tests added, NOT RUN |
| FIN-2.02 | 2 — CoA | Verify all 36 control-account ownership mappings and fail-closed seeder preflight | SOURCE PRESENT — tests added, NOT RUN |
| FIN-2.03 | 2 — CoA | Preserve opt-in seeder, no overwrite of non-system accounts, and no opening-balance creation | SOURCE PRESENT — execution NOT RUN |
| FIN-3.01 | 3 — Reporting | Verify trial balance and account activity against posted journal-line totals | NOT VERIFIED |
| FIN-3.02 | 3 — Reporting | Verify income statement and balance sheet totals, date boundaries, reversed journals, and negative balances | SOURCE/TESTS PRESENT — NOT RUN |
| FIN-3.03 | 3 — Reporting | Verify cash-flow classification, undetermined movements, reversal netting, and report permissions | SOURCE/TESTS PRESENT — NOT RUN |
| FIN-3.04 | 3 — Reporting | Add opening/closing cash reconciliation and full statement only under a separately approved contract | NOT IMPLEMENTED |
| FIN-4.01 | 4 — Integrations | Read-only audit of Wallet source events, identity, transaction boundary, reversals, currency, and reconciliation extract | NOT COMPLETE |
| FIN-4.02 | 4 — Integrations | Read-only audit of Loan principal, interest, fees, repayments, allocations, write-offs, and reversals | NOT COMPLETE |
| FIN-4.03 | 4 — Integrations | Read-only audit of Shareholder capital, paid-in events, distributions, and non-accounting holding transfers | NOT COMPLETE |
| FIN-4.04 | 4 — Integrations | Read-only audit of Procurement and People/Payroll source events and entity ownership | NOT COMPLETE |
| FIN-4.05 | 4 — Integrations | Approve event-to-GL mappings, exception handling, and reconciliation ownership before adapter activation | BLOCKED |
| FIN-5.01 | 5 — Period close | Implement close checklist, close snapshot, transactional close lock, reopen authority/reason, and audit trail | PARTIAL / NOT VERIFIED |
| FIN-5.02 | 5 — Consolidation | Define entity group, intercompany mapping, elimination policy, and reproducible consolidation snapshot | POLICY BLOCKED |
| FIN-6.01 | 6 — FX | Define currency precision, FX source, effective time, rounding, revaluation, and realized/unrealized treatment | POLICY BLOCKED; foreign-currency posting disabled |
| FIN-6.02 | 6 — Tax/statutory | Implement transaction-level tax only after approved tax codes and policy | DEFERRED per Owner direction |
| FIN-7.01 | 7 — Verification | Exact-head automated tests, migrations/rollback, MySQL concurrency, authorization, isolation, and report reconciliation | NOT RUN / NOT VERIFIED |
| FIN-7.02 | 7 — Release | Pilot, cutover balances, operational acceptance, production migration, and deployment | NOT STARTED; separate authorization required |

## 6A. Phase 1 implementation log — FIN-1.01 and FIN-1.06

Implementation branch: `feat/finance-gl-integrity-phase1`, based on the reviewed PR #43 head before Phase 1 changes.

### FIN-1.01 — versioned source-event idempotency

- Added `source_event_id`, `source_event_version`, and `source_event_hash` to `journal_entries`. `source_id` continues to identify the owning aggregate/business record; `source_event_id` identifies the individual immutable event within that aggregate.
- Added database uniqueness on `tenant_id + legal_entity_id + source_type + source_event_id + source_event_version`. Nullable versions preserve legacy/manual rows; new external-source journals must supply a non-empty version.
- The canonical source-event fingerprint excludes the request idempotency key, so a retry of the same source event and payload can return the original journal even if the caller uses a different request key.
- A repeated source identity with a changed canonical payload returns HTTP 409.
- Unique-index races are reconciled after transaction rollback by re-reading the idempotency key and source identity; matching hashes return the persisted journal, changed hashes conflict.
- `SubledgerPostingInstruction` now requires and forwards the immutable source event ID and version; the adapter must derive both from the owning domain's authoritative event record.
- MySQL insert guard requires complete external source identity; source type/ID/version/hash are immutable after draft creation, and the posted-journal immutability trigger also protects version and hash.
- Existing legacy rows are not assigned invented event versions; their nullable version is preserved. External event replay for historical unversioned rows remains a migration/reconciliation concern and must not be guessed.

### FIN-1.06 — posting-time entity/account revalidation

- Posting now locks and revalidates the tenant-scoped legal entity and confirms active status and base-currency consistency.
- Posting now locks journal lines and referenced account rows in deterministic ID order, and verifies same tenant/entity ownership, active status, and posting eligibility.
- Cash-flow classified lines are rejected if the account is no longer designated as a cash equivalent.
- Validation occurs inside the same posting transaction before the journal status transition and synchronous audit event.

### Evidence boundary

The source changes and regression tests are committed on the Phase 1 branch. No local tests, CI, migration execution, MySQL trigger execution, or database concurrency tests were run. Therefore both items are **IMPLEMENTED IN SOURCE / NOT RUNTIME VERIFIED**, not closed for production acceptance.


## 6B. Phase 1 implementation log — FIN-1.02

### Atomic posting and audit persistence

- The journal lifecycle audit listener remains synchronous and now fails closed unless the journal model, audit model, and active transaction resolve to the same connection and the connection has an active transaction.
- Journal posting persists the posted state and its audit row within the same transaction. An audit exception propagates and aborts the transaction.
- Reversal continues to run as one outer transaction, including reversal draft creation, reversal posting, original-journal state transition, and both posting/reversal audit writes. A failure in the final reversal audit therefore rolls back the reversal journal, its posting audit, and the original journal's reversed state.
- Added regression tests for posting-audit failure rollback, reversal-audit failure rollback, and direct database attempts to update a posted header or insert/update/delete posted journal lines.
- Added database immutability triggers for journal-line insert/update/delete on MySQL and SQLite. SQLite also receives posted-header and permitted posted-to-reversed transition guards so the direct-SQL regression coverage exercises database enforcement in the feature-test driver.
- Draft journal lines remain mutable only while the parent journal is in draft; posted, reversed, and cancelled journal lines are immutable at the database boundary.

### Evidence boundary

The listener, migration, and regression-test source are committed on the Phase 1 branch. This is IMPLEMENTED IN SOURCE / NOT RUNTIME VERIFIED. No tests, migration execution, MySQL trigger execution, or CI workflows were run. MySQL production schema compatibility and actual transaction rollback behavior remain unverified until an authorized runtime environment is available.


## 6C. Phase 1 concurrency review — FIN-1.03

### Source-level protections confirmed

- Draft creation serializes on the tenant-scoped legal-entity row, then locks the matching open accounting-period row before journal and line creation.
- The database enforces uniqueness for tenant + legal entity + journal number and tenant + legal entity + idempotency key. FIN-1.01 adds tenant + legal entity + source type + immutable source event ID + version uniqueness.
- Duplicate idempotency/source-event insert races are handled only after the failed transaction rolls back; the service re-reads the persisted identity, returns the original matching journal, and conflicts on a changed payload.
- Posting locks the journal, legal entity, accounting period, journal lines, and account rows. It revalidates period-open state after acquiring the period lock.
- Period closure locks the same accounting-period row and refuses closure while any draft journal remains. This serializes close against both draft creation and posting: whichever transaction acquires the period lock first determines the valid outcome.
- The FIN-1.02 journal-line database triggers now take a locking read on the parent journal before insert/update/delete, aligning direct line writes with the posting transaction's parent-row lock. Moving a line between journal entries is rejected.

### Verification boundary

This is a source-level concurrency assessment, not proof of simultaneous execution behavior. No MySQL parallel-session test, deadlock/retry test, workflow, local test, or database migration was run. FIN-1.03 remains **PARTIAL / NOT RUNTIME VERIFIED** until those tests execute against the supported MySQL version and isolation configuration.


## 6D. FIN-1.03 — bounded MySQL concurrency verification design

### Scope and execution boundary

This plan covers exactly three races:

1. Two concurrent submissions using the same tenant, legal entity, idempotency key, and identical payload.
2. Accounting-period closure racing with posting an existing draft journal.
3. A direct SQL journal-line insert racing with posting the parent draft journal.

The plan is a design artifact only. It does not authorize execution, database access, migration execution, test runs, workflow runs, retries, or deployment. All scenarios require a disposable, isolated MySQL test database on the same supported MySQL major/minor version and transaction isolation configuration intended for deployment. Use two independently established database connections (A and B), not two Laravel transactions sharing one connection. Disable test-harness outer transactions for these scenarios; each worker must commit or roll back its own transaction so the competing connection can observe committed state.

Use explicit process barriers/latches and bounded lock-wait timeouts. Do not coordinate with arbitrary sleeps. Record MySQL version, storage engine, isolation level, connection IDs, transaction start/commit/rollback timestamps, SQLSTATE/error code, and the final row counts/state. Do not allow automatic retry logic to conceal the first-attempt result; record retries separately if the application contract later requires them.

### Scenario 1 — duplicate idempotency submissions

**Fixture:** One active tenant, one active legal entity, one open accounting period, two valid posting accounts, and one balanced draft payload. Both workers submit the exact same payload, including the same idempotency key, using separate connections.

**Controlled schedule:**

1. Worker A begins the service operation and acquires the tenant-scoped legal-entity lock.
2. A signals the coordinator that it holds the lock, then pauses before the transaction commits.
3. Worker B begins the same service operation on connection B and attempts to acquire the same legal-entity lock. The coordinator confirms B is waiting using bounded lock-wait/Performance Schema evidence; do not infer this from elapsed time.
4. Release A; A completes draft creation and commits.
5. B resumes, re-reads the idempotency identity within its transaction, and returns the persisted journal rather than inserting a second journal.

**Assertions:**

- Both calls return the same journal primary key and the same persisted request fingerprint.
- Exactly one journal header exists for the tenant/legal-entity/idempotency-key tuple.
- Exactly the expected journal-line count exists; no duplicate lines were inserted.
- No second journal-created audit record exists for the losing/replayed request.
- Both calls produce the documented successful replay result; no raw unique-constraint exception escapes.
- A follow-up request using the same idempotency key but a different canonical payload returns HTTP 409 and does not alter the original journal.

**Failure conditions:** duplicate journal or line rows, differing returned journal IDs, missing persisted journal after both calls, leaked unique-constraint exception, or changed-payload replay accepted.

### Scenario 2 — accounting-period close versus journal posting

**Fixture:** One open accounting period and one balanced draft journal belonging to that period. Use separate connections for the close and post operations.

Run both lock-order permutations, with barriers proving which transaction acquired the period row first.

**Schedule A — posting acquires the period lock first:**

1. Posting worker locks the journal and then the accounting-period row.
2. Pause posting while it holds the period lock and before commit.
3. Close worker begins and is confirmed waiting for the same period row.
4. Release posting; it validates the open period, posts the journal, writes its audit record, and commits.
5. Close worker resumes, sees no remaining draft journal in that period, and may close the period.

**Expected result A:** posting succeeds; close succeeds after posting commits; journal remains posted; period ends closed; posting audit exists exactly once.

**Schedule B — close acquires the period lock first:**

1. Close worker locks the accounting-period row.
2. Pause close before its draft-journal check/commit boundary, while retaining the lock.
3. Posting worker begins, locks the target journal, and is confirmed waiting for the accounting-period row.
4. Release close; close sees the existing draft and rejects closure under the current contract, then rolls back/releases the period lock.
5. Posting resumes, sees the still-open period, and may post successfully.

**Expected result B:** close is rejected because a draft exists; period remains open; posting succeeds; journal is posted; posting audit exists exactly once.

**Assertions for both schedules:**

- No committed state has a closed period containing an unposted draft journal.
- No successful posting occurs after the period has become closed.
- The final period and journal states match one of the two serialized outcomes above.
- Audit rows agree with committed state; failed close attempts leave no close audit row.
- No deadlock or lock timeout occurs under the controlled schedule. If one does occur, preserve the complete deadlock/lock-wait evidence and classify the scenario as failed; do not silently retry.

### Scenario 3 — direct journal-line insert versus posting

**Fixture:** One balanced draft journal with two lines. A direct SQL worker attempts to append a third line whose debit/credit amount would make the journal unbalanced. The insert is issued through the database connection so Eloquent/model events cannot provide the protection.

Run both lock-order permutations against the MySQL immutability trigger.

**Schedule A — direct line insert acquires the parent journal lock first:**

1. Worker A begins a transaction and attempts the direct insert. The trigger's locking read acquires the parent journal row while its status is draft.
2. A pauses before commit, retaining the parent lock.
3. Posting worker B begins and is confirmed waiting for that journal row.
4. Release A; the extra line commits.
5. B resumes, locks the journal and its lines, revalidates the complete line set, and must reject posting because the added line makes the journal unbalanced.

**Expected result A:** direct insert commits while the journal is draft; posting fails balance validation; journal remains draft; all three lines remain visible; no posting audit row exists.

**Schedule B — posting acquires the parent journal lock first:**

1. Posting worker A locks the journal and its lines, validates the balanced two-line journal, and pauses before commit.
2. Direct SQL worker B attempts the insert and is confirmed waiting at the trigger's parent-row locking read.
3. Release A; posting changes the journal status to posted and commits.
4. B resumes; the trigger re-reads the parent status and rejects the insert.

**Expected result B:** posting succeeds; journal remains posted with exactly its original two lines; direct insert fails with the expected database trigger error; one posting audit row exists.

**Assertions for both schedules:**

- A committed posted journal never acquires an additional line after posting.
- If the direct insert wins first, posting revalidates and rejects the resulting unbalanced journal.
- If posting wins first, the direct insert is rejected by the database trigger.
- No line is moved between journal entries.
- The final line count, journal status, balance, and audit count agree with the expected outcome.

### Bounded run matrix and evidence

For each of the three scenarios, execute the two controlled lock-order permutations once as a deterministic smoke test, then repeat each permutation 10 times in the disposable MySQL environment. This is a bounded race check, not a statistical proof of correctness. Stop on the first unexpected state, deadlock, lock timeout, duplicate row, or audit mismatch and preserve the failing database diagnostics rather than continuing retries.

Capture for every run:

- exact application commit SHA and migration version;
- MySQL version, storage engine, isolation level, and connection IDs;
- barrier order and lock-acquisition evidence;
- each worker's returned value or exception, SQLSTATE, and transaction outcome;
- before/after journal, line, period, idempotency, and audit row snapshots;
- deadlock/lock-wait diagnostics when applicable;
- pass/fail per assertion and a concise run summary.

**Acceptance rule:** FIN-1.03 cannot be marked verified until all six lock-order permutations pass their 10 bounded repetitions, the final database state and audit assertions pass on every run, and the supported MySQL environment/version is recorded. A source review or SQLite test is not a substitute for this MySQL evidence.

### Current status

The source-level lock paths and this verification protocol are documented. No database connection, migration, test, workflow, or concurrency run has been performed. FIN-1.03 remains **DESIGN DOCUMENTED / NOT EXECUTED / NOT RUNTIME VERIFIED**.

## 7. Safety and release boundaries

- No opening balances are created or inferred.
- The CoA seeder is not wired into `DatabaseSeeder`.
- The seeder must not overwrite existing matching tenant-owned non-system accounts.
- Tax categories do not activate subledger adapters.
- Loan principal is not revenue merely because of an account mapping.
- No production database mutation, production posting, merge, or deployment is authorized by this register.
- PR #43 remains open and in draft status until exact-head source review, verification, and separate Owner approval.

## 8. Verification record

| Check | Status |
|---|---|
| Phase 0 architecture baseline | CLOSED — documentation and technical contract |
| PR #43 exact-head diff review | PARTIAL — source/diff review; no runtime verification |
| Canonical CoA structure | Contract tests added; NOT RUN |
| Control-account ownership coverage | Contract tests added; NOT RUN |
| Journal invariants and concurrency | Source present; NOT VERIFIED |
| Income statement and balance sheet | Source/tests added; NOT RUN |
| Cash-flow classification/report | Source/migration/tests added; NOT RUN |
| Seeder against a real schema/database | NOT RUN |
| Repository tests | NOT RUN per Owner instruction |
| CI/workflows | NOT RUN |
| Migration execution and rollback | NOT RUN |
| Runtime authorization and tenant isolation | NOT VERIFIED |
| Production readiness | NOT ESTABLISHED |
| Merge/deployment | NOT AUTHORIZED / NOT PERFORMED |

## 9. Next gate

Proceed with Phase 1 core GL integrity, beginning with source-event idempotency identity and database uniqueness, then posting/audit atomicity, immutable posted records, reversal correctness, and period concurrency. Keep all adapters disabled until source audits and ownership controls are complete. Run only destination GitHub verification that is separately within the approved verification gate; do not run local repository tests. Do not merge or deploy until the Owner separately authorizes those actions.
