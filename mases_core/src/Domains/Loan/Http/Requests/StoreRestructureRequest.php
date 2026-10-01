<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreRestructureRequest
 *
 * The HTTP request validator for the loan-restructure endpoint:
 * `POST /loans/{loan}/restructure` (A.77, `LoanController::restructure()`).
 *
 * =============================================================================
 * Scope — shape validation only
 * =============================================================================
 * This request validates the SHAPE of the incoming payload. It accepts
 * four fields: three REQUIRED `new_*` fields that describe the terms the
 * restructured loan will carry, and one REQUIRED `reason` that captures
 * the operator's justification.
 *
 * The request does NOT perform:
 *
 *   - Existence checks. Whether `{loan}` references an existing loan in
 *     the authenticated tenant is resolved by
 *     `LoanController::restructure()` (A.77) via
 *     `LoanRepository::findForTenantOrFail()` (A.55), which produces a
 *     404 for a non-existent or cross-tenant loan id.
 *
 *   - State checks. Whether the loan is currently in `in_arrears` (the
 *     only status from which the frozen C4 matrix permits a transition
 *     into `restructured`) is validated by
 *     `LoanRestructuringService::restructure()` (A.34), which produces
 *     a 422 with a `status` field error for an illegal state.
 *
 *   - Policy-version existence. Whether `new_policy_version_id` refers
 *     to an existing `loan_product_rules` policy version is validated by
 *     the service (A.34), which resolves the version through the Core
 *     VersionedPolicyRegistry and produces a 422 with a
 *     `new_policy_version_id` field error on absence or a policy-type
 *     mismatch.
 *
 *   - Policy-version compatibility. Whether the new policy version
 *     belongs to the same product as the loan is validated by the
 *     service (A.34), which produces a 422 on a product mismatch.
 *
 *   - Principal-bound checks. Whether the new principal falls within the
 *     new policy's declared bounds is validated by the service (A.34).
 *
 *   - Reason-required re-checks at the service layer. The service
 *     (`LoanRestructuringService::restructure()`, A.34) performs its OWN
 *     pre-transaction reason check, independent of this request. See the
 *     sibling note on `RejectLoanRequest` (A.62) for the rationale on
 *     the double enforcement.
 *
 * The separation matches the shape/service split documented on
 * `StoreLoanRequest` (A.60).
 *
 * =============================================================================
 * The REQUIRED `reason` field — the reason-required transition
 * =============================================================================
 * The `in_arrears → restructured` transition is one of the five entries
 * in `LoanStateMachine::REASON_REQUIRED_TRANSITIONS` (A.26). The frozen
 * decision is that a restructure without a reason is structurally
 * incomplete: the business needs to know WHY the loan's terms were
 * renegotiated, both for regulatory audit and for the borrower's
 * relationship record.
 *
 * This request enforces the requirement at the HTTP boundary with the
 * same `required` + trim-and-null-normalization combination used by
 * `RejectLoanRequest` (A.62). A payload that omits `reason`, sends
 * `null`, sends `""`, or sends `"   "` fails the `required` rule.
 *
 * =============================================================================
 * Field reference
 * =============================================================================
 * The endpoint accepts four fields:
 *
 *   new_principal_amount   REQUIRED. Decimal string with up to 4 decimal
 *                          places. The principal the restructured loan
 *                          will carry. The value is passed to the
 *                          service as a STRING; the platform-wide
 *                          no-floats-in-financial-code discipline
 *                          applies. Under the frozen A.34 flow, this may
 *                          be greater than, equal to, or less than the
 *                          previous outstanding principal — the three
 *                          cases are distinguished by the
 *                          `LoanRestructuring` model's
 *                          `isPrincipalIncrease()`,
 *                          `isPrincipalUnchanged()`, and
 *                          `isPrincipalDecrease()` predicates (A.21).
 *
 *   new_term_months        REQUIRED. Integer, >= 1. The term the
 *                          restructured loan will carry. The upper
 *                          bound is a defensive 600 (50 years), matching
 *                          the convention established by
 *                          `StoreLoanRequest` (A.60); the new policy's
 *                          authoritative bounds are enforced by the
 *                          service.
 *
 *   new_policy_version_id  REQUIRED. Integer, >= 1. The versioned
 *                          `loan_product_rules` policy that will govern
 *                          the restructured loan. Its existence and
 *                          type-compatibility are validated by the
 *                          service; the request validates shape only.
 *
 *   reason                 REQUIRED. String, 1 to 500 characters after
 *                          trimming. Free-text justification supplied by
 *                          the operator.
 *
 * No other field is accepted. A caller who sends extra fields (e.g. a
 * `previous_policy_version_id`, a `previous_outstanding_principal`, a
 * `restructured_by` override) finds them silently ignored by the
 * validation layer and never passed to the service — those values are
 * derived by the service from the loan's current state, not supplied by
 * the caller.
 *
 * The `new_maturity_date`, `new_total_scheduled_repayment`, and
 * `new_installment_count` fields are NOT accepted either: those are
 * computed by `LoanScheduleService::generate()` (A.27) from the new
 * principal, the new term, and the new policy's rate, inside the service
 * transaction. Accepting them would break the schedule's determinism.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * `authorize()` returns `true` unconditionally. Authorization is enforced
 * by the controller (A.77) via `Gate::authorize('restructure', $loan)`,
 * which resolves through `LoanPolicy::restructure()` (A.52). Same posture
 * as A.60–A.64.
 *
 * =============================================================================
 * What this request deliberately does NOT do
 * =============================================================================
 *   - It does NOT authorize. See §"Authorization".
 *
 *   - It does NOT check the loan's existence or state. The controller
 *     resolves the loan; the service is the authoritative state-machine
 *     boundary.
 *
 *   - It does NOT check the existence, type, or product-compatibility of
 *     the referenced policy version. The service performs all three
 *     checks.
 *
 *   - It does NOT check the new principal's positivity at the request
 *     level. The service enforces it; the same rationale as A.60's
 *     `principal_amount` and A.64's `amount` (Laravel's `gt:0` rule
 *     would internally parse the string as a float, violating the
 *     no-floats discipline). The `decimal:0,4` rule validates shape
 *     only.
 *
 *   - It does NOT check the new principal's bounds against the new
 *     policy. The service performs the bound check.
 *
 *   - It does NOT accept `previous_*` fields. The prior terms are read
 *     by the service from the loan's current state and from the prior
 *     schedule's installments; they are not caller inputs.
 *
 *   - It does NOT accept `restructured_by` or `restructured_at`. The
 *     actor is derived from the authenticated user by the controller;
 *     the moment is set by the service from `Carbon::now()` inside the
 *     transaction.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130, B2, B6
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2 (reference-id
 *                                                  ordering)
 *   Phase 2C A.12                                — `loan_restructurings`
 *                                                  migration (the
 *                                                  new_* columns the
 *                                                  request feeds)
 *   Phase 2C A.18                                — `Loan` (the affected
 *                                                  aggregate)
 *   Phase 2C A.21                                — `LoanRestructuring`
 *                                                  (the model whose
 *                                                  columns this request
 *                                                  partially populates)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *                                                  (the
 *                                                  REASON_REQUIRED_TRANSITIONS
 *                                                  set that includes this
 *                                                  transition)
 *   Phase 2C A.27                                — `LoanScheduleService`
 *                                                  (computes the derived
 *                                                  new_* fields)
 *   Phase 2C A.34                                — `LoanRestructuringService::restructure()`
 *                                                  (the service that
 *                                                  consumes this request)
 *   Phase 2C A.52                                — `LoanPolicy::restructure()`
 *                                                  (the authorization
 *                                                  ability)
 *   Phase 2C A.60                                — `StoreLoanRequest`
 *                                                  (the shape precedent)
 *   Phase 2C A.62                                — `RejectLoanRequest`
 *                                                  (the required-reason
 *                                                  precedent)
 *   Phase 2C A.77                                — `LoanController::restructure()`
 *                                                  (the consumer)
 *   Phase 2A 2A.22                               — `ApproveCertificateRequest`
 *                                                  (the shape precedent)
 *   Step 5 (manifest) A.65                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Requests
 * @version 1.0.0
 */
