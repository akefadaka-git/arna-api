<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreWriteOffRequest
 *
 * The HTTP request validator for the loan write-off endpoint:
 * `POST /loans/{loan}/writeoff` (A.77, `LoanController::writeoff()`).
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
 *     the authenticated tenant is resolved by
 *     `LoanController::writeoff()` (A.77) via
 *     `LoanRepository::findForTenantOrFail()` (A.55), which produces a
 *     404 for a non-existent or cross-tenant loan id.
 *
 *   - State checks. Whether the loan is currently in one of the three
 *     write-off-eligible statuses (`active`, `in_arrears`,
 *     `restructured`) is validated by
 *     `LoanWriteOffService::writeOff()` (A.35), which produces a 422
 *     with a `status` field error for an illegal state.
 *
 *   - Wallet presence. Whether the borrower has a Wallet to discharge
 *     obligations against is validated by
 *     `LoanWriteOffService::writeOff()` (A.35), which produces a
 *     `LogicException` (a data-integrity defect) when the borrower has
 *     no Wallet. The reason: a loan in a write-off-eligible status
 *     necessarily went through disbursement, which required a Wallet at
 *     that time; a missing Wallet at write-off time is a defect, not a
 *     caller error.
 *
 *   - Reason-required re-checks at the service layer. The service
 *     (`LoanWriteOffService::writeOff()`, A.35) performs its OWN
 *     pre-transaction reason check, independent of this request. See
 *     the sibling note on `RejectLoanRequest` (A.62) for the rationale
 *     on the double enforcement.
 *
 * The separation matches the shape/service split documented on
 * `StoreLoanRequest` (A.60).
 *
 * =============================================================================
 * The REQUIRED `reason` field — the reason-required transition
 * =============================================================================
 * Every transition into `written_off` — `active → written_off`,
 * `in_arrears → written_off`, and `restructured → written_off` — is in
 * `LoanStateMachine::REASON_REQUIRED_TRANSITIONS` (A.26). The frozen
 * decision is that a write-off without a reason is structurally
 * incomplete: the business needs to know WHY a loan was forgiven, both
 * for regulatory audit and for the Shareholder-domain relationship
 * record.
 *
 * This request enforces the requirement at the HTTP boundary with the
 * same `required` + trim-and-null-normalization combination used by
 * `RejectLoanRequest` (A.62) and `StoreRestructureRequest` (A.65). A
 * payload that omits `reason`, sends `null`, sends `""`, or sends
 * `"   "` fails the `required` rule.
 *
 * =============================================================================
 * Field reference
 * =============================================================================
 * The endpoint accepts one field:
 *
 *   reason   REQUIRED. String, 1 to 500 characters after trimming.
 *            Free-text justification supplied by the operator.
 *
 * No other field is accepted. A caller who sends extra fields (e.g. a
 * `write_off_amount` override, a `written_off_by` override, a
 * `written_off_at` override) finds them silently ignored by the
 * validation layer and never passed to the service.
 *
 * The write-off amount, the three `*_at_writeoff` snapshot columns, the
 * operator, and the moment are ALL derived by the service:
 *
 *   - `write_off_amount` and the three snapshot columns are computed
 *     from the loan's current outstanding projections (A.35, step 5).
 *   - `written_off_by` is derived from the authenticated user by the
 *     controller and passed to the service as `$actorUserId`.
 *   - `written_off_at` is set from `Carbon::now()` inside the service
 *     transaction (or from the caller-supplied `$asOf` in a future CR;
 *     no Phase 2C endpoint exposes that override).
 *
 * Accepting any of these as caller inputs would let a caller falsify the
 * write-off record — the most sensitive entry in the Loan domain's
 * history.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * `authorize()` returns `true` unconditionally. Authorization is enforced
 * by the controller (A.77) via `Gate::authorize('writeoff', $loan)`,
 * which resolves through `LoanPolicy::writeoff()` (A.52). Same posture as
 * A.60–A.65.
 *
 * Note that `loan.writeoff` is one of the two permissions the frozen
 * RBAC matrix (B7) withholds from the `platform_admin` role; only
 * `super_admin` holds it among the platform-level roles, and no
 * tenant-scoped role holds it. This is a policy-level distinction; the
 * request does not encode it.
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
 *   - It does NOT accept a `write_off_amount` field. The amount forgiven
 *     is the loan's total outstanding at the moment of the operation; it
 *     is computed by the service from the loan's projections. Accepting
 *     a caller-supplied amount would allow a partial write-off (which
 *     Phase 2C does not support) or an excess write-off (which the
 *     frozen flow never produces).
 *
 *   - It does NOT accept the three `*_at_writeoff` snapshot fields. They
 *     are read from the loan's projections by the service, in the same
 *     transaction that zeroes them.
 *
 *   - It does NOT accept a `written_off_by` field. The actor is derived
 *     from the authenticated user.
 *
 *   - It does NOT accept a `written_off_at` field. The moment is set by
 *     the service from `Carbon::now()` inside the transaction.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130, A10, B1, B2
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.3 (reference-id
 *                                                  ordering)
 *   Phase 2C A.13                                — `loan_write_offs`
 *                                                  migration
 *   Phase 2C A.18                                — `Loan` (the affected
 *                                                  aggregate)
 *   Phase 2C A.22                                — `LoanWriteOff` (the
 *                                                  model whose row this
 *                                                  request's service
 *                                                  produces)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *                                                  (the
 *                                                  REASON_REQUIRED_TRANSITIONS
 *                                                  set that includes all
 *                                                  three write-off
 *                                                  transitions)
 *   Phase 2C A.35                                — `LoanWriteOffService::writeOff()`
 *                                                  (the service that
 *                                                  consumes this request)
 *   Phase 2C A.52                                — `LoanPolicy::writeoff()`
 *                                                  (the authorization
 *                                                  ability)
 *   Phase 2C A.60                                — `StoreLoanRequest`
 *                                                  (the shape precedent)
 *   Phase 2C A.62                                — `RejectLoanRequest`
 *                                                  (the required-reason
 *                                                  precedent)
 *   Phase 2C A.65                                — `StoreRestructureRequest`
 *                                                  (the most recent
 *                                                  required-reason
 *                                                  sibling)
 *   Phase 2C A.77                                — `LoanController::writeoff()`
 *                                                  (the consumer)
 *   Phase 2A 2A.22                               — `ApproveCertificateRequest`
 *                                                  (the shape precedent)
 *   Step 5 (manifest) A.66                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Requests
 * @version 1.0.0
 */
final class StoreWriteOffRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is enforced by the
     * controller via `Gate::authorize('writeoff', $loan)`. See the class
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