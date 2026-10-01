<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanApplicationSubmitted;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Shareholder\Repositories\ShareholderRepository;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanProductRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;

/**
 * LoanApplicationService
 *
 * Owns the DRAFT-CREATION and SUBMISSION phases of a loan's lifecycle.
 *
 * =============================================================================
 * Scope — draft creation and submission ONLY
 * =============================================================================
 * This service owns exactly TWO operations:
 *
 *   createDraft()  — creates a loan in the `draft` state.
 *   submit()       — transitions a `draft` loan to `submitted`.
 *
 * Withdrawal is NOT part of Phase 2C. The frozen twelve-state vocabulary
 * (C4, A.2) has no `withdrawn` state, and the frozen API surface (Stage 6
 * §4.16) exposes no withdrawal endpoint. Any reference to withdrawal in
 * an earlier draft of the architecture is superseded by this file's
 * class docblock. A future Change Request that introduces withdrawal
 * would:
 *
 *   1. Add a `withdrawn` case to `LoanStatus` (A.2).
 *   2. Add the transition(s) `draft → withdrawn` and (optionally)
 *      `submitted → withdrawn` to the enum's `canTransitionTo()` matrix.
 *   3. Add the corresponding entries to
 *      `LoanStateMachine::REASON_REQUIRED_TRANSITIONS` (A.26) if the
 *      reason is required.
 *   4. Add a `withdraw()` method to this service.
 *   5. Add an HTTP endpoint, request, resource, permission, and event.
 *
 * None of the above is in Phase 2C scope.
 *
 * Everything AFTER submission — review, approval, rejection, disbursement,
 * repayment, interest accrual, penalty accrual, restructuring, write-off,
 * settlement, closure — is owned by other services:
 *
 *     LoanApprovalService       (A.29) — approve / reject
 *     LoanDisbursementService   (A.30) — disburse
 *     LoanRepaymentService      (A.31) — record repayment
 *     LoanInterestAccrualService (A.32) — accrual command
 *     LoanArrearsService        (A.33) — arrears detection
 *     LoanRestructuringService  (A.34) — restructure
 *     LoanWriteOffService       (A.35) — write off
 *
 * This service does NOT touch any of those concerns.
 *
 * =============================================================================
 * The two-layer write path
 * =============================================================================
 * Every write in this service flows through TWO layers, in order:
 *
 *   1. DOMAIN VALIDATION — this service checks the domain invariants that
 *      require loading additional data: the product exists, is active,
 *      and belongs to the tenant; the borrower is a shareholder in the
 *      tenant; the principal and term fall within the product's declared
 *      bounds.
 *
 *   2. STATE MACHINE — `LoanStateMachine` (A.26) is the sole writer of
 *      the loan's `status` column and its history rows. This service
 *      calls `initialize()` on draft creation and `transition()` on
 *      submission. The state machine handles the row lock, the
 *      structural check, the reason check, and the after-commit event
 *      dispatch.
 *
 * The split is deliberate. The state machine is a narrow persistence
 * primitive; this service carries the domain validation logic that
 * requires additional I/O. See the state machine's class docblock
 * §"Caller's responsibility" for the full rationale.
 *
 * =============================================================================
 * Tenant scoping
 * =============================================================================
 * Every lookup this service performs is tenant-scoped. The service
 * receives a `$tenantId` parameter and passes it to every repository
 * lookup. A cross-tenant reference (a borrower or a product belonging to
 * a different tenant) produces a `ModelNotFoundException` at the
 * repository boundary, which the caller (the controller) renders as a
 * 404. The service does not distinguish "does not exist" from "belongs
 * to another tenant" — the two produce identical responses.
 *
 * This mirrors the tenant-isolation pattern established by Phase 2A's
 * `ShareholderService` and Phase 2B's `WalletService`.
 *
 * =============================================================================
 * Currency handling
 * =============================================================================
 * Per the Q8 single-currency invariant (Phase 2B), a loan's currency must
 * match the borrower's Wallet currency. At draft-creation time:
 *
 *   - If the borrower's Wallet exists, this service validates that the
 *     product's `currency` equals the Wallet's currency. A mismatch is a
 *     `ValidationException` — the product cannot be offered to a
 *     borrower whose Wallet is denominated in a different currency.
 *
 *   - If the borrower's Wallet does not yet exist, no check is possible.
 *     The loan's `currency` column remains null until disbursement; the
 *     `LoanDisbursementService` (A.30) will re-validate the currency
 *     match against the (by-then-existing) Wallet and throw if the
 *     mismatch persists.
 *
 * This two-stage check ensures that no loan can be disbursed with a
 * currency that does not match its borrower's Wallet.
 *
 * -----------------------------------------------------------------------------
 * Wallet resolution — the repository path, not a relation
 * -----------------------------------------------------------------------------
 * The wallet is resolved through `WalletRepository::findForShareholder()`,
 * the same method every sibling service uses (`LoanDisbursementService`
 * A.30, `LoanRepaymentService` A.31, `LoanInterestAccrualService` A.32,
 * `LoanArrearsService` A.33, `LoanRestructuringService` A.34,
 * `LoanWriteOffService` A.35, `LoanReconciliationService` A.36, and the
 * statement controller A.82).
 *
 * An earlier version of this service attempted to read the wallet through
 * a `wallet` relation on the `Shareholder` model. The `Shareholder` model
 * does not declare that relation, so the property read resolved to null
 * and the currency check silently passed for every request — including
 * the ones the test suite asserts must fail. The repository path used
 * here is the correct resolution mechanism and matches the rest of the
 * domain.
 *
 * =============================================================================
 * Transaction discipline
 * =============================================================================
 * Both operations of this service open their OWN transaction. Unlike
 * `LoanStateMachine` (A.26) and `LoanScheduleService` (A.27), which
 * require a surrounding transaction and perform no I/O beyond their
 * narrow write path, this service is the TOP-LEVEL entry point for its
 * two operations. The controller calls `createDraft()` or `submit()`
 * directly, and there is no outer transaction.
 *
 * `createDraft()` opens a transaction because the loan insert and the
 * initial-history-row insert must commit atomically. If the history
 * insert fails, the loan must roll back.
 *
 * `submit()` opens a transaction because the state machine's
 * `transition()` requires one. The service wraps the call and lets the
 * state machine perform its own writes inside the enclosing transaction.
 *
 * This is the correct layering: the top-level operation owns the
 * transaction; the subordinate operations require one.
 *
 * =============================================================================
 * Events
 * =============================================================================
 * `createDraft()` dispatches NO event. The domain has no `LoanCreated`
 * event: draft creation is a private phase whose audit-relevant follow-up
 * is the submission. The initial history row (written by the state
 * machine's `initialize()`) records the creation fact for the audit
 * trail; no listener needs to act on it.
 *
 * `submit()` dispatches `LoanApplicationSubmitted` (A.38) after the
 * transaction commits, via `LoanStateMachine::transition()`'s
 * `$afterCommitEvent` parameter. The event is constructed HERE (this
 * service has the loan and the actor) and passed to the state machine,
 * which registers the after-commit dispatch.
 *
 * =============================================================================
 * Validation strategy
 * =============================================================================
 * The service performs THREE kinds of validation, in this order:
 *
 *   1. SHAPE-LEVEL VALIDATION — the caller (the HTTP request class,
 *      `StoreLoanRequest`, A.55) has already validated that the inputs
 *      are of the correct shape (integers where integers are expected,
 *      a decimal-string principal, a positive term, etc.). This service
 *      does NOT re-validate shape; it trusts the caller's pre-validation.
 *
 *   2. CROSS-ENTITY VALIDATION — the borrower and product must exist and
 *      belong to the tenant. These checks require I/O; the service
 *      performs them via the repositories. A failed check throws
 *      `ModelNotFoundException` (rendered as 404 by the controller).
 *
 *   3. DOMAIN VALIDATION — the principal and term must fall within the
 *      product's declared bounds. The currency (if the borrower's Wallet
 *      exists) must match the product's currency. The product must be
 *      active. A failed check throws `ValidationException` (rendered as
 *      422 by the controller).
 *
 * The three kinds are performed in this order, from cheapest to most
 * expensive (shape is free, existence is one query, domain is in-memory).
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not support withdrawal. See §"Scope".
 *
 *   - It does not approve, reject, disburse, or perform any post-
 *     submission operation. Those belong to their own services.
 *
 *   - It does not compute the schedule or any of the loan's scheduled
 *     amounts. The schedule is generated at disbursement (or restructure)
 *     time by `LoanScheduleService` (A.27), reading the loan's frozen
 *     policy version.
 *
 *   - It does not evaluate any policy. The product's policy version is
 *     not read here; the frozen policy version is copied onto the loan
 *     at approval time by `LoanApprovalService` (A.29).
 *
 *   - It does not freeze the product's policy version onto the loan. The
 *     freeze happens at approval.
 *
 *   - It does not touch the Wallet ledger. It reads the borrower's Wallet
 *     row for the currency-compatibility check only; it does not write
 *     to the Wallet, and it dispatches no Wallet-side event.
 *
 *   - It does not enforce a per-borrower active-loan limit or any other
 *     portfolio-level constraint. The register does not freeze such a
 *     limit; adding one is a future CR.
 *
 *   - It does not dispatch a `LoanCreated` event. See §"Events".
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D129 (borrower is
 *                                                  shareholder), D130
 *                                                  (product catalogue),
 *                                                  D132 (term structure),
 *                                                  B6 (policy storage)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3 (the
 *                                                  ordering patterns that
 *                                                  this service's
 *                                                  transaction discipline
 *                                                  supports)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.27                                — `LoanScheduleService`
 *   Phase 2C A.30                                — `LoanDisbursementService`
 *                                                  (the sibling whose
 *                                                  Wallet-repository
 *                                                  resolution this
 *                                                  service mirrors)
 *   Phase 2A 2A.12                               — `ShareholderService`
 *                                                  (the tenant-scoped
 *                                                  write-path pattern)
 *   Phase 2B 2B.15                               — `WalletService`
 *                                                  (the same pattern)
 *   Step 5 (manifest) A.28                       — This file
 *   Step 6 v1.1.0 §3.9                           — `loans` table
 *                                                  specification
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Services
 * @version 1.0.1
 */
