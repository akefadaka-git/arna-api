<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RejectLoanRequest
 *
 * The HTTP request validator for the loan-rejection endpoint:
 * `POST /loans/{loan}/reject` (A.77, `LoanController::reject()`).
 *
 * =============================================================================
 * Scope — shape validation only
 * =============================================================================
 * This request validates the SHAPE of the incoming payload. It accepts a
 * single REQUIRED field, `reason`, whose presence and non-emptiness are
 * enforced by the validation rules.
 *
 * The request does NOT perform:
 *
 *   - Existence checks. Whether `{loan}` references an existing loan in
 *     the authenticated tenant is resolved by `LoanController::reject()`
 *     (A.77) via `LoanRepository::findForTenantOrFail()` (A.55), which
 *     produces a 404 for a non-existent or cross-tenant loan id.
 *
 *   - State checks. Whether the loan is currently in a decidable state
 *     (`submitted` or `under_review`) is validated by
 *     `LoanApprovalService::lockAndAssertDecidable()` (A.29), which
 *     produces a 422 with a `status` field error for an illegal state.
 *
 *   - Reason-presence re-checks at the service layer. The service
 *     (`LoanApprovalService::reject()`, A.29) performs its OWN
 *     pre-transaction reason check, independent of this request. The
 *     duplication is deliberate: the request's check produces a 422
 *     through the standard validation pipeline (with a field-keyed error
 *     envelope); the service's check protects against a caller that
 *     bypasses the HTTP layer (a future programmatic caller, a test, a
 *     console command). Both must hold; neither replaces the other.
 *
 * The separation matches the shape/service split documented on
 * `StoreLoanRequest` (A.60).
 *
 * =============================================================================
 * The REQUIRED `reason` field — the reason-required transition
 * =============================================================================
 * The `under_review → rejected` transition is one of the five entries in
 * `LoanStateMachine::REASON_REQUIRED_TRANSITIONS` (A.26). The frozen
 * decision is that a rejection without a reason is structurally
 * incomplete: the business needs to know WHY a loan was declined, both
 * for regulatory audit and for a future re-application.
 *
 * This request enforces the requirement at the HTTP boundary. The rules
 * are:
 *
 *   - `required`    — the field must be present and non-empty AFTER the
 *                     `prepareForValidation()` trim. A payload that
 *                     omits `reason`, sends `null`, sends `""`, or
 *                     sends `"   "` (whitespace-only) fails the
 *                     `required` rule.
 *
 *   - `string`      — the field must be a string (not a number, array,
 *                     or object).
 *
 *   - `max:500`     — the field must not exceed 500 characters.
 *
 * The non-emptiness is enforced via the `required` rule combined with the
 * `prepareForValidation()` trim-and-null-normalization. Laravel's
 * `required` rule treats a `null` value as missing and an empty string
 * as missing; the normalization converts `""` and `"   "` to `null`
 * before the rule runs, so all three empty forms are caught by the same
 * rule.
 *
 * Contrast with `ApproveLoanRequest` (A.61), whose `reason` is OPTIONAL
 * because `under_review → approved` is not in the reason-required set.
 *
 * =============================================================================
 * Field reference
 * =============================================================================
 * The endpoint accepts one field:
 *
 *   reason   REQUIRED. String, 1 to 500 characters after trimming.
 *            Free-text justification supplied by the rejector.
 *
 * No other field is accepted. A caller who sends extra fields (e.g. a
 * `rejected_by` override, a `decision_date` override) finds them
 * silently ignored by the validation layer and never passed to the
 * service — this request's `validated()` output contains only the
 * documented field.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * `authorize()` returns `true` unconditionally. Authorization is enforced
 * by the controller (A.77) via `Gate::authorize('reject', $loan)`, which
 * resolves through `LoanPolicy::reject()` (A.52). Same posture as
 * `StoreLoanRequest` (A.60) and `ApproveLoanRequest` (A.61).
 *
 * =============================================================================
 * What this request deliberately does NOT do
 * =============================================================================
 *   - It does NOT authorize. See §"Authorization".
 *
 *   - It does NOT check the loan's existence. The controller resolves
 *     `{loan}` through the tenant-scoped repository.
 *
 *   - It does NOT check the loan's state. The service is the
 *     authoritative state-machine boundary.
 *
 *   - It does NOT accept a `rejected_by` field. The rejecting actor is
 *     derived from the authenticated user by the controller and passed
 *     to the service as `$actorUserId`. The `approved_by` column on the
 *     loan row is NOT written for a rejection; the actor is recorded on
 *     the `loan_status_histories.actor_user_id` row instead (see A.29's
 *     class docblock §"The decision metadata written by this service"
 *     for the rationale).
 *
 *   - It does NOT accept a `decision_date` field. The decision moment is
 *     set by the service from `Carbon::now()` inside the transaction.
 *
 *   - It does NOT permit a reason longer than 500 characters. The
 *     migration A.14 declares the `transition_reason` column as a
 *     reasonable-width string; the 500-character limit is a defensive
 *     bound that matches the platform-wide free-text field convention
 *     and the sibling requests (A.60, A.61).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — C4 (state freeze),
 *                                                  D133 (reason
 *                                                  requirement for
 *                                                  rejections)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (transition matrix)
 *   Phase 2C A.14                                — `loan_status_histories`
 *                                                  migration (the
 *                                                  transition_reason
 *                                                  column)
 *   Phase 2C A.18                                — `Loan` (the affected
 *                                                  aggregate)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *                                                  (the
 *                                                  REASON_REQUIRED_TRANSITIONS
 *                                                  set that includes this
 *                                                  transition)
 *   Phase 2C A.29                                — `LoanApprovalService::reject()`
 *                                                  (the service that
 *                                                  consumes this request,
 *                                                  and that performs its
 *                                                  own pre-transaction
 *                                                  reason check)
 *   Phase 2C A.52                                — `LoanPolicy::reject()`
 *                                                  (the authorization
 *                                                  ability)
 *   Phase 2C A.60                                — `StoreLoanRequest`
 *                                                  (the shape precedent)
 *   Phase 2C A.61                                — `ApproveLoanRequest`
 *                                                  (the sibling whose
 *                                                  `reason` is optional)
 *   Phase 2C A.77                                — `LoanController::reject()`
 *                                                  (the consumer)
 *   Phase 2A 2A.22                               — `ApproveCertificateRequest`
 *                                                  (the shape precedent
 *                                                  for the reason-required
 *                                                  pattern)
 *   Step 5 (manifest) A.62                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Requests
 * @version 1.0.0
 */
final class RejectLoanRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is enforced by the
     * controller via `Gate::authorize('reject', $loan)`. See the class
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
     * See the class docblock §"The REQUIRED `reason` field" for the
     * rationale.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
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
            'reason' => __('loan::attributes.reason'),
        ];
    }

    /**
     * Normalize input before validation.
     *
     * Trims leading and trailing whitespace from `reason`. An
     * empty-after-trim value becomes `null`, so the `required` rule
     * catches all three empty forms (`null` input, `""` input, and
     * whitespace-only input) through a single rule.
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