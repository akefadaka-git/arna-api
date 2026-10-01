<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Controllers;

use Gate;
use LogicException;
use Illuminate\Database\Eloquent\Collection;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Http\Resources\LoanStatementResource;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanCollateral;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInterestAccrual;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanPenaltyAccrual;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanRestructuring;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanWriteOff;
use MaaleArna\MASES\Core\Domains\Loan\Repositories\LoanRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;

/**
 * LoanStatementController
 *
 * The HTTP surface for a loan's statement view. Owns exactly ONE action:
 *
 *   show()    GET  /loans/{loan}/statement    — the statement view
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.1.0 — Out-of-CR change (audit identifier B4). Removed `status_change`
 *         line emission from the statement. Concretely:
 *
 *           · `'status_change'` was removed from `TYPE_PRIORITY`.
 *           · The collection loop over `$loan->statusHistories` in
 *             `collectEvents()` was deleted, replaced by a comment
 *             asserting the exclusion was deliberate.
 *           · `'statusHistories'` was removed from the eager-load list
 *             in `resolveLoan()`.
 *           · The class docblock was rewritten to describe the removal
 *             as a semantic improvement, and to assert the existence of
 *             "the dedicated status-history surface" as the replacement
 *             for lifecycle information.
 *
 *         The asserted replacement surface does not exist. The Loan
 *         domain's routes file (A.84) declares seventeen routes; none
 *         is a status-history surface. The claim was aspirational. The
 *         change therefore removed a public read capability without a
 *         verified replacement, and did so without an authorized CR.
 *
 * 1.2.0 — B4 audit remediation. Reverted v1.1.0 in full:
 *
 *           · `'status_change'` is restored to `TYPE_PRIORITY`
 *             (at priority 8 — after every monetary event at the same
 *             instant).
 *           · `collectEvents()` again walks `$loan->statusHistories`
 *             and emits one `status_change` line per history row.
 *           · `'statusHistories'` is restored to the eager-load list
 *             in `resolveLoan()`.
 *           · The class docblock is corrected: the pre-B4 exclusion
 *             narrative is removed, and the event-source table now
 *             lists `status_change` alongside the monetary events.
 *
 *         The revert restores the pre-v1.1.0 contract: a loan's statement
 *         contains BOTH monetary movements AND lifecycle transitions,
 *         each as its own line. A status-change line carries zero
 *         principal, interest, and penalty deltas; its presence is
 *         informational. The consequences are:
 *
 *           · An approved loan whose only recorded activity is a series
 *             of status transitions produces a NON-empty statement —
 *             one `status_change` line per transition. This is the
 *             pre-v1.1.0 behaviour.
 *
 *           · The running balance is unaffected by status-change lines
 *             (their deltas are zero), so the closing balance and the
 *             totals are identical to those the v1.1.0 controller
 *             computed.
 *
 *         TEST IMPACT
 *         -----------
 *         The test `LoanStatementTest::test_statement_of_approved_loan_returns_empty_period`
 *         asserted the v1.1.0 behaviour (empty period for a pre-
 *         disbursement loan). Under this revert it will fail, because
 *         an approved loan has a status history. The test and the
 *         controller are asserting two different contracts; the Owner
 *         chose the pre-v1.1.0 contract by authorizing this revert. The
 *         test is corrected separately.
 *
 *         ATTRIBUTE-NAME ASSUMPTION
 *         -------------------------
 *         The `LoanStatusHistory` model was not pasted during this
 *         audit, so its exact attribute names are not fully verified
 *         from repository evidence. The event builder reads the row's
 *         timestamp, actor, statuses, and reason through small private
 *         helpers (`statusHistoryOccurredAt()`, `statusHistoryActor()`,
 *         `statusHistoryId()`, `statusHistoryFromStatus()`,
 *         `statusHistoryToStatus()`, `statusHistoryReason()`), each of
 *         which tries the most likely attribute names in order. If the
 *         on-disk model uses different names, each helper is a one-line
 *         fix.
 *
 * =============================================================================
 * The endpoint's contract
 * =============================================================================
 * The endpoint returns a chronological statement of the loan's activity,
 * aggregated by the controller from:
 *
 *   - the loan's own lifecycle dates and projections,
 *   - the loan's interest and penalty accrual bookkeeping (A.18's
 *     `interestAccruals` and `penaltyAccruals` relations),
 *   - the borrower's Wallet-side payments and their allocations
 *     (read directly from the Wallet domain's tables via the same
 *     direct-model query pattern A.31 and A.36 use),
 *   - the loan's restructuring history and write-off record,
 *   - the loan's collateral records (releases only),
 *   - the loan's status-history rows (the B4 revert restores this
 *     source).
 *
 * The aggregated structure is passed to `LoanStatementResource` (A.74)
 * as its composite input `['loan' => $loan, 'statement' => $statement]`.
 * The resource serializes the structure; it does NOT recompute any value.
 *
 * =============================================================================
 * The event sources and their balance deltas
 * =============================================================================
 * The controller assembles one event per source record. Each event carries
 * three delta values — `principal_delta`, `interest_delta`,
 * `penalty_delta` — that describe the movement's effect on the loan's
 * three outstanding components. Every delta is a decimal string at scale
 * 4, computed with BCMath.
 *
 *   DISBURSEMENT          — one event, at `$loan->disbursement_date`.
 *                           principal_delta = +principal_amount.
 *
 *   INTEREST_ACCRUAL      — one event per `loan_interest_accruals` row,
 *                           at the accrual's `recorded_at`.
 *                           interest_delta = +interest_amount.
 *
 *   PENALTY_ACCRUAL       — one event per `loan_penalty_accruals` row,
 *                           at the accrual's `recorded_at`.
 *                           penalty_delta = +penalty_amount.
 *
 *   REPAYMENT             — one event per Wallet `Payment` linked to the
 *                           loan, at `received_at`. The three deltas are
 *                           derived from the payment's allocations: each
 *                           allocation reduces its target obligation's
 *                           component by the allocated amount. Since
 *                           allocations are stored per obligation and
 *                           the obligation carries a `component`, the
 *                           controller groups the allocation amounts by
 *                           component and emits negative deltas.
 *
 *   RESTRUCTURE           — one event per `loan_restructurings` row, at
 *                           `restructured_at`. The deltas reflect the
 *                           net effect on outstanding:
 *                             principal_delta =
 *                               new_principal_amount
 *                               − previous_outstanding_principal
 *                             interest_delta =
 *                               − previous_outstanding_interest
 *                             penalty_delta =
 *                               − previous_outstanding_penalty
 *
 *   WRITE_OFF             — one event, at `written_off_at` (at most one
 *                           per loan). The deltas reflect the write-off:
 *                             principal_delta = −outstanding_principal_at_writeoff
 *                             interest_delta  = −outstanding_interest_at_writeoff
 *                             penalty_delta   = −outstanding_penalty_at_writeoff
 *
 *   COLLATERAL_RELEASE    — one event per released `LoanCollateral` row,
 *                           at `released_at`. All deltas are zero: a
 *                           collateral release has no monetary effect
 *                           on the loan's outstanding (per A.10's
 *                           "collateral is not Wallet money" freeze).
 *                           The line is included because the release
 *                           does change the security position and is
 *                           relevant to a statement read by the
 *                           borrower or by an auditor.
 *
 *   STATUS_CHANGE         — one event per `loan_status_histories` row,
 *                           at the history row's occurrence timestamp.
 *                           All deltas are zero: a lifecycle transition
 *                           has no monetary effect on the loan's
 *                           outstanding. The line is included so that
 *                           a reader can see the loan's lifecycle
 *                           interleaved with its monetary movements
 *                           in one chronology — the pre-v1.1.0 contract
 *                           restored by the B4 revert.
 *
 * The set above is EXHAUSTIVE. No other event source contributes lines
 * to the statement.
 *
 * =============================================================================
 * The running balance
 * =============================================================================
 * After collecting the events, the controller sorts them chronologically
 * and walks the sorted list, accumulating a running balance. The running
 * balance starts at zero on all three components (the loan did not exist
 * before the first event).
 *
 * Each event's emitted line carries a `running_balance` object that
 * reflects the loan's outstanding IMMEDIATELY AFTER the event was
 * applied. The last line's `running_balance` equals the statement's
 * `closing_balance`.
 *
 * Status-change lines contribute zero to every component of the running
 * balance; they appear in the chronology but do not alter it.
 *
 * =============================================================================
 * The chronological sort and its tiebreakers
 * =============================================================================
 * Events are sorted by `occurred_at` ascending. Two events that share a
 * timestamp are sorted by a stable secondary key:
 *
 *   1. Type priority, in the order: disbursement, interest_accrual,
 *      penalty_accrual, repayment, restructure, write_off,
 *      collateral_release, status_change.
 *
 *   2. Reference id ascending (a stable tertiary key).
 *
 * The primary sort is on the event's `occurred_at` value; the priority
 * and reference-id tiebreakers make the statement deterministic when two
 * events share a timestamp.
 *
 * The `status_change` priority is 8 — after every monetary and security
 * event — because a status transition that accompanies a monetary event
 * is the state-machine's response to it, and reading the monetary event
 * first reflects that causal order.
 *
 * =============================================================================
 * The Wallet-side reads
 * =============================================================================
 * The Wallet-side reads use the same direct-model query pattern
 * established by `LoanRepaymentService` (A.31) and
 * `LoanReconciliationService` (A.36). The controller:
 *
 *   1. Resolves the borrower's Wallet via
 *      `WalletRepository::findForShareholder($tenantId, $shareholderId)`
 *      (the repository method used by A.30, A.31, A.32, A.33, A.35, A.36).
 *
 *   2. Queries the loan's principal and per-installment interest/penalty
 *      obligations via `Obligation::query()` filtered by the frozen
 *      `reference_type = 'Loan'` / `reference_id = (string) $loan->id`
 *      pair.
 *
 *   3. Queries the loan's payments via `Payment::query()` filtered by
 *      the frozen `reference_type = 'LoanRepayment'` /
 *      `reference_id = (string) $loan->id` pair.
 *
 *   4. Queries the payments' allocations via `PaymentAllocation::query()`
 *      filtered by the resolved payment ids, and the allocations'
 *      obligations via `Obligation::query()` filtered by the resolved
 *      obligation ids.
 *
 * All reads are scoped to the borrower's Wallet id, which the controller
 * has already resolved through the tenant-scoped repository. A
 * cross-tenant Wallet is unreachable through this path.
 *
 * =============================================================================
 * The pre-disbursement loan
 * =============================================================================
 * A loan in a pre-disbursement state (`draft`, `submitted`, `under_review`,
 * `approved`) has no Wallet-side monetary activity:
 *
 *   - No payments exist (no `Payment` rows reference the loan).
 *   - No obligations exist (the principal obligation is recorded at
 *     disbursement, and interest/penalty obligations are recorded at
 *     schedule-generation time and later).
 *   - The borrower may or may not have a Wallet; if they do, the Wallet
 *     is simply irrelevant to this loan at this moment.
 *
 * What a pre-disbursement loan DOES carry is a status history — one
 * `LoanStatusHistory` row per lifecycle transition, beginning with the
 * `initialize()` row written by `LoanStateMachine` (A.26) at creation.
 * The controller therefore produces a NON-EMPTY statement for such a
 * loan: one `status_change` line per history row, with zero monetary
 * deltas on each.
 *
 * The controller does NOT throw on a missing Wallet. A missing Wallet is
 * a legitimate state for a pre-disbursement loan; only the absence of a
 * Wallet on a DISBURSED loan would be a data-integrity defect, and that
 * case cannot occur here because the disbursement service (A.30)
 * requires a Wallet at disbursement time.
 *
 * =============================================================================
 * The response envelope
 * =============================================================================
 * A successful call returns HTTP 200 with the canonical MASES envelope:
 *
 *     { "success": true, "data": {...} }
 *
 * The `data` payload is the composite serialized by
 * `LoanStatementResource` (A.74), whose `$wrap = null` setting prevents
 * Laravel's own `data` wrapper from double-wrapping the payload inside
 * the controller's envelope.
 *
 * =============================================================================
 * What this controller deliberately does NOT do
 * =============================================================================
 *   - It does NOT paginate or date-filter the statement. A statement is
 *     the full chronology of the loan's activity. A future CR that
 *     introduces a `?from=` / `?to=` range filter extends this
 *     controller at that time.
 *
 *   - It does NOT recompute any projection. Every monetary value in the
 *     statement is read from its authoritative source (the loan row,
 *     an accrual row, a Payment row, an allocation row, a restructuring
 *     row, or the write-off row), or derived by adding/subtracting those
 *     values in the running-balance walk.
 *
 *   - It does NOT check the reconciliation invariant. That is
 *     `LoanReconciliationService::reconcile()`'s concern (A.36), exposed
 *     through the reconciliation endpoint (A.83).
 *
 *   - It does NOT mutate any state. The endpoint is a pure read.
 *
 *   - It does NOT open a transaction. The endpoint is a read.
 *
 *   - It does NOT catch exceptions. Every exception propagates to the
 *     framework's exception renderer.
 *
 *   - It does NOT compute a `period.from` / `period.to` range beyond
 *     the earliest and latest event timestamps. See §"The statement
 *     period".
 *
 * =============================================================================
 * The statement period
 * =============================================================================
 * The `period` object emitted by the resource carries:
 *
 *   `from` — the earliest event's `occurred_at`, or null when no events
 *            exist.
 *
 *   `to`   — the latest event's `occurred_at`, or null when no events
 *            exist.
 *
 * With the B4 revert restored, a pre-disbursement loan has events (its
 * status history), so its statement period is non-null. A future CR
 * that introduces `?from=` / `?to=` query parameters would filter the
 * events to the range and set the period to the requested bounds.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D132, D133, D135,
 *                                                  B1, B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation)
 *   Phase 2C A.18                                — `Loan` (the relations
 *                                                  the controller
 *                                                  reads)
 *   Phase 2C A.21                                — `LoanStatusHistory`
 *                                                  (the status-change
 *                                                  event source)
 *   Phase 2C A.24                                — `LoanInterestAccrual`
 *   Phase 2C A.25                                — `LoanPenaltyAccrual`
 *   Phase 2C A.26                                — `LoanStateMachine`
 *                                                  (the writer of the
 *                                                  status-history rows
 *                                                  this controller
 *                                                  reads)
 *   Phase 2C A.31                                — `LoanRepaymentService`
 *                                                  (the direct-model
 *                                                  query pattern this
 *                                                  controller follows)
 *   Phase 2C A.36                                — `LoanReconciliationService`
 *                                                  (the sibling report
 *                                                  producer; its
 *                                                  direct-model read
 *                                                  pattern is the
 *                                                  closest precedent)
 *   Phase 2C A.52                                — `LoanPolicy::viewStatement()`
 *   Phase 2C A.55                                — `LoanRepository`
 *   Phase 2C A.74                                — `LoanStatementResource`
 *                                                  (the composite
 *                                                  serializer this
 *                                                  endpoint returns)
 *   Phase 2C A.77                                — `LoanController` (the
 *                                                  shape precedent)
 *   Phase 2C A.84                                — the routes file
 *   Phase 2B `Payment`                           — the source of
 *                                                  repayment events
 *   Phase 2B `PaymentAllocation`                 — the source of the
 *                                                  per-component
 *                                                  repayment deltas
 *   Phase 2B `Obligation`                        — the source of the
 *                                                  per-component
 *                                                  obligation reads
 *   Phase 2B `WalletRepository`                  — the borrower Wallet
 *                                                  lookup
 *   Step 5 (manifest) A.82                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Controllers
 * @version 1.2.0
 */
