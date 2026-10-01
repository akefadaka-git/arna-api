<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Listeners;

use Illuminate\Support\Facades\Log;
use LogicException;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanApplicationSubmitted;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanApproved;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanCollateralReleased;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanClosed;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanDisbursed;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanEnteredArrears;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanInstallmentSettled;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanPenaltyRecorded;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRejected;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRepaymentRecorded;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRestructured;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanSettled;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanWrittenOff;
use Throwable;

/**
 * AuditLoanEvent
 *
 * The single listener for all thirteen Loan-domain business events. Writes
 * one audit record per dispatched event through the Core `AuditService`,
 * with a payload that captures the event's identity fields and its
 * event-specific data.
 *
 * =============================================================================
 * Scope — one listener for the whole domain
 * =============================================================================
 * The Loan domain registers exactly one listener against the thirteen
 * event classes, in `LoanServiceProvider::registerEventListeners()`
 * (A.85):
 *
 *     Event::listen(LoanApplicationSubmitted::class, AuditLoanEvent::class);
 *     Event::listen(LoanApproved::class,             AuditLoanEvent::class);
 *     Event::listen(LoanRejected::class,             AuditLoanEvent::class);
 *     Event::listen(LoanDisbursed::class,            AuditLoanEvent::class);
 *     Event::listen(LoanRepaymentRecorded::class,    AuditLoanEvent::class);
 *     Event::listen(LoanInstallmentSettled::class,   AuditLoanEvent::class);
 *     Event::listen(LoanEnteredArrears::class,       AuditLoanEvent::class);
 *     Event::listen(LoanPenaltyRecorded::class,      AuditLoanEvent::class);
 *     Event::listen(LoanRestructured::class,         AuditLoanEvent::class);
 *     Event::listen(LoanWrittenOff::class,           AuditLoanEvent::class);
 *     Event::listen(LoanSettled::class,              AuditLoanEvent::class);
 *     Event::listen(LoanClosed::class,               AuditLoanEvent::class);
 *     Event::listen(LoanCollateralReleased::class,   AuditLoanEvent::class);
 *
 * The listener's `handle()` method branches on the event class via
 * `match (true)` and dispatches to a per-event private method that builds
 * the audit payload and calls the Core `AuditService`. The pattern is
 * identical to `AuditShareholderEvent` (2A.35) and `AuditWalletEvent`
 * (2B.47); this file mirrors those implementations in shape so that a
 * reader who understands one understands all three.
 *
 * =============================================================================
 * The thirteen event types and their payload shapes
 * =============================================================================
 * The audit event-type strings are frozen by the manifest (Step 5) and are
 * the SAME strings documented on the corresponding event classes. Every
 * audit record produced by this listener carries one of the following
 * event_type values:
 *
 *   1.  'loan.application.submitted'   (A.38 LoanApplicationSubmitted)
 *           Carries: Loan $loan, ?int $actorUserId
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    actor_user_id, submitted_by (nil-able; same as
 *                    actor_user_id; included for audit-stream
 *                    readability)
 *
 *   2.  'loan.approved'                (A.39 LoanApproved)
 *           Carries: Loan $loan
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    approved_by_user_id, frozen_policy_version_id,
 *                    decision_date
 *
 *   3.  'loan.rejected'                (A.40 LoanRejected)
 *           Carries: Loan $loan, string $reason
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    rejection_reason, decision_date
 *
 *   4.  'loan.disbursed'               (A.41 LoanDisbursed)
 *           Carries: Loan $loan, int $walletId, int $walletObligationId
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    wallet_id, wallet_obligation_id, disbursement_date
 *
 *   5.  'loan.repayment.recorded'      (A.42 LoanRepaymentRecorded)
 *           Carries: Loan $loan, Payment $payment,
 *                    array<PaymentAllocation> $allocations, string $amount
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    payment_id, wallet_id, amount, unapplied_amount,
 *                    allocation_count, allocation_ids,
 *                    allocated_obligation_ids, received_at
 *
 *   6.  'loan.installment.settled'     (A.43 LoanInstallmentSettled)
 *           Carries: Loan $loan, LoanInstallment $installment,
 *                    ?int $actorUserId
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    installment_id, installment_number, due_date,
 *                    expected_total, settled_amount, actor_user_id
 *
 *   7.  'loan.entered_arrears'         (A.44 LoanEnteredArrears)
 *           Carries: Loan $loan, LoanInstallment $installment,
 *                    ?int $actorUserId
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    installment_id, installment_number, due_date,
 *                    expected_total, settled_amount, outstanding_penalty,
 *                    actor_user_id
 *
 *   8.  'loan.penalty.recorded'        (A.45 LoanPenaltyRecorded)
 *           Carries: Loan $loan, LoanInstallment $installment,
 *                    LoanPenaltyAccrual $accrual, ?int $actorUserId
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    installment_id, installment_number,
 *                    accrual_id, penalty_amount, wallet_obligation_id,
 *                    accrual_period_key, outstanding_penalty,
 *                    recorded_at, actor_user_id
 *
 *   9.  'loan.restructured'            (A.46 LoanRestructured)
 *           Carries: Loan $loan, LoanRestructuring $restructuring,
 *                    ?int $actorUserId
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    restructuring_id, reason,
 *                    previous_outstanding_total, new_principal_amount,
 *                    new_term_months, is_principal_increase,
 *                    is_principal_decrease, is_principal_unchanged,
 *                    has_policy_changed, restructured_at, actor_user_id
 *
 *  10.  'loan.written_off'             (A.47 LoanWrittenOff)
 *           Carries: Loan $loan, LoanWriteOff $writeOff, ?int $actorUserId
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    write_off_id, write_off_amount, reason,
 *                    outstanding_principal_at_writeoff,
 *                    outstanding_interest_at_writeoff,
 *                    outstanding_penalty_at_writeoff, is_full_write_off,
 *                    written_off_at, actor_user_id
 *
 *  11.  'loan.settled'                (A.48 LoanSettled)
 *           Carries: Loan $loan
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    principal_amount, settled_amount,
 *                    total_scheduled_repayment, maturity_date
 *
 *  12.  'loan.closed'                 (A.49 LoanClosed) — RESERVED
 *           Carries: Loan $loan
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    principal_amount, settled_amount, closed_at
 *
 *           NOTE: no Phase 2C code path dispatches LoanClosed. This arm
 *           is present for completeness and for the future closure CR;
 *           it will not execute under Phase 2C.
 *
 *  13.  'loan.collateral.released'    (A.50 LoanCollateralReleased)
 *           Carries: Loan $loan, LoanCollateral $collateral,
 *                    string $reason, ?int $actorUserId
 *           Payload: loan_id, tenant_id, borrower_id, loan_product_id,
 *                    collateral_id, collateral_type, description,
 *                    is_external, is_shareholding, released_at,
 *                    release_reason, actor_user_id
 *
 * =============================================================================
 * The failure-isolation contract
 * =============================================================================
 * The listener MUST NOT propagate an exception to the event dispatcher.
 * This is a load-bearing property of the platform's audit-trail design:
 *
 *   - The business operation that produced the event has ALREADY
 *     committed its transaction by the time the after-commit dispatch
 *     fires. An exception from a listener at that point cannot roll
 *     back the business fact; it can only fail the request AFTER the
 *     fact has been durably written. Rolling back is impossible;
 *     propagating is useless; the ONLY correct response is to log and
 *     continue.
 *
 *   - A broken audit-write path (e.g. a transient DB outage on the
 *     `audit_logs` table) must not turn a successful loan disbursement
 *     into a 500 response to the operator. The operator's action has
 *     succeeded; the response should reflect that.
 *
 *   - The audit trail is a best-effort secondary record. Its failure
 *     is a monitored operational event (via the log channel), not a
 *     user-facing error.
 *
 * The implementation follows the pattern established by
 * `AuditShareholderEvent` (2A.35) and `AuditWalletEvent` (2B.47):
 *
 *   1. The ENTIRE body of `handle()` is wrapped in a single
 *      `try { ... } catch (Throwable $e) { ... }` block.
 *
 *   2. The catch block writes a structured error to the `Log` facade
 *      with enough context to triage the failure (the event class, the
 *      event's primary identifiers, and the exception's message and
 *      class).
 *
 *   3. The catch block returns WITHOUT re-throwing. The exception is
 *      swallowed from the dispatcher's perspective.
 *
 * The `match (true)` block's `default` arm throws `LogicException`. This
 * arm is UNREACHABLE under normal registration: every event class that
 * the provider registers against this listener has a matching arm. The
 * default exists as a defensive guard against a future contributor who
 * registers an event class without adding its arm here. When the default
 * fires, the outer catch absorbs it and logs it — the same failure-
 * isolation contract applies.
 *
 * =============================================================================
 * The payload shape
 * =============================================================================
 * Every audit record is written through
 * `AuditService::log(eventType: ..., data: [...])`. The `data` array for
 * each event follows a consistent shape:
 *
 *   - Identity (present on every event):
 *         tenant_id       — the loan's tenant
 *         loan_id         — the loan's primary key
 *         borrower_id     — the borrower's shareholder id
 *         loan_product_id — the loan product's primary key
 *
 *   - Actor (present where the event carries one):
 *         actor_user_id   — nullable; system-actor events set it to null
 *
 *   - Event-specific fields — a stable, small set of scalar values per
 *     event, chosen so that a reader of the audit log can reconstruct
 *     the essential facts of the event without a join to the domain
 *     tables. Amounts are decimal strings at scale 4; timestamps are
 *     ISO 8601 strings; identifiers are integers.
 *
 * The `AuditService::log()` call uses named arguments
 * (`eventType:`, `data:`) so that a reader at the call site sees the
 * intent at a glance. The service's other parameters (if any) are
 * supplied by the service's own configuration; this listener does not
 * override them.
 *
 * =============================================================================
 * What this listener deliberately does NOT do
 * =============================================================================
 *   - It does NOT propagate exceptions. See §"The failure-isolation
 *     contract".
 *
 *   - It does NOT open a transaction. The listener runs AFTER the
 *     business transaction has committed; opening a new transaction
 *     for the audit write would be a separate unit of work, which the
 *     `AuditService` owns internally if needed.
 *
 *   - It does NOT mutate any of the carried models. The events'
 *     properties are `readonly`; the listener reads them and does
 *     nothing else.
 *
 *   - It does NOT dispatch any further events. The audit write is
 *     terminal from the dispatch pipeline's perspective; no
 *     `AuditRecorded` event is emitted.
 *
 *   - It does NOT branch on the business meaning of the event. The
 *     listener is a mechanical translator from event shape to audit
 *     payload. Any business-level interpretation (e.g. "the loan is
 *     now in arrears") belongs to dedicated listeners registered by a
 *     future CR.
 *
 *   - It does NOT filter events by any criterion. Every dispatched
 *     instance of every registered event class produces exactly one
 *     audit record. The dispatch itself is the only gate (via
 *     `config('loan.audit.emit_events')`, applied by the services and
 *     the state machine BEFORE the listener is invoked).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — all relevant decisions
 *                                                  (this file is the
 *                                                  audit-trail
 *                                                  consolidator)
 *   Phase 2C Clarification Response, 2026-09-12  — C4, C5
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3
 *   Phase 2C A.38–A.50                           — the thirteen events
 *                                                  handled by this file
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *                                                  (the registration
 *                                                  site)
 *   Phase 2A 2A.35                               — `AuditShareholderEvent`
 *                                                  (the shape template)
 *   Phase 2B 2B.47                               — `AuditWalletEvent`
 *                                                  (the direct sibling)
 *   Step 5 (manifest) A.51                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Listeners
 * @version 1.0.0
 */
