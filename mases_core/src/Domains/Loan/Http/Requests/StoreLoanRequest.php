<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreLoanRequest
 *
 * The HTTP request validator for the loan-application endpoint:
 * `POST /loans` (A.77, `LoanController::store()`).
 *
 * =============================================================================
 * Scope — shape validation only
 * =============================================================================
 * This request validates the SHAPE of the incoming payload: that the
 * required fields are present, that they are of the correct type, and
 * that scalar values are well-formed. It does NOT perform:
 *
 *   - Cross-entity existence checks. Whether `borrower_shareholder_id`
 *     and `loan_product_id` reference existing rows is resolved by
 *     `LoanApplicationService::createDraft()` (A.28), which returns a
 *     404 (`ModelNotFoundException`) for a non-existent or cross-tenant
 *     reference. Moving the existence check into the request would
 *     change the 404 into a 422 and break the frozen tenant-isolation
 *     contract (handoff §3).
 *
 *   - Cross-field domain validation. Whether the requested principal
 *     falls within the product's `min_principal`/`max_principal` bounds,
 *     whether the requested term falls within the product's
 *     `min_term_months`/`max_term_months` bounds, and whether the
 *     product's currency matches the borrower's Wallet currency are
 *     validated by `LoanApplicationService::createDraft()` (A.28). The
 *     service is the authoritative enforcement point; the request does
 *     not duplicate that logic.
 *
 * The separation is deliberate and matches the `LoanApplicationService`
 * class docblock's §"Validation strategy": the request validates shape,
 * the service validates cross-entity and domain rules.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * `authorize()` returns `true` unconditionally. Authorization is enforced
 * by the controller (A.77) via `Gate::authorize('loan.apply')` — or, if
 * a future CR moves it to route middleware, by
 * `auth.permission:loan.apply`. The request is not the authorization
 * boundary; see the handoff §5 "Controller pattern" for the platform-wide
 * rule.
 *
 * =============================================================================
 * Field reference
 * =============================================================================
 * The endpoint accepts six fields. Their shapes and validation rules:
 *
 *   borrower_shareholder_id   REQUIRED. Integer, >= 1. Identifies the
 *                             borrower (a shareholder of the tenant, per
 *                             D129). Existence is checked by the service.
 *
 *   loan_product_id           REQUIRED. Integer, >= 1. Identifies the
 *                             product under which the loan is applied.
 *                             Existence and active-status are checked by
 *                             the service.
 *
 *   principal_amount          REQUIRED. A decimal string with up to 4
 *                             decimal places. Bounded by the product's
 *                             declared range; the bound check is
 *                             performed by the service. The value is
 *                             passed to the service as a STRING; the
 *                             platform-wide no-floats-in-financial-code
 *                             discipline applies.
 *
 *   term_months               REQUIRED. Integer, >= 1. Bounded by the
 *                             product's declared range; the bound check
 *                             is performed by the service.
 *
 *   purpose                   OPTIONAL. String, up to 500 characters.
 *                             Free-text justification supplied by the
 *                             applicant.
 *
 *   external_reference        OPTIONAL. String, up to 64 characters.
 *                             Free-text external identifier supplied by
 *                             the applicant or operator (a paper-file
 *                             reference, an out-of-band loan number,
 *                             etc.).
 *
 * All string fields are trimmed by `prepareForValidation()`. No case
 * normalization is applied: the free-text fields preserve their original
 * casing.
 *
 * =============================================================================
 * The `principal_amount` shape rule
 * =============================================================================
 * The rule `['required', 'string', 'decimal:0,4']` accepts any decimal
 * string with zero to four digits after the decimal point. It does NOT
 * constrain the integer part's magnitude, does NOT require a leading
 * digit, and does NOT accept a sign. The examples below pass:
 *
 *   "100"          ✓
 *   "100.5"        ✓
 *   "100.0001"     ✓
 *   "9999999999"   ✓
 *
 * The examples below fail:
 *
 *   "100.00001"    ✗ (5 decimal places — beyond the storage precision)
 *   "1e3"          ✗ (scientific notation)
 *   "-100"         ✗ (signed value)
 *   "1,000"        ✗ (thousand separator)
 *   ""             ✗ (empty)
 *
 * The rule is deliberately permissive on the integer part: the product
 * bound check in the service is the authoritative range enforcement.
 * The rule's job is to catch a caller who submits a value the schema
 * cannot store at its declared precision, not to enforce the product's
 * business range.
 *
 * The value passed to the service is the trimmed string, unchanged by
 * the validation. Laravel's `decimal` rule validates without casting,
 * so the string survives the request boundary intact.
 *
 * =============================================================================
 * What this request deliberately does NOT do
 * =============================================================================
 *   - It does NOT authorize. See §"Authorization".
 *
 *   - It does NOT check existence of referenced rows. See §"Scope".
 *
 *   - It does NOT check product bounds. See §"Scope".
 *
 *   - It does NOT normalize case of any field. Free-text fields preserve
 *     their casing; the values are trimmed but not modified otherwise.
 *
 *   - It does NOT accept a `currency` field. The loan's currency is
 *     copied from the borrower's Wallet at disbursement time (Q8, per
 *     the migration A.9's docblock), not supplied by the caller.
 *
 *   - It does NOT accept a `status` field. The loan is created in the
 *     `draft` state by the service; the caller cannot choose an initial
 *     state.
 *
 *   - It does NOT accept `approved_by`, `disbursed_by`, `decision_date`,
 *     `disbursement_date`, or any other column managed by a downstream
 *     service. Mass-assignment of those columns would be a security
 *     defect; the request does not expose them.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D129 (borrower is
 *                                                  shareholder), D130
 *                                                  (product catalogue),
 *                                                  D132 (term structure)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze)
 *   Phase 2C A.18                                — `Loan` (the fillable
 *                                                  columns the request
 *                                                  feeds)
 *   Phase 2C A.28                                — `LoanApplicationService`
 *                                                  (the authoritative
 *                                                  validation point)
 *   Phase 2C A.77                                — `LoanController::store()`
 *                                                  (the consumer)
 *   Phase 2A 2A.20                               — `StoreShareholderRequest`
 *                                                  (the shape precedent)
 *   Phase 2B 2B.22                               — `OpenWalletRequest`
 *                                                  (the direct sibling)
 *   Step 5 (manifest) A.60                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Requests
 * @version 1.0.0
 */
