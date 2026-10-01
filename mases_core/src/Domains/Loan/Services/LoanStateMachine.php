<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;

/**
 * LoanStateMachine
 *
 * The single, authoritative writer of a loan's lifecycle state.
 *
 * =============================================================================
 * Responsibility
 * =============================================================================
 * Every change to `loans.status` and every row in `loan_status_histories`
 * flows through this service. It is the sole writer of both. No controller,
 * no repository, no other service writes either. This is the invariant that
 * keeps the loan's lifecycle path reconstructible and auditable.
 *
 * The state machine performs four things, in order, on every transition:
 *
 *   1. STRUCTURAL VALIDATION — the proposed `from → to` pair is checked
 *      against the frozen twelve-state transition matrix (C4). A
 *      structurally illegal pair throws `ValidationException`.
 *
 *   2. GUARD VALIDATION — the transitions whose business meaning depends
 *      on operator input require a non-empty `transition_reason`. A
 *      transition that requires a reason but receives none throws
 *      `ValidationException`.
 *
 *   3. STATE WRITE — the loan's `status` column is updated to the target
 *      status. The write happens on a row-locked fresh read of the loan,
 *      so two concurrent transitions serialize instead of racing.
 *
 *   4. HISTORY WRITE — one row is appended to `loan_status_histories`
 *      recording the transition: the prior status, the new status, the
 *      actor (nullable for automatic transitions), the reason (nullable),
 *      and the moment of the change.
 *
 * The optional after-commit event is dispatched by the state machine on
 * behalf of the caller, via `DB::afterCommit()`. The event is CONSTRUCTED
 * by the caller (which has the domain-specific data the event carries) and
 * PASSED IN as a parameter; the state machine only manages the timing.
 *
 * =============================================================================
 * Caller's responsibility vs. state machine's responsibility
 * =============================================================================
 * The state machine does NOT enforce domain preconditions beyond the
 * structural matrix and the reason-required set. Specifically, it does
 * NOT check:
 *
 *   - whether the loan's fields are complete for submission
 *   - whether the borrower has a Wallet
 *   - whether all installments are settled before a settle transition
 *   - whether the collateral requirement is satisfied before approval
 *   - whether the restructure request's parameters are valid
 *   - whether the write-off reason satisfies any policy
 *
 * Those checks require loading additional data (the borrower, the Wallet,
 * the schedule, the product's policy). They are the calling service's
 * responsibility. The service performs its checks, then calls
 * `transition()` to persist the outcome. The state machine's contract is
 * narrow: it validates what it can validate without additional I/O
 * (structure and reason presence), and it persists the transition.
 *
 * This separation is deliberate. A state machine that loaded the borrower,
 * the Wallet, the schedule, and the policy on every transition would be a
 * mega-service that duplicated every other service's data-fetch logic. The
 * narrow state machine is a persistence primitive with a structural
 * contract.
 *
 * =============================================================================
 * Transaction discipline (mirrors WalletService::recordEntry, Phase 2B)
 * =============================================================================
 * This service does NOT open its own transaction. It REQUIRES that its
 * caller be inside one. The reason is identical to the reason documented
 * on `WalletService::recordEntry()` (Phase 2B):
 *
 *   1. The row lock (`lockForUpdate()`) is only meaningful inside a
 *      transaction. Outside one, the SELECT ... FOR UPDATE acquires and
 *      immediately releases the lock, defeating the purpose.
 *
 *   2. The calling service owns the transaction that wraps its OTHER
 *      writes (creating an obligation on the Wallet, generating a new
 *      schedule, discharging old obligations, etc.). The status change
 *      is one step of a larger atomic operation.
 *
 *   3. The after-commit event dispatch must fire after the OUTERMOST
 *      transaction commits, not after an inner savepoint. Letting the
 *      state machine open its own transaction would push the event
 *      registration inside a savepoint whose commit may not coincide
 *      with the caller's outermost commit.
 *
 * A defensive check at the top of `transition()` throws `LogicException`
 * if `DB::transactionLevel() === 0`. This is not a caller input error —
 * it is a programming defect — hence `LogicException`, not
 * `ValidationException`. The message names the defect explicitly.
 *
 * =============================================================================
 * Concurrency (row lock via SELECT ... FOR UPDATE)
 * =============================================================================
 * `transition()` acquires a row lock on the loan before reading its
 * current status. Two concurrent transitions against the same loan
 * serialize: the second caller blocks on the lock until the first commits,
 * then reads the updated status. Without the lock, two callers could both
 * read `active`, both pass the structural check for `active → written_off`,
 * and both attempt the write — one would succeed, the other would produce
 * a history row whose `from_status` disagreed with the loan's actual prior
 * status.
 *
 * The lock is held only for the duration of the caller's transaction. The
 * state machine performs no I/O beyond the two writes (loan status update
 * + history insert), so the lock is short-lived.
 *
 * This mirrors the pattern established by Phase 2A's
 * `ShareHoldingService::persistEntry()` (2A.14) and Phase 2B's
 * `WalletService::recordEntry()` (2B.15).
 *
 * =============================================================================
 * The transition matrix (C4, frozen)
 * =============================================================================
 * The structural matrix is declared on `LoanStatus::canTransitionTo()`
 * (A.2), not re-implemented here. This service delegates to the enum. The
 * matrix is:
 *
 *     draft         → submitted
 *     submitted     → under_review
 *     under_review  → approved | rejected
 *     approved      → disbursed
 *     disbursed     → active
 *     active        → in_arrears | settled | written_off
 *     in_arrears    → restructured | settled | written_off
 *     restructured  → active | settled | written_off
 *     settled       → closed
 *     rejected      → (none — terminal)
 *     written_off   → (none — terminal)
 *     closed        → (none — terminal)
 *
 * Every pair not listed above is structurally illegal. The state machine
 * throws `ValidationException` on any illegal pair before attempting any
 * write.
 *
 * =============================================================================
 * The reason-required set
 * =============================================================================
 * Five transitions require a non-empty `transition_reason`, because their
 * business meaning depends on the operator's judgment:
 *
 *     under_review  → rejected
 *     in_arrears    → restructured
 *     active        → written_off
 *     in_arrears    → written_off
 *     restructured  → written_off
 *
 * Every other transition carries an optional reason. The set is declared
 * as a constant on this class and enforced by
 * `assertReasonIsProvidedIfRequired()`.
 *
 * The set is FROZEN. A future Change Request that makes a new transition
 * require a reason (or relaxes one that currently does) updates the
 * constant and adds a test case.
 *
 * =============================================================================
 * The initial-creation row
 * =============================================================================
 * When a loan is created in the `draft` state (by
 * `LoanApplicationService::createDraft()`, A.27), the state machine's
 * `initialize()` method writes the loan's FIRST history row:
 *
 *     from_status = null
 *     to_status   = draft
 *     reason      = null
 *     actor       = the creator (or null)
 *
 * The row exists so that every loan has a complete lifecycle log whose
 * first entry explains the loan's entry into its initial state. No event
 * is dispatched on initialization — the domain does not have a
 * "LoanCreated" event, because creation is a draft-phase operation whose
 * audit-relevant follow-up is the submission (`LoanApplicationSubmitted`,
 * dispatched when `draft → submitted` fires).
 *
 * `initialize()` requires a surrounding transaction, like `transition()`.
 * It does NOT lock the loan row — a freshly-created loan cannot be the
 * target of a concurrent transition. It DOES insert the history row
 * atomically with the caller's insert of the loan row itself.
 *
 * =============================================================================
 * Event dispatch discipline
 * =============================================================================
 * The optional `$afterCommitEvent` parameter carries an event instance that
 * the state machine registers for post-commit dispatch via
 * `DB::afterCommit()`. The registration is gated on
 * `config('loan.audit.emit_events')` (A.1, default `true`).
 *
 * When the parameter is `null` — as it is for automatic transitions like
 * `submitted → under_review`, `disbursed → active`, and
 * `restructured → active` — no event is registered.
 *
 * The event is CONSTRUCTED by the caller, because the caller has the
 * domain-specific data the event carries. For example:
 *
 *     // Inside LoanApprovalService::approve()
 *     $event = new LoanApproved($loan, $actorId);
 *     $this->stateMachine->transition(
 *         loan:             $loan,
 *         nextStatus:       LoanStatus::Approved,
 *         actorUserId:      $actorId,
 *         afterCommitEvent: $event,
 *     );
 *
 * The state machine's job is only to register the dispatch AFTER the
 * outermost transaction commits. It does not need to know the event
 * class or its payload.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not open its own transaction. See §"Transaction discipline".
 *
 *   - It does not enforce domain preconditions beyond structure and
 *     reason-presence. See §"Caller's responsibility".
 *
 *   - It does not construct events. See §"Event dispatch discipline".
 *
 *   - It does not evaluate policies. The loan's frozen
 *     `loan_product_rules` policy version is referenced by the loan row;
 *     it plays no role in the state machine.
 *
 *   - It does not touch the Wallet, the Shareholder domain, the schedule,
 *     the collateral, the restructure records, the write-off records, or
 *     the accrual records. It writes exactly two things: `loans.status`
 *     and `loan_status_histories` rows.
 *
 *   - It does not expose a `canTransition()` method. The structural check
 *     is on `LoanStatus::canTransitionTo()` (A.2) and is available to
 *     callers that want to branch before calling `transition()`. The
 *     state machine's public API is `initialize()` and `transition()`.
 *
 *   - It does not expose a way to write a `loan_status_histories` row
 *     without changing the loan's status, or vice versa. The two writes
 *     are inseparable by design.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — C4 (state freeze)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (transition matrix)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3 (the
 *                                                  reference-id ordering
 *                                                  pattern that this
 *                                                  service's transaction
 *                                                  discipline supports)
 *   Phase 2A 2A.14                               — `ShareHoldingService`
 *                                                  (the row-lock and
 *                                                  transaction pattern)
 *   Phase 2B 2B.15                               — `WalletService`
 *                                                  (the same pattern)
 *   Step 5 (manifest) A.26                       — This file
 *   Step 6 v1.1.0 §3.14                          — `loan_status_histories`
 *                                                  table specification
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Services
 * @version 1.0.0
 */