final class AuditLoanEvent
{
    /**
     * Constructor.
     *
     * One dependency: the Core `AuditService`, which owns the persisted
     * audit log table and exposes the `log(eventType:, data:)` entry
     * point. The service is resolved by the container; no explicit
     * binding is required beyond the Core Audit provider's own
     * registration.
     *
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly AuditService $auditService,
    ) {
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Handle a dispatched Loan-domain event.
     *
     * The entire body is wrapped in a `try/catch (Throwable)` block. An
     * exception from any step — including the `default` arm's
     * `LogicException` — is logged and swallowed. The listener NEVER
     * propagates an exception to the dispatcher. See the class docblock
     * §"The failure-isolation contract".
     *
     * The `match (true)` block dispatches on the event's concrete class.
     * Each arm calls a private method that builds the audit payload and
     * writes it through `AuditService::log()`.
     *
     * @param object $event  The dispatched event instance. Its concrete
     *                       class determines the audit payload.
     * @return void
     */
    public function handle(object $event): void
    {
        try {
            match (true) {
                $event instanceof LoanApplicationSubmitted
                    => $this->auditApplicationSubmitted($event),

                $event instanceof LoanApproved
                    => $this->auditApproved($event),

                $event instanceof LoanRejected
                    => $this->auditRejected($event),

                $event instanceof LoanDisbursed
                    => $this->auditDisbursed($event),

                $event instanceof LoanRepaymentRecorded
                    => $this->auditRepaymentRecorded($event),

                $event instanceof LoanInstallmentSettled
                    => $this->auditInstallmentSettled($event),

                $event instanceof LoanEnteredArrears
                    => $this->auditEnteredArrears($event),

                $event instanceof LoanPenaltyRecorded
                    => $this->auditPenaltyRecorded($event),

                $event instanceof LoanRestructured
                    => $this->auditRestructured($event),

                $event instanceof LoanWrittenOff
                    => $this->auditWrittenOff($event),

                $event instanceof LoanSettled
                    => $this->auditSettled($event),

                $event instanceof LoanClosed
                    => $this->auditClosed($event),

                $event instanceof LoanCollateralReleased
                    => $this->auditCollateralReleased($event),

                default => throw new LogicException(
                    sprintf(
                        'AuditLoanEvent received an unregistered event '
                        . 'class: %s. Every event class registered by '
                        . 'LoanServiceProvider must have a matching arm '
                        . 'in this listener\'s match (true) block. Add '
                        . 'the arm or remove the registration.',
                        $event::class,
                    ),
                ),
            };
        } catch (Throwable $e) {
            // -----------------------------------------------------------------
            // Failure isolation: log and return. Never propagate.
            //
            // The business transaction has already committed; the audit
            // trail is a best-effort secondary record. See the class
            // docblock §"The failure-isolation contract".
            // -----------------------------------------------------------------
            $this->logFailure($event, $e);
        }
    }