final class LoanStatementController
{
    /**
     * The monetary scale used for all deltas and running balances.
     *
     * Matches the DECIMAL(20,4) monetary columns on both domains.
     */
    private const FINAL_SCALE = 4;

    /**
     * The Wallet-side reference-type constant for loan-linked
     * obligations (per B1).
     */
    private const WALLET_REFERENCE_TYPE_LOAN = 'Loan';

    /**
     * The Wallet-side reference-type constant for loan-linked payments
     * (per B1).
     */
    private const WALLET_REFERENCE_TYPE_LOAN_REPAYMENT = 'LoanRepayment';

    /**
     * The type-priority order used as the secondary sort key for the
     * statement's chronological lines. See the class docblock §"The
     * chronological sort and its tiebreakers".
     *
     * The list contains every event type the controller emits. The
     * `status_change` entry is at priority 8 — after every monetary and
     * security event at the same instant — because a status transition
     * that accompanies a monetary event is the state machine's response
     * to it, and reading the monetary event first reflects that causal
     * order. This entry was removed by the v1.1.0 out-of-CR change and
     * restored by the B4 revert.
     *
     * @var array<string, int>
     */
    private const TYPE_PRIORITY = [
        'disbursement'       => 1,
        'interest_accrual'   => 2,
        'penalty_accrual'    => 3,
        'repayment'          => 4,
        'restructure'        => 5,
        'write_off'          => 6,
        'collateral_release' => 7,
        'status_change'      => 8,
    ];