final class StoreLoanRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is enforced by the
     * controller via `Gate::authorize('loan.apply')` (or, if a future
     * CR moves it, by route middleware). See the class docblock
     * §"Authorization".
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
     * See the class docblock §"Field reference" and §"The
     * `principal_amount` shape rule" for the rationale behind each rule.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'borrower_shareholder_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'loan_product_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'principal_amount' => [
                'required',
                'string',
                'decimal:0,4',
            ],

            'term_months' => [
                'required',
                'integer',
                'min:1',
                'max:600',
            ],

            'purpose' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],

            'external_reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
            ],
        ];
    }

    /**
     * The custom validation messages.
     *
     * Messages are resolved through the Loan domain's translation
     * namespace. See the class docblock of `LoanServiceProvider` (A.85)
     * for the namespace registration; the `loan::validation.*` keys are
     * expected to be declared there (or in a shared locale file loaded
     * alongside the provider).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'borrower_shareholder_id.required' => __('loan::validation.borrower_shareholder_id.required'),
            'borrower_shareholder_id.integer'  => __('loan::validation.borrower_shareholder_id.integer'),
            'borrower_shareholder_id.min'      => __('loan::validation.borrower_shareholder_id.min'),

            'loan_product_id.required' => __('loan::validation.loan_product_id.required'),
            'loan_product_id.integer'  => __('loan::validation.loan_product_id.integer'),
            'loan_product_id.min'      => __('loan::validation.loan_product_id.min'),

            'principal_amount.required' => __('loan::validation.principal_amount.required'),
            'principal_amount.string'   => __('loan::validation.principal_amount.string'),
            'principal_amount.decimal'  => __('loan::validation.principal_amount.decimal'),

            'term_months.required' => __('loan::validation.term_months.required'),
            'term_months.integer'  => __('loan::validation.term_months.integer'),
            'term_months.min'      => __('loan::validation.term_months.min'),
            'term_months.max'      => __('loan::validation.term_months.max'),

            'purpose.string' => __('loan::validation.purpose.string'),
            'purpose.max'    => __('loan::validation.purpose.max'),

            'external_reference.string' => __('loan::validation.external_reference.string'),
            'external_reference.max'    => __('loan::validation.external_reference.max'),
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
            'borrower_shareholder_id' => __('loan::attributes.borrower_shareholder_id'),
            'loan_product_id'         => __('loan::attributes.loan_product_id'),
            'principal_amount'        => __('loan::attributes.principal_amount'),
            'term_months'             => __('loan::attributes.term_months'),
            'purpose'                 => __('loan::attributes.purpose'),
            'external_reference'      => __('loan::attributes.external_reference'),
        ];
    }

    /**
     * Normalize input before validation.
     *
     * Trims leading and trailing whitespace from the string fields. Empty
     * strings become `null` for the optional fields, so the `nullable`
     * rule and the service's null-handling are consistent regardless of
     * whether the caller submitted an empty string or omitted the field.
     *
     * No case normalization is performed: the free-text fields preserve
     * their original casing.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach (['purpose', 'external_reference'] as $field) {
            if (!$this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    $value = null;
                }
            }

            $merged[$field] = $value;
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }
}