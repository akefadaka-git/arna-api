<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use MaaleArna\MASES\Core\Domains\Loan\Enums\CollateralType;

/**
 * StoreCollateralRequest
 *
 * The HTTP request validator for the collateral-registration endpoint:
 * `POST /loans/{loan}/collateral` (A.79,
 * `LoanCollateralController::store()`).
 *
 * This is the second of two requests in the Loan domain that perform
 * cross-field validation inside `withValidator()` (the other is
 * `StoreRepaymentRequest`, A.64). Its cross-field logic is per-TYPE:
 * the required attribute set depends on the `collateral_type` value.
 *
 * =============================================================================
 * Scope — shape validation and per-type conditional requirements
 * =============================================================================
 * This request validates:
 *
 *   - The SHAPE of the top-level fields (`collateral_type`,
 *     `description`, and the six optional attribute fields).
 *
 *   - The PER-TYPE conditional requirements (see §"The per-type
 *     conditional rules"). Two types are supported in Phase 2C, per the
 *     `CollateralType` enum (A.4):
 *
 *       external      — requires the valuation triple
 *                       (`valuation_amount`, `valuation_currency`,
 *                       `valuation_date`); forbids the pledge linkage
 *                       (`pledged_shareholding_id`,
 *                       `pledged_quantity`).
 *
 *       shareholding  — requires the pledge linkage
 *                       (`pledged_shareholding_id`,
 *                       `pledged_quantity`); permits but does not
 *                       require the valuation triple. If the triple is
 *                       supplied, all three components must be present
 *                       (an all-or-none rule).
 *
 * The request does NOT perform:
 *
 *   - Existence checks. Whether `{loan}` references an existing loan in
 *     the authenticated tenant is resolved by
 *     `LoanCollateralController::store()` (A.79) via
 *     `LoanRepository::findForTenantOrFail()` (A.55), which produces a
 *     404 for a non-existent or cross-tenant loan id.
 *
 *   - The reference-target validation for `pledged_shareholding_id`.
 *     Whether the referenced shareholding exists, belongs to the loan's
 *     borrower, and has sufficient quantity to cover the pledge is
 *     validated by `LoanCollateralService::register()` (A.37). The
 *     service reads the shareholding through the tenant-scoped
 *     Shareholder repository and produces a 404 for a non-existent
 *     reference, or a 422 for a borrower mismatch.
 *
 *   - The product's collateral policy check. Whether the loan's product
 *     permits the supplied collateral type, and whether the product
 *     requires a collateral at all, is validated by
 *     `LoanCollateralService::register()` (A.37), which reads the loan's
 *     frozen `loan_product_rules` policy.
 *
 *   - The loan's state check. Whether the loan is in a state that
 *     permits collateral attachment (any non-terminal status, per A.3's
 *     class docblock §"Lifecycle semantics") is validated by the
 *     service.
 *
 * The separation matches the shape/service split documented on
 * `StoreLoanRequest` (A.60).
 *
 * =============================================================================
 * The per-type conditional rules — `withValidator()`
 * =============================================================================
 * The `withValidator()` hook runs after the field-level rules have
 * passed. It:
 *
 *   1. Returns early if the field-level rules already failed. The
 *      per-type checks assume well-formed input.
 *
 *   2. Reads `collateral_type` from the raw input. If it is not a valid
 *      enum case (which the field-level rule would have caught), the
 *      method returns early.
 *
 *   3. Branches on the enum case:
 *
 *      For `CollateralType::External`:
 *        - Add an error on `valuation_amount` if it is null.
 *        - Add an error on `valuation_currency` if it is null.
 *        - Add an error on `valuation_date` if it is null.
 *        - Add an error on `pledged_shareholding_id` if it is non-null
 *          (the field must not be set for an external collateral).
 *        - Add an error on `pledged_quantity` if it is non-null.
 *
 *      For `CollateralType::Shareholding`:
 *        - Add an error on `pledged_shareholding_id` if it is null.
 *        - Add an error on `pledged_quantity` if it is null.
 *        - Check the valuation triple for all-or-none consistency:
 *          if ANY of the three is non-null and ANY of the three is
 *          null, add an error on each missing component.
 *
 * The all-or-none rule for the shareholding valuation triple is
 * identical to the `LoanCollateral::valuationConsistency()` predicate
 * (A.20), which the model exposes as a read-only consistency check. The
 * two are intentionally aligned: the request enforces the shape at the
 * boundary; the model reports it in diagnostics.
 *
 * =============================================================================
 * Field reference
 * =============================================================================
 * The endpoint accepts eight fields:
 *
 *   collateral_type          REQUIRED. One of the `CollateralType` enum
 *                            cases: `'external'` or `'shareholding'`
 *                            (per A.4).
 *
 *   description              REQUIRED. String, 1 to 500 characters after
 *                            trimming. Free-text description of the
 *                            collateral asset or pledge.
 *
 *   external_reference       OPTIONAL. String, up to 64 characters. A
 *                            reference to an external appraisal,
 *                            registration, or document.
 *
 *   pledged_shareholding_id  OPTIONAL. Integer, >= 1. Required for
 *                            `shareholding`-typed collateral; must be
 *                            null for `external`-typed. Per the
 *                            deferred-FK decision (B4, C3, and
 *                            CR-2C-COLLATERAL-PLEDGE-FK-001), the value
 *                            is validated only at the service layer in
 *                            Phase 2C; the migration A.11 declares no
 *                            database-level FK.
 *
 *   pledged_quantity         OPTIONAL. Decimal string, 0..4 decimal
 *                            places. Required for `shareholding`-typed
 *                            collateral; must be null for
 *                            `external`-typed. The quantity is a
 *                            decimal, not an integer, because share
 *                            quantities on the Phase 2A `share_holdings`
 *                            table are DECIMAL(20,4).
 *
 *   valuation_amount         OPTIONAL. Decimal string, 0..4 decimal
 *                            places. Required for `external`-typed
 *                            collateral; optional for
 *                            `shareholding`-typed.
 *
 *   valuation_currency       OPTIONAL. ISO 4217 three-letter code
 *                            (`^[A-Z]{3}$`). Required for
 *                            `external`-typed collateral; optional for
 *                            `shareholding`-typed.
 *
 *   valuation_date           OPTIONAL. ISO 8601 date. Required for
 *                            `external`-typed collateral; optional for
 *                            `shareholding`-typed.
 *
 * No other field is accepted. A caller who sends extra fields (e.g. a
 * `status`, a `registered_by`, a `released_at`, or a `release_reason`)
 * finds them silently ignored by the validation layer and never passed
 * to the service.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * `authorize()` returns `true` unconditionally. Authorization is enforced
 * by the controller (A.79) via `Gate::authorize('manage', $transient)`,
 * where `$transient` is a transient `LoanCollateral` instance whose
 * `loan_id` is set to the resolved loan's id. See
 * `LoanCollateralPolicy` (A.54) §"The transient-instance dispatch
 * pattern" for the rationale on that dispatch form.
 *
 * =============================================================================
 * What this request deliberately does NOT do
 * =============================================================================
 *   - It does NOT authorize. See §"Authorization".
 *
 *   - It does NOT check the loan's existence or state. The controller
 *     resolves the loan; the service is the authoritative state
 *     boundary.
 *
 *   - It does NOT validate the reference target of
 *     `pledged_shareholding_id`. The service resolves the shareholding
 *     through the tenant-scoped Shareholder repository.
 *
 *   - It does NOT check the loan's product policy. The service reads
 *     the frozen `loan_product_rules` payload and enforces the
 *     product's `collateral.permitted_types` and
 *     `collateral.required` values.
 *
 *   - It does NOT accept a `status` field. The collateral is created
 *     in the `registered` state (per the migration A.11's column
 *     default); the caller cannot choose an initial state.
 *
 *   - It does NOT accept a `registered_by` field. The registering actor
 *     is derived from the authenticated user by the controller and
 *     passed to the service as `$actorUserId`.
 *
 *   - It does NOT accept `released_at`, `release_reason`, or
 *     `released_by` fields. The release is a separate operation,
 *     dispatched through a different endpoint (A.79's `release()`),
 *     validated by a different request (`ReleaseCollateralRequest`,
 *     A.68).
 *
 *   - It does NOT accept an `id` field. The primary key is assigned by
 *     the database.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A10 (collateral rules),
 *                                                  B4 (collateral
 *                                                  integration)
 *   Phase 2C Clarification Response, 2026-09-12  — C2 (release endpoint),
 *                                                  C3 (Loan-side pledge
 *                                                  sequencing)
 *   Phase 2C A.3                                 — `CollateralStatus`
 *   Phase 2C A.4                                 — `CollateralType` (the
 *                                                  enum whose cases
 *                                                  branch the per-type
 *                                                  rules)
 *   Phase 2C A.11                                — `loan_collaterals`
 *                                                  migration
 *   Phase 2C A.18                                — `Loan` (the parent
 *                                                  aggregate)
 *   Phase 2C A.20                                — `LoanCollateral` (the
 *                                                  model; its
 *                                                  `valuationConsistency()`
 *                                                  and
 *                                                  `pledgeConsistency()`
 *                                                  predicates mirror the
 *                                                  request's checks)
 *   Phase 2C A.37                                — `LoanCollateralService::register()`
 *                                                  (the service that
 *                                                  consumes this request)
 *   Phase 2C A.52                                — `LoanPolicy`
 *   Phase 2C A.54                                — `LoanCollateralPolicy`
 *                                                  (the authorization
 *                                                  ability)
 *   Phase 2C A.60                                — `StoreLoanRequest`
 *                                                  (the shape precedent)
 *   Phase 2C A.64                                — `StoreRepaymentRequest`
 *                                                  (the other request
 *                                                  with a `withValidator()`
 *                                                  hook)
 *   Phase 2C A.68                                — `ReleaseCollateralRequest`
 *                                                  (the sibling collateral
 *                                                  request)
 *   Phase 2C A.79                                — `LoanCollateralController`
 *                                                  (the consumer)
 *   Phase 2A 2A.22                               — `ApproveCertificateRequest`
 *                                                  (the shape precedent)
 *   CR-2C-COLLATERAL-PLEDGE-FK-001               — deferred FK
 *   Step 5 (manifest) A.67                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Requests
 * @version 1.0.0
 */