final class LoanStateMachine
{
    /**
     * The set of transitions that require a non-empty
     * `transition_reason`.
     *
     * Each entry is a `[from, to]` pair of `LoanStatus` cases. The set is
     * frozen by the C4 clarification (2026-09-12).
     *
     * @var list<array{0: LoanStatus, 1: LoanStatus}>
     */
    private const REASON_REQUIRED_TRANSITIONS = [
        [LoanStatus::UnderReview,  LoanStatus::Rejected],
        [LoanStatus::InArrears,    LoanStatus::Restructured],
        [LoanStatus::Active,       LoanStatus::WrittenOff],
        [LoanStatus::InArrears,    LoanStatus::WrittenOff],
        [LoanStatus::Restructured, LoanStatus::WrittenOff],
    ];

    // =========================================================================
    // Public API — initialize
    // =========================================================================

    /**
     * Write the loan's initial-creation history row.
     *
     * Called by `LoanApplicationService::createDraft()` (A.27) inside the
     * transaction that creates the loan row. The method appends a
     * `loan_status_histories` row with `from_status = null`,
     * `to_status = draft`, and the supplied actor (nullable).
     *
     * The method does NOT:
     *
     *   - update the loan's status (the loan is already in `draft` by
     *     virtue of the caller's insert);
     *   - lock the loan row (a freshly-created loan cannot be the target
     *     of a concurrent transition);
     *   - dispatch an event (the domain has no `LoanCreated` event; see
     *     the class docblock §"The initial-creation row").
     *
     * MUST be called from inside a database transaction. A defensive
     * check throws `LogicException` if no transaction is active.
     *
     * @param Loan   $loan         The freshly-created loan.
     * @param int|null $actorUserId The user who created the draft, or
     *                              null for a system-initiated draft.
     * @return Loan  The same loan instance, refreshed.
     *
     * @throws LogicException  When called outside a transaction.
     */
    public function initialize(Loan $loan, ?int $actorUserId = null): Loan
    {
        $this->assertInTransaction();

        if (!$loan->exists) {
            throw new LogicException(
                'LoanStateMachine::initialize() requires a persisted loan. '
                . 'The loan must have been inserted before the initial '
                . 'history row can be written.'
            );
        }

        $now = Carbon::now();

        LoanStatusHistory::create([
            'loan_id'           => $loan->id,
            'from_status'       => null,
            'to_status'         => LoanStatus::Draft->value,
            'transition_reason' => null,
            'actor_user_id'     => $actorUserId,
            'occurred_at'       => $now,
            'created_at'        => $now,
        ]);

        return $loan->refresh();
    }