    // =========================================================================
    // Private — event handlers
    // =========================================================================

    /**
     * Handle a `LoanApplicationSubmitted` event.
     *
     * Audit event_type: `'loan.application.submitted'`.
     *
     * @param LoanApplicationSubmitted $event
     * @return void
     */
    private function auditApplicationSubmitted(LoanApplicationSubmitted $event): void
    {
        $this->auditService->log(
            eventType: 'loan.application.submitted',
            data: [
                'tenant_id'       => $event->tenantId(),
                'loan_id'         => $event->loanId(),
                'borrower_id'     => $event->borrowerId(),
                'loan_product_id' => $event->loanProductId(),
                'actor_user_id'   => $event->actorUserId(),
            ],
        );
    }

    /**
     * Handle a `LoanApproved` event.
     *
     * Audit event_type: `'loan.approved'`.
     *
     * @param LoanApproved $event
     * @return void
     */
    private function auditApproved(LoanApproved $event): void
    {
        $this->auditService->log(
            eventType: 'loan.approved',
            data: [
                'tenant_id'                => $event->tenantId(),
                'loan_id'                  => $event->loanId(),
                'borrower_id'              => $event->borrowerId(),
                'loan_product_id'          => $event->loanProductId(),
                'actor_user_id'            => $event->approvedByUserId(),
                'frozen_policy_version_id' => $event->frozenPolicyVersionId(),
                'decision_date'            => $event->decisionDate(),
            ],
        );
    }

