<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\Arna\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\Arna\Core\Domains\Loan\Models\Loan;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanStatusHistory;

/**
 * Sole writer of loan status and append-only status history. Enforces the transition matrix, guards, authorization and event dispatch transactionally.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Services
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