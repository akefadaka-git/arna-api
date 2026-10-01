<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Enums;

/**
 * CollateralType
 *
 * The closed vocabulary of collateral types that a Phase 2C loan collateral
 * can be classified as.
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * Decision A10 (Phase 2C Decision Register, 2026-09-12) establishes the
 * following constraints, which jointly determine the Phase 2C type set:
 *
 *   - Collateral is OPTIONAL and PRODUCT-CONFIGURABLE. A loan product may
 *     be unsecured; secured by shareholder shares; secured by an approved
 *     external collateral type; or secured by a guarantor where explicitly
 *     permitted by the product.
 *
 *   - No collateral type may be accepted unless its ownership or authority
 *     can be verified.
 *
 * Decision B4 (Phase 2C Decision Register, 2026-09-12) narrows the Phase
 * 2C scope to two implemented types:
 *
 *   - Shareholding collateral is integrated through a dedicated pledge
 *     relationship. The Loan domain declares the linkage column
 *     (`loan_collaterals.pledged_shareholding_id`), but the actual FK and
 *     mutation workflow are deferred to a Phase 2A Change Request
 *     (CR-2C-COLLATERAL-PLEDGE-FK-001).
 *
 *   - External collateral remains Loan-domain data in Phase 2C. It is
 *     registered and managed entirely within the Loan bounded context.
 *
 *   - Cash-deposit collateral is explicitly OUT OF SCOPE for Phase 2C.
 *
 * The B4 freeze yields exactly two implemented types:
 *
 *     external      — a collateral asset held outside the MASES ledger
 *                     (for example, real property, equipment, or a
 *                     third-party instrument). Its ownership and valuation
 *                     are recorded as attributes on the collateral row.
 *
 *     shareholding  — a pledge against a shareholder's shareholding.
 *                     The collateral row carries the pledge linkage
 *                     columns (`pledged_shareholding_id`,
 *                     `pledged_quantity`); the FK is added by a Phase 2A
 *                     Change Request.
 *
 * The set is closed. Adding a type is a Change Request that updates this
 * enum, the `loan_collaterals.collateral_type` column's accepted values
 * (documented in the migration, A.11), the `LoanCollateral` model's
 * validation rules (A.18), `LoanCollateralService` (A.33), and the
 * corresponding tests.
 *
 * =============================================================================
 * Why `guarantor` and `cash_deposit` are NOT Phase 2C types
 * =============================================================================
 * A10 lists two additional security mechanisms that a PRODUCT may permit:
 * a guarantor, and (by implication from the general framework) a cash
 * deposit.
 *
 * Neither is implemented as a Phase 2C `CollateralType`:
 *
 *   - A GUARANTOR is not a collateral asset. It is a separate security
 *     mechanism — an obligation undertaken by a third party to answer for
 *     the borrower's debt. Modelling a guarantor as a `CollateralType`
 *     would conflate two distinct security arrangements that have
 *     different ownership semantics, different release conditions, and
 *     different audit requirements. When Phase 2C's successor (or a
 *     Change Request) defines the guarantor workflow, that CR introduces
 *     a dedicated `loan_guarantors` table (or equivalent) and the
 *     corresponding enum, service, controller, resource, permission, and
 *     tests. It does NOT add a `guarantor` case to this enum.
 *
 *   - A CASH-DEPOSIT is explicitly OUT OF SCOPE per B4. A pledged cash
 *     deposit would require integrating with the Wallet domain (a "hold"
 *     or "reservation" semantic that Phase 2B does not implement) or
 *     introducing a new Loan-domain ledger parallel to the Wallet — both
 *     of which are architecturally forbidden. Cash-deposit collateral may
 *     be added by a future Change Request once the Wallet gains the
 *     required primitive; it does not appear in this enum in Phase 2C.
 *
 * Recording these exclusions in the file that a future implementer will
 * naturally consult (this enum) is deliberate: it is the single most
 * likely place that a reader will ask "why isn't `guarantor` here?".
 *
 * =============================================================================
 * Storage
 * =============================================================================
 * The enum is string-backed. Values are snake_case; case names are
 * PascalCase. This mirrors the convention used by Phase 2A's
 * `ShareholderType`, `HoldingDirection`, and `CertificateStatus`, by
 * Phase 2B's `ObligationComponent` and `WalletEntryType`, and by the
 * sibling Loan enums `LoanStatus` (A.2) and `CollateralStatus` (A.3).
 *
 * Persisted as `loan_collaterals.collateral_type VARCHAR(32)` (A.11). The
 * VARCHAR(32) width accommodates every current value — the longest is
 * `shareholding` (12 characters) — with headroom for a future value that
 * a Change Request might add.
 *
 * The column is NOT a MySQL ENUM. Adding a type is a Change Request that
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
 * translation key namespace (`loan::collateral_type.*`) will render these
 * values in Amharic and English at the presentation layer; the enum itself
 * remains a technical vocabulary.
 *
 * =============================================================================
 * No transition matrix
 * =============================================================================
 * Unlike `LoanStatus` (A.2) and `CollateralStatus` (A.3), this enum is a
 * CLASSIFIER, not a state. A collateral's type is set once, at registration
 * time, and does not change over the collateral's lifetime. There is
 * therefore no `canTransitionTo()` method and no transition matrix.
 *
 * If a future Change Request introduces a workflow that can change a
 * collateral's type (for example, converting an external collateral into a
 * shareholding pledge after the Phase 2A CR lands), that CR decides how the
 * change is authorized, audited, and constrained. This file does not
 * anticipate such a workflow.
 *
 * =============================================================================
 * Type-specific attribute requirements
 * =============================================================================
 * The two types carry different attribute requirements on the
 * `loan_collaterals` row (A.11). The service (`LoanCollateralService`,
 * A.33) enforces the per-type requirements at registration time; the
 * schema declares all columns as nullable because MySQL cannot express
 * "column X is required iff column Y has value Z" portably via Laravel's
 * migration DSL.
 *
 *   external:
 *       description              — required
 *       external_reference       — optional (a reference to an external
 *                                   appraisal, registration, or document)
 *       valuation_amount         — required
 *       valuation_currency       — required
 *       valuation_date           — required
 *       pledged_shareholding_id  — must be null
 *       pledged_quantity         — must be null
 *
 *   shareholding:
 *       description              — required
 *       pledged_shareholding_id  — required
 *       pledged_quantity         — required
 *       external_reference       — optional
 *       valuation_amount         — optional (if the product's policy
 *                                   requires valuation of pledged shares,
 *                                   the service enforces it)
 *       valuation_currency       — optional (paired with valuation_amount)
 *       valuation_date           — optional (paired with valuation_amount)
 *
 * The predicate helpers below (`isExternal()`, `isShareholding()`) exist so
 * that the service's per-type validation logic branches on the type
 * explicitly, rather than on the string value or on a bare boolean.
 *
 * =============================================================================
 * Usage
 * =============================================================================
 * Consumers reference the enum by its case:
 *
 *     CollateralType::External
 *     CollateralType::Shareholding
 *
 * Persistence writes `->value` (the snake_case string). Deserialization
 * uses `CollateralType::tryFrom($string)`; a null return signals an
 * unrecognised value — which, for a well-formed row, should never occur.
 *
 * The `values()` helper returns the full list of accepted string values in
 * declaration order. The `pledgeBackedTypes()` helper returns the subset of
 * types that require a Phase 2A pledge relationship, for use by
 * administrative reports and by the future FK-migration CR.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A10 (collateral rules),
 *                                                  B4 (collateral integration)
 *   Phase 2C Clarification Response, 2026-09-12  — C3 (Loan-side pledge
 *                                                  sequencing)
 *   CR-2C-COLLATERAL-PLEDGE-FK-001               — Deferred FK
 *   Step 5 (manifest) A.4                        — This file
 *   Step 6 v1.1.0 §3.11                          — loan_collaterals table
 *                                                  specification
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