    /**
     * Handle a `LoanRejected` event.
     *
     * Audit event_type: `'loan.rejected'`.
     *
     * @param LoanRejected $event
     * @return void
     */
    private function auditRejected(LoanRejected $event): void
    {
        $this->auditService->log(
            eventType: 'loan.rejected',
            data: [
                'tenant_id'        => $event->tenantId(),
                'loan_id'          => $event->loanId(),
                'borrower_id'      => $event->borrowerId(),
                'loan_product_id'  => $event->loanProductId(),
                'rejection_reason' => $event->reason(),
                'decision_date'    => $event->decisionDate(),
            ],
        );
    }

    /**
     * Handle a `LoanDisbursed` event.
     *
     * Audit event_type: `'loan.disbursed'`.
     *
     * @param LoanDisbursed $event
     * @return void
     */
    private function auditDisbursed(LoanDisbursed $event): void
    {
        $this->auditService->log(
            eventType: 'loan.disbursed',
            data: [
                'tenant_id'            => $event->tenantId(),
                'loan_id'              => $event->loanId(),
                'borrower_id'          => $event->borrowerId(),
                'loan_product_id'      => $event->loanProductId(),
                'wallet_id'            => $event->walletId(),
                'wallet_obligation_id' => $event->walletObligationId(),
                'disbursement_date'    => $event->disbursementDate(),
            ],
        );
    }