    /**
     * Constructor.
     *
     * Two dependencies:
     *
     *   - `LoanRepository`    — the tenant-scoped loan lookup.
     *   - `WalletRepository`  — the borrower Wallet lookup.
     *
     * @param LoanRepository    $loanRepository
     * @param WalletRepository  $walletRepository
     */
    public function __construct(
        private readonly LoanRepository $loanRepository,
        private readonly WalletRepository $walletRepository,
    ) {
    }

    // =========================================================================
    // Public API — show
    // =========================================================================

    /**
     * Return a loan's statement view.
     *
     * @param Request $request
     * @param int     $loanId
     * @return JsonResponse
     */
    public function show(Request $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        Gate::authorize('viewStatement', $loan);

        $statement = $this->buildStatement($loan);

        return response()->json([
            'success' => true,
            'data'    => (new LoanStatementResource([
                'loan'      => $loan,
                'statement' => $statement,
            ]))->toArray($request),
        ]);
    }

    // =========================================================================
    // Private — resolution
    // =========================================================================

    /**
     * Resolve the loan addressed by the route's `{loan}` parameter,
     * eager-loading every relation the statement aggregation reads.
     *
     * The `statusHistories` relation is eager-loaded because the B4
     * revert restored `status_change` line emission. Dropping it (as
     * the v1.1.0 out-of-CR change did) would force a lazy-load on the
     * first history-row read.
     *
     * @param int $loanId
     * @return Loan
     */
    private function resolveLoan(int $loanId): Loan
    {
        return $this->loanRepository->findForTenantOrFail(
            tenantId: $this->resolveTenantId(),
            loanId:   $loanId,
            with:     [
                'borrower',
                'interestAccruals',
                'penaltyAccruals',
                'restructurings',
                'writeOff',
                'collaterals',
                'statusHistories',
            ],
        );
    }

