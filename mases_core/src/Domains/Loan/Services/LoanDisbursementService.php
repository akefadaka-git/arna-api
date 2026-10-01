<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanDisbursed;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\ObligationService;

/**
 * LoanDisbursementService
 *
 * Owns the DISBURSEMENT phase of a loan's lifecycle: the moment the
 * lender transfers the principal into the borrower's financial position
 * (recorded, per the B2 hybrid-ledger freeze, as a principal obligation
 * on the borrower's Wallet), generates the loan's contractual schedule,
 * and transitions the loan from `approved` to `active`.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * This service owns exactly ONE operation:
 *
 *   disburse()  — record the Wallet principal obligation, generate the
 *                 schedule, write the loan's disbursement metadata, and
 *                 transition the loan through `approved → disbursed →
 *                 active`.
 *
 * Everything before disbursement — draft, submission, review, decision —
 * belongs to `LoanApplicationService` (A.28) and `LoanApprovalService`
 * (A.29). Everything after — repayment, interest accrual, arrears,
 * restructure, write-off, settlement, closure — belongs to its own
 * service.
 *
 * =============================================================================
 * The frozen disbursement flow
 * =============================================================================
 * The operation performs the following steps, in this order, inside ONE
 * transaction:
 *
 *   1. Read the loan (no lock — the Wallet is locked first, per the
 *      frozen lock order; see §"Lock ordering").
 *   2. Assert the loan's status is `approved`. Any other status is a
 *      `ValidationException` (422).
 *   3. Resolve the borrower and the product via the loan's loaded
 *      relations.
 *   4. Resolve the borrower's Wallet via
 *      `WalletRepository::findForShareholder()`. A missing Wallet is a
 *      `ValidationException` — the borrower cannot receive a loan
 *      without a Wallet to receive it into.
 *   5. Assert that the product's currency equals the Wallet's currency
 *      (Q8). A mismatch is a `ValidationException`.
 *   6. Record the Wallet principal obligation via
 *      `ObligationService::record()` with component
 *      `ObligationComponent::Principal`, `reference_type = 'Loan'`, and
 *      `reference_id = (string) $loan->id`. This is the moment the
 *      monetary fact is recorded on the Wallet: a `wallet_obligations`
 *      row is created and a corresponding `obligation_recorded` ledger
 *      entry is written, reducing the Wallet's `current_balance` by the
 *      principal amount.
 *   7. Generate the loan's contractual schedule via
 *      `LoanScheduleService::generate()`, using the disbursement moment
 *      as the reference date. This writes the `loan_installments` rows
 *      and updates the loan's summary columns
 *      (`monthly_installment_amount`, `total_scheduled_interest`,
 *      `total_scheduled_repayment`, `maturity_date`).
 *   8. Write the loan's disbursement metadata: `currency` (copied from
 *      the Wallet — the Q8 single-currency invariant), `disbursement_date`
 *      (the moment of the operation), and `outstanding_principal` (the
 *      loan's principal amount).
 *   9. Transition the loan's lifecycle status through two state-machine
 *      steps:
 *        a. `approved → disbursed`, dispatching `LoanDisbursed` (A.38)
 *           after commit. This is the audit-relevant milestone: the
 *           moment the disbursement was recorded.
 *        b. `disbursed → active`, no event. The transient `disbursed`
 *           state settles into `active` in the same transaction.
 *  10. Return the refreshed loan.
 *
 * If any step after step 6 fails, the entire transaction rolls back —
 * including the Wallet obligation, the schedule rows, and any partial
 * loan metadata. The Wallet's ledger entry is discarded, and the Wallet's
 * `current_balance` is restored by the rollback. There is no possibility
 * of a "half-disbursed" loan.
 *
 * =============================================================================
 * Lock ordering (Wallet → Loan, per the frozen B2 hybrid discipline)
 * =============================================================================
 * The B2 hybrid-ledger freeze declares a deterministic lock-acquisition
 * order for operations that touch both domains:
 *
 *     Wallet → Payment → Obligation → PaymentAllocation → Loan
 *
 * This service acquires locks in exactly that order:
 *
 *   - Step 6 (`ObligationService::record()`) locks the Wallet row via
 *     `WalletService::recordEntry()`'s `SELECT ... FOR UPDATE`.
 *
 *   - Step 7 (`LoanScheduleService::generate()`) acquires the loan-side
 *     lock implicitly via the UPDATE it performs on `loans`.
 *
 *   - Steps 9a and 9b (`LoanStateMachine::transition()`) re-acquire the
 *     loan lock via their own `SELECT ... FOR UPDATE`, which is a no-op
 *     inside a transaction that already holds the lock.
 *
 * The result: no two locks are ever held in an order that another
 * Phase 2C operation would need to acquire in the reverse. Deadlock
 * between concurrent disbursements, or between a disbursement and any
 * other Phase 2C operation, is therefore impossible by construction.
 *
 * To keep the ordering correct, step 1 reads the loan WITHOUT a lock.
 * Locking the loan at step 1 would invert the order and reintroduce the
 * possibility of a deadlock in a future phase that introduces a
 * wallet-first operation.
 *
 * The price of the lock-free read at step 1 is a small race window: a
 * concurrent operation could theoretically change the loan's status
 * between step 1 and step 7. In practice this window is closed by two
 * factors:
 *
 *   - The operation is short (the aggregate is small and the number of
 *     queries is fixed).
 *
 *   - The state machine at step 9a re-reads the loan with its own lock
 *     and re-validates the `approved → disbursed` transition. If a
 *     concurrent operation has changed the status, the state machine
 *     throws `ValidationException` and the whole transaction rolls back.
 *
 * The disbursement is therefore safe: either it commits with the loan
 * transitioned to `active` and its Wallet obligation recorded, or it
 * rolls back without side effects.
 *
 * =============================================================================
 * Transaction discipline
 * =============================================================================
 * This service opens its OWN transaction. It is the TOP-LEVEL entry point
 * for its operation (the controller calls `disburse()` directly), so
 * there is no outer transaction.
 *
 * The transaction wraps the Wallet obligation write, the schedule
 * generation, the loan metadata writes, and the two state transitions —
 * all as one atomic unit. This is the ONLY correct layering given the
 * frozen reconciliation invariant (see the migration A.9): a loan in
 * `active` state whose Wallet obligation is missing would violate the
 * invariant, and a Wallet obligation whose loan is not yet in `active`
 * would be an orphan. Either outcome is prevented by the atomic
 * transaction.
 *
 * The subordinate services (`ObligationService`, `LoanScheduleService`,
 * `LoanStateMachine`) each require a surrounding transaction. The
 * disbursement transaction is that surrounding transaction. The Wallet's
 * `ObligationService` opens its own nested `DB::transaction()` (Laravel
 * treats it as a savepoint inside ours), which does not affect atomicity.
 *
 * =============================================================================
 * Wallet integration — the required Change Request
 * =============================================================================
 * This service passes `reference_type = 'Loan'` to
 * `ObligationService::record()`. The Wallet's
 * `WalletService::assertReferenceTypePermitted()` enforces the reference
 * types associated with each entry type via the frozen
 * `REFERENCE_TYPE_MAP` constant (Phase 2B, migration 2B.54).
 *
 * Before this service is deployed, CR-2C-WALLET-REFTYPE-001 must have
 * landed in the Wallet domain. That CR extends the map so that
 * `obligation_recorded` entries accept `'Loan'` as an alternate reference
 * type alongside the original `'Obligation'`, and so that
 * `payment_received` entries accept `'LoanRepayment'`. Without the CR,
 * the Wallet throws `ValidationException` on the disbursement's
 * obligation write.
 *
 * The CR is additive and non-breaking for existing Wallet tests. It is
 * documented in the Phase 2C manifest (Block D, CR-2C-WALLET-REFTYPE-001)
 * and in the WalletService's docblock.
 *
 * =============================================================================
 * Event construction
 * =============================================================================
 * The `LoanDisbursed` event (A.38) is constructed HERE, by this service,
 * because this service has the domain-specific data the event carries:
 * the loan, the Wallet id, and the Wallet obligation id.
 *
 * The event is passed to `LoanStateMachine::transition()` via its
 * `$afterCommitEvent` parameter. The state machine registers the
 * after-commit dispatch, gated on `config('loan.audit.emit_events')` —
 * it does not know the event's class or payload.
 *
 * Note that the Wallet's `ObligationService::record()` ALSO registers an
 * after-commit event (`ObligationRecorded`, A.41 of the Wallet domain).
 * Both events fire after this transaction commits, in registration order.
 * The Wallet's `AuditWalletEvent` listener writes its audit row; the Loan
 * domain's `AuditLoanEvent` listener writes its own. The two audit
 * streams are independent and complementary.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not approve the loan. That belongs to
 *     `LoanApprovalService` (A.29).
 *
 *   - It does not record the loan's interest or penalty obligations.
 *     Interest accrual begins on the first installment's due date, via
 *     `LoanInterestAccrualService` (A.32); penalty accrual begins on the
 *     first overdue installment, via `LoanArrearsService` (A.33).
 *
 *   - It does not set `wallet.interest_policy_version_id` or
 *     `wallet.allocation_policy_version_id`. Those Wallet slots are for
 *     capabilities outside the Loan domain's scope (A7, A8, D110), and
 *     the Loan domain's own policy freeze lives on
 *     `loans.frozen_policy_version_id`.
 *
 *   - It does not link the loan to the Wallet obligation via a column.
 *     The linkage is one-directional and by opaque reference:
 *     `wallet_obligations.reference_type = 'Loan'` +
 *     `wallet_obligations.reference_id = (string) $loan->id`. A consumer
 *     that needs "the principal obligation for loan X" queries the
 *     Wallet's obligations by that reference pair.
 *
 *   - It does not copy the schedule's installment amounts onto the
 *     Wallet as obligations. Only the PRINCIPAL is recorded at
 *     disbursement. Interest and penalty obligations are recorded later,
 *     each at the moment of its own accrual, per the B3 freeze.
 *
 *   - It does not dispatch a `LoanScheduleGenerated` event. The schedule
 *     is a subordinate artifact of the disbursement; its existence is
 *     recorded implicitly by the loan's transition to `active` and by
 *     the `loan_installments` rows.
 *
 *   - It does not accept a disbursement amount different from the loan's
 *     principal. The frozen flow disburses the loan's full principal in
 *     one operation. Partial disbursement is not in Phase 2C scope; a
 *     future CR that introduces it would need to extend this service,
 *     the loan's projections, and the state machine.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D129, D135,
 *                                                  B1 (reference types),
 *                                                  B2 (hybrid ledger),
 *                                                  B3 (interest recording)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3 (the
 *                                                  reference-id ordering
 *                                                  pattern)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.27                                — `LoanScheduleService`
 *   Phase 2C A.29                                — `LoanApprovalService`
 *                                                  (the sibling
 *                                                  top-level service)
 *   Phase 2B migration 2B.49                     — `wallets`
 *   Phase 2B migration 2B.50                     — `wallet_obligations`
 *   Phase 2B `ObligationService`                 — the Wallet write path
 *   CR-2C-WALLET-REFTYPE-001                     — extends the Wallet's
 *                                                  reference-type map
 *   Step 5 (manifest) A.30                       — This file
 *   Step 6 v1.1.0 §3.9                           — `loans` table
 *                                                  specification
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Services
 * @version 1.0.0
 */