    // =========================================================================
    // Public API — transition
    // =========================================================================

    /**
     * Perform a structural state transition on a loan.
     *
     * The method:
     *
     *   1. Asserts the caller is inside a transaction (defensive
     *      `LogicException` otherwise).
     *   2. Asserts the loan is persisted (`LogicException` otherwise).
     *   3. Locks the loan row (`SELECT ... FOR UPDATE`) and re-reads its
     *      current status.
     *   4. Validates the proposed `from → to` pair against
     *      `LoanStatus::canTransitionTo()` (A.2). An illegal pair throws
     *      `ValidationException`.
     *   5. Validates the reason presence for the five transitions in
     *      `REASON_REQUIRED_TRANSITIONS`. A required-but-missing reason
     *      throws `ValidationException`.
     *   6. Updates `loans.status` on the locked row.
     *   7. Inserts a `loan_status_histories` row recording the
     *      transition.
     *   8. Registers an after-commit dispatch of the supplied event, if
     *      any, gated on `config('loan.audit.emit_events')`.
     *   9. Returns the refreshed loan.
     *
     * The caller is responsible for any domain preconditions specific to
     * the transition (see the class docblock §"Caller's responsibility").
     * The state machine's contract is structural only, plus the
     * reason-presence rule.
     *
     * @param Loan       $loan             The loan to transition.
     * @param LoanStatus $nextStatus       The target status.
     * @param string|null $reason          Optional reason; required for
     *                                      five transitions (see the
     *                                      class docblock).
     * @param int|null   $actorUserId      The user who performed the
     *                                      transition, or null for an
     *                                      automatic transition.
     * @param object|null $afterCommitEvent Optional event instance to
     *                                      dispatch after the outermost
     *                                      transaction commits.
     * @return Loan  The refreshed loan.
     *
     * @throws LogicException       When called outside a transaction or
     *                              with an unpersisted loan.
     * @throws ValidationException  When the transition is structurally
     *                              illegal or a required reason is
     *                              missing.
     */
    public function transition(
        Loan $loan,
        LoanStatus $nextStatus,
        ?string $reason = null,
        ?int $actorUserId = null,
        ?object $afterCommitEvent = null,
    ): Loan {
        $this->assertInTransaction();

        if (!$loan->exists) {
            throw new LogicException(
                'LoanStateMachine::transition() requires a persisted loan. '
                . 'A transient model has no id and cannot be the target of '
                . 'a status transition.'
            );
        }

        // ---------------------------------------------------------------------
        // Lock the loan row and re-read its current status.
        //
        // The lock ensures that two concurrent transitions against the same
        // loan serialize. The re-read ensures that the structural check
        // below is performed against the loan's ACTUAL current status, not
        // against a possibly-stale in-memory value the caller held before
        // opening the transaction.
        // ---------------------------------------------------------------------
        /** @var Loan $locked */
        $locked = Loan::query()
            ->whereKey($loan->id)
            ->lockForUpdate()
            ->firstOrFail();

        $currentStatus = $locked->status;

        // ---------------------------------------------------------------------
        // 1. Structural validation.
        // ---------------------------------------------------------------------
        if (!$currentStatus->canTransitionTo($nextStatus)) {
            throw ValidationException::withMessages([
                'status' => [
                    sprintf(
                        'A loan in status "%s" cannot transition to "%s". '
                        . 'See LoanStatus::canTransitionTo() for the frozen '
                        . 'transition matrix.',
                        $currentStatus->value,
                        $nextStatus->value,
                    ),
                ],
            ]);
        }

        // ---------------------------------------------------------------------
        // 2. Reason-required validation.
        // ---------------------------------------------------------------------
        $this->assertReasonIsProvidedIfRequired(
            from: $currentStatus,
            to: $nextStatus,
            reason: $reason,
        );

        // ---------------------------------------------------------------------
        // 3. Update the loan's status.
        //
        // The update is on the locked row. `status` is not mass-assignable
        // (see Loan's `$fillable`), so the assignment is performed
        // explicitly and persisted with `save()`.
        // ---------------------------------------------------------------------
        $now = Carbon::now();

        $locked->status = $nextStatus;
        $locked->save();

        // ---------------------------------------------------------------------
        // 4. Append the history row.
        // ---------------------------------------------------------------------
        LoanStatusHistory::create([
            'loan_id'           => $locked->id,
            'from_status'       => $currentStatus->value,
            'to_status'         => $nextStatus->value,
            'transition_reason' => $reason,
            'actor_user_id'     => $actorUserId,
            'occurred_at'       => $now,
            'created_at'        => $now,
        ]);

        // ---------------------------------------------------------------------
        // 5. Register the after-commit event dispatch.
        // ---------------------------------------------------------------------
        if ($afterCommitEvent !== null) {
            $this->dispatchAfterCommit($afterCommitEvent);
        }

        return $locked->refresh();
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that the caller is inside a database transaction.
     *
     * The check mirrors the defensive guard on Phase 2B's
     * `WalletService::recordEntry()`. See the class docblock
     * §"Transaction discipline" for the rationale.
     *
     * @return void
     *
     * @throws LogicException
     */
    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'LoanStateMachine must be called from inside a database '
                . 'transaction. The loan row lock (SELECT ... FOR UPDATE) '
                . 'is only meaningful inside a transaction; outside one, '
                . 'the lock is acquired and immediately released, '
                . 'defeating its purpose. The caller owns the transaction '
                . 'because it owns the larger operation (creating the '
                . 'loan, disbursing it, restructuring it, writing it off, '
                . 'etc.), of which the status change is one step.'
            );
        }
    }

    /**
     * Assert that the reason is provided if the transition requires one.
     *
     * The required set is declared on `REASON_REQUIRED_TRANSITIONS`. A
     * reason is considered "provided" when it is non-null and its trimmed
     * value is non-empty. A whitespace-only reason is treated as absent.
     *
     * When the transition does not require a reason, the method is a
     * no-op. The reason parameter remains optional for every transition;
     * a caller may supply one for an optional-reason transition and the
     * value is stored as-is.
     *
     * @param LoanStatus  $from
     * @param LoanStatus  $to
     * @param string|null $reason
     * @return void
     *
     * @throws ValidationException
     */
    private function assertReasonIsProvidedIfRequired(
        LoanStatus $from,
        LoanStatus $to,
        ?string $reason,
    ): void {
        if (!$this->transitionRequiresReason($from, $to)) {
            return;
        }

        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages([
                'transition_reason' => [
                    sprintf(
                        'A reason is required for the transition "%s → %s". '
                        . 'Supply a non-empty explanation of why the '
                        . 'transition is being performed.',
                        $from->value,
                        $to->value,
                    ),
                ],
            ]);
        }
    }

    /**
     * Whether a given transition requires a reason.
     *
     * Uses `REASON_REQUIRED_TRANSITIONS` as the single source of truth.
     * The comparison is on the enum cases themselves, not on string
     * values, so an accidental rename of a case's backing value cannot
     * silently break the check.
     *
     * @param LoanStatus $from
     * @param LoanStatus $to
     * @return bool
     */
    private function transitionRequiresReason(LoanStatus $from, LoanStatus $to): bool
    {
        foreach (self::REASON_REQUIRED_TRANSITIONS as [$requiredFrom, $requiredTo]) {
            if ($from === $requiredFrom && $to === $requiredTo) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // Private — event dispatch
    // =========================================================================

    /**
     * Register an event for dispatch after the current transaction
     * commits, subject to the audit feature flag.
     *
     * The gate is `config('loan.audit.emit_events')`, declared in
     * `Config/loan.php` (A.1) and defaulted to `true`. When the config is
     * false, the event is not registered; the state change and its
     * history row are still written.
     *
     * `DB::afterCommit()` registers a callback that runs after the
     * OUTERMOST transaction commits. If no transaction is active, the
     * callback runs immediately — a defensive case for tests or scripts
     * that call the state machine outside a transaction. In production,
     * `transition()` always wraps in a transaction (via the caller), so
     * the immediate branch is not exercised.
     *
     * @param object $event
     * @return void
     */
    private function dispatchAfterCommit(object $event): void
    {
        if (!config('loan.audit.emit_events', true)) {
            return;
        }

        DB::afterCommit(static function () use ($event): void {
            Event::dispatch($event);
        });
    }
}