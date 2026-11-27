<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\Arna\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\Arna\Core\Domains\Loan\Events\LoanDisbursed;
use MaaleArna\Arna\Core\Domains\Loan\Models\Loan;
use MaaleArna\Arna\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\Arna\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\Arna\Core\Domains\Wallet\Services\ObligationService;

/**
 * Disbursement transaction records the Wallet principal obligation, generates the schedule and transitions approved → disbursed → active.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Services
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