final class StoreCollateralRequest extends FormRequest
{
    /**
     * The maximum length, in characters, of a valuation currency code.
     *
     * ISO 4217 three-letter codes. Declared as a constant so that the
     * rule and the per-type check reference the same value.
     */
    private const CURRENCY_CODE_LENGTH = 3;

    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is enforced by the
     * controller via `Gate::authorize('manage', $transient)`. See the
     * class docblock §"Authorization".
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The validation rules for the request.
     *
     * The rules validate SHAPE only. The per-type conditional
     * requirements are enforced by `withValidator()`. See the class
     * docblock §"Field reference" and §"The per-type conditional rules".
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'collateral_type' => [
                'required',
                'string',
                Rule::enum(CollateralType::class),
            ],

            'description' => [
                'required',
                'string',
                'max:500',
            ],

            'external_reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
            ],

            'pledged_shareholding_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            'pledged_quantity' => [
                'sometimes',
                'nullable',
                'string',
                'decimal:0,4',
            ],

            'valuation_amount' => [
                'sometimes',
                'nullable',
                'string',
                'decimal:0,4',
            ],

            'valuation_currency' => [
                'sometimes',
                'nullable',
                'string',
                'size:' . self::CURRENCY_CODE_LENGTH,
                'regex:/^[A-Z]{3}$/',
            ],

            'valuation_date' => [
                'sometimes',
                'nullable',
                'date',
            ],
        ];
    }

    /**
     * The custom validation messages.
     *
     * Messages are resolved through the Loan domain's translation
     * namespace. See the class docblock of `StoreLoanRequest` (A.60)
     * §"Deployment Notes" for the namespace registration contract.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'collateral_type.required' => __('loan::validation.collateral_type.required'),
            'collateral_type.string'   => __('loan::validation.collateral_type.string'),
            'collateral_type.enum'     => __('loan::validation.collateral_type.enum'),

            'description.required' => __('loan::validation.description.required'),
            'description.string'   => __('loan::validation.description.string'),
            'description.max'      => __('loan::validation.description.max'),

            'external_reference.string' => __('loan::validation.external_reference.string'),
            'external_reference.max'    => __('loan::validation.external_reference.max'),

            'pledged_shareholding_id.integer' => __('loan::validation.pledged_shareholding_id.integer'),
            'pledged_shareholding_id.min'     => __('loan::validation.pledged_shareholding_id.min'),

            'pledged_quantity.string'  => __('loan::validation.pledged_quantity.string'),
            'pledged_quantity.decimal' => __('loan::validation.pledged_quantity.decimal'),

            'valuation_amount.string'  => __('loan::validation.valuation_amount.string'),
            'valuation_amount.decimal' => __('loan::validation.valuation_amount.decimal'),

            'valuation_currency.string' => __('loan::validation.valuation_currency.string'),
            'valuation_currency.size'   => __('loan::validation.valuation_currency.size'),
            'valuation_currency.regex'  => __('loan::validation.valuation_currency.regex'),

            'valuation_date.date' => __('loan::validation.valuation_date.date'),
        ];
    }

    /**
     * The human-readable attribute names.
     *
     * Attribute names are resolved through the Loan domain's translation
     * namespace, same as the messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'collateral_type'         => __('loan::attributes.collateral_type'),
            'description'             => __('loan::attributes.description'),
            'external_reference'      => __('loan::attributes.external_reference'),
            'pledged_shareholding_id' => __('loan::attributes.pledged_shareholding_id'),
            'pledged_quantity'        => __('loan::attributes.pledged_quantity'),
            'valuation_amount'        => __('loan::attributes.valuation_amount'),
            'valuation_currency'      => __('loan::attributes.valuation_currency'),
            'valuation_date'          => __('loan::attributes.valuation_date'),
        ];
    }

    /**
     * Attach the per-type conditional validator.
     *
     * See the class docblock §"The per-type conditional rules" for the
     * full rationale. The callback runs after the field-level rules have
     * passed and branches on the `collateral_type` value.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // -----------------------------------------------------------------
            // Guard: if the field-level rules already failed, do not run
            // the per-type checks. They assume well-formed input.
            // -----------------------------------------------------------------
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $typeRaw = $this->input('collateral_type');

            if (!is_string($typeRaw)) {
                return;
            }

            $type = CollateralType::tryFrom($typeRaw);

            if ($type === null) {
                // The field-level enum rule should have caught this;
                // the guard here is defensive.
                return;
            }

            $pledgedShareholdingId = $this->input('pledged_shareholding_id');
            $pledgedQuantity       = $this->input('pledged_quantity');
            $valuationAmount       = $this->input('valuation_amount');
            $valuationCurrency     = $this->input('valuation_currency');
            $valuationDate         = $this->input('valuation_date');

            if ($type === CollateralType::External) {
                // -------------------------------------------------------------
                // External: valuation triple REQUIRED; pledge linkage FORBIDDEN.
                // -------------------------------------------------------------
                if ($valuationAmount === null) {
                    $v->errors()->add(
                        'valuation_amount',
                        __('loan::validation.valuation_amount.required_for_external'),
                    );
                }

                if ($valuationCurrency === null) {
                    $v->errors()->add(
                        'valuation_currency',
                        __('loan::validation.valuation_currency.required_for_external'),
                    );
                }

                if ($valuationDate === null) {
                    $v->errors()->add(
                        'valuation_date',
                        __('loan::validation.valuation_date.required_for_external'),
                    );
                }

                if ($pledgedShareholdingId !== null) {
                    $v->errors()->add(
                        'pledged_shareholding_id',
                        __('loan::validation.pledged_shareholding_id.forbidden_for_external'),
                    );
                }

                if ($pledgedQuantity !== null) {
                    $v->errors()->add(
                        'pledged_quantity',
                        __('loan::validation.pledged_quantity.forbidden_for_external'),
                    );
                }

                return;
            }

            if ($type === CollateralType::Shareholding) {
                // -------------------------------------------------------------
                // Shareholding: pledge linkage REQUIRED; valuation triple
                // optional but all-or-none.
                // -------------------------------------------------------------
                if ($pledgedShareholdingId === null) {
                    $v->errors()->add(
                        'pledged_shareholding_id',
                        __('loan::validation.pledged_shareholding_id.required_for_shareholding'),
                    );
                }

                if ($pledgedQuantity === null) {
                    $v->errors()->add(
                        'pledged_quantity',
                        __('loan::validation.pledged_quantity.required_for_shareholding'),
                    );
                }

                $presentCount = 0;
                $presentCount += $valuationAmount   !== null ? 1 : 0;
                $presentCount += $valuationCurrency !== null ? 1 : 0;
                $presentCount += $valuationDate     !== null ? 1 : 0;

                if ($presentCount > 0 && $presentCount < 3) {
                    if ($valuationAmount === null) {
                        $v->errors()->add(
                            'valuation_amount',
                            __('loan::validation.valuation_amount.triple_incomplete'),
                        );
                    }

                    if ($valuationCurrency === null) {
                        $v->errors()->add(
                            'valuation_currency',
                            __('loan::validation.valuation_currency.triple_incomplete'),
                        );
                    }

                    if ($valuationDate === null) {
                        $v->errors()->add(
                            'valuation_date',
                            __('loan::validation.valuation_date.triple_incomplete'),
                        );
                    }
                }

                return;
            }
        });
    }

    /**
     * Normalize input before validation.
     *
     * Trims leading and trailing whitespace from the string fields.
     * Empty-after-trim values become `null` for the OPTIONAL fields, so
     * the per-type checks in `withValidator()` can treat "empty" as
     * "absent" consistently, and the service's null-handling is uniform.
     *
     * The two REQUIRED string fields (`collateral_type`,
     * `description`) are trimmed but NOT nulled: an empty-after-trim
     * value fails the `required` rule, which is the correct behavior.
     *
     * `valuation_currency` is uppercased after trimming (ISO 4217 codes
     * are uppercase by convention; normalizing on input lets a caller
     * supply `"usd"` and have it accepted as `"USD"`).
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach (
            [
                'collateral_type',
                'description',
                'external_reference',
                'pledged_quantity',
                'valuation_amount',
                'valuation_currency',
                'valuation_date',
            ] as $field
        ) {
            if (!$this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($field === 'valuation_currency') {
                $value = strtoupper($value);
            }

            // Optional fields normalize empty-after-trim to null.
            if (
                $value === ''
                && in_array($field, [
                    'external_reference',
                    'pledged_quantity',
                    'valuation_amount',
                    'valuation_currency',
                    'valuation_date',
                ], true)
            ) {
                $value = null;
            }

            $merged[$field] = $value;
        }

        // ---------------------------------------------------------------------
        // pledged_shareholding_id — trim string values; the integer rule
        // coerces them. Do not normalize empty to null because an
        // empty-string id would be an error worth surfacing.
        // ---------------------------------------------------------------------
        if ($this->has('pledged_shareholding_id')) {
            $value = $this->input('pledged_shareholding_id');

            if (is_string($value)) {
                $value = trim($value);
                $merged['pledged_shareholding_id'] = $value === '' ? null : $value;
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }
}