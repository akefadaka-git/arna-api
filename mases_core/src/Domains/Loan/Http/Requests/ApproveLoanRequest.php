<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ApproveLoanRequest
 *
 * The HTTP request validator for the loan-approval endpoint:
 * `POST /loans/{loan}/approve` (A.77, `LoanController::approve()`).
 *
 * =============================================================================
 * Scope — shape validation only
 * =============================================================================
 * This request validates the SHAPE of the incoming payload. It accepts a
 * single OPTIONAL field, `reason`, whose presence is not required and
 * whose absence is a valid input.
 *
 * The request does NOT perform:
 *
 *   - Existence checks. Whether `{loan}` references an existing loan in
 *     the authenticated tenant is resolved by `LoanController::approve()`
 *     (A.77) via `LoanRepository::findForTenantOrFail()` (A.55), which
 *     produces a 404 for a non-existent or cross-tenant loan id.
 *
 *   - State checks. Whether the loan is currently in a decidable state
 *     (`submitted` or `under_review`) is validated by
 *     `LoanApprovalService::lockAndAssertDecidable()` (A.29), which
 *     produces a 422 with a `status` field error for an illegal state.
 *
 *   - Reason-required checks. The `under_review → approved` transition
 *     is NOT one of the transitions in
 *     `LoanStateMachine::REASON_REQUIRED_TRANSITIONS` (A.26). Approval
 *     therefore never requires a reason. A caller MAY supply one, and
 *     the service records it on the history row if supplied.
 *
 * The separation matches the shape/service split documented on
 * `StoreLoanRequest` (A.60).
 *
 * =============================================================================
 * The absence of a required `reason`
 * =============================================================================
 * The `reason` field is a `sometimes`-plus-`nullable` optional. This
 * mirrors the frozen business decision: an approval's meaning is
 * conveyed by its endpoints; the reason is supplementary context, not a
 * load-bearing input. Contrast with `RejectLoanRequest` (A.62), whose
 * `reason` is REQUIRED because the frozen
 * `REASON_REQUIRED_TRANSITIONS` set includes `under_review → rejected`.
 *
 * A caller that supplies an empty string for `reason` (or omits it, or
 * sends `null`) is normalizing to "no reason" — the service's
 * `$reason` parameter defaults to `null`, and the history row's
 * `transition_reason` column is nullable (migration A.14).
 *
 * =============================================================================
 * Field reference
 * =============================================================================
 * The endpoint accepts one field:
 *
 *   reason   OPTIONAL. String, up to 500 characters. Free-text
 *            justification supplied by the approver.
 *
 * The field is trimmed by `prepareForValidation()`. An empty-after-trim
 * value is normalized to `null`, so the service's null-handling is
 * consistent regardless of whether the caller submitted an empty string
 * or omitted the field.
 *
 * No other field is accepted by this request. A caller who sends extra
 * fields (e.g. an `approved_by` override, a `decision_date` override)
 * finds them silently ignored by the validation layer and never passed
 * to the service — the controller (A.77) is responsible for reading only
 * the fields it needs, and this request's `validated()` output contains
 * only the documented field.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * `authorize()` returns `true` unconditionally. Authorization is enforced
 * by the controller (A.77) via `Gate::authorize('approve', $loan)`, which
 * resolves through `LoanPolicy::approve()` (A.52). Same posture as
 * `StoreLoanRequest` (A.60); see that file's class docblock
 * §"Authorization".
 *
 * =============================================================================
 * What this request deliberately does NOT do
 * =============================================================================
 *   - It does NOT authorize. See §"Authorization".
 *
 *   - It does NOT check the loan's existence. The controller resolves
 *     `{loan}` through the tenant-scoped repository; a non-existent or
 *     cross-tenant id produces a 404 before this request's rules are
 *     even evaluated in the controller.
 *
 *   - It does NOT check the loan's state. The service is the
 *     authoritative state-machine boundary.
 *
 *   - It does NOT require a reason. See §"The absence of a required
 *     `reason`".
 *
 *   - It does NOT accept a `frozen_policy_version_id` field. The
 *     policy freeze is performed by the service (A.29), not by the
 *     caller.
 *
 *   - It does NOT accept a `decision_date` field. The decision moment is
 *     set by the service from `Carbon::now()` inside the transaction.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — B6 (policy freeze)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze)
 *   Phase 2C A.18                                — `Loan` (the affected
 *                                                  aggregate)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *                                                  (the
 *                                                  REASON_REQUIRED_TRANSITIONS
 *                                                  set that excludes this
 *                                                  transition)
 *   Phase 2C A.29                                — `LoanApprovalService::approve()`
 *                                                  (the service that
 *                                                  consumes this request)
 *   Phase 2C A.52                                — `LoanPolicy::approve()`
 *                                                  (the authorization
 *                                                  ability)
 *   Phase 2C A.60                                — `StoreLoanRequest`
 *                                                  (the shape precedent)
 *   Phase 2C A.62                                — `RejectLoanRequest`
 *                                                  (the sibling whose
 *                                                  `reason` is required)
 *   Phase 2C A.77                                — `LoanController::approve()`
 *                                                  (the consumer)
 *   Phase 2A 2A.22                               — `ApproveCertificateRequest`
 *                                                  (the shape precedent)
 *   Step 5 (manifest) A.61                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Requests
 * @version 1.0.0
 */
final class ApproveLoanRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is enforced by the
     * controller via `Gate::authorize('approve', $loan)`. See the class
     * docblock §"Authorization".
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
            'reason' => [
                'sometimes',
                'nullable',
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
            'reason.string' => __('loan::validation.reason.string'),
            'reason.max'    => __('loan::validation.reason.max'),
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
            'reason' => __('loan::attributes.reason'),
        ];
    }

    /**
     * Normalize input before validation.
     *
     * Trims leading and trailing whitespace from `reason`. An
     * empty-after-trim value becomes `null`, so the service's
     * null-handling is consistent regardless of whether the caller
     * submitted an empty string or omitted the field.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('reason')) {
            return;
        }

        $value = $this->input('reason');

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                $value = null;
            }
        }

        $this->merge(['reason' => $value]);
    }
}