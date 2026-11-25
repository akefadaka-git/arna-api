<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Support;

/**
 * Canonical typed keys for loan_product_rules payloads; services must use constants instead of raw strings.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Support
 * @version 1.0.0
 */
final class LoanPolicyKeys
{
    // =========================================================================
    // Top-level sections
    // =========================================================================

    /**
     * The key of the interest section.
     *
     * Payload key: `'interest'`
     * Payload value type: object (associative array).
     *
     * Contains the A9 interest-computation rules. The section is required
     * on every `loan_product_rules` payload; a payload without it is
     * malformed and any service that reads it must throw rather than
     * fall back to a default.
     */
    public const INTEREST_SECTION = 'interest';

    /**
     * The key of the term section.
     *
     * Payload key: `'term'`
     * Payload value type: object (associative array).
     *
     * Contains the D132 term-structure rules. Required on every
     * `loan_product_rules` payload.
     */
    public const TERM_SECTION = 'term';

    /**
     * The key of the arrears section.
     *
     * Payload key: `'arrears'`
     * Payload value type: object (associative array).
     *
     * Contains the D133 arrears/penalty rules. Required on every
     * `loan_product_rules` payload, even when the product does not
     * permit arrears penalties — in that case the section's
     * `ARREARS_ENABLED` key is `false` and the remaining keys are
     * ignored by the reader. Making the section optional would force
     * every reader to distinguish "no section" from "disabled section"
     * for no benefit.
     */
    public const ARREARS_SECTION = 'arrears';

    /**
     * The key of the collateral section.
     *
     * Payload key: `'collateral'`
     * Payload value type: object (associative array).
     *
     * Contains the A10 collateral rules. Required on every
     * `loan_product_rules` payload, even when the product is unsecured —
     * in that case the section's `COLLATERAL_REQUIRED` key is `false`
     * and the reader treats the product as unsecured.
     */
    public const COLLATERAL_SECTION = 'collateral';

    /**
     * The key of the fees section.
     *
     * Payload key: `'fees'`
     * Payload value type: list<object> (a proper list of associative
     * arrays, one per fee).
     *
     * Contains the D130 fee schedule. May be an empty list for a product
     * with no fees. Each entry is an object with the `FEES_...` sub-keys
     * declared below.
     */
    public const FEES_SECTION = 'fees';

    // =========================================================================
    // Interest sub-keys
    // =========================================================================
    //
    // Read by `LoanScheduleService` (A.23) and, for the annual rate,
    // by `LoanInterestAccrualService` (A.28). The A9 freeze fixes the
    // METHOD (declining balance, annual rate, monthly, Actual/365,
    // half-up, 4dp, BCMath); the values stored under these keys record
    // which method the product uses. A product whose payload records a
    // method that differs from A9's freeze is a data defect and must be
    // rejected by the reading service.
    // =========================================================================

    /**
     * The key of the interest basis.
     *
     * Sub-key of `INTEREST_SECTION`.
     * Payload key: `'basis'`
     * Payload value type: string.
     *
     * The Phase 2C freeze (A9) requires the value `'declining_balance'`.
     * Any other value is a data defect and must be rejected by the
     * reading service, because the amortization engine
     * (`AmortizationCalculator`, A.6) implements only the
     * declining-balance rule.
     */
    public const INTEREST_BASIS = 'basis';

    /**
     * The key of the interest rate representation.
     *
     * Sub-key of `INTEREST_SECTION`.
     * Payload key: `'rate_representation'`
     * Payload value type: string.
     *
     * The A9 freeze requires the value `'annual_percentage'`. Any other
     * value is a data defect.
     */
    public const INTEREST_RATE_REPRESENTATION = 'rate_representation';

    /**
     * The key of the interest computation method.
     *
     * Sub-key of `INTEREST_SECTION`.
     * Payload key: `'computation_method'`
     * Payload value type: string.
     *
     * The Phase 2C freeze (D135) requires the value `'annuity'`. Any
     * other value is a data defect, because the amortization engine
     * (`AmortizationCalculator`, A.6) implements only the annuity
     * method.
     */
    public const INTEREST_COMPUTATION_METHOD = 'computation_method';

    /**
     * The key of the interest periodicity.
     *
     * Sub-key of `INTEREST_SECTION`.
     * Payload key: `'periodicity'`
     * Payload value type: string.
     *
     * The A9 freeze requires the value `'monthly'`. Any other value is a
     * data defect.
     */
    public const INTEREST_PERIODICITY = 'periodicity';

