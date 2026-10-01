<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRestructured;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanRestructuring;
use MaaleArna\MASES\Core\Domains\Loan\Support\AmortizationCalculator;
use MaaleArna\MASES\Core\Domains\Loan\Support\LoanPolicyKeys;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\WalletEntryType;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\ObligationService;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;

/**
 * LoanRestructuringService
 *
 * Owns the RESTRUCTURE operation of a loan's lifecycle: the renegotiation
 * of a loan's contractual terms. The loan's principal, term, and/or
 * policy version are replaced; the prior schedule's unsettled
 * installments are superseded; the prior loan-linked Wallet obligations
 * are discharged with a `LoanRestructure` reference; a new schedule is
 * generated against the new terms; and the loan transitions through
 * `in_arrears → restructured → active`.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — `halfUp()` off-by-one fix, closing R-22. The private rounding
 *         helper constructed the half-unit-in-the-last-place as
 *         `'0.' . str_repeat('0', $scale - 1) . '5'`, which at
 *         `scale = 4` produces `'0.0005'` — the half-ULP of scale 3,
 *         not scale 4. The correct construction is
 *         `'0.' . str_repeat('0', $scale) . '5'`, which at `scale = 4`
 *         produces `'0.00005'`.
 *
 *         The bug was a drift from the three other copies of this
 *         helper. `AmortizationCalculator::halfUp()` (A.6),
 *         `LoanScheduleService::halfUp()` (A.27), and
 *         `LoanArrearsService::halfUp()` (A.33) all received the same
 *         fix in the v1.0.1 deployment; this file — the fourth copy —
 *         did not, despite its method docblock asserting the four were
 *         identical. The docblock claim was factually wrong until this
 *         revision.
 *
 *         Observable effect before the fix: when a restructure carried
 *         a non-zero principal grace period, the grace interest value
 *         recorded on the immutable `loan_restructurings` row
 *         (`new_total_scheduled_repayment`) could diverge from the
 *         schedule actually generated at step 12 by
 *         `LoanScheduleService`, because the two computations used
 *         different half-ULPs. Values lying in
 *         `[x.xxxx5, x.xxxx5 + 0.0004)` rounded up under the defective
 *         helper and down under the patched one. With a 6-month grace,
 *         the maximum divergence per restructure was on the order of
 *         0.0024. Small, but real, and a violation of the class
 *         docblock's own claim that "the two computations use identical
 *         inputs — so the values agree".
 *
 *         This fix restores lockstep among all four copies. The
 *         extraction of the helper into a shared trait remains an open
 *         follow-up (see the cross-file note in
 *         `AmortizationCalculator` v1.0.1) — four byte-for-byte copies
 *         of a financial rounding primitive has now produced one drift
 *         incident, and the platform should not carry the hazard
 *         indefinitely.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * This service owns exactly ONE operation:
 *
 *   restructure()  — replace the loan's terms and rebuild the schedule.
 *
 * The frozen state machine (A.2) permits exactly ONE path into
 * `restructured`:
 *
 *     in_arrears → restructured
 *
 * The `restructured → active` edge is the SETTLING transition that the
 * state machine performs in the same transaction, mirroring the
 * `disbursed → active` transient-settling pattern (A.30).
 *
 * A loan in any other status — including `active`, `settled`,
 * `written_off`, or the pre-disbursement states — is ineligible for
 * restructuring. The command that would drive it (there is no command;
 * the controller invokes this service directly via the restructure
 * endpoint, A.77) is expected to have filtered it out; the service
 * re-validates and throws.
 *
 * =============================================================================
 * The frozen restructure flow (Final Reconciliation §1.2)
 * =============================================================================
 * The operation performs the following steps, in this order, inside ONE
 * transaction:
 *
 *   1. Validate the reason and the new terms.
 *   2. Read the loan (no lock — the Wallet is locked first, per the
 *      frozen `Wallet → Loan` lock order).
 *   3. Assert the loan is in `in_arrears` state.
 *   4. Resolve the borrower's Wallet.
 *   5. Capture the loan's previous terms:
 *        · previous_policy_version_id  (from `frozen_policy_version_id`)
 *        · previous_outstanding_principal / _interest / _penalty
 *        · previous_maturity_date
 *        · previous_installment_count  (active installments only)
 *   6. Resolve and validate the new policy version.
 *   7. Compute the new schedule's summary values (maturity date, total
 *      scheduled repayment, installment count) using the same
 *      amortization the schedule service uses. This computation is
 *      performed WITHOUT persisting the schedule, because the
 *      restructuring row (step 8) is immutable and requires the values
 *      at INSERT time.
 *   8. INSERT the `loan_restructurings` row → obtain `$restructuringId`.
 *   9. Discharge every outstanding Wallet obligation linked to the loan,
 *      using `(string) $restructuringId` as the entry's `reference_id`.
 *  10. Supersede the prior schedule's unsettled installments. Settled
 *      installments are preserved; only those whose obligations were
 *      just discharged are transitioned to `Superseded`.
 *  11. Overwrite the loan's `principal_amount`, `term_months`, and
 *      `frozen_policy_version_id` with the new values.
 *  12. Call `LoanScheduleService::generate()` to persist the new
 *      installment rows and update the loan's summary columns
 *      (`monthly_installment_amount`, `total_scheduled_interest`,
 *      `total_scheduled_repayment`, `maturity_date`).
 *  13. Set the loan's three outstanding projections to reflect the NEW
 *      contractual debt: `outstanding_principal` becomes the new
 *      principal, `outstanding_interest` and `outstanding_penalty`
 *      become zero. The prior obligations were discharged at step 9;
 *      the new schedule's interest obligations will be recorded by
 *      `LoanInterestAccrualService` (A.32) as each installment's due
 *      date arrives, at which point the loan's projections will be
 *      adjusted incrementally.
 *  14. Transition the loan through `in_arrears → restructured`,
 *      dispatching `LoanRestructured` after commit.
 *  15. Transition the loan through `restructured → active` (no event;
 *      the restructure's audit-relevant milestone was the first
 *      transition).
 *  16. Return the refreshed loan.
 *
 * Steps 7 and 8 are the reference-id ordering fix: the restructuring row
 * exists BEFORE the Wallet discharges reference it, exactly as documented
 * on `LoanRestructuring` (A.21) §"The reference-id ordering role".
 *
 * =============================================================================
 * The reference-id ordering fix (Phase 2C Final Reconciliation §1.2)
 * =============================================================================
 * The prior delivery's restructure flow attempted to use the
 * `loan_restructurings` row's auto-generated id as the discharge's
 * `reference_id` before the row existed. The corrected ordering (step 8
 * before step 9) ensures that every discharge entry has a valid
 * reference id: the id of the restructuring row that will be visible to
 * audit once the transaction commits.
 *
 * A rollback at any step after step 8 discards the restructuring row,
 * the discharge entries, the supersessions, the new schedule rows, the
 * loan's term rewrites, the projection updates, and both status
 * transitions — all together.
 *
 * =============================================================================
 * Discharging the loan's Wallet obligations
 * =============================================================================
 * Step 9 calls `ObligationService::discharge()` — the same method that
 * `LoanWriteOffService` (A.35) uses. The method:
 *
 *   - reduces the obligation's `outstanding_amount` to zero;
 *   - writes a `wallet_entries` row of the given type with a POSITIVE
 *     signed quantity equal to the discharged amount (crediting the
 *     member's position);
 *   - PRESERVES any existing payment allocations against the obligation
 *     (they are historical facts and are never mutated);
 *   - fires the Wallet's event after commit.
 *
 * The entry type is `WalletEntryType::ObligationCancelled`, NOT
 * `ObligationWrittenOff`. The distinction is deliberate and semantically
 * meaningful: a cancellation says "these terms no longer apply; new
 * terms replace them"; a write-off says "this debt is forgiven". The two
 * are recorded as different Wallet entry types so that the Wallet's
 * audit trail preserves the distinction. See `LoanWriteOffService`'s
 * class docblock §"Discharging Wallet obligations" for the mirrored
 * reasoning.
 *
 * The discharge entries' `reference_type = 'LoanRestructure'` and
 * `reference_id = (string) $restructuringId` point back at the
 * restructuring row that caused the discharge. The Wallet's
 * `assertReferenceTypePermitted()` accepts this pairing after
 * CR-2C-WALLET-REFTYPE-001 lands.
 *
 * =============================================================================
 * Superseding the prior schedule's unsettled installments
 * =============================================================================
 * Step 10 transitions every unsettled installment of the loan to
 * `InstallmentStatus::Superseded` (A.5). Settled installments are
 * preserved as-is: their monetary facts are historical, and their
 * corresponding Wallet obligations were discharged when they were paid.
 * Only the UNSETTLED installments are superseded, because only they
 * carried obligations that step 9 just discharged.
 *
 * The exclusion is EXPLICIT: the query excludes both
 * `InstallmentStatus::Settled` and `InstallmentStatus::Superseded`,
 * rather than relying on `InstallmentStatus::unsettledValues()`. The
 * helper's membership is not guaranteed to align with the frozen
 * contract of this step — in the current enum it includes `Settled`,
 * which would violate the preservation rule stated above. The explicit
 * exclusion list keeps the service correct regardless of any future
 * revision to the helper.
 *
 * The superseded rows remain in the `loan_installments` table with their
 * `settled_*` values preserved. The migration A.10 forbids deletion of
 * installment rows, and the monotonic `installment_number` continues from
 * where the prior schedule left off — see `LoanScheduleService`'s class
 * docblock §"Monotonic installment numbering".
 *
 * =============================================================================
 * The new policy version validation
 * =============================================================================
 * Step 6 validates that the `$newPolicyVersionId` refers to an existing
 * `VersionedPolicy` (CORE.3) whose `policy_type` is `'loan_product_rules'`.
 * A missing version or a version of the wrong type throws a
 * `ValidationException` keyed on `new_policy_version_id`.
 *
 * -----------------------------------------------------------------------------
 * Deferred check — same-product linkage
 * -----------------------------------------------------------------------------
 * The `LoanRestructureForm` docblock records a second precondition:
 * "the new policy version belongs to the same product as the loan". The
 * current implementation does NOT enforce this, because the
 * `VersionedPolicy` model as declared by CORE.3 does not carry a
 * product FK that this service can check. The check is therefore
 * deferred to a future Change Request that either adds the FK to
 * `VersionedPolicy` or introduces a documented linkage. See the
 * accompanying handoff for the request to resolve this gap.
 *
 * =============================================================================
 * Transaction discipline
 * =============================================================================
 * This service opens its OWN transaction. It is the TOP-LEVEL entry point
 * for its operation (the controller calls `restructure()` directly), so
 * there is no outer transaction.
 *
 * The transaction wraps the restructuring row insert, the Wallet
 * discharges, the installment supersessions, the loan's term rewrites,
 * the new schedule generation, the projection recomputation, and both
 * status transitions — all as one atomic unit. There is no possibility of
 * a partially-restructured loan.
 *
 * =============================================================================
 * Lock ordering — Wallet → Loan
 * =============================================================================
 * The frozen B2 hybrid-ledger lock order is `Wallet → Payment →
 * Obligation → PaymentAllocation → Loan`. This service acquires locks in
 * exactly that order (see A.30 §"Lock ordering" for the full rationale).
 *
 * The loan is read WITHOUT a lock at step 2. The loan-side writes
 * (term rewrites, projection updates, status transitions) occur AFTER
 * the Wallet lock is acquired by the first discharge at step 9.
 *
 * =============================================================================
 * The summary computation at step 7
 * =============================================================================
 * Because the `LoanRestructuring` model is IMMUTABLE (see A.21's class
 * docblock §"Immutability"), the row's `new_maturity_date`,
 * `new_total_scheduled_repayment`, and `new_installment_count` columns
 * must carry their final values at INSERT time (step 8). The new
 * schedule, however, is not persisted until step 12 — `LoanScheduleService::generate()`
 * requires the loan's terms to have been rewritten (step 11), which
 * happens after the discharges (step 9) and supersessions (step 10).
 *
 * The service therefore computes the summary values at step 7 WITHOUT
 * persisting anything, using the SAME amortization calculator
 * (`AmortizationCalculator`, A.6) that the schedule service will use at
 * step 12. The two computations use identical inputs — the new
 * principal, the new term, the new policy version's annual rate, and the
 * new policy version's principal grace period — so the values agree.
 *
 * See the accompanying handoff §"Decisions" for the alternate reading
 * (allowing a one-time write-back of the "new_*" columns after schedule
 * generation). The chosen design honors the model's stated immutability.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not restructure a loan in any status other than
 *     `in_arrears`. See §"Scope".
 *
 *   - It does not partially restructure a loan. Every restructure
 *     replaces the loan's full term and principal; a future CR that
 *     introduces partial restructuring would extend this service, the
 *     `loan_restructurings` migration, and the state machine.
 *
 *   - It does not reverse a restructure. The restructuring row is
 *     immutable (see A.21), and the discharges and schedule rebuilds
 *     are permanent. A future CR that permits unwinding a restructure
 *     would introduce a dedicated reversal mechanism.
 *
 *   - It does not create new Wallet obligations for the restructured
 *     loan. The new schedule's interest obligations will be recorded
 *     later by `LoanInterestAccrualService` (A.32), one per installment,
 *     at each installment's due date. Only the discharges happen here.
 *
 *   - It does not touch the loan's collaterals, write-off record, or
 *     status history beyond the two transitions' history rows. The
 *     collateral portfolio survives a restructure unchanged; the
 *     write-off record is created only by `LoanWriteOffService` (A.35).
 *
 *   - It does not evaluate any product-level restriction on
 *     restructures (e.g. "a product may be restructured at most N
 *     times"). The register does not freeze such a limit; adding one is
 *     a future CR.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130, A10, B1 (reference
 *                                                  types), B2 (hybrid
 *                                                  ledger), B6 (policy
 *                                                  storage)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2 (reference-id
 *                                                  ordering), §3 (the
 *                                                  invariant that a
 *                                                  restructure preserves)
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.6                                 — `AmortizationCalculator`
 *   Phase 2C A.7                                 — `LoanPolicyKeys`
 *   Phase 2C A.12                                — `loan_restructurings`
 *                                                  migration
 *   Phase 2C A.21                                — `LoanRestructuring` (the
 *                                                  model)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.27                                — `LoanScheduleService`
 *   Phase 2C A.30                                — `LoanDisbursementService`
 *                                                  (the sibling top-level
 *                                                  service that also
 *                                                  generates a schedule
 *                                                  and rewrites the
 *                                                  loan's terms)
 *   Phase 2C A.35                                — `LoanWriteOffService`
 *                                                  (the structural sibling
 *                                                  that also discharges
 *                                                  obligations)
 *   Phase 2B `ObligationService`                 — the Wallet write path
 *   CR-2C-WALLET-REFTYPE-001                     — Wallet ref-type map
 *   CR-2C-WALLET-OBLIGATION-LIFECYCLE-001        — adds `discharge()`
 *   Step 5 (manifest) A.34                       — This file
 *   Step 6 v1.1.0 §3.12                          — `loan_restructurings`
 *                                                  table specification
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Services
 * @version 1.0.1
 */