    /**
     * Handle a `LoanRepaymentRecorded` event.
     *
     * Audit event_type: `'loan.repayment.recorded'`.
     *
     * @param LoanRepaymentRecorded $event
     * @return void
     */
    private function auditRepaymentRecorded(LoanRepaymentRecorded $event): void
    {
        $this->auditService->log(
            eventType: 'loan.repayment.recorded',
            data: [
                'tenant_id'                 => $event->tenantId(),
                'loan_id'                   => $event->loanId(),
                'borrower_id'               => $event->borrowerId(),
                'loan_product_id'           => $event->loanProductId(),
                'payment_id'                => $event->paymentId(),
                'wallet_id'                 => $event->walletId(),
                'amount'                    => $event->amount(),
                'unapplied_amount'          => $event->unappliedAmount(),
                'allocation_count'          => $event->allocationCount(),
                'allocation_ids'            => $event->allocationIds(),
                'allocated_obligation_ids'  => $event->allocatedObligationIds(),
                'received_at'               => $event->receivedAt(),
                'actor_user_id'             => $event->recordedByUserId(),
            ],
        );
    }

    /**
     * Handle a `LoanInstallmentSettled` event.
     *
     * Audit event_type: `'loan.installment.settled'`.
     *
     * @param LoanInstallmentSettled $event
     * @return void
     */
    private function auditInstallmentSettled(LoanInstallmentSettled $event): void
    {
        $this->auditService->log(
            eventType: 'loan.installment.settled',
            data: [
                'tenant_id'          => $event->tenantId(),
                'loan_id'            => $event->loanId(),
                'borrower_id'        => $event->borrowerId(),
                'loan_product_id'    => $event->loanProductId(),
                'installment_id'     => $event->installmentId(),
                'installment_number' => $event->installmentNumber(),
                'due_date'           => $event->dueDate(),
                'expected_total'     => $event->expectedTotal(),
                'settled_amount'     => $event->settledAmount(),
                'actor_user_id'      => $event->actorUserId(),
            ],
        );
    }

