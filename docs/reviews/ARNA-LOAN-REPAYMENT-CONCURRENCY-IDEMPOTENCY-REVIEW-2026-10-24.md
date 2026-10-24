# ARNA Loan Repayment Concurrency and Idempotency Review

**Record ID:** ARNA-REVIEW-2026-10-24-LOAN-REPAYMENT-001  
**Review date:** 2026-10-24  
**Repository:** `akeayu/arna-api`  
**Review type:** Read-only source review  
**Source baseline:** `1ef479898975f9cdab23d94ca7d4af739ba08797`  
**Status:** FINDINGS RECORDED — implementation and runtime verification NOT performed  
**Release effect:** This review does not authorize production use, merge, schema changes, or deployment.

## 1. Executive summary

The exact-baseline source review confirms two material repayment risks:

1. **Concurrent repayment can overwrite the loan's `settled_amount` projection.** `LoanRepaymentService::record()` reads the loan without a row lock, then later calculates a new `settled_amount` from that in-memory value and saves it. The Wallet row lock is acquired later in the payment-recording path. Two transactions can therefore both read the same old loan value before serializing at the Wallet lock, and the later save can overwrite the earlier increment.
2. **No request-level idempotency contract is present in the inspected repayment request/service flow.** The request rules have no idempotency key. Each accepted call creates a new Payment through `PaymentService::record()`. A client/network retry can therefore be treated as a new economic operation rather than a replay of the first result. Whether a specific duplicate succeeds depends on current obligation balances and allocation validation; duplicate success is a risk, not a claim that every retry succeeds.

No code was changed for these findings. No test was executed. The recommended next engineering step is a separately scoped change request with explicit idempotency semantics, deterministic locking, and concurrent/retry regression tests.

## 2. Exact source evidence

All links below point to the same baseline commit, `1ef479898975f9cdab23d94ca7d4af739ba08797`.

