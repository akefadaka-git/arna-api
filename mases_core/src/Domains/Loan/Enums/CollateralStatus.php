<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Enums;

/**
 * CollateralStatus
 *
 * The closed vocabulary of lifecycle states that a piece of loan collateral
 * can carry in Phase 2C.
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * Decision A10 (Phase 2C Decision Register, 2026-09-12) establishes the
 * following constraints, which jointly determine the Phase 2C state set:
 *
 *   - Collateral is OPTIONAL and PRODUCT-CONFIGURABLE. A loan product may
 *     be unsecured, secured by shareholder shares, secured by an approved
 *     external collateral type, or secured by a guarantor.
 *
 *   - Collateral is RELEASED only through an authorized Loan-domain
 *     workflow after the applicable release condition is satisfied.
 *
 *   - NO AUTOMATIC ENFORCEMENT OR SEIZURE MECHANISM is implemented in
 *     Phase 2C.
 *
 * The A10 freeze yields exactly two states in Phase 2C:
 *
 *     registered  — the collateral has been recorded against a loan and
 *                   is currently held as security.
 *
 *     released    — the collateral has been released by an authorized
 *                   workflow. Terminal.
 *
 * The set is closed. Adding a state is a Change Request that updates this
 * enum, the `loan_collaterals.status` column's accepted values (documented
 * in the migration, A.11), the transition matrix in this enum, the
 * `LoanCollateral` model (A.18), `LoanCollateralService` (A.33), and the
 * corresponding tests.
 *
 * =============================================================================
 * Why `forfeited` is NOT a Phase 2C state
 * =============================================================================
 * A frequently-encountered collateral lifecycle includes a `forfeited` (or
 * `seized`, or `enforced`) state — the state a security interest enters
 * when the lender has exercised its right to realize the collateral because
 * the borrower has defaulted.
 *
 * Phase 2C does NOT include this state, because A10 explicitly excludes
 * automatic enforcement and seizure from the phase. Introducing a
 * `forfeited` state without an enforcement mechanism would be a state that
 * nothing can reach through any authorized code path, or — worse — one that
 * could only be reached through a manual database edit outside the domain's
 * audit trail. Either outcome is unacceptable.
 *
 * When Phase 2C's successor phase (or a Phase 2C Change Request) defines
 * the enforcement workflow, that CR adds the new state, the transition
 * into it, the guard that authorizes it, the permission that gates it, the
 * corresponding Wallet interaction if any, and the audit event. This file
 * does not anticipate that CR.
 *
 * =============================================================================
 * Lifecycle semantics
 * =============================================================================
 * The Phase 2C lifecycle is a two-state linear sequence with no branches:
 *
 *     registered → released
 *
 * `registered` is the state a collateral is created in. Registration is
 * performed by `LoanCollateralService::register()` (A.33) in response to
 * `POST /loans/{loan}/collateral`. Registration validates that the loan is
 * in a state that permits collateral attachment (the loan must be in
 * `draft`, `submitted`, `under_review`, `approved`, `active`,
 * `in_arrears`, or `restructured` — not in any terminal state), and that
 * the collateral type and required attributes are consistent with the
 * product's collateral policy.
 *
 * `released` is the state a collateral enters after
 * `LoanCollateralService::release()` (A.33) has authorized and executed
 * the release workflow in response to
 * `POST /loans/{loan}/collateral/{collateral}/release`. Release is
 * terminal: a released collateral cannot return to `registered`. If a
 * subsequent security interest is required for the same loan, a NEW
 * collateral row is registered; the released row remains in the record for
 * audit history.
 *
 * The transition `registered → released` is the only legal transition.
 * Every other pair of states is an illegal transition, including every
 * self-transition.
 *
 * =============================================================================
 * Storage
 * =============================================================================
 * The enum is string-backed. Values are snake_case; case names are
 * PascalCase. This mirrors the convention used by Phase 2A's
 * `ShareholderType`, `HoldingDirection`, and `CertificateStatus`, by
 * Phase 2B's `ObligationComponent` and `WalletEntryType`, and by the
 * sibling `LoanStatus` (A.2).
 *
 * Persisted as `loan_collaterals.status VARCHAR(16)` (A.11). The
 * VARCHAR(16) width accommodates every current value — the longest is
 * `registered` (10 characters) — with headroom for a future value that a
 * Change Request might add.
 *
 * The column is NOT a MySQL ENUM. Adding a state is a Change Request that
 * updates this enum; a MySQL ENUM would require a schema migration for
 * every value change and would couple the migration to application-code
 * terminology.
 *
 * =============================================================================
 * No display labels
 * =============================================================================
 * This enum deliberately provides no `label()` method. Display strings
 * belong to the resource layer and are resolved through the localization
 * system's `__()` mechanism in the caller's active locale. A future
 * translation key namespace (`loan::collateral_status.*`) will render these
 * values in Amharic and English at the presentation layer; the enum itself
 * remains a technical vocabulary.
 *
 * =============================================================================
 * No guards, permissions, or event mappings
 * =============================================================================
 * This enum encodes only the state set and the structural transition
 * matrix. It does NOT encode:
 *
 *   - the guards that a transition must satisfy (e.g. "the loan must not
 *     be in a terminal state");
 *   - the permission required to release a collateral
 *     (`loan.collateral.release`);
 *   - the event dispatched when a release occurs
 *     (`LoanCollateralReleased`);
 *   - the audit payload that a release emits.
 *
 * Those concerns belong to `LoanCollateralService` (A.33), which is the
 * single enforcement point that the controller delegates to. The service
 * asks `$from->canTransitionTo($to)` first; if the structural check fails,
 * it throws; if the structural check passes, it applies the guard,
 * permission, and event layers on top.
 *
 * =============================================================================
 * Usage
 * =============================================================================
 * Consumers reference the enum by its case:
 *
 *     CollateralStatus::Registered
 *     CollateralStatus::Released
 *
 * Persistence writes `->value` (the snake_case string). Deserialization
 * uses `CollateralStatus::tryFrom($string)`; a null return signals an
 * unrecognised value — which, for a well-formed row, should never occur.
 *
 * The `values()` helper returns the full list of accepted string values in
 * declaration order. The `activeValues()` helper returns the subset of
 * non-terminal states, for use in query `whereIn` filters (e.g. "list the
 * currently-registered collateral for this loan").
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A10 (collateral rules)
 *   Phase 2C Clarification Response, 2026-09-12  — C2 (release endpoint),
 *                                                  C3 (Loan-side pledge)
 *   Step 5 (manifest) A.3                        — This file
 *   Step 6 v1.1.0 §3.11                          — loan_collaterals table
 *                                                  specification
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Enums
 * @version 1.0.0
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