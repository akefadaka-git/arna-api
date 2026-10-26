# ARNA Loan Repayment Lock-Order and Concurrency Review

**Review date:** 2026-10-26  
**Reviewed baseline:** `52b52c7ef3c237f31b74beaacd59383a33700303`  
**Scope:** Read-only source review followed by authorized, bounded test/documentation changes. No production database or deployment actions.

## Findings

### Lock order

The documented multi-row order is:

`Wallet → Payment → Obligation → PaymentAllocation → Loan`

The reviewed repayment path inserts an idempotency reservation without locking the loan, records the payment (wallet row lock), applies allocations (payment then obligation locks), then re-reads the loan with `lockForUpdate()` before incrementing `settled_amount`. The loan status is rechecked after acquiring the lock. The reviewed disbursement path is documented as wallet-first and loan-second.

No direct lock-order inversion was identified in these bounded paths. This is not a proof for every platform write path and does not rule out all deadlocks.

### Idempotency

The source reserves the tenant/loan/key tuple inside the financial transaction under a unique database constraint, stores a request hash, links the payment, and writes the successful response snapshot before commit. The controller reads that snapshot for the returned response. Existing feature tests cover sequential same-key replay, payload mismatch, canonical decimal replay, and distinct repayment accumulation.

### Regression test enhancement

The distinct-repayment feature test now checks reconciliation after two valid repayments:
- loan `settled_amount` equals 1100.0000;
- obligation outstanding amount equals 8900.0000;
- exactly two payments and two idempotency records exist;
- aggregate payment and allocation amounts each equal 1100.0000.

The existing clean-install MySQL workflow now also runs the loan repayment HTTP/financial-invariant feature suite using its established hermetic SQLite test configuration. This adds the suite to the relevant PR gate without creating a new workflow or adding unrelated CI runs.

### Explicit remaining limitation

These feature tests execute sequential HTTP requests and do **not** establish real concurrent execution between independent transactions. The existing test class uses `RefreshDatabase`, and its test step uses SQLite in-memory; neither is sufficient evidence for MySQL row-lock contention. A genuine concurrency test needs a dedicated MySQL test harness with committed shared fixtures and independent processes/connections, coordinated to overlap the service calls. Do not describe the current tests as proving concurrent safety.

## Decision

No implementation defect was conclusively proven by this bounded review, so no production-service logic was changed. The current correction remains the lock/re-read already present in the reviewed baseline. The immediate change is limited to test assertions, workflow coverage, and documentation alignment.

## Required acceptance evidence

1. The updated Loan repayment feature suite passes.
2. The relevant existing clean-install MySQL workflow passes on the exact PR revision.
3. The existing tenant isolation regression gate remains green for the PR revision.
4. Real MySQL concurrent service-level execution remains a separate evidence gap until the dedicated harness is implemented and passes.

No production deployment or production database modification is authorized by this work.