final class LoanApplicationService
{
    /**
     * Constructor.
     *
     * Four dependencies:
     *
     *   - `LoanStateMachine` — the sole writer of the loan's status and
     *     its lifecycle history. Used for both operations.
     *
     *   - `ShareholderRepository` — tenant-scoped borrower resolution.
     *     Used to validate that the borrower is a shareholder in the
     *     tenant.
     *
     *   - `LoanProductRepository` — tenant-scoped product resolution.
     *     Used to validate that the product exists, is active, and
     *     belongs to the tenant.
     *
     *   - `WalletRepository` — tenant-scoped Wallet resolution. Used to
     *     read the borrower's Wallet for the Q8 currency-compatibility
     *     check. The repository is the same one every sibling service
     *     uses; see the class docblock §"Wallet resolution — the
     *     repository path, not a relation".
     *
     * The service does NOT inject the loan's policy registry. The policy
     * registry is not consulted; see the class docblock §"What this
     * service deliberately does NOT do".
     *
     * @param LoanStateMachine       $stateMachine
     * @param ShareholderRepository  $shareholderRepository
     * @param LoanProductRepository  $productRepository
     * @param WalletRepository       $walletRepository
     */
    public function __construct(
        private readonly LoanStateMachine $stateMachine,
        private readonly ShareholderRepository $shareholderRepository,
        private readonly LoanProductRepository $productRepository,
        private readonly WalletRepository $walletRepository,
    ) {
    }