    /**
     * Handle a `LoanEnteredArrears` event.
     *
     * Audit event_type: `'loan.entered_arrears'`.
     *
     * @param LoanEnteredArrears $event
     * @return void
     */
    private function auditEnteredArrears(LoanEnteredArrears $event): void
    {
        $this->auditService->log(
            eventType: 'loan.entered_arrears',
            data: [
                'tenant_id'           => $event->tenantId(),
                'loan_id'             => $event->loanId(),
                'borrower_id'         => $event->borrowerId(),
                'loan_product_id'     => $event->loanProductId(),
                'installment_id'      => $event->installmentId(),
                'installment_number'  => $event->installmentNumber(),
                'due_date'            => $event->dueDate(),
                'expected_total'      => $event->expectedTotal(),
                'settled_amount'      => $event->settledAmount(),
                'outstanding_penalty' => $event->outstandingPenalty(),
                'actor_user_id'       => $event->actorUserId(),
            ],
        );
    }

    /**
     * Handle a `LoanPenaltyRecorded` event.
     *
     * Audit event_type: `'loan.penalty.recorded'`.
     *
     * @param LoanPenaltyRecorded $event
     * @return void
     */
    private function auditPenaltyRecorded(LoanPenaltyRecorded $event): void
    {
        $this->auditService->log(
            eventType: 'loan.penalty.recorded',
            data: [
                'tenant_id'           => $event->tenantId(),
                'loan_id'             => $event->loanId(),
                'borrower_id'         => $event->borrowerId(),
                'loan_product_id'     => $event->loanProductId(),
                'installment_id'      => $event->installmentId(),
                'installment_number'  => $event->installmentNumber(),
                'accrual_id'          => $event->accrualId(),
                'penalty_amount'      => $event->penaltyAmount(),
                'wallet_obligation_id' => $event->walletObligationId(),
                'accrual_period_key'  => $event->accrualPeriodKey(),
                'outstanding_penalty' => $event->outstandingPenalty(),
                'recorded_at'         => $event->recordedAt(),
                'actor_user_id'       => $event->actorUserId(),
            ],
        );
    }

    /**
     * Handle a `LoanRestructured` event.
     *
     * Audit event_type: `'loan.restructured'`.
     *
     * @param LoanRestructured $event
     * @return void
     */
    private function auditRestructured(LoanRestructured $event): void
    {
        $this->auditService->log(
            eventType: 'loan.restructured',
            data: [
                'tenant_id'                   => $event->tenantId(),
                'loan_id'                     => $event->loanId(),
                'borrower_id'                 => $event->borrowerId(),
                'loan_product_id'             => $event->loanProductId(),
                'restructuring_id'            => $event->restructuringId(),
                'reason'                      => $event->reason(),
                'previous_outstanding_total'  => $event->previousOutstandingTotal(),
                'new_principal_amount'        => $event->newPrincipalAmount(),
                'new_term_months'             => $event->newTermMonths(),
                'is_principal_increase'       => $event->isPrincipalIncrease(),
                'is_principal_decrease'       => $event->isPrincipalDecrease(),
                'is_principal_unchanged'      => $event->isPrincipalUnchanged(),
                'has_policy_changed'          => $event->hasPolicyChanged(),
                'restructured_at'             => $event->restructuredAt(),
                'actor_user_id'               => $event->actorUserId(),
            ],
        );
    }

    /**
     * Handle a `LoanWrittenOff` event.
     *
     * Audit event_type: `'loan.written_off'`.
     *
     * @param LoanWrittenOff $event
     * @return void
     */
    private function auditWrittenOff(LoanWrittenOff $event): void
    {
        $this->auditService->log(
            eventType: 'loan.written_off',
            data: [
                'tenant_id'                               => $event->tenantId(),
                'loan_id'                                 => $event->loanId(),
                'borrower_id'                             => $event->borrowerId(),
                'loan_product_id'                         => $event->loanProductId(),
                'write_off_id'                            => $event->writeOffId(),
                'write_off_amount'                        => $event->writeOffAmount(),
                'reason'                                  => $event->reason(),
                'outstanding_principal_at_writeoff'       => $event->outstandingPrincipalAtWriteOff(),
                'outstanding_interest_at_writeoff'        => $event->outstandingInterestAtWriteOff(),
                'outstanding_penalty_at_writeoff'         => $event->outstandingPenaltyAtWriteOff(),
                'is_full_write_off'                       => $event->isFullWriteOff(),
                'written_off_at'                          => $event->writtenOffAt(),
                'actor_user_id'                           => $event->actorUserId(),
            ],
        );
    }

