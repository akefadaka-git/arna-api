<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Enums;

/**
 * Closed collateral lifecycle: registered → released only.
 * Release authorization and guards belong to LoanCollateralService.
 * No forfeiture or seizure state exists in this phase.
 * Values are persisted as snake_case strings.
 */
enum CollateralStatus: string
{
    /**
     * The collateral has been recorded against a loan and is currently
     * held as security. This is the state every collateral row is created
     * in.
     *
     * The only outgoing transition is to Released.
     *
     * Entry: `LoanCollateralService::register()` (A.33).
     * Exit:  `LoanCollateralService::release()` (A.33).
     *
     * A `registered` collateral is included in the loan's reconciliation
     * context and is visible to any caller authorized to view the loan's
     * collateral (permission `loan.collateral.view`).
     */
    case Registered = 'registered';

    /**
     * The collateral has been released by an authorized workflow.
     * Terminal — no outgoing transitions. A released collateral remains in
     * the record for audit history; it is never deleted and never returns
     * to `registered`.
     *
     * Entry: `LoanCollateralService::release()` (A.33).
     * Exit:  none.
     *
     * A `released` collateral is excluded from the loan's active collateral
     * population. Its `released_at` and `release_reason` columns (per the
     * migration, A.11) record the moment and the justification of the
     * release. If a subsequent security interest is required for the same
     * loan, a NEW collateral row is registered.
     */
    case Released = 'released';

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Return the full list of accepted string values, in declaration order.
     *
     * Intended for validation rules and for administrative iteration. The
     * returned array is `list<string>` — a numerically-indexed array whose
     * order matches the enum's case declaration.
     *
     * The order matches the order in which the states are presented in
     * this file: registered first, released second.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }

    /**
     * Return the string values of all non-terminal states, in declaration
     * order.
     *
     * Currently this is a single value: `['registered']`. The helper is
     * provided so that callers filter for "the collateral currently held
     * against a loan" without hard-coding the string `'registered'`. If a
     * future Change Request adds a non-terminal state (e.g. `pending`),
     * this helper's return value extends automatically and every caller
     * that uses it remains correct.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(
                self::cases(),
                static fn (self $case): bool => !$case->isTerminal(),
            ),
        ));
    }

    /**
     * Whether this status represents a terminal state — one from which
     * there is no outgoing transition.
     *
     * True for Released. False for Registered.
     *
     * For this enum, `isTerminal()` and `isFinal()` would be identical, so
     * only `isTerminal()` is provided. When a future Change Request
     * introduces a state that has a further outgoing transition (e.g. a
     * `pending` state that leads to `registered`), the distinction may
     * become useful and `isFinal()` would be added at that time.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Released   => true,
            self::Registered => false,
        };
    }

    /**
     * Whether this status represents a registered (currently held)
     * collateral.
     *
     * A convenience predicate that expresses intent at the call site. The
     * caller may equivalently write `$status === CollateralStatus::Registered`
     * or `!$status->isTerminal()`. Both forms are correct in the current
     * two-state vocabulary. The predicate exists so that call sites that
     * ask "is this collateral currently active?" read as such, and so that
     * if a future Change Request adds a third state (e.g. `pending`), the
     * predicate's implementation is the single place that needs updating.
     *
     * @return bool
     */
    public function isRegistered(): bool
    {
        return $this === self::Registered;
    }

    /**
     * Determine whether a transition from this status to the given status
     * is structurally legal.
     *
     * This method encodes the exact transition matrix established by the
     * A10 freeze:
     *
     *     registered → released
     *
     * Released has no outgoing transitions. Self-transitions
     * (`Registered → Registered`, `Released → Released`) are never
     * permitted.
     *
     * The check is STRUCTURAL only. It answers "is this a legal edge in
     * the state graph?" — it does NOT answer "is this transition permitted
     * for this caller, on this collateral, in this context?" Those
     * questions are answered by `LoanCollateralService::release()` (A.33),
     * which composes this check with guards, permission enforcement, and
     * event dispatch.
     *
     * Callers MUST consult `LoanCollateralService` before persisting a
     * status change. Direct use of `canTransitionTo()` outside the service
     * is intended for tests and for read-only diagnostics, not for
     * production writes.
     *
     * @param self $next  The proposed new status.
     * @return bool       True if the transition is structurally permitted.
     */
    public function canTransitionTo(self $next): bool
    {
        // Self-transitions are never permitted. Every state change is an
        // event; a transition that leaves the state unchanged is not a
        // transition and would pollute any status history the domain
        // chooses to record.
        if ($this === $next) {
            return false;
        }

        return match ($this) {
            self::Registered => $next === self::Released,
            self::Released   => false,
        };
    }
}