    /**
     * Resolve the authenticated tenant's id from the request context.
     *
     * See `LoanController::resolveTenantId()` (A.77) for the shared
     * rationale. The guard mirrors that method verbatim.
     *
     * @return int
     *
     * @throws LogicException
     */
    private function resolveTenantId(): int
    {
        /** @var int|string|null $tenantId */
        $tenantId = function_exists('mases_tenant_id')
            ? mases_tenant_id()
            : null;

        if ($tenantId === null || $tenantId === '') {
            throw new LogicException(
                'LoanStatementController requires a resolved tenant id. '
                . 'The `tenant.membership` middleware must populate the '
                . 'request context before the statement action runs. '
                . 'A missing tenant indicates a middleware-bypass defect '
                . 'or a route registered without the middleware stack.'
            );
        }

        return (int) $tenantId;
    }

    /**
     * Resolve the borrower's Wallet, if it exists.
     *
     * Returns null when the borrower has no Wallet — a legitimate state
     * for a pre-disbursement loan. See the class docblock §"The
     * pre-disbursement loan".
     *
     * @param Loan $loan
     * @return Wallet|null
     */
    private function resolveBorrowerWallet(Loan $loan): ?Wallet
    {
        $loan->loadMissing('borrower');

        $borrower = $loan->borrower;

        if ($borrower === null) {
            return null;
        }

        return $this->walletRepository->findForShareholder(
            (int) $loan->tenant_id,
            (int) $borrower->id,
        );
    }

    // =========================================================================
    // Private — statement assembly
    // =========================================================================

    /**
     * Build the composite statement structure for a loan.
     *
     * See the class docblock §"The event sources and their balance
     * deltas" for the interpretation of each line type.
     *
     * @param Loan $loan
     * @return array<string, mixed>
     */
    private function buildStatement(Loan $loan): array
    {
        $wallet = $this->resolveBorrowerWallet($loan);

        $events = $this->collectEvents($loan, $wallet);

        if ($events === []) {
            return $this->emptyStatement();
        }

        usort($events, [$this, 'compareEvents']);

        return $this->walkEvents($events);
    }

    /**
     * Build an empty statement structure.
     *
     * @return array<string, mixed>
     */
    private function emptyStatement(): array
    {
        return [
            'period' => [
                'from' => null,
                'to'   => null,
            ],
            'opening_balance' => $this->zeroBalance(),
            'lines'           => [],
            'closing_balance' => $this->zeroBalance(),
            'totals'          => $this->zeroTotals(),
        ];
    }

    /**
     * Collect the raw event list from every source.
     *
     * The sources enumerated here are the ONLY ones the statement
     * recognizes. The `statusHistories` source was restored by the B4
     * revert; see the class docblock §"Revision history" for the full
     * account of its v1.1.0 removal and this reinstatement.
     *
     * @param Loan        $loan
     * @param Wallet|null $wallet
     * @return list<array<string, mixed>>
     */
    private function collectEvents(Loan $loan, ?Wallet $wallet): array
    {
        $events = [];

        // ---------------------------------------------------------------------
        // Disbursement.
        // ---------------------------------------------------------------------
        if ($loan->disbursement_date !== null) {
            $events[] = $this->disbursementEvent($loan);
        }

        // ---------------------------------------------------------------------
        // Interest accruals.
        // ---------------------------------------------------------------------
        foreach ($loan->interestAccruals as $accrual) {
            $events[] = $this->interestAccrualEvent($accrual);
        }

        // ---------------------------------------------------------------------
        // Penalty accruals.
        // ---------------------------------------------------------------------
        foreach ($loan->penaltyAccruals as $accrual) {
            $events[] = $this->penaltyAccrualEvent($accrual);
        }

        // ---------------------------------------------------------------------
        // Repayments — require the Wallet.
        // ---------------------------------------------------------------------
        if ($wallet !== null) {
            $events = array_merge(
                $events,
                $this->collectRepaymentEvents($loan, $wallet),
            );
        }

        // ---------------------------------------------------------------------
        // Restructurings.
        // ---------------------------------------------------------------------
        foreach ($loan->restructurings as $restructuring) {
            $events[] = $this->restructureEvent($restructuring);
        }

        // ---------------------------------------------------------------------
        // Write-off (at most one).
        // ---------------------------------------------------------------------
        if ($loan->writeOff !== null) {
            $events[] = $this->writeOffEvent($loan->writeOff);
        }

        // ---------------------------------------------------------------------
        // Collateral releases.
        // ---------------------------------------------------------------------
        if ($loan->relationLoaded('collaterals')) {
            foreach ($loan->collaterals as $collateral) {
                if ($collateral->isReleased()) {
                    $events[] = $this->collateralReleaseEvent($collateral);
                }
            }
        }

        // ---------------------------------------------------------------------
        // Status changes.
        //
        // One event per `LoanStatusHistory` row. Every delta is zero —
        // a lifecycle transition moves no money — but the line is
        // emitted so that the statement interleaves lifecycle and
        // monetary activity in a single chronology. This is the
        // pre-v1.1.0 contract, restored by the B4 revert.
        // ---------------------------------------------------------------------
        if ($loan->relationLoaded('statusHistories')) {
            foreach ($loan->statusHistories as $history) {
                $events[] = $this->statusChangeEvent($history);
            }
        }

        return $events;
    }