    /**
     * The key of the interest day-count convention.
     *
     * Sub-key of `INTEREST_SECTION`.
     * Payload key: `'day_count'`
     * Payload value type: string.
     *
     * The A9 freeze requires the value `'actual_365'`. Any other value
     * is a data defect.
     *
     * Note: for the standard monthly annuity schedule, the day-count
     * convention does not enter the calculation — the schedule's
     * installment dates are calendar-month spaced, and each period's
     * interest is `outstanding_principal · monthly_rate` (where
     * `monthly_rate = annual_rate / 12`). The convention matters only
     * for partial-period pro-rating, which Phase 2C does not implement
     * (see `AmortizationCalculator` §"What this class does NOT do").
     * The key is present so that a future CR that introduces pro-rating
     * has a stable place to record the convention.
     */
    public const INTEREST_DAY_COUNT = 'day_count';

    /**
     * The key of the interest rounding mode.
     *
     * Sub-key of `INTEREST_SECTION`.
     * Payload key: `'rounding'`
     * Payload value type: string.
     *
     * The A9 freeze requires the value `'half_up'`. Any other value is a
     * data defect.
     */
    public const INTEREST_ROUNDING = 'rounding';

    /**
     * The key of the interest-precision scale.
     *
     * Sub-key of `INTEREST_SECTION`.
     * Payload key: `'precision'`
     * Payload value type: integer.
     *
     * The A9 freeze requires the value `4`. Any other value is a data
     * defect, because the entire schema (loans, loan_installments, the
     * Wallet columns the schedule will populate) is DECIMAL(20,4).
     */
    public const INTEREST_PRECISION = 'precision';

    /**
     * The key of the annual interest rate.
     *
     * Sub-key of `INTEREST_SECTION`.
     * Payload key: `'annual_rate'`
     * Payload value type: string.
     *
     * The value is a decimal fraction (for example, `"0.12"` for 12%).
     * The reader passes it verbatim to `AmortizationCalculator::schedule()`
     * (A.6), which expects a non-negative decimal string and divides by
     * 12 internally.
     *
     * The specific rate is product policy data, not a fixed value. The
     * reader must validate the shape (a non-negative decimal string) but
     * must not constrain the value beyond that; the product catalogue
     * owns the range decision.
     */
    public const INTEREST_ANNUAL_RATE = 'annual_rate';

    // =========================================================================
    // Term sub-keys
    // =========================================================================
    //
    // Read by `LoanApplicationService` (A.24) at request-validation time
    // and by `LoanScheduleService` (A.23) at schedule-generation time.
    // D132 freezes the unit (`months`) and the frequency (`monthly`);
    // the specific bounds and grace periods are product policy data.
    // =========================================================================

    /**
     * The key of the minimum permitted term.
     *
     * Sub-key of `TERM_SECTION`.
     * Payload key: `'min_months'`
     * Payload value type: integer.
     *
     * Expressed in months, per D132. The value must be >= 1.
     */
    public const TERM_MIN_MONTHS = 'min_months';

    /**
     * The key of the maximum permitted term.
     *
     * Sub-key of `TERM_SECTION`.
     * Payload key: `'max_months'`
     * Payload value type: integer.
     *
     * Expressed in months, per D132. The value must be >=
     * `TERM_MIN_MONTHS`.
     */
    public const TERM_MAX_MONTHS = 'max_months';

    /**
     * The key of the repayment frequency.
     *
     * Sub-key of `TERM_SECTION`.
     * Payload key: `'frequency'`
     * Payload value type: string.
     *
     * The D132 freeze requires the value `'monthly'`. Any other value is
     * a data defect, because the amortization engine
     * (`AmortizationCalculator`, A.6) generates monthly installments
     * only.
     */
    public const TERM_FREQUENCY = 'frequency';

    /**
     * The key of the principal grace period.
     *
     * Sub-key of `TERM_SECTION`.
     * Payload key: `'principal_grace_months'`
     * Payload value type: integer.
     *
     * The number of leading installments during which principal is not
     * due. The A9 / D135 freeze allows grace to be product-configurable;
     * the specific value is product policy data.
     *
     * Phase 2C's schedule generator treats grace as follows: during the
     * grace period, the installment's `expected_principal` is
     * `0.0000` and its `expected_interest` is `outstanding_principal ·
     * monthly_rate`. The outstanding principal is not amortized during
     * grace. The term is extended by the grace period so that the
     * annuity is computed over `term_months - principal_grace_months`
     * amortizing installments, with the grace installments prepended.
     *
     * A value of `0` means no principal grace.
     */
    public const TERM_PRINCIPAL_GRACE_MONTHS = 'principal_grace_months';