final class LoanRestructuringService
{
    /**
     * The `reference_type` value on loan-linked Wallet obligations.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN = 'Loan';

    /**
     * The `reference_type` value on the discharge entries this service
     * writes to the Wallet.
     *
     * Frozen by CR-2C-WALLET-OBLIGATION-LIFECYCLE-001.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN_RESTRUCTURE = 'LoanRestructure';

    /**
     * Monetary scale.
     *
     * @var int
     */
    private const FINAL_SCALE = 4;

    /**
     * Intermediate scale for the amortization computation.
     *
     * Matches the scale used by `AmortizationCalculator` (A.6) and
     * `LoanScheduleService` (A.27).
     *
     * @var int
     */
    private const INTERMEDIATE_SCALE = 20;

    /**
     * The number of months in a year.
     *
     * A9 freezes the periodicity as monthly. The monthly rate is
     * `annual_rate / 12`.
     *
     * @var int
     */
    private const MONTHS_PER_YEAR = 12;

    /**
     * The policy type a loan's policy version must carry.
     *
     * @var string
     */
    private const EXPECTED_POLICY_TYPE = 'loan_product_rules';

    /**
     * Constructor.
     *
     * Five dependencies:
     *
     *   - `LoanStateMachine` — for the two status transitions.
     *
     *   - `LoanScheduleService` — to persist the new schedule at step 12.
     *
     *   - `WalletRepository` — tenant-scoped Wallet resolution.
     *
     *   - `ObligationService` — for the discharge calls.
     *
     *   - `AmortizationCalculator` — to compute the summary values at
     *     step 7 (see the class docblock §"The summary computation at
     *     step 7").
     *
     * @param LoanStateMachine       $stateMachine
     * @param LoanScheduleService    $scheduleService
     * @param WalletRepository       $walletRepository
     * @param ObligationService      $obligationService
     * @param AmortizationCalculator $calculator
     */
    public function __construct(
        private readonly LoanStateMachine $stateMachine,
        private readonly LoanScheduleService $scheduleService,
        private readonly WalletRepository $walletRepository,
        private readonly ObligationService $obligationService,
        private readonly AmortizationCalculator $calculator,
    ) {
    }