- [LoanRepaymentService.php](https://github.com/akeayu/arna-api/blob/1ef479898975f9cdab23d94ca7d4af739ba08797/mases_core/src/Domains/Loan/Services/LoanRepaymentService.php)
- [LoanRepaymentController.php](https://github.com/akeayu/arna-api/blob/1ef479898975f9cdab23d94ca7d4af739ba08797/mases_core/src/Domains/Loan/Http/Controllers/LoanRepaymentController.php)
- [StoreRepaymentRequest.php](https://github.com/akeayu/arna-api/blob/1ef479898975f9cdab23d94ca7d4af739ba08797/mases_core/src/Domains/Loan/Http/Requests/StoreRepaymentRequest.php)
- [PaymentService.php](https://github.com/akeayu/arna-api/blob/1ef479898975f9cdab23d94ca7d4af739ba08797/mases_core/src/Domains/Wallet/Services/PaymentService.php)
- [AllocationService.php](https://github.com/akeayu/arna-api/blob/1ef479898975f9cdab23d94ca7d4af739ba08797/mases_core/src/Domains/Wallet/Services/AllocationService.php)

## 3. Finding LRP-01 — stale loan read and lost update risk

**Severity:** High financial-integrity risk  
**Evidence state:** VERIFIED by source inspection; concurrency outcome not runtime-tested.

### Observed implementation

In `LoanRepaymentService::record()`:

1. The operation opens a database transaction.
2. It reads the loan using `Loan::query()->whereKey($loan->id)->firstOrFail()`; the source comment explicitly says “Read the loan (no lock).”
3. It validates the loan status and resolves the borrower Wallet.
4. It calls `PaymentService::record()`. That service writes through `WalletService::recordEntry()`, whose documented behavior locks the Wallet row. This lock is acquired after the loan was read.
5. After payment allocations, it calculates `settled_amount` by applying `bcadd()` to the already-read in-memory `$current->settled_amount`, then calls `$current->save()`.

### Failure mechanism

Two repayment transactions can both load the same initial loan value before either acquires the Wallet lock. The Wallet lock can serialize the later write path but does not refresh the loan model already held by the waiting transaction. If both repayments proceed, the second transaction can calculate from stale `settled_amount` and overwrite the first transaction's increment.

The loan's outstanding projections are recomputed from Wallet obligations later, so the aggregate projection columns can become inconsistent: Wallet obligation balances reflect both committed allocations, while `settled_amount` may reflect only one increment. State checks also occur against the earlier loan read.

This is a source-level race condition assessment, not a claim that a specific production transaction has already suffered this outcome.

### Required correction design

- Define one documented global lock order for repayment and its dependent Wallet/obligation rows; reconcile with the existing `Wallet → Loan` order before changing code.
- Re-read and lock the loan at the correct point in that order, or use a rigorously designed atomic/optimistic update that cannot overwrite a concurrent result.
- Ensure the repayable-state check, projection update, and settlement transition use the authoritative locked state.
- Add a concurrency regression test using independent database connections/transactions that submits two valid repayments against the same loan and verifies:
  - both accepted repayments are reflected exactly once in `settled_amount`;
  - Wallet payment and allocation rows match the accepted operations;
  - obligation balances and loan projections reconcile;
  - loan/installment status and emitted after-commit events are consistent.
- Verify deadlock handling/retry behavior and lock order against the supported production database engine. Do not assume SQLite tests establish MySQL locking behavior.

## 4. Finding LRP-02 — no request idempotency key

**Severity:** High financial-integrity / duplicate-operation risk  
**Evidence state:** VERIFIED for the inspected HTTP request and service flow; end-to-end duplicate behavior not runtime-tested.

### Observed implementation

- `StoreRepaymentRequest::rules()` validates `amount`, `allocations`, `received_at`, `reference_type`, `reference_id`, and `description`. There is no idempotency/request key field or validation rule.
- `LoanRepaymentController::store()` delegates each accepted request to `LoanRepaymentService::record()`.
- `LoanRepaymentService::record()` calls `PaymentService::record()` for a new Payment. No request key is passed through this inspected path.
- `PaymentService::record()` creates a Payment and a corresponding wallet ledger entry. The reference pair currently identifies the loan-repayment category and loan, not a unique client operation.

### Impact

If a caller retries after a timeout or lost response, the server has no demonstrated request-level mechanism in this path to recognize the retry as the same operation and return the original result. The second request may be treated as a fresh payment. Allocation validation may reject some repeats when obligations are no longer sufficiently outstanding, but that is not a substitute for idempotency: a retry can target other still-outstanding obligations or otherwise be accepted as another operation.

### Required contract and design

Owner/product/finance approval is required for the exact contract. Recommended minimum behavior:

- Require an idempotency key for repayment writes (or explicitly approve another authoritative deduplication key).
- Scope uniqueness by tenant and operation/loan context; store a canonical request fingerprint covering the economically meaningful fields.
- Persist the idempotency record and repayment outcome atomically with the payment, ledger entry, allocations, loan projections, and status changes.
- For the same key and same canonical payload, return the original successful outcome without creating another payment or ledger entry.
- For the same key with a different payload, return a deterministic conflict and create no financial effects.
- Define behavior for concurrent identical requests, in-progress requests, rolled-back attempts, retention, and key reuse.
- Add tests for same-key sequential retry, same-key concurrent retry, same-key/different-payload conflict, response-loss simulation, and distinct keys for genuinely distinct repayments.
- Enforce uniqueness in the database; application-only “check then insert” logic is insufficient under concurrency.

Do not silently treat `reference_type`/`reference_id` as an idempotency key. The current code documents those values as a loan-level reference, and the request's optional reference fields are not used as the unique identity of a repayment.

## 5. Existing controls that do not close these findings

- `DB::transaction()` protects atomicity within one repayment, but does not alone prevent two transactions from making stale decisions or overwriting stale model state.
- Wallet locking in the payment-recording path serializes some Wallet writes, but the loan is read before that lock and the loaded loan model is subsequently saved.
- AllocationService re-resolves payment/obligation rows under locks and validates balances, which protects allocation invariants but does not establish request-level repayment deduplication or repair the stale `settled_amount` read.
- A successful migration/seed workflow is not evidence that these concurrency and idempotency properties pass.

## 6. Verification ledger

| Check | Status | Basis |
|---|---|---|
| Exact baseline identified | VERIFIED | SHA `1ef479898975f9cdab23d94ca7d4af739ba08797` |
| Loan repayment reads loan without row lock | VERIFIED | Source lines/logic in `LoanRepaymentService::record()` |
| `settled_amount` updated from loaded value then saved | VERIFIED | `bcadd((string) $current->settled_amount, ...)` followed by `save()` |
| Wallet write path acquires a Wallet lock | SOURCE-DOCUMENTED | `PaymentService` / `WalletService::recordEntry()` design; no runtime test in this review |
| Request-level idempotency key in inspected request | NOT PRESENT | Full `rules()` array inspected |
| Atomic database deduplication for repayment request | NOT ESTABLISHED | No key/record in inspected flow |
| Concurrent repayment test passed | NOT RUN | Read-only review only |
| Duplicate retry test passed | NOT RUN | Read-only review only |
| Production data affected | NO ACTION TAKEN | No production access or operation |
| Code modified | NO | This branch is documentation-only |

## 7. Implementation status on the dedicated branch

The bounded implementation was prepared on `fix/loan-repayment-idempotency-concurrency-2026-10-24`, based on `1ef479898975f9cdab23d94ca7d4af739ba08797`.

Changes include a tenant+loan scoped idempotency table, required `Idempotency-Key` header, same-key/same-payload replay handling, HTTP 409 for same-key/different-payload reuse, delayed loan-row locking after Wallet → Payment → Obligation → PaymentAllocation, a fresh locked read before updating `settled_amount`, and feature regressions for retry, key conflict, missing key, and additive totals.

**Not verified:** no PHP test suite or migration was run, no workflow was dispatched, and simultaneous MySQL concurrency has not been exercised. The sequential additive-total test is not proof of concurrency correctness. Do not merge or deploy until migration discovery and targeted tests pass on the exact branch head and the diff is reviewed.

---
*Prepared from GitHub source at the stated baseline on 2026-10-24. This document records source-level findings and design requirements; it is not runtime test evidence or production approval.*
