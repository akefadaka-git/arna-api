<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Enums;

/**
 * Closed collateral vocabulary for this phase: external and shareholding. Product policy controls eligibility; unsupported seizure workflows remain excluded.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Enums
 * @version 1.0.0
 */
enum CollateralType: string
{
    /**
     * A collateral asset held outside the MASES ledger.
     *
     * Examples include real property, equipment, third-party instruments,
     * and any other asset whose ownership and valuation the Loan domain
     * records directly. The collateral row's `description`,
     * `valuation_amount`, `valuation_currency`, and `valuation_date`
     * columns carry the identifying and valuation attributes.
     *
     * External collateral is registered and managed entirely within the
     * Loan bounded context. It has no FK relationship to any other MASES
     * domain. See B4.
     *
     * A `shareholding`-typed pledge that is later converted to an external
     * collateral (or vice versa) is not supported in Phase 2C. If needed,
     * a new collateral row is registered and the previous one is released;
     * the release workflow records the reason.
     */
    case External = 'external';

    /**
     * A pledge against a shareholder's shareholding.
     *
     * The collateral row's `pledged_shareholding_id` and
     * `pledged_quantity` columns identify the shareholding being pledged
     * and the quantity of shares subject to the pledge.
     *
     * The `pledged_shareholding_id` column is declared in the migration
     * (A.11) as a nullable `unsignedBigInteger` with **no FK constraint**.
     * The FK is deferred to the Phase 2A Change Request
     * CR-2C-COLLATERAL-PLEDGE-FK-001, which will:
     *
     *   1. Introduce the `shareholding_pledges` table in the Shareholder
     *      domain (the authoritative pledge relationship).
     *   2. Add the FK from `loan_collaterals.pledged_shareholding_id` to
     *      `shareholding_pledges.id` (or to `share_holdings.id`, depending
     *      on the final schema designed by that CR).
     *   3. Update this migration's comment to remove the "deferred" note.
     *   4. Backfill the FK for any rows whose `pledged_shareholding_id`
     *      is non-null.
     *
     * Until that CR lands, a shareholding-typed collateral row can be
     * registered with a `pledged_shareholding_id` value, but the value is
     * only enforced by the Loan-side service (A.33), not by the database.
     * The service validates that the referenced shareholding exists and
     * belongs to the loan's borrower before persisting.
     *
     * The Loan domain MUST NOT modify `share_holdings.current_quantity` or
     * `share_holding_entries` directly (per B4 and the Phase 2A service
     * pattern). All mutations of the Shareholder ledger flow through
     * `ShareHoldingService` (2A.14). This file does not declare the pledge
     * workflow; it only classifies the type.
     */
    case Shareholding = 'shareholding';

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
     * The order matches the order in which the types are presented in this
     * file: external first, shareholding second. The order is presentation-
     * only; `Rule::enum(CollateralType::class)` does not depend on it.
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
     * Return the string values of all pledge-backed types, in declaration
     * order.
     *
     * Currently this is a single value: `['shareholding']`. The helper is
     * provided for two purposes:
     *
     *   1. A future FK-migration CR (CR-2C-COLLATERAL-PLEDGE-FK-001) can
     *      query the row set it needs to backfill by filtering on this
     *      value list, without hard-coding the string `'shareholding'`.
     *
     *   2. Administrative reports that need "which collaterals are subject
     *      to a pledge relationship?" can enumerate the types by calling
     *      this helper, rather than by maintaining their own list.
     *
     * If a future Change Request adds a third pledge-backed type (for
     * example, a real-estate pledge registered through an external
     * registry), the helper's return value extends automatically.
     *
     * @return list<string>
     */
    public static function pledgeBackedTypes(): array
    {
        return [self::Shareholding->value];
    }

    /**
     * Whether this type represents an external collateral.
     *
     * Equivalent to `$type === CollateralType::External`. The predicate
     * exists so that call sites that ask "is this collateral external?"
     * read as such, and so that the service's per-type validation
     * (`LoanCollateralService`, A.33) can branch on an intent-expressing
     * method rather than on a direct case comparison. If a future Change
     * Request adds a new external-adjacent type, the predicate's
     * implementation is the single place that needs updating.
     *
     * @return bool
     */
    public function isExternal(): bool
    {
        return $this === self::External;
    }

    /**
     * Whether this type represents a shareholding-pledge collateral.
     *
     * Equivalent to `$type === CollateralType::Shareholding`. The predicate
     * exists for the same reasons as `isExternal()`: expressiveness at the
     * call site, and a single point of extension if the type set grows.
     *
     * @return bool
     */
    public function isShareholding(): bool
    {
        return $this === self::Shareholding;
    }

    /**
     * Whether this type requires a pledge relationship to be valid.
     *
     * True for `Shareholding`. False for `External`.
     *
     * The Loan domain's `LoanCollateralService::register()` (A.33) uses
     * this predicate to decide whether to validate the
     * `pledged_shareholding_id` and `pledged_quantity` columns. When the
     * Phase 2A Change Request (CR-2C-COLLATERAL-PLEDGE-FK-001) lands and
     * the FK is added, the predicate's meaning does not change; it
     * continues to answer "does this type carry a pledge linkage?".
     *
     * A future Change Request that adds a non-pledge type (for example, a
     * type that carries a different kind of linkage) would introduce a
     * parallel predicate rather than extend this one.
     *
     * @return bool
     */
    public function isPledgeBacked(): bool
    {
        return in_array($this->value, self::pledgeBackedTypes(), true);
    }
}