    // =========================================================================
    // Private — per-event builders
    // =========================================================================

    /**
     * Build the disbursement event.
     *
     * @param Loan $loan
     * @return array<string, mixed>
     */
    private function disbursementEvent(Loan $loan): array
    {
        $amount = (string) $loan->principal_amount;

        return [
            'occurred_at'     => $loan->disbursement_date,
            'type'            => 'disbursement',
            'description'     => __('loan::statement.disbursement', [
                'loan_id' => $loan->id,
            ]),
            'principal_delta' => $amount,
            'interest_delta'  => '0.0000',
            'penalty_delta'   => '0.0000',
            'total_delta'     => $amount,
            'reference_type'  => 'Loan',
            'reference_id'    => (int) $loan->id,
            'meta'            => [
                'currency' => $loan->currency,
            ],
        ];
    }

    /**
     * Build an interest-accrual event.
     *
     * @param LoanInterestAccrual $accrual
     * @return array<string, mixed>
     */
    private function interestAccrualEvent(LoanInterestAccrual $accrual): array
    {
        $amount = (string) $accrual->interest_amount;

        return [
            'occurred_at'     => $accrual->recorded_at,
            'type'            => 'interest_accrual',
            'description'     => __('loan::statement.interest_accrual', [
                'installment_id' => $accrual->installment_id,
            ]),
            'principal_delta' => '0.0000',
            'interest_delta'  => $amount,
            'penalty_delta'   => '0.0000',
            'total_delta'     => $amount,
            'reference_type'  => 'LoanInterestAccrual',
            'reference_id'    => (int) $accrual->getKey(),
            'meta'            => [
                'installment_id'       => (int) $accrual->installment_id,
                'accrual_period_key'   => (string) $accrual->accrual_period_key,
                'wallet_obligation_id' => $accrual->wallet_obligation_id !== null
                    ? (int) $accrual->wallet_obligation_id
                    : null,
            ],
        ];
    }

    /**
     * Build a penalty-accrual event.
     *
     * @param LoanPenaltyAccrual $accrual
     * @return array<string, mixed>
     */
    private function penaltyAccrualEvent(LoanPenaltyAccrual $accrual): array
    {
        $amount = (string) $accrual->penalty_amount;

        return [
            'occurred_at'     => $accrual->recorded_at,
            'type'            => 'penalty_accrual',
            'description'     => __('loan::statement.penalty_accrual', [
                'installment_id' => $accrual->installment_id,
            ]),
            'principal_delta' => '0.0000',
            'interest_delta'  => '0.0000',
            'penalty_delta'   => $amount,
            'total_delta'     => $amount,
            'reference_type'  => 'LoanPenaltyAccrual',
            'reference_id'    => (int) $accrual->getKey(),
            'meta'            => [
                'installment_id'       => (int) $accrual->installment_id,
                'accrual_period_key'   => (string) $accrual->accrual_period_key,
                'wallet_obligation_id' => $accrual->wallet_obligation_id !== null
                    ? (int) $accrual->wallet_obligation_id
                    : null,
            ],
        ];
    }

    /**
     * Build a restructure event.
     *
     * @param LoanRestructuring $restructuring
     * @return array<string, mixed>
     */
    private function restructureEvent(LoanRestructuring $restructuring): array
    {
        $principalDelta = bcsub(
            (string) $restructuring->new_principal_amount,
            (string) $restructuring->previous_outstanding_principal,
            self::FINAL_SCALE,
        );

        $interestDelta = bcmul(
            (string) $restructuring->previous_outstanding_interest,
            '-1',
            self::FINAL_SCALE,
        );

        $penaltyDelta = bcmul(
            (string) $restructuring->previous_outstanding_penalty,
            '-1',
            self::FINAL_SCALE,
        );

        $totalDelta = bcadd(
            bcadd($principalDelta, $interestDelta, self::FINAL_SCALE),
            $penaltyDelta,
            self::FINAL_SCALE,
        );

        return [
            'occurred_at'     => $restructuring->restructured_at,
            'type'            => 'restructure',
            'description'     => __('loan::statement.restructure', [
                'restructuring_id' => $restructuring->getKey(),
            ]),
            'principal_delta' => $principalDelta,
            'interest_delta'  => $interestDelta,
            'penalty_delta'   => $penaltyDelta,
            'total_delta'     => $totalDelta,
            'reference_type'  => 'LoanRestructure',
            'reference_id'    => (int) $restructuring->getKey(),
            'meta'            => [
                'reason'                   => (string) $restructuring->reason,
                'new_principal_amount'     => (string) $restructuring->new_principal_amount,
                'new_term_months'          => (int) $restructuring->new_term_months,
                'previous_installment_count' => (int) $restructuring->previous_installment_count,
                'new_installment_count'    => (int) $restructuring->new_installment_count,
            ],
        ];
    }