    /**
     * Handle a `LoanSettled` event.
     *
     * Audit event_type: `'loan.settled'`.
     *
     * @param LoanSettled $event
     * @return void
     */
    private function auditSettled(LoanSettled $event): void
    {
        $this->auditService->log(
            eventType: 'loan.settled',
            data: [
                'tenant_id'                 => $event->tenantId(),
                'loan_id'                   => $event->loanId(),
                'borrower_id'               => $event->borrowerId(),
                'loan_product_id'           => $event->loanProductId(),
                'principal_amount'          => $event->principalAmount(),
                'settled_amount'            => $event->settledAmount(),
                'total_scheduled_repayment' => $event->totalScheduledRepayment(),
                'maturity_date'             => $event->maturityDate(),
            ],
        );
    }

    /**
     * Handle a `LoanClosed` event — RESERVED.
     *
     * Audit event_type: `'loan.closed'`.
     *
     * NOTE: no Phase 2C code path dispatches `LoanClosed`. This handler
     * is present for completeness and for the future closure CR. See
     * the class docblock §"The thirteen event types" and A.49's class
     * docblock.
     *
     * @param LoanClosed $event
     * @return void
     */
    private function auditClosed(LoanClosed $event): void
    {
        $this->auditService->log(
            eventType: 'loan.closed',
            data: [
                'tenant_id'        => $event->tenantId(),
                'loan_id'          => $event->loanId(),
                'borrower_id'      => $event->borrowerId(),
                'loan_product_id'  => $event->loanProductId(),
                'principal_amount' => $event->principalAmount(),
                'settled_amount'   => $event->settledAmount(),
                'closed_at'        => $event->closedAt(),
            ],
        );
    }

    /**
     * Handle a `LoanCollateralReleased` event.
     *
     * Audit event_type: `'loan.collateral.released'`.
     *
     * @param LoanCollateralReleased $event
     * @return void
     */
    private function auditCollateralReleased(LoanCollateralReleased $event): void
    {
        $this->auditService->log(
            eventType: 'loan.collateral.released',
            data: [
                'tenant_id'        => $event->tenantId(),
                'loan_id'          => $event->loanId(),
                'borrower_id'      => $event->borrowerId(),
                'loan_product_id'  => $event->loanProductId(),
                'collateral_id'    => $event->collateralId(),
                'collateral_type'  => $event->collateralType(),
                'description'      => $event->description(),
                'is_external'      => $event->isExternal(),
                'is_shareholding'  => $event->isShareholding(),
                'released_at'      => $event->releasedAt(),
                'release_reason'   => $event->reason(),
                'actor_user_id'    => $event->actorUserId(),
            ],
        );
    }

    // =========================================================================
    // Private — failure logging
    // =========================================================================

    /**
     * Log a listener failure without propagating it.
     *
     * The context array is deliberately narrow and safe: it includes the
     * event's concrete class and the exception's class and message, but
     * it does NOT attempt to extract the event's domain identifiers.
     * The reason: the failure may have occurred at any point during the
     * per-event handler, including during the identifier extraction
     * itself. Attempting to reach into the event after a failure could
     * itself throw, producing a nested failure inside the catch block.
     * The safe choice is to log only what is guaranteed available: the
     * event's class name and the exception's class and message.
     *
     * A deployment operator triaging the log entry uses the event class
     * name to identify the affected handler and the exception message to
     * identify the root cause. Correlating the failure to a specific
     * loan is left to a follow-up investigation if needed; the audit
     * trail's absence for a specific event is the diagnostic clue.
     *
     * @param object    $event
     * @param Throwable $exception
     * @return void
     */
    private function logFailure(object $event, Throwable $exception): void
    {
        Log::error(
            'AuditLoanEvent: failed to write audit record.',
            [
                'event_class'     => $event::class,
                'exception_class' => $exception::class,
                'exception_msg'   => $exception->getMessage(),
            ],
        );
    }
}