    // =========================================================================
    // Public API
    // =========================================================================
    /**
     * Restructure a loan.
     *
     * See the class docblock §"The frozen restructure flow" for the full
     * sequence of operations.
     *
     * @param Loan                 $loan
     * @param string               $newPrincipalAmount  Decimal string, at
     *                                                   scale 4.
     * @param int                  $newTermMonths
     * @param int                  $newPolicyVersionId
     * @param string               $reason              Required,
     *                                                   non-empty.
     * @param CarbonInterface|null $asOf                The restructure
     *                                                   moment; defaults
     *                                                   to `now()`.
     * @param int|null             $actorUserId
     * @return Loan  The refreshed loan in `active` state.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     * @throws LogicException
     */
    public function restructure(
        Loan $loan,
        string $newPrincipalAmount,
        int $newTermMonths,
        int $newPolicyVersionId,
        string $reason,
        ?CarbonInterface $asOf = null,
        ?int $actorUserId = null,
    ): Loan {
        return DB::transaction(function () use (
            $loan,
            $newPrincipalAmount,
            $newTermMonths,
            $newPolicyVersionId,
            $reason,
            $asOf,
            $actorUserId,
        ): Loan {
            // -----------------------------------------------------------------
            // 1. Validate the inputs.
            // -----------------------------------------------------------------
            $this->assertReasonIsProvided($reason);
            $this->assertNewTermsAreValid($newPrincipalAmount, $newTermMonths);

            // -----------------------------------------------------------------
            // 2. Read the loan (no lock).
            // -----------------------------------------------------------------
            /** @var Loan $current */
            $current = Loan::query()
                ->whereKey($loan->id)
                ->firstOrFail();

            // -----------------------------------------------------------------
            // 3. Assert the loan is restructurable.
            // -----------------------------------------------------------------
            $this->assertLoanIsRestructurable($current);

            // -----------------------------------------------------------------
            // 4. Resolve the borrower and Wallet.
            // -----------------------------------------------------------------
            $current->loadMissing('borrower');

            $borrower = $current->borrower;

            if ($borrower === null) {
                throw new LogicException(
                    sprintf(
                        'Loan id=%d has no borrower. Data-integrity defect.',
                        $current->id,
                    )
                );
            }

            $wallet = $this->walletRepository->findForShareholder(
                (int) $current->tenant_id,
                (int) $borrower->id,
            );

            if ($wallet === null) {
                throw new LogicException(
                    sprintf(
                        'Borrower id=%d has no Wallet, yet loan id=%d is '
                        . 'restructurable. Data-integrity defect.',
                        $borrower->id,
                        $current->id,
                    )
                );
            }

            // -----------------------------------------------------------------
            // 5. Capture the loan's previous terms.
            // -----------------------------------------------------------------
            $previousPolicyVersionId = $current->frozen_policy_version_id;
            $previousOutstandingPrincipal = (string) ($current->outstanding_principal ?? '0');
            $previousOutstandingInterest  = (string) ($current->outstanding_interest  ?? '0');
            $previousOutstandingPenalty   = (string) $current->outstanding_penalty;
            $previousMaturityDate         = $current->maturity_date;

            $previousInstallmentCount = $this->countActiveInstallments($current);

            // -----------------------------------------------------------------
            // 6. Resolve and validate the new policy version.
            // -----------------------------------------------------------------
            $newPolicy = $this->resolveAndValidateNewPolicy(
                $newPolicyVersionId,
                (int) $current->tenant_id,
            );

            // -----------------------------------------------------------------
            // 7. Compute the new schedule's summary values WITHOUT
            //    persisting anything. See the class docblock
            //    §"The summary computation at step 7".
            // -----------------------------------------------------------------
            $moment = $asOf !== null
                ? Carbon::instance($asOf)
                : Carbon::now();

            $summary = $this->computeScheduleSummary(
                principal:        $newPrincipalAmount,
                termMonths:       $newTermMonths,
                policy:           $newPolicy,
                referenceDate:    $moment,
            );

            // -----------------------------------------------------------------
            // 8. INSERT the restructuring row.
            // -----------------------------------------------------------------
            /** @var LoanRestructuring $restructuring */
            $restructuring = LoanRestructuring::create([
                'loan_id'                           => $current->id,
                'previous_policy_version_id'        => $previousPolicyVersionId,
                'previous_outstanding_principal'    => $previousOutstandingPrincipal,
                'previous_outstanding_interest'     => $previousOutstandingInterest,
                'previous_outstanding_penalty'      => $previousOutstandingPenalty,
                'previous_maturity_date'            => $previousMaturityDate,
                'previous_installment_count'        => $previousInstallmentCount,
                'new_policy_version_id'             => $newPolicyVersionId,
                'new_principal_amount'              => $newPrincipalAmount,
                'new_term_months'                   => $newTermMonths,
                'new_maturity_date'                 => $summary['maturity_date'],
                'new_total_scheduled_repayment'     => $summary['total_scheduled_repayment'],
                'new_installment_count'             => $summary['installment_count'],
                'reason'                            => $reason,
                'restructured_at'                   => $moment,
                'restructured_by'                   => $actorUserId,
            ]);

            $restructuringId = (int) $restructuring->getKey();

            // -----------------------------------------------------------------
            // 9. Discharge every outstanding Wallet obligation linked to
            //    the loan.
            // -----------------------------------------------------------------
            $this->dischargeLoanObligations(
                wallet:        $wallet,
                loan:          $current,
                restructuringId: $restructuringId,
                reason:        $reason,
                actorUserId:   $actorUserId,
            );

            // -----------------------------------------------------------------
            // 10. Supersede the prior schedule's unsettled installments.
            // -----------------------------------------------------------------
            $this->supersedeUnsettledInstallments($current);

            // -----------------------------------------------------------------
            // 11. Overwrite the loan's terms with the new values.
            //
            // The schedule service at step 12 reads these fields, so they
            // must be updated first.
            // -----------------------------------------------------------------
            $current->principal_amount        = $newPrincipalAmount;
            $current->term_months             = $newTermMonths;
            $current->frozen_policy_version_id = $newPolicyVersionId;
            $current->save();

            // -----------------------------------------------------------------
            // 12. Generate the new installment schedule.
            //
            // The schedule service updates the loan's summary columns
            // (`monthly_installment_amount`, `total_scheduled_interest`,
            // `total_scheduled_repayment`, `maturity_date`). The values
            // it writes must agree with the summary computed at step 7;
            // they do, because the two computations use identical inputs.
            // -----------------------------------------------------------------
            $this->scheduleService->generate($current, $moment);

            $current->refresh();

            // -----------------------------------------------------------------
            // 13. Set the loan's three outstanding projections to
            //     reflect the NEW contractual debt.
            //
            // The prior loan-linked obligations were discharged at step
            // 9; the new schedule's interest obligations will be
            // recorded by `LoanInterestAccrualService` (A.32) as each
            // installment's due date arrives, at which point the loan's
            // projections will be adjusted incrementally. Immediately
            // after the restructure, the loan's outstanding principal
            // equals the newly-agreed principal, and interest and
            // penalty are zero.
            // -----------------------------------------------------------------
            $current->outstanding_principal = $newPrincipalAmount;
            $current->outstanding_interest  = '0.0000';
            $current->outstanding_penalty   = '0.0000';
            $current->save();

            // -----------------------------------------------------------------
            // 14. Transition `in_arrears → restructured`, dispatching
            //     `LoanRestructured` after commit.
            // -----------------------------------------------------------------
            $event = new LoanRestructured(
                $current,
                $restructuring,
                $actorUserId,
            );

            $current = $this->stateMachine->transition(
                loan: $current,
                nextStatus: LoanStatus::Restructured,
                reason: $reason,
                actorUserId: $actorUserId,
                afterCommitEvent: $event,
            );

            // -----------------------------------------------------------------
            // 15. Transition `restructured → active`, no event.
            //
            // The `restructured` state is transient: it settles into
            // `active` in the same transaction. The second transition
            // carries no actor (it is automatic) and no event (the
            // audit-relevant milestone was the first transition).
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

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert the reason is non-empty.
     *
     * @param string $reason
     * @return void
     *
     * @throws ValidationException
     */
    private function assertReasonIsProvided(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => [
                    'A restructure reason is required. Supply a non-empty '
                    . 'explanation of why the loan is being restructured.',
                ],
            ]);
        }
    }