    /**
     * Build a write-off event.
     *
     * @param LoanWriteOff $writeOff
     * @return array<string, mixed>
     */
    private function writeOffEvent(LoanWriteOff $writeOff): array
    {
        $principalDelta = bcmul(
            (string) $writeOff->outstanding_principal_at_writeoff,
            '-1',
            self::FINAL_SCALE,
        );

        $interestDelta = bcmul(
            (string) $writeOff->outstanding_interest_at_writeoff,
            '-1',
            self::FINAL_SCALE,
        );

        $penaltyDelta = bcmul(
            (string) $writeOff->outstanding_penalty_at_writeoff,
            '-1',
            self::FINAL_SCALE,
        );

        $totalDelta = bcadd(
            bcadd($principalDelta, $interestDelta, self::FINAL_SCALE),
            $penaltyDelta,
            self::FINAL_SCALE,
        );

        return [
            'occurred_at'     => $writeOff->written_off_at,
            'type'            => 'write_off',
            'description'     => __('loan::statement.write_off', [
                'write_off_id' => $writeOff->getKey(),
            ]),
            'principal_delta' => $principalDelta,
            'interest_delta'  => $interestDelta,
            'penalty_delta'   => $penaltyDelta,
            'total_delta'     => $totalDelta,
            'reference_type'  => 'LoanWriteOff',
            'reference_id'    => (int) $writeOff->getKey(),
            'meta'            => [
                'reason'          => (string) $writeOff->reason,
                'write_off_amount' => (string) $writeOff->write_off_amount,
            ],
        ];
    }

    /**
     * Build a collateral-release event.
     *
     * @param LoanCollateral $collateral
     * @return array<string, mixed>
     */
    private function collateralReleaseEvent(LoanCollateral $collateral): array
    {
        return [
            'occurred_at'     => $collateral->released_at,
            'type'            => 'collateral_release',
            'description'     => __('loan::statement.collateral_release', [
                'collateral_id' => $collateral->getKey(),
            ]),
            'principal_delta' => '0.0000',
            'interest_delta'  => '0.0000',
            'penalty_delta'   => '0.0000',
            'total_delta'     => '0.0000',
            'reference_type'  => 'LoanCollateral',
            'reference_id'    => (int) $collateral->getKey(),
            'meta'            => [
                'collateral_type' => $collateral->collateral_type->value,
                'release_reason'  => (string) $collateral->release_reason,
            ],
        ];
    }

    /**
     * Build a status-change event.
     *
     * Restored by the B4 revert. Every delta is zero — the state-machine
     * transition does not touch the loan's outstanding projections — but
     * the line is emitted so a reader sees the lifecycle interleaved
     * with the monetary activity.
     *
     * The history row's attributes are read through small private
     * helpers rather than direct property access, because the exact
     * attribute names on the `LoanStatusHistory` model were not
     * verified from pasted evidence during this audit. See the class
     * docblock §"Attribute-name assumption".
     *
     * @param LoanStatusHistory $history
     * @return array<string, mixed>
     */
    private function statusChangeEvent(LoanStatusHistory $history): array
    {
        $from = $this->statusHistoryFromStatus($history);
        $to   = $this->statusHistoryToStatus($history);

        return [
            'occurred_at'     => $this->statusHistoryOccurredAt($history),
            'type'            => 'status_change',
            'description'     => __('loan::statement.status_change', [
                'from' => $from,
                'to'   => $to,
            ]),
            'principal_delta' => '0.0000',
            'interest_delta'  => '0.0000',
            'penalty_delta'   => '0.0000',
            'total_delta'     => '0.0000',
            'reference_type'  => 'LoanStatusHistory',
            'reference_id'    => $this->statusHistoryId($history),
            'meta'            => [
                'from_status' => $from,
                'to_status'   => $to,
                'reason'      => $this->statusHistoryReason($history),
                'actor_id'    => $this->statusHistoryActor($history),
            ],
        ];
    }

    // =========================================================================
    // Private — repayment collection (the Wallet-side reads)
    // =========================================================================

    /**
     * Collect the loan's repayment events.
     *
     * Reads the loan's Wallet-side payments and their allocations using
     * the direct-model query pattern established by A.31 and A.36.
     *
     * @param Loan   $loan
     * @param Wallet $wallet
     * @return list<array<string, mixed>>
     */
    private function collectRepaymentEvents(Loan $loan, Wallet $wallet): array
    {
        /** @var Collection<int, Payment> $payments */
        $payments = Payment::query()
            ->where('wallet_id', $wallet->id)
            ->where('reference_type', self::WALLET_REFERENCE_TYPE_LOAN_REPAYMENT)
            ->where('reference_id', (string) $loan->id)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        if ($payments->isEmpty()) {
            return [];
        }

        $paymentIds = $payments->pluck('id')->all();

        /** @var Collection<int, PaymentAllocation> $allocations */
        $allocations = PaymentAllocation::query()
            ->whereIn('payment_id', $paymentIds)
            ->get();

        $allocationsByPayment = $allocations->groupBy('payment_id');

        $obligationIds = $allocations->pluck('obligation_id')->unique()->values()->all();

        /** @var Collection<int, Obligation> $obligations */
        $obligations = $obligationIds === []
            ? new Collection()
            : Obligation::query()->whereIn('id', $obligationIds)->get()->keyBy('id');

        $events = [];

        foreach ($payments as $payment) {
            $events[] = $this->repaymentEvent(
                $payment,
                $allocationsByPayment->get($payment->id, collect()),
                $obligations,
            );
        }

        return $events;
    }