final class LoanDisbursementService
{
    /**
     * The `reference_type` value this service writes to the Wallet for
     * the principal obligation.
     *
     * Frozen by the B1 decision and by the Phase 2C Final Reconciliation
     * §1.2. The Wallet's `assertReferenceTypePermitted()` (Phase 2B)
     * accepts this value after CR-2C-WALLET-REFTYPE-001 lands.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN = 'Loan';

    /**
     * Constructor.
     *
     * Four dependencies:
     *
     *   - `LoanStateMachine` — the sole writer of the loan's status and
     *     its lifecycle history. Used for the two transitions at the end
     *     of the disbursement transaction.
     *
     *   - `LoanScheduleService` — generates and persists the schedule.
     *     Called after the Wallet obligation is recorded.
     *
     *   - `WalletRepository` — tenant-scoped Wallet resolution for the
     *     borrower. The Wallet is the target of the principal obligation.
     *
     *   - `ObligationService` — the Wallet domain's write path for
     *     obligations. Records the principal obligation and writes the
     *     corresponding `wallet_entries` ledger row.
     *
     * The service does NOT inject the Shareholder repository or the
     * product repository. Both the borrower and the product are reachable
     * via the loan's loaded relations; loading them explicitly with an
     * additional repository lookup would duplicate the `loan -> borrower`
     * and `loan -> product` FK relationships.
     *
     * @param LoanStateMachine      $stateMachine
     * @param LoanScheduleService   $scheduleService
     * @param WalletRepository      $walletRepository
     * @param ObligationService     $obligationService
     */
    public function __construct(
        private readonly LoanStateMachine $stateMachine,
        private readonly LoanScheduleService $scheduleService,
        private readonly WalletRepository $walletRepository,
        private readonly ObligationService $obligationService,
    ) {
    }