    /**
     * Assert the new terms are well-formed.
     *
     * @param string $newPrincipalAmount
     * @param int    $newTermMonths
     * @return void
     *
     * @throws ValidationException
     */
    private function assertNewTermsAreValid(
        string $newPrincipalAmount,
        int $newTermMonths,
    ): void {
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $newPrincipalAmount)) {
            throw ValidationException::withMessages([
                'new_principal_amount' => [
                    'The new principal amount must be a positive decimal '
                    . 'string with at most four decimal places.',
                ],
            ]);
        }

        if (bccomp($newPrincipalAmount, '0', self::FINAL_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'new_principal_amount' => [
                    'The new principal amount must be strictly greater '
                    . 'than zero.',
                ],
            ]);
        }

        if ($newTermMonths < 1) {
            throw ValidationException::withMessages([
                'new_term_months' => [
                    'The new term must be at least 1 month.',
                ],
            ]);
        }
    }

    /**
     * Assert the loan is in a restructurable state.
     *
     * Eligible state: `in_arrears`. See the class docblock §"Scope".
     *
     * @param Loan $loan
     * @return void
     *
     * @throws ValidationException
     */
    private function assertLoanIsRestructurable(Loan $loan): void
    {
        if ($loan->status === LoanStatus::InArrears) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'A loan in status "%s" cannot be restructured. The '
                    . 'frozen transition matrix permits only '
                    . '"in_arrears → restructured".',
                    $loan->status->value,
                ),
            ],
        ]);
    }

    /**
     * Resolve and validate the new policy version.
     *
     * The version must exist within the loan's tenant and carry the
     * expected `policy_type`. See the class docblock §"The new policy
     * version validation" for the deferred same-product check.
     *
     * @param int $newPolicyVersionId
     * @param int $tenantId
     * @return VersionedPolicy
     *
     * @throws ValidationException
     */
    private function resolveAndValidateNewPolicy(
        int $newPolicyVersionId,
        int $tenantId,
    ): VersionedPolicy {
        /** @var VersionedPolicy|null $policy */
        $policy = VersionedPolicy::query()
            ->whereKey($newPolicyVersionId)
            ->first();

        if ($policy === null) {
            throw ValidationException::withMessages([
                'new_policy_version_id' => [
                    sprintf(
                        'No policy version exists with id=%d.',
                        $newPolicyVersionId,
                    ),
                ],
            ]);
        }

        if ((string) $policy->policy_type !== self::EXPECTED_POLICY_TYPE) {
            throw ValidationException::withMessages([
                'new_policy_version_id' => [
                    sprintf(
                        'Policy version id=%d is of type "%s"; the '
                        . 'restructure requires a policy of type "%s".',
                        $newPolicyVersionId,
                        $policy->policy_type,
                        self::EXPECTED_POLICY_TYPE,
                    ),
                ],
            ]);
        }

        // The tenant filter is a soft check: the tenant on a
        // VersionedPolicy is expected to be non-null in production.
        // A null tenant on the policy is treated as "shared across
        // tenants" and accepted; a mismatched non-null tenant is
        // rejected.
        if (
            isset($policy->tenant_id)
            && $policy->tenant_id !== null
            && (int) $policy->tenant_id !== $tenantId
        ) {
            throw ValidationException::withMessages([
                'new_policy_version_id' => [
                    sprintf(
                        'Policy version id=%d belongs to a different '
                        . 'tenant. Cross-tenant policy references are '
                        . 'forbidden.',
                        $newPolicyVersionId,
                    ),
                ],
            ]);
        }

        return $policy;
    }

    // =========================================================================
    // Private — previous-state capture
    // =========================================================================

    /**
     * Count the loan's active (non-superseded) installments.
     *
     * @param Loan $loan
     * @return int
     */
    private function countActiveInstallments(Loan $loan): int
    {
        return LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->whereNotIn('status', [InstallmentStatus::Superseded->value])
            ->count();
    }

    // =========================================================================
    // Private — new schedule summary computation
    // =========================================================================

    /**
     * Compute the new schedule's summary values.
     *
     * See the class docblock §"The summary computation at step 7" for the
     * rationale on computing the summary before the schedule is persisted.
     *
     * @param string          $principal
     * @param int             $termMonths
     * @param VersionedPolicy $policy
     * @param CarbonInterface $referenceDate
     * @return array{maturity_date: string, total_scheduled_repayment: string, installment_count: int}
     *
     * @throws LogicException
     */
    private function computeScheduleSummary(
        string $principal,
        int $termMonths,
        VersionedPolicy $policy,
        CarbonInterface $referenceDate,
    ): array {
        $payload = $policy->payload;

        if (!is_array($payload)) {
            throw new LogicException(
                sprintf(
                    'Policy version id=%d has a non-array payload.',
                    $policy->id,
                ),
            );
        }

        $annualRate     = $this->extractAnnualRate($payload);
        $principalGrace = $this->extractPrincipalGrace($payload);

        if ($principalGrace >= $termMonths) {
            throw ValidationException::withMessages([
                'new_policy_version_id' => [
                    sprintf(
                        'The policy\'s principal grace period (%d months) '
                        . 'must be strictly less than the new term '
                        . '(%d months).',
                        $principalGrace,
                        $termMonths,
                    ),
                ],
            ]);
        }

        $amortizingTerm = $termMonths - $principalGrace;

        $amortizing = $this->calculator->schedule(
            $principal,
            $annualRate,
            $amortizingTerm,
        );

        $totalPrincipal = '0.0000';
        $totalInterest  = '0.0000';

        if ($principalGrace > 0) {
            $monthlyRate = bcdiv(
                $annualRate,
                (string) self::MONTHS_PER_YEAR,
                self::INTERMEDIATE_SCALE,
            );

            $graceInterest = $this->halfUp(
                bcmul($principal, $monthlyRate, self::INTERMEDIATE_SCALE),
                self::FINAL_SCALE,
            );

            for ($i = 0; $i < $principalGrace; $i++) {
                $totalInterest = bcadd($totalInterest, $graceInterest, self::FINAL_SCALE);
            }
        }

        foreach ($amortizing['installments'] as $inst) {
            $totalPrincipal = bcadd(
                $totalPrincipal,
                (string) $inst['expected_principal'],
                self::FINAL_SCALE,
            );
            $totalInterest = bcadd(
                $totalInterest,
                (string) $inst['expected_interest'],
                self::FINAL_SCALE,
            );
        }

        $totalRepayment = bcadd($totalPrincipal, $totalInterest, self::FINAL_SCALE);

        $maturityDate = Carbon::instance($referenceDate)
            ->copy()
            ->addMonthsNoOverflow($termMonths)
            ->toDateString();

        return [
            'maturity_date'             => $maturityDate,
            'total_scheduled_repayment' => $totalRepayment,
            'installment_count'         => $termMonths,
        ];
    }

    /**
     * Extract the annual rate from a policy payload.
     *
     * @param array<string, mixed> $payload
     * @return string
     *
     * @throws LogicException
     */
    private function extractAnnualRate(array $payload): string
    {
        $interestSection = $payload[LoanPolicyKeys::INTEREST_SECTION] ?? null;

        if (!is_array($interestSection)) {
            throw new LogicException(
                sprintf(
                    'The loan_product_rules payload is missing the "%s" '
                    . 'section.',
                    LoanPolicyKeys::INTEREST_SECTION,
                ),
            );
        }

        $annualRate = $interestSection[LoanPolicyKeys::INTEREST_ANNUAL_RATE] ?? null;

        if (!is_string($annualRate) || $annualRate === '') {
            throw new LogicException(
                sprintf(
                    'The loan_product_rules payload is missing the '
                    . '"%s.%s" value.',
                    LoanPolicyKeys::INTEREST_SECTION,
                    LoanPolicyKeys::INTEREST_ANNUAL_RATE,
                ),
            );
        }

        return $annualRate;
    }

    /**
     * Extract the principal grace period from a policy payload.
     *
     * @param array<string, mixed> $payload
     * @return int
     */
    private function extractPrincipalGrace(array $payload): int
    {
        $termSection = $payload[LoanPolicyKeys::TERM_SECTION] ?? null;

        if (!is_array($termSection)) {
            return 0;
        }

        $value = $termSection[LoanPolicyKeys::TERM_PRINCIPAL_GRACE_MONTHS] ?? 0;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Round a decimal string to a target scale using half-up rounding.
     *
     * ------------------------------------------------------------------
     * Revision note (v1.0.1)
     * ------------------------------------------------------------------
     * This method is a byte-for-byte copy of
     * `AmortizationCalculator::halfUp()` (A.6, patched in the same
     * deployment as v1.0.1) and `LoanScheduleService::halfUp()`
     * (A.27, patched in the same deployment as v1.0.1).
     *
     * The previous construction of the half-ULP was
     * `'0.' . str_repeat('0', $scale - 1) . '5'`, which at `scale = 4`
     * produces `'0.0005'` (the half-ULP of scale 3, not scale 4). The
     * corrected construction is `'0.' . str_repeat('0', $scale) . '5'`,
     * which at `scale = 4` produces `'0.00005'`. See the
     * `AmortizationCalculator` v1.0.1 revision note for a worked
     * example.
     *
     * All four copies MUST be kept in lockstep pending a follow-up
     * Change Request that extracts the helper into a shared trait.
     *
     * @param string $value
     * @param int    $scale
     * @return string
     */
    private function halfUp(string $value, int $scale): string
    {
        $sign = '';

        if (str_starts_with($value, '-')) {
            $sign  = '-';
            $value = substr($value, 1);
        } elseif (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        }

        $halfUlp = $scale === 0
            ? '0.5'
            : '0.' . str_repeat('0', $scale) . '5';

        $shifted   = bcadd($value, $halfUlp, self::INTERMEDIATE_SCALE);
        $truncated = bcadd($shifted, '0', $scale);

        return $sign . $truncated;
    }

    // =========================================================================
    // Private — discharge and supersede
    // =========================================================================
    /**
     * Discharge every outstanding Wallet obligation linked to the loan.
     *
     * See the class docblock §"Discharging the loan's Wallet
     * obligations".
     *
     * @param Wallet $wallet
     * @param Loan     $loan
     * @param int      $restructuringId
     * @param string   $reason
     * @param int|null $actorUserId
     * @return void
     */
    private function dischargeLoanObligations(
        Wallet $wallet,
        Loan $loan,
        int $restructuringId,
        string $reason,
        ?int $actorUserId,
    ): void {
        /** @var Collection<int, Obligation> $obligations */
        $obligations = Obligation::query()
            ->where('wallet_id', $wallet->id)
            ->where('reference_type', self::WALLET_REFERENCE_TYPE_LOAN)
            ->where('reference_id', (string) $loan->id)
            ->where('outstanding_amount', '>', 0)
            ->orderBy('id')
            ->get();

        foreach ($obligations as $obligation) {
            $this->obligationService->discharge(
                wallet:        $wallet,
                obligation:    $obligation,
                entryType:     WalletEntryType::ObligationCancelled,
                reason:        $reason,
                referenceType: self::WALLET_REFERENCE_TYPE_LOAN_RESTRUCTURE,
                referenceId:   (string) $restructuringId,
                createdBy:     $actorUserId,
            );
        }
    }

    /**
     * Supersede the loan's unsettled installments.
     *
     * See the class docblock §"Superseding the prior schedule's
     * unsettled installments".
     *
     * The query uses an EXPLICIT exclusion list — `Settled` and
     * `Superseded` — rather than relying on
     * `InstallmentStatus::unsettledValues()`. The helper's membership
     * is not guaranteed to align with the frozen contract of this step
     * (in the current enum it includes `Settled`, which would violate
     * the preservation rule). The explicit list keeps the service
     * correct regardless of any future revision to the helper.
     *
     * @param Loan $loan
     * @return void
     */
    private function supersedeUnsettledInstallments(Loan $loan): void
    {
        LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->whereNotIn('status', [
                InstallmentStatus::Settled->value,
                InstallmentStatus::Superseded->value,
            ])
            ->update([
                'status' => InstallmentStatus::Superseded->value,
            ]);
    }
}