    /**
     * Build a repayment event from a Wallet payment and its allocations.
     *
     * @param Payment                                         $payment
     * @param \Illuminate\Support\Collection<int, PaymentAllocation> $allocations
     * @param \Illuminate\Support\Collection<int, Obligation> $obligations
     * @return array<string, mixed>
     */
    private function repaymentEvent(
        Payment $payment,
        \Illuminate\Support\Collection $allocations,
        \Illuminate\Support\Collection $obligations,
    ): array {
        $principalDelta = '0.0000';
        $interestDelta  = '0.0000';
        $penaltyDelta   = '0.0000';

        $allocationIds = [];
        $allocatedObligationIds = [];

        foreach ($allocations as $allocation) {
            $allocationIds[] = (int) $allocation->getKey();
            $allocatedObligationIds[] = (int) $allocation->obligation_id;

            $obligation = $obligations->get($allocation->obligation_id);

            if ($obligation === null) {
                // An allocation pointing at a missing obligation is a
                // data-integrity defect; the controller emits the
                // allocation's amount as a principal delta as the
                // safest fallback (it will surface in the running
                // balance and can be investigated separately).
                $principalDelta = bcadd(
                    $principalDelta,
                    bcmul((string) $allocation->amount, '-1', self::FINAL_SCALE),
                    self::FINAL_SCALE,
                );
                continue;
            }

            $componentValue = $obligation->component instanceof ObligationComponent
                ? $obligation->component->value
                : (string) $obligation->component;

            $negated = bcmul((string) $allocation->amount, '-1', self::FINAL_SCALE);

            if ($componentValue === ObligationComponent::Principal->value) {
                $principalDelta = bcadd($principalDelta, $negated, self::FINAL_SCALE);
            } elseif ($componentValue === ObligationComponent::Interest->value) {
                $interestDelta = bcadd($interestDelta, $negated, self::FINAL_SCALE);
            } elseif ($componentValue === ObligationComponent::Penalty->value) {
                $penaltyDelta = bcadd($penaltyDelta, $negated, self::FINAL_SCALE);
            } else {
                // Unrecognised component — treat as principal for a
                // visible fallback.
                $principalDelta = bcadd($principalDelta, $negated, self::FINAL_SCALE);
            }
        }

        $totalDelta = bcadd(
            bcadd($principalDelta, $interestDelta, self::FINAL_SCALE),
            $penaltyDelta,
            self::FINAL_SCALE,
        );

        return [
            'occurred_at'     => $payment->received_at,
            'type'            => 'repayment',
            'description'     => __('loan::statement.repayment', [
                'payment_id' => $payment->getKey(),
            ]),
            'principal_delta' => $principalDelta,
            'interest_delta'  => $interestDelta,
            'penalty_delta'   => $penaltyDelta,
            'total_delta'     => $totalDelta,
            'reference_type'  => self::WALLET_REFERENCE_TYPE_LOAN_REPAYMENT,
            'reference_id'    => (int) $payment->getKey(),
            'meta'            => [
                'payment_amount'         => (string) $payment->amount,
                'unapplied_amount'       => (string) $payment->unapplied_amount,
                'allocation_count'       => count($allocationIds),
                'allocation_ids'         => $allocationIds,
                'allocated_obligation_ids' => $allocatedObligationIds,
            ],
        ];
    }

    // =========================================================================
    // Private — chronological sort
    // =========================================================================

    /**
     * Compare two events for the chronological ordering.
     *
     * See the class docblock §"The chronological sort and its
     * tiebreakers".
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return int
     */
    private function compareEvents(array $a, array $b): int
    {
        $aTime = $this->timestampOf($a['occurred_at']);
        $bTime = $this->timestampOf($b['occurred_at']);

        if ($aTime !== $bTime) {
            return $aTime <=> $bTime;
        }

        $aPriority = self::TYPE_PRIORITY[$a['type']] ?? 99;
        $bPriority = self::TYPE_PRIORITY[$b['type']] ?? 99;

        if ($aPriority !== $bPriority) {
            return $aPriority <=> $bPriority;
        }

        return ((int) $a['reference_id']) <=> ((int) $b['reference_id']);
    }

    /**
     * Extract a sortable integer timestamp from a date-like value.
     *
     * @param mixed $value
     * @return int
     */
    private function timestampOf(mixed $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_string($value) && $value !== '') {
            return (int) strtotime($value);
        }

