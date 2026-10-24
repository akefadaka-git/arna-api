# ARNA Overall Progress & Production Readiness Report

**Report date:** 2026-10-24  
**Repository:** `akeayu/arna-api`  
**Report status:** Current snapshot; evidence-led, not a release approval  
**Source-of-truth policy:** GitHub repository state and exact commit/workflow evidence; local copies are not used as the source of truth.

## 1. Executive status

ARNA remains **NOT CLEARED FOR PRODUCTION RELEASE** on the evidence summarized here. A bounded wallet audit listener fix has been submitted in [PR #56](https://github.com/akeayu/arna-api/pull/56), but it is unmerged and the associated exact-head Actions run failed with an unknown root cause because its logs and step summaries could not be retrieved.

No production database operation, migration, deployment, or merge was performed as part of this work. No local files were changed. The next read-only work item is now the separate loan repayment concurrency/idempotency review; repeated CI-log retrieval attempts are stopped per Owner direction.

## 2. Current work item: wallet audit listener

| Field | Current recorded state |
|---|---|
| Finding | Wallet listener uses unsupported `eventType` named argument for `AuditService::log()` |
| Correction | Seven call sites changed to `action`; tenant ID passed explicitly |
| Regression coverage | `tests/Feature/WalletAuditListenerTest.php` added |
| PR | [#56 — Fix wallet audit listener service contract](https://github.com/akeayu/arna-api/pull/56) |
| PR base | `main` at `1ef479898975f9cdab23d94ca7d4af739ba08797` |
| Source-fix commit used by the recorded run | `a6bad23d5a708579608e41d9e1d1c8c556edf9af` |
| Actions run | [40090590973](https://github.com/akeayu/arna-api/actions/runs/40090590973) |
| Run conclusion | **FAILURE** |
| Failure cause | **UNKNOWN** — job logs returned `BlobNotFound`; step summaries were empty |
| Test passed? | **NOT VERIFIED** |
| Merge / deployment | **NOT DONE** |

The recorded run executed workflow `.github/workflows/arna-shareholder-seed-number-initialization.yml`, job `Exact-head fresh migration and seed regression`. Its metadata confirms that the run targeted the source-fix commit above. It does not prove the wallet regression test passed, and the failure must not be attributed to the patch without logs. Documentation commits added after that run are not covered by that run's exact-head evidence. Follow-up runs [40092630819](https://github.com/akeayu/arna-api/actions/runs/40092630819), [40092652556](https://github.com/akeayu/arna-api/actions/runs/40092652556), and [40092688635](https://github.com/akeayu/arna-api/actions/runs/40092688635) were also observed as **FAILURE** on later documentation commits; step summaries/logs were unavailable and the root cause remains **UNKNOWN**. Per Owner direction, log retries have stopped.

Detailed incident chronology, evidence ledger, and acceptance steps: [Wallet Audit Listener Incident Record](incidents/ARNA-WALLET-AUDIT-LISTENER-INCIDENT-2026-10-24.md).

## 3. Evidence-based progress

Progress is reported by verifiable deliverables and gates, not by an unsupported overall percentage.

| Workstream / gate | State | Evidence / next proof required |
|---|---|---|
| Wallet listener source correction | PATCH SUBMITTED | PR #56; source changes present on branch |
| Wallet listener regression test | ADDED, NOT EXECUTED SUCCESSFULLY IN EVIDENCE | Obtain a passing run that demonstrably executes the regression test |
| CI failure diagnosis | OPEN | Recover job logs/step output for run 40090590973; diagnose from actual failing step |
| Tenant isolation | OPEN / NOT CLEARED HERE | Exact-head tests and review covering trusted tenant context, cross-tenant access, inactive memberships, and fail-closed behavior |
| Authorization boundary | OPEN / NOT CLEARED HERE | Exact-head tests/review covering role/permission scope and tenant enforcement |
| Loan repayment concurrency | SOURCE-LEVEL HIGH-RISK FINDING VERIFIED; RUNTIME NOT TESTED | Loan service reads without row lock then saves `settled_amount` derived from loaded value; see separate review document |
| Loan repayment idempotency | SOURCE-LEVEL GAP VERIFIED; RUNTIME NOT TESTED | Inspected request has no idempotency key and each accepted service call creates a new payment; define contract and test retries |
| Audit persistence failure policy | OPEN / OWNER DECISION REQUIRED | Decide whether audit persistence failure blocks the business operation; document and test the policy |
| Financial ledger completeness | OPEN / NOT VERIFIED | Evidence of production-grade double-entry journal, balanced postings, account catalogue, reversals/corrections, and relevant invariant tests |
| AI provenance and action boundaries | OPEN / NOT CLEARED HERE | Verify provenance, authorization, traceability, and human approval constraints against explicit requirements |
| Operational readiness | UNKNOWN / OPEN | Verify monitoring, backup/restore, rollback, incident response, capacity, deployment and recovery evidence |
| Production database preflight | NOT RUN / NOT CLEARED | Separate approved read-only preflight evidence; no migration authorized by this report |
| Production release | **NO-GO** | Required security, financial-integrity, operations, preflight, rollback and Owner approvals remain outstanding |

**Interpretation:** “OPEN,” “UNKNOWN,” and “NOT VERIFIED” are not claims that a control is absent. They mean this report does not have sufficient current exact-head evidence to mark the control verified.

## 4. Previously recorded baseline evidence

The earlier bounded source review used base SHA `1ef479898975f9cdab23d94ca7d4af739ba08797`. It identified the wallet audit service-contract defect and recorded separate open review items for loan repayment row locking, idempotency, and audit persistence failure semantics. Those items remain separate from PR #56.

A previously referenced run, [39806437687](https://github.com/akeayu/arna-api/actions/runs/39806437687), passed its stated clean-install/MySQL/migration/shareholder seed regression checks on its own source revision. That result is scoped to those checks and that exact revision; it is not evidence that all current security, tenant, loan, accounting, or production-readiness gates pass.

The historical [Phase 1 Gate Report](PHASE_1_GATE_REPORT.md) is dated 2026-09-10 and records a frozen earlier baseline. It should be read as historical phase evidence, not as a current production release decision.

## 5. Next-step loan repayment review

A read-only review was performed against the same baseline SHA, `1ef479898975f9cdab23d94ca7d4af739ba08797`. It recorded two source-level risks: (1) stale loan state can cause a lost update to `settled_amount` during concurrent repayments because the loan is read without a row lock before the later Wallet lock, and the in-memory amount is then saved; (2) the inspected repayment request/service path has no request idempotency key, so retries are not demonstrably deduplicated. No code or tests were changed or executed.

Detailed evidence, failure mechanism, design requirements, and verification ledger: [Loan Repayment Concurrency and Idempotency Review](reviews/ARNA-LOAN-REPAYMENT-CONCURRENCY-IDEMPOTENCY-REVIEW-2026-10-24.md).

The next implementation step requires a separately scoped change request approving the lock order, request-key contract, duplicate/conflict semantics, schema requirements, and concurrency/retry test plan. Keep this work separate from wallet listener PR #56.

## 6. Change control and non-actions

- Work is scoped to the wallet audit listener correction and its documentation.
- PR #56 remains the review vehicle; do not merge based solely on this report.
- Do not modify production data, run migrations, deploy, or close the work item without the required explicit authorization and evidence.
- Do not bundle loan repayment, database, accounting, or unrelated security changes into the wallet listener patch without a separately approved scope.
- Preserve unrelated changes and use exact commit SHAs for all future verification claims.

## 7. Loan repayment implementation change — 2026-10-24

A separate bounded implementation is available as [draft PR #57](https://github.com/akeayu/arna-api/pull/57), branch `fix/loan-repayment-idempotency-concurrency-2026-10-24`, based on `1ef479898975f9cdab23d94ca7d4af739ba08797`.

Implemented on the branch (source changes committed; not yet runtime-verified):
- Added tenant+loan scoped idempotency persistence in the canonical flat `database/migrations/` directory, with a unique `(tenant_id, loan_id, idempotency_key)` constraint and a request hash.
- Required the `Idempotency-Key` request header (16–128 characters, restricted to letters, digits, `.`, `_`, `:`, `-`).
- Same-key/same-payload retries return the existing loan state without creating a second payment; same-key/different-payload requests return HTTP 409.
- Moved the loan row lock until after Wallet → Payment → Obligation → PaymentAllocation work, re-read the loan under `lockForUpdate()`, and re-check its repayable status before updating `settled_amount`.
- Added feature regressions for replay, key reuse conflict, required header, additive totals across distinct keys, and canonical migration discovery.

**Verification:** PHP tests and migration execution NOT RUN. GitHub automatically ran two workflows against earlier head `883b74783ae3aa49fd434c8e1c2658dd1a320f9e`; both failed: [run 40094302868](https://github.com/akeayu/arna-api/actions/runs/40094302868) and [run 40094302873](https://github.com/akeayu/arna-api/actions/runs/40094302873). Step summaries were empty and log retrieval returned `BlobNotFound`, so the failure cause is UNKNOWN. No workflow was manually dispatched or rerun. The migration has since been moved to the canonical flat path and a discovery regression was added, but neither change has runtime verification. True simultaneous MySQL concurrency has NOT been exercised; the additive-total regression is sequential and is not evidence of a concurrent run. The implementation branch is not merged and no production database or deployment action occurred.

The wallet PR #56 remains unmerged; its CI failures remain unexplained and its regression test remains unverified. Per Owner direction, do not retry log retrieval. Next step: review the diff and run the targeted migration/test suite on the exact implementation head; no merge or deployment until exact-head evidence and required approvals.


### PR #57 follow-up — Option A response replay (source change; tests NOT RUN)
- Canonicalized valid amount/allocation decimal strings to scale four and normalized `received_at` to UTC for request fingerprinting.
- Added `response_status` and `response_body` to the new idempotency table; the controller builds the successful response inside the repayment transaction, and the service stores the response snapshot before commit.
- Same-key/same-payload retries return the stored original response, even if later repayments changed the loan. Missing snapshots fail closed. A new feature regression covers canonical decimal retry and original response replay after a subsequent repayment.
- Source change is committed to the existing draft PR branch. PHP tests, migration execution, MySQL concurrency, and CI verification remain NOT RUN / NOT VERIFIED. No merge, production DB operation, or deployment occurred.

---
*This is an evidence snapshot as of 2026-10-24. Statuses should change only when new, linkable evidence supports the transition.*