    // =========================================================================
    // Public API
    // =========================================================================
    /**
     * Disburse a loan.
     *
     * The frozen sequence of operations is documented in the class
     * docblock §"The frozen disbursement flow". This method executes
     * every step inside one transaction.
     *
     * @param Loan        $loan         The approved loan to disburse.
     * @param string|null $description  Optional description for the
     *                                   Wallet obligation; a default is
     *                                   generated if null.
     * @param int|null    $actorUserId  The user performing the
     *                                   disbursement, or null for a
     *                                   system-initiated disbursement.
     * @return Loan  The refreshed loan in `active` state.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     * @throws LogicException
     */
    public function disburse(
        Loan $loan,
        ?string $description = null,
        ?int $actorUserId = null,
    ): Loan {
        return DB::transaction(function () use ($loan, $description, $actorUserId): Loan {
            // -----------------------------------------------------------------
            // 1. Read the loan (no lock; see the class docblock
            //    §"Lock ordering" for the rationale).
            // -----------------------------------------------------------------
            /** @var Loan $current */
            $current = Loan::query()
                ->whereKey($loan->id)
                ->firstOrFail();

            // -----------------------------------------------------------------
            // 2. Assert the loan is in `approved` state.
            //
            // The state machine will re-validate this under its own lock
            // at step 9a. The pre-check here produces a clearer error
            // message and lets us fail fast before any Wallet or schedule
            // write.
            // -----------------------------------------------------------------
            if ($current->status !== LoanStatus::Approved) {
                throw ValidationException::withMessages([
                    'status' => [
                        sprintf(
                            'A loan in status "%s" cannot be disbursed; '
                            . 'only a loan in "approved" state may be '
                            . 'disbursed.',
                            $current->status->value,
                        ),
                    ],
                ]);
            }

            // -----------------------------------------------------------------
            // 3. Load the borrower and product via the loan's relations.
            // -----------------------------------------------------------------
            $current->loadMissing(['borrower', 'product']);

            $borrower = $current->borrower;
            $product  = $current->product;

            if ($borrower === null) {
                throw new LogicException(
                    sprintf(
                        'Loan id=%d has no borrower. This is a '
                        . 'data-integrity defect: the FK '
                        . '`loans.borrower_shareholder_id` is declared '
                        . 'non-null and `RESTRICT`; a null value here '
                        . 'indicates the row was corrupted by an '
                        . 'out-of-band edit that bypassed the database '
                        . 'constraint.',
                        $current->id,
                    ),
                );
            }

            if ($product === null) {
                throw new LogicException(
                    sprintf(
                        'Loan id=%d has no product. See the sibling '
                        . 'invariant on the borrower relation for the '
                        . 'rationale.',
                        $current->id,
                    ),
                );
            }

            // -----------------------------------------------------------------
            // 4. Resolve the borrower's Wallet.
            // -----------------------------------------------------------------
            $wallet = $this->walletRepository->findForShareholder(
                (int) $current->tenant_id,
                (int) $borrower->id,
            );

            if ($wallet === null) {
                throw ValidationException::withMessages([
                    'wallet' => [
                        sprintf(
                            'The borrower (shareholder id=%d) does not '
                            . 'have a Wallet in this tenant. Open a '
                            . 'Wallet for the borrower before '
                            . 'disbursing the loan.',
                            $borrower->id,
                        ),
                    ],
                ]);
            }

            // -----------------------------------------------------------------
            // 5. Assert currency equality (Q8).
            //
            // The product is denominated in some currency; the Wallet is
            // denominated in some currency. Per the Q8 single-currency
            // invariant, the loan must use the Wallet's currency. Since
            // the product determines the loan's currency context, the
            // two must match.
            // -----------------------------------------------------------------
            if ($product->currency !== $wallet->currency) {
                throw ValidationException::withMessages([
                    'currency' => [
                        sprintf(
                            'The product is denominated in %s, but the '
                            . 'borrower\'s Wallet is denominated in %s. '
                            . 'A loan must match the borrower\'s Wallet '
                            . 'currency (Q8).',
                            $product->currency,
                            $wallet->currency,
                        ),
                    ],
                ]);
            }

            $now = Carbon::now();

            $resolvedDescription = $description
                ?? sprintf('Loan disbursement #%d', $current->id);

            // -----------------------------------------------------------------
            // 6. Record the Wallet principal obligation.
            //
            // This is the moment the monetary fact is recorded. The
            // Wallet's `ObligationService::record()` creates a
            // `wallet_obligations` row of component `Principal` and, via
            // `WalletService::recordEntry()`, writes a corresponding
            // `obligation_recorded` ledger entry that reduces the
            // Wallet's `current_balance` by the principal amount.
            //
            // The Wallet row is locked for the duration of the
            // transaction. This is the FIRST lock acquired, honoring the
            // frozen Wallet → Loan order (see the class docblock
            // §"Lock ordering").
            // -----------------------------------------------------------------
            $obligation = $this->obligationService->record(
                wallet: $wallet,
                component: ObligationComponent::Principal,
                amount: (string) $current->principal_amount,
                occurredAt: $now,
                dueAt: null,
                referenceType: self::WALLET_REFERENCE_TYPE_LOAN,
                referenceId: (string) $current->id,
                description: $resolvedDescription,
                createdBy: $actorUserId,
            );

            // -----------------------------------------------------------------
            // 7. Generate the loan's contractual schedule.
            //
            // The schedule is generated using the disbursement moment as
            // the reference date. The service writes the
            // `loan_installments` rows and updates the loan's summary
            // columns and `maturity_date`. See `LoanScheduleService`
            // (A.27) for the amortization algorithm.
            //
            // The schedule's generation acquires the loan-side lock
            // implicitly (via the UPDATE on `loans`), honoring the
            // Wallet → Loan order.
            // -----------------------------------------------------------------
            $this->scheduleService->generate($current, $now);

            // Refresh to pick up the summary columns and maturity_date
            // written by the schedule service.
            $current->refresh();

            // -----------------------------------------------------------------
            // 8. Write the loan's disbursement metadata.
            // -----------------------------------------------------------------
            $current->currency              = $wallet->currency;
            $current->disbursement_date     = $now;
            $current->outstanding_principal = (string) $current->principal_amount;
            $current->save();

            // -----------------------------------------------------------------
            // 9a. Transition `approved → disbursed`, dispatching
            //     `LoanDisbursed` after commit.
            //
            // The event is constructed HERE, because this service has
            // the domain-specific data: the loan, the Wallet id, and
            // the Wallet obligation id.
            // -----------------------------------------------------------------
            $event = new LoanDisbursed(
                $current,
                (int) $wallet->id,
                (int) $obligation->id,
            );

            $current = $this->stateMachine->transition(
                loan: $current,
                nextStatus: LoanStatus::Disbursed,
                reason: null,
                actorUserId: $actorUserId,
                afterCommitEvent: $event,
            );

            // -----------------------------------------------------------------
            // 9b. Transition `disbursed → active`, no event.
            //
            // The `disbursed` state is transient: it is the moment the
            // disbursement was recorded, and it settles into `active`
            // in the same transaction. The second transition carries no
            // actor (it is automatic) and no event (the audit-relevant
            // milestone was the first transition).
            // -----------------------------------------------------------------
            $current = $this->stateMachine->transition(
                loan: $current,
                nextStatus: LoanStatus::Active,
                reason: null,
                actorUserId: null,
                afterCommitEvent: null,
            );

            $current->refresh();

            return $current;
        });
    }
}