        return 0;
    }

    // =========================================================================
    // Private — running-balance walk
    // =========================================================================

    /**
     * Walk the sorted events, compute running balances, aggregate totals,
     * and produce the statement structure.
     *
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function walkEvents(array $events): array
    {
        $runningPrincipal = '0.0000';
        $runningInterest  = '0.0000';
        $runningPenalty   = '0.0000';

        $totals = $this->zeroTotals();

        $lines = [];

        foreach ($events as $event) {
            $runningPrincipal = bcadd(
                $runningPrincipal,
                (string) $event['principal_delta'],
                self::FINAL_SCALE,
            );
            $runningInterest = bcadd(
                $runningInterest,
                (string) $event['interest_delta'],
                self::FINAL_SCALE,
            );
            $runningPenalty = bcadd(
                $runningPenalty,
                (string) $event['penalty_delta'],
                self::FINAL_SCALE,
            );

            $runningTotal = bcadd(
                bcadd($runningPrincipal, $runningInterest, self::FINAL_SCALE),
                $runningPenalty,
                self::FINAL_SCALE,
            );

            $line = $event;
            $line['running_balance'] = [
                'principal' => $runningPrincipal,
                'interest'  => $runningInterest,
                'penalty'   => $runningPenalty,
                'total'     => $runningTotal,
            ];

            $lines[] = $line;

            $totals = $this->accumulateTotals($totals, $event);
        }

        $opening = $this->zeroBalance();

        $closing = [
            'principal' => $runningPrincipal,
            'interest'  => $runningInterest,
            'penalty'   => $runningPenalty,
            'total'     => bcadd(
                bcadd($runningPrincipal, $runningInterest, self::FINAL_SCALE),
                $runningPenalty,
                self::FINAL_SCALE,
            ),
        ];

        return [
            'period' => [
                'from' => $events[0]['occurred_at'] ?? null,
                'to'   => $events[count($events) - 1]['occurred_at'] ?? null,
            ],
            'opening_balance' => $opening,
            'lines'           => $lines,
            'closing_balance' => $closing,
            'totals'          => $totals,
        ];
    }

    /**
     * Accumulate a single event's contribution to the totals buckets.
     *
     * @param array<string, string> $totals
     * @param array<string, mixed>  $event
     * @return array<string, string>
     */
    private function accumulateTotals(array $totals, array $event): array
    {
        switch ($event['type']) {
            case 'disbursement':
                $totals['total_disbursed'] = bcadd(
                    $totals['total_disbursed'],
                    (string) $event['principal_delta'],
                    self::FINAL_SCALE,
                );
                break;

            case 'interest_accrual':
                $totals['total_interest_accrued'] = bcadd(
                    $totals['total_interest_accrued'],
                    (string) $event['interest_delta'],
                    self::FINAL_SCALE,
                );
                break;

            case 'penalty_accrual':
                $totals['total_penalty_accrued'] = bcadd(
                    $totals['total_penalty_accrued'],
                    (string) $event['penalty_delta'],
                    self::FINAL_SCALE,
                );
                break;

            case 'repayment':
                // The repaid amount is the absolute value of the
                // (negative) total_delta.
                $totals['total_repaid'] = bcadd(
                    $totals['total_repaid'],
                    bcmul((string) $event['total_delta'], '-1', self::FINAL_SCALE),
                    self::FINAL_SCALE,
                );
                break;

            case 'restructure':
                $newPrincipal = (string) (
                    $event['meta']['new_principal_amount'] ?? '0.0000'
                );
                $totals['total_restructured'] = bcadd(
                    $totals['total_restructured'],
                    $newPrincipal,
                    self::FINAL_SCALE,
                );
                break;

            case 'write_off':
                $totals['total_written_off'] = bcadd(
                    $totals['total_written_off'],
                    bcmul((string) $event['total_delta'], '-1', self::FINAL_SCALE),
                    self::FINAL_SCALE,
                );
                break;

            default:
                // collateral_release and status_change have no
                // contribution to the totals.
                break;
        }

        return $totals;
    }

    // =========================================================================
    // Private — LoanStatusHistory attribute accessors
    //
    // Small helpers that read the status-history row's attributes through
    // the most likely names, in order. See the class docblock
    // §"Attribute-name assumption".
    // =========================================================================

    /**
     * The history row's occurrence timestamp.
     *
     * @param LoanStatusHistory $history
     * @return mixed  DateTimeInterface, string, or null.
     */
    private function statusHistoryOccurredAt(LoanStatusHistory $history): mixed
    {
        return $history->occurred_at
            ?? $history->changed_at
            ?? $history->transitioned_at
            ?? $history->created_at
            ?? null;
    }

    /**
     * The history row's actor user id.
     *
     * @param LoanStatusHistory $history
     * @return int|null
     */
    private function statusHistoryActor(LoanStatusHistory $history): ?int
    {
        $actor = $history->changed_by
            ?? $history->actor_user_id
            ?? $history->created_by
            ?? null;

        return $actor !== null ? (int) $actor : null;
    }

    /**
     * The history row's primary key.
     *
     * @param LoanStatusHistory $history
     * @return int
     */
    private function statusHistoryId(LoanStatusHistory $history): int
    {
        return (int) $history->getKey();
    }

    /**
     * The history row's `from` status, as a string value.
     *
     * Handles both an enum-cast attribute and a raw string. A null
     * `from_status` (the initialize() row) renders as an empty string,
     * which the resource passes through verbatim.
     *
     * @param LoanStatusHistory $history
     * @return string
     */
    private function statusHistoryFromStatus(LoanStatusHistory $history): string
    {
        $from = $history->from_status ?? null;

        if ($from instanceof LoanStatus) {
            return $from->value;
        }

        return $from === null ? '' : (string) $from;
    }

    /**
     * The history row's `to` status, as a string value.
     *
     * Handles both an enum-cast attribute and a raw string.
     *
     * @param LoanStatusHistory $history
     * @return string
     */
    private function statusHistoryToStatus(LoanStatusHistory $history): string
    {
        $to = $history->to_status ?? null;

        if ($to instanceof LoanStatus) {
            return $to->value;
        }

        return $to === null ? '' : (string) $to;
    }

    /**
     * The history row's reason, if any.
     *
     * @param LoanStatusHistory $history
     * @return string|null
     */
    private function statusHistoryReason(LoanStatusHistory $history): ?string
    {
        $reason = $history->reason ?? null;

        return is_string($reason) ? $reason : null;
    }

    // =========================================================================
    // Private — zero-value constructors
    // =========================================================================

    /**
     * A four-component balance at zero.
     *
     * @return array<string, string>
     */
    private function zeroBalance(): array
    {
        return [
            'principal' => '0.0000',
            'interest'  => '0.0000',
            'penalty'   => '0.0000',
            'total'     => '0.0000',
        ];
    }

    /**
     * The six-bucket totals at zero.
     *
     * @return array<string, string>
     */
    private function zeroTotals(): array
    {
        return [
            'total_disbursed'        => '0.0000',
            'total_interest_accrued' => '0.0000',
            'total_penalty_accrued'  => '0.0000',
            'total_repaid'           => '0.0000',
            'total_restructured'     => '0.0000',
            'total_written_off'      => '0.0000',
        ];
    }
}