    /**
     * The key of the interest grace period.
     *
     * Sub-key of `TERM_SECTION`.
     * Payload key: `'interest_grace_months'`
     * Payload value type: integer.
     *
     * The number of leading installments during which interest is not
     * due. The A9 / D135 freeze allows grace to be product-configurable;
     * the specific value is product policy data.
     *
     * A value of `0` means no interest grace.
     *
     * Note: a product that grants an interest grace period AND a
     * principal grace period simultaneously is unusual; the domain does
     * not forbid it, but a product designer choosing such a
     * configuration is responsible for the resulting accounting. The
     * schedule generator applies both grace rules independently.
     */
    public const TERM_INTEREST_GRACE_MONTHS = 'interest_grace_months';

    // =========================================================================
    // Arrears sub-keys
    // =========================================================================
    //
    // Read by `LoanArrearsService` (A.29) when the
    // `DetectArrearsCommand` (A.88) runs. D133 freezes the FORM (a
    // penalty/late-charge obligation, no compounding, no
    // capitalization); the specific rate, grace threshold, and cap are
    // product policy data.
    // =========================================================================

    /**
     * The key of the arrears-enabled flag.
     *
     * Sub-key of `ARREARS_SECTION`.
     * Payload key: `'enabled'`
     * Payload value type: boolean.
     *
     * When `true`, the product permits penalty obligations to be
     * recorded against overdue installments. When `false`, the arrears
     * detection pass is a no-op for loans of this product, and the
     * remaining `ARREARS_...` keys are ignored by the reader.
     */
    public const ARREARS_ENABLED = 'enabled';

    /**
     * The key of the arrears penalty rate.
     *
     * Sub-key of `ARREARS_SECTION`.
     * Payload key: `'penalty_rate'`
     * Payload value type: string.
     *
     * The value is a decimal fraction applied to the arrearage basis.
     * The specific rate is product policy data; the reader passes it
     * verbatim to the penalty computation.
     *
     * Read only when `ARREARS_ENABLED` is `true`. When `false`, the key
     * may be absent from the payload or present with any value; the
     * reader does not consult it.
     */
    public const ARREARS_PENALTY_RATE = 'penalty_rate';

    /**
     * The key of the arrears grace threshold.
     *
     * Sub-key of `ARREARS_SECTION`.
     * Payload key: `'grace_days'`
     * Payload value type: integer.
     *
     * The number of days after an installment's due date before the
     * installment is considered overdue and its penalty obligation is
     * recorded. Expressed in DAYS, not months, because the threshold is
     * a fine-grained operational parameter that products typically
     * express in days.
     *
     * A value of `0` means no grace: the installment is overdue the day
     * after its due date.
     *
     * Read only when `ARREARS_ENABLED` is `true`.
     */
    public const ARREARS_GRACE_DAYS = 'grace_days';

    /**
     * The key of the arrears basis.
     *
     * Sub-key of `ARREARS_SECTION`.
     * Payload key: `'basis'`
     * Payload value type: string.
     *
     * Per D133, the arrears penalty is computed against "the overdue
     * installment amount". The Phase 2C freeze therefore requires the
     * value `'overdue_installment'`. Any other value is a data defect
     * and must be rejected by the reading service, because
     * `LoanArrearsService` (A.29) implements only that basis.
     *
     * A future Change Request that introduces a different basis (for
     * example, `'outstanding_principal'`) extends the reader's switch
     * statement and adds a test vector for the new basis. The key's
     * presence here is stable; only the accepted value set grows.
     *
     * Read only when `ARREARS_ENABLED` is `true`.
     */
    public const ARREARS_BASIS = 'basis';

    /**
     * The key of the arrears penalty cap.
     *
     * Sub-key of `ARREARS_SECTION`.
     * Payload key: `'cap'`
     * Payload value type: string or `null`.
     *
     * When non-null, a decimal string expressing the maximum penalty
     * amount that may be recorded for a single overdue installment. The
     * specific value is product policy data.
     *
     * A `null` value means no cap: the computed penalty is recorded
     * as-is.
     *
     * Read only when `ARREARS_ENABLED` is `true`.
     */
    public const ARREARS_CAP = 'cap';

    // =========================================================================
    // Collateral sub-keys
    // =========================================================================
    //
    // Read by `LoanCollateralService` (A.33) at registration time, to
    // validate that a proposed collateral is consistent with the
    // product's collateral policy. A10 freezes the optionality and the
    // product-configurability of collateral; the specific requirements
    // are product policy data.
    // =========================================================================