final class StoreRestructureRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is enforced by the
     * controller via `Gate::authorize('restructure', $loan)`. See the
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
     * See the class docblock §"Field reference" for the rationale.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'new_principal_amount' => [
                'required',
                'string',
                'decimal:0,4',
            ],

            'new_term_months' => [
                'required',
                'integer',
                'min:1',
                'max:600',
            ],

            'new_policy_version_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'reason' => [
                'required',
                'string',
                'max:500',
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
            'new_principal_amount.required' => __('loan::validation.new_principal_amount.required'),
            'new_principal_amount.string'   => __('loan::validation.new_principal_amount.string'),
            'new_principal_amount.decimal'  => __('loan::validation.new_principal_amount.decimal'),

            'new_term_months.required' => __('loan::validation.new_term_months.required'),
            'new_term_months.integer'  => __('loan::validation.new_term_months.integer'),
            'new_term_months.min'      => __('loan::validation.new_term_months.min'),
            'new_term_months.max'      => __('loan::validation.new_term_months.max'),

            'new_policy_version_id.required' => __('loan::validation.new_policy_version_id.required'),
            'new_policy_version_id.integer'  => __('loan::validation.new_policy_version_id.integer'),
            'new_policy_version_id.min'      => __('loan::validation.new_policy_version_id.min'),

            'reason.required' => __('loan::validation.reason.required'),
            'reason.string'   => __('loan::validation.reason.string'),
            'reason.max'      => __('loan::validation.reason.max'),
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
            'new_principal_amount'  => __('loan::attributes.new_principal_amount'),
            'new_term_months'       => __('loan::attributes.new_term_months'),
            'new_policy_version_id' => __('loan::attributes.new_policy_version_id'),
            'reason'                => __('loan::attributes.reason'),
        ];
    }

    /**
     * Normalize input before validation.
     *
     * Trims leading and trailing whitespace from the two string fields
     * (`new_principal_amount` and `reason`). An empty-after-trim value
     * for `reason` becomes `null`, so the `required` rule catches all
     * three empty forms (`null` input, `""` input, and whitespace-only
     * input) through a single rule — the same normalization used by
     * `RejectLoanRequest` (A.62).
     *
     * `new_principal_amount` is trimmed but NOT nulled: an
     * empty-after-trim value fails the `required` rule, which is the
     * correct behavior. The same reasoning applies to `reason` when it
     * is submitted as `""` or whitespace-only.
     *
     * The two integer fields (`new_term_months`, `new_policy_version_id`)
     * are not touched; Laravel's `integer` rule handles string-integer
     * coercion transparently.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        // ---------------------------------------------------------------------
        // new_principal_amount — trim, do not null.
        // ---------------------------------------------------------------------
        if ($this->has('new_principal_amount')) {
            $value = $this->input('new_principal_amount');

            if (is_string($value)) {
                $merged['new_principal_amount'] = trim($value);
            }
        }

        // ---------------------------------------------------------------------
        // reason — trim, then null-normalize empty-after-trim.
        // ---------------------------------------------------------------------
        if ($this->has('reason')) {
            $value = $this->input('reason');

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    $value = null;
                }
            }

            $merged['reason'] = $value;
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }
}