    // =========================================================================
    // Public API — createDraft
    // =========================================================================
    /**
     * Create a new loan in the `draft` state.
     *
     * The method:
     *
     *   1. Opens a transaction.
     *   2. Resolves the borrower under the tenant scope (`ModelNotFound`
     *      on failure).
     *   3. Resolves the product under the tenant scope (`ModelNotFound`
     *      on failure).
     *   4. Validates that the product is active (`ValidationException` on
     *      failure).
     *   5. Validates that the principal and term fall within the
     *      product's bounds (`ValidationException` on failure).
     *   6. Validates currency compatibility when the borrower's Wallet
     *      exists (`ValidationException` on failure).
     *   7. Inserts the loan row in the `draft` state.
     *   8. Calls `LoanStateMachine::initialize()` to write the initial
     *      history row.
     *   9. Returns the fresh loan.
     *
     * No event is dispatched. See the class docblock §"Events".
     *
     * @param int         $tenantId
     * @param int         $borrowerShareholderId
     * @param int         $loanProductId
     * @param string      $principalAmount       Positive decimal string, at scale 4.
     * @param int         $termMonths            Positive integer.
     * @param string|null $purpose               Optional free text.
     * @param string|null $externalReference     Optional free text.
     * @param int|null    $actorUserId           The user creating the draft, or null.
     * @return Loan  The newly-created loan in `draft` state.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function createDraft(
        int $tenantId,
        int $borrowerShareholderId,
        int $loanProductId,
        string $principalAmount,
        int $termMonths,
        ?string $purpose = null,
        ?string $externalReference = null,
        ?int $actorUserId = null,
    ): Loan {
        return DB::transaction(function () use (
            $tenantId,
            $borrowerShareholderId,
            $loanProductId,
            $principalAmount,
            $termMonths,
            $purpose,
            $externalReference,
            $actorUserId,
        ): Loan {
            // -----------------------------------------------------------------
            // 1. Resolve the borrower and product under the tenant scope.
            // -----------------------------------------------------------------
            $borrower = $this->resolveBorrower($tenantId, $borrowerShareholderId);
            $product  = $this->resolveActiveProduct($tenantId, $loanProductId);

            // -----------------------------------------------------------------
            // 2. Domain validation.
            // -----------------------------------------------------------------
            $this->assertPrincipalWithinBounds($product, $principalAmount);
            $this->assertTermWithinBounds($product, $termMonths);
            $this->assertCurrencyMatchesBorrowerWallet(
                $tenantId,
                $product,
                $borrower,
            );

            // -----------------------------------------------------------------
            // 3. Insert the loan row.
            //
            // `status`, `application_date`, `settled_amount`, and
            // `outstanding_penalty` take their migration-provided defaults
            // (`draft`, CURRENT_TIMESTAMP, 0.0000, 0.0000). The
            // `$fillable` list on `Loan` does not include `status`, so the
            // default applies.
            // -----------------------------------------------------------------
            /** @var Loan $loan */
            $loan = Loan::create([
                'tenant_id'               => $tenantId,
                'borrower_shareholder_id' => $borrower->id,
                'loan_product_id'         => $product->id,
                'principal_amount'        => $principalAmount,
                'term_months'             => $termMonths,
                'purpose'                 => $purpose,
                'external_reference'      => $externalReference,
                'created_by'              => $actorUserId,
            ]);