    /**
     * The key of the collateral-required flag.
     *
     * Sub-key of `COLLATERAL_SECTION`.
     * Payload key: `'required'`
     * Payload value type: boolean.
     *
     * When `true`, the product requires at least one collateral
     * registration before the loan may be approved or disbursed. When
     * `false`, the product is unsecured and the remaining
     * `COLLATERAL_...` keys are informational only.
     *
     * The enforcement point is `LoanApprovalService` (A.25), which
     * checks the flag before permitting the under_review → approved
     * transition.
     */
    public const COLLATERAL_REQUIRED = 'required';

    /**
     * The key of the permitted collateral types.
     *
     * Sub-key of `COLLATERAL_SECTION`.
     * Payload key: `'permitted_types'`
     * Payload value type: list<string>.
     *
     * A proper list of `CollateralType` values (A.4) that the product
     * permits. An empty list with `COLLATERAL_REQUIRED == true` is a
     * data defect. An empty list with `COLLATERAL_REQUIRED == false` is
     * equivalent to "no collateral accepted", which is unusual but
     * permitted.
     *
     * `LoanCollateralService` (A.33) validates that a proposed
     * registration's `collateral_type` is in this list before
     * persisting.
     */
    public const COLLATERAL_PERMITTED_TYPES = 'permitted_types';

    /**
     * The key of the collateral coverage ratio.
     *
     * Sub-key of `COLLATERAL_SECTION`.
     * Payload key: `'coverage_ratio'`
     * Payload value type: string or `null`.
     *
     * When non-null, a decimal fraction expressing the minimum ratio of
     * collateral valuation to loan principal that the product requires
     * (for example, `"1.50"` for 150% coverage). The specific value is
     * product policy data.
     *
     * A `null` value means the product imposes no coverage ratio; a
     * collateral of any valuation is accepted subject to the other
     * rules.
     *
     * `LoanCollateralService` (A.33) validates the ratio when
     * `COLLATERAL_REQUIRED == true` and the ratio is non-null. The
     * ratio computation itself (`valuation_amount / loan_principal`)
     * is performed by the service, not by this class.
     */
    public const COLLATERAL_COVERAGE_RATIO = 'coverage_ratio';

    // =========================================================================
    // Fees sub-keys
    // =========================================================================
    //
    // Read by `LoanDisbursementService` (A.26) at disbursement time, to
    // record one fee obligation per entry in the `FEES_SECTION` list.
    // D130 places fees in the product catalogue; the specific fee
    // entries are product policy data.
    //
    // Each entry of the `FEES_SECTION` list is an object with the keys
    // declared below. A malformed entry (missing a required key,
    // wrong-typed value) is a data defect.
    // =========================================================================

    /**
     * The key of a fee's code.
     *
     * Sub-key of each entry in `FEES_SECTION`.
     * Payload key: `'code'`
     * Payload value type: string.
     *
     * The fee's stable identifier. Used by the loan's records to
     * reference the fee. The domain does not maintain a closed
     * vocabulary of fee codes; the product catalogue owns the code
     * scheme.
     */
    public const FEES_CODE = 'code';

    /**
     * The key of a fee's amount.
     *
     * Sub-key of each entry in `FEES_SECTION`.
     * Payload key: `'amount'`
     * Payload value type: string.
     *
     * The fee amount as a positive decimal string. The specific value
     * is product policy data.
     *
     * `LoanDisbursementService` (A.26) records the amount as a Wallet
     * obligation of component `ObligationComponent::Fee` (Phase 2B), with
     * `reference_type = 'Loan'` and `reference_id = (string) loan.id`.
     */
    public const FEES_AMOUNT = 'amount';

    /**
     * The key of a fee's Wallet component.
     *
     * Sub-key of each entry in `FEES_SECTION`.
     * Payload key: `'component'`
     * Payload value type: string.
     *
     * The `ObligationComponent` value (Phase 2B) under which the fee is
     * recorded. The Phase 2C freeze assumes every fee is recorded under
     * `'fee'`. A future CR that introduces a fee whose economic
     * category is different (for example, a tax that the catalogue
     * chooses to record as `'tax'`) extends the accepted value set.
     */
    public const FEES_COMPONENT = 'component';

    // =========================================================================
    // Non-instantiability
    // =========================================================================

    /**
     * Private constructor.
     *
     * The class is a registry of constants; there is nothing to
     * instantiate. Declaring the constructor `private` prevents
     * `new LoanPolicyKeys()` at the language level. The `final` class
     * modifier prevents subclassing, which would be an obscure way to
     * introduce an unrelated constant namespace under the same name.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
        // Intentionally empty. Never reached.
    }
}