            // -----------------------------------------------------------------
            // 4. Write the initial history row via the state machine.
            //
            // The state machine asserts it is inside a transaction (it is,
            // by virtue of this enclosing one) and appends a row with
            // from_status = null, to_status = 'draft'. No event is
            // dispatched; see the class docblock §"Events".
            // -----------------------------------------------------------------
            $loan = $this->stateMachine->initialize($loan, $actorUserId);

            return $loan;
        });
    }

    // =========================================================================
    // Public API — submit
    // =========================================================================

    /**
     * Submit a draft loan for review.
     *
     * The method:
     *
     *   1. Opens a transaction.
     *   2. Validates that the loan is in the `draft` state.
     *   3. Calls `LoanStateMachine::transition()` with the target status
     *      `submitted` and a `LoanApplicationSubmitted` event.
     *   4. Returns the refreshed loan.
     *
     * The state machine performs the structural check
     * (`draft → submitted` is legal), acquires the row lock, updates the
     * loan's status, appends the history row, and registers the
     * after-commit event dispatch.
     *
     * The `draft → submitted` transition does NOT require a reason (see
     * the state machine's `REASON_REQUIRED_TRANSITIONS`), so the `$reason`
     * parameter is optional and is usually null.
     *
     * @param Loan        $loan
     * @param string|null $reason       Optional reason (not required for this transition).
     * @param int|null    $actorUserId  The user submitting the loan, or null.
     * @return Loan  The refreshed loan in `submitted` state.
     *
     * @throws ValidationException
     */
    public function submit(
        Loan $loan,
        ?string $reason = null,
        ?int $actorUserId = null,
    ): Loan {
        return DB::transaction(function () use ($loan, $reason, $actorUserId): Loan {
            // -----------------------------------------------------------------
            // 1. Re-read the loan under a lock before the state machine
            //    takes its own lock. This is a defensive check: the state
            //    machine's own lock and structural check are authoritative,
            //    but reading the current status here lets us produce a
            //    clearer error message ("this loan is not in draft state")
            //    when the caller attempts a double submission.
            // -----------------------------------------------------------------
            /** @var Loan $fresh */
            $fresh = Loan::query()
                ->whereKey($loan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status !== LoanStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => [
                        sprintf(
                            'A loan in status "%s" cannot be submitted; '
                            . 'only a loan in "draft" state may be '
                            . 'submitted.',
                            $fresh->status->value,
                        ),
                    ],
                ]);
            }

            // -----------------------------------------------------------------
            // 2. Delegate the state change to the state machine.
            //
            // The state machine will re-lock the row (a no-op inside our
            // transaction), re-validate the structural transition, update
            // the loan's status, append the history row, and register the
            // after-commit event dispatch.
            //
            // The event is constructed HERE, because this service has the
            // loan and the actor. The state machine only registers the
            // dispatch; it does not know the event's class or payload.
            // -----------------------------------------------------------------
            $event = new LoanApplicationSubmitted($fresh, $actorUserId);

            return $this->stateMachine->transition(
                loan: $fresh,
                nextStatus: LoanStatus::Submitted,
                reason: $reason,
                actorUserId: $actorUserId,
                afterCommitEvent: $event,
            );
        });
    }

    // =========================================================================
    // Private — resolution
    // =========================================================================
    /**
     * Resolve the borrower under the tenant scope.
     *
     * A borrower that does not exist OR belongs to another tenant
     * produces a `ModelNotFoundException`, which the controller renders
     * as a 404. The two cases are indistinguishable from the caller's
     * perspective, satisfying the tenant-isolation invariant.
     *
     * @param int $tenantId
     * @param int $shareholderId
     * @return Shareholder
     *
     * @throws ModelNotFoundException
     */
    private function resolveBorrower(int $tenantId, int $shareholderId): Shareholder
    {
        $borrower = $this->shareholderRepository->findForTenant(
            $tenantId,
            $shareholderId,
        );

        if ($borrower === null) {
            throw (new ModelNotFoundException())
                ->setModel(Shareholder::class, [$shareholderId]);
        }

        return $borrower;
    }

    /**
     * Resolve the product under the tenant scope and assert that it is
     * active.
     *
     * A product that does not exist OR belongs to another tenant produces
     * a `ModelNotFoundException` (404). A product that exists and belongs
     * to the tenant but is inactive produces a `ValidationException`
     * (422).
     *
     * The two failure modes are deliberately distinguished: a
     * non-existent product is indistinguishable from a cross-tenant
     * product (404 in both cases); an inactive product is a legitimate
     * request against a legitimate product that the operator has
     * temporarily withdrawn (422 with a clear message).
     *
     * @param int $tenantId
     * @param int $productId
     * @return LoanProduct
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    private function resolveActiveProduct(int $tenantId, int $productId): LoanProduct
    {
        $product = $this->productRepository->findForTenant($tenantId, $productId);

        if ($product === null) {
            throw (new ModelNotFoundException())
                ->setModel(LoanProduct::class, [$productId]);
        }

        if (!$product->isActive()) {
            throw ValidationException::withMessages([
                'loan_product_id' => [
                    sprintf(
                        'The loan product "%s" is not currently offered '
                        . 'for new applications.',
                        $product->code,
                    ),
                ],
            ]);
        }

        return $product;
    }

    // =========================================================================
    // Private — domain validation
    // =========================================================================

    /**
     * Assert that the principal falls within the product's declared
     * bounds.
     *
     * Uses `LoanProduct::isPrincipalWithinBounds()` (A.17), which is the
     * single source of truth for the check. The `$principalAmount`
     * parameter is a decimal string at scale 4; the comparison uses
     * bcmath internally.
     *
     * @param LoanProduct $product
     * @param string      $principalAmount
     * @return void
     *
     * @throws ValidationException
     */
    private function assertPrincipalWithinBounds(
        LoanProduct $product,
        string $principalAmount,
    ): void {
        if ($product->isPrincipalWithinBounds($principalAmount)) {
            return;
        }

        throw ValidationException::withMessages([
            'principal_amount' => [
                sprintf(
                    'The requested principal (%s %s) is outside the '
                    . 'product\'s permitted range [%s, %s].',
                    $principalAmount,
                    $product->currency,
                    (string) $product->min_principal,
                    (string) $product->max_principal,
                ),
            ],
        ]);
    }

    /**
     * Assert that the term falls within the product's declared bounds.
     *
     * Uses `LoanProduct::isTermWithinBounds()` (A.17), which is the
     * single source of truth for the check.
     *
     * @param LoanProduct $product
     * @param int         $termMonths
     * @return void
     *
     * @throws ValidationException
     */
    private function assertTermWithinBounds(
        LoanProduct $product,
        int $termMonths,
    ): void {
        if ($product->isTermWithinBounds($termMonths)) {
            return;
        }

        throw ValidationException::withMessages([
            'term_months' => [
                sprintf(
                    'The requested term (%d months) is outside the '
                    . 'product\'s permitted range [%d, %d] months.',
                    $termMonths,
                    $product->min_term_months,
                    $product->max_term_months,
                ),
            ],
        ]);
    }

    /**
     * Assert that the product's currency matches the borrower's Wallet
     * currency, when the borrower's Wallet exists.
     *
     * Per the Q8 single-currency invariant (Phase 2B), a loan's currency
     * must equal the borrower's Wallet currency. At draft-creation time:
     *
     *   - If the borrower has a Wallet, the product's currency must match
     *     the Wallet's currency. A mismatch throws.
     *
     *   - If the borrower has no Wallet, no check is possible. The check
     *     is deferred to disbursement time, when the Wallet must exist.
     *
     * The Wallet is resolved via `WalletRepository::findForShareholder()`,
     * the same path every sibling service uses. See the class docblock
     * §"Wallet resolution — the repository path, not a relation" for the
     * rationale.
     *
     * @param int         $tenantId
     * @param LoanProduct $product
     * @param Shareholder $borrower
     * @return void
     *
     * @throws ValidationException
     */
    private function assertCurrencyMatchesBorrowerWallet(
        int $tenantId,
        LoanProduct $product,
        Shareholder $borrower,
    ): void {
        $wallet = $this->walletRepository->findForShareholder(
            $tenantId,
            (int) $borrower->id,
        );

        if ($wallet === null) {
            // No Wallet yet; currency compatibility cannot be checked.
            // The disbursement service will re-validate.
            return;
        }

        if ($product->currency === $wallet->currency) {
            return;
        }

        throw ValidationException::withMessages([
            'loan_product_id' => [
                sprintf(
                    'The product is denominated in %s, but the borrower\'s '
                    . 'Wallet is denominated in %s. A loan must match the '
                    . 'borrower\'s Wallet currency.',
                    $product->currency,
                    $wallet->currency,
                ),
            ],
        ]);
    }
}