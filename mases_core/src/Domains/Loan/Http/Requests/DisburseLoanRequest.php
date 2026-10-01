<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DisburseLoanRequest
 *
 * The HTTP request validator for the loan-disbursement endpoint:
 * `POST /loans/{loan}/disburse` (A.77, `LoanController::disburse()`).
 *
 * =============================================================================
 * Scope — shape validation only
 * =============================================================================
 * This request validates the SHAPE of the incoming payload. It accepts a
 * single OPTIONAL field, `description`, whose presence is not required
 * and whose absence is a valid input.
 *
 * The request does NOT perform:
 *
 *   - Existence checks. Whether `{loan}` references an existing loan in
 *     the authenticated tenant is resolved by `LoanController::disburse()`
 *     (A.77) via `LoanRepository::findForTenantOrFail()` (A.55), which
 *     produces a 404 for a non-existent or cross-tenant loan id.
 *
 *   - State checks. Whether the loan is currently in `approved` state is
 *     validated by `LoanDisbursementService::disburse()` (A.30), which
 *     produces a 422 with a `status` field error for an illegal state.
 *
 *   - Wallet presence. Whether the borrower has a Wallet to receive the
 *     principal obligation is validated by
 *     `LoanDisbursementService::disburse()` (A.30), which produces a 422
 *     with a `wallet` field error when the borrower has no Wallet.
 *
 *   - Currency compatibility. Whether the product's currency matches the
 *     borrower's Wallet currency (Q8 invariant) is validated by
 *     `LoanDisbursementService::disburse()` (A.30), which produces a 422
 *     with a `currency` field error on a mismatch.
 *
 * The separation matches the shape/service split documented on
 * `StoreLoanRequest` (A.60).
 *
 * =============================================================================
 * The `description` field
 * =============================================================================
 * The `description` field is a `sometimes`-plus-`nullable` optional. It
 * carries a free-text description for the Wallet principal obligation
 * that the disbursement will record.
 *
 * Under the frozen A.30 flow, `LoanDisbursementService::disburse()`
 * accepts `?string $description = null`. When the caller omits it (or
 * sends `null`), the service generates a default description:
 *
 *     sprintf('Loan disbursement #%d', $current->id);
 *
 * The generated default is used as the Wallet obligation's `description`
 * column. A caller that supplies a custom `description` overrides the
 * default; the two are interchangeable at the service boundary.
 *
 * The field is deliberately optional: the default description is
 * sufficient for audit purposes, and requiring the caller to supply one
 * would add friction without benefit. The frozen service signature
 * reflects this — `?string $description = null`, not `string $description`.
 *
 * =============================================================================
 * Field reference
 * =============================================================================
 * The endpoint accepts one field:
 *
 *   description   OPTIONAL. String, up to 500 characters. Free-text
 *                 description for the Wallet principal obligation.
 *
 * The field is trimmed by `prepareForValidation()`. An empty-after-trim
 * value is normalized to `null`, so the service's default-description
 * fallback is used consistently regardless of whether the caller
 * submitted an empty string or omitted the field.
 *
 * No other field is accepted by this request. A caller who sends extra
 * fields (e.g. a `disbursed_by` override, a `disbursement_date`
 * override, a `wallet_id` override) finds them silently ignored by the
 * validation layer and never passed to the service. The wallet is
 * resolved by the service from the borrower's relations; the
 * disbursement actor is resolved from the authenticated user; the
 * disbursement date is set from `Carbon::now()` inside the transaction.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * `authorize()` returns `true` unconditionally. Authorization is enforced
 * by the controller (A.77) via `Gate::authorize('disburse', $loan)`,
 * which resolves through `LoanPolicy::disburse()` (A.52). Same posture as
 * `StoreLoanRequest` (A.60), `ApproveLoanRequest` (A.61), and
 * `RejectLoanRequest` (A.62).
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
 *   - It does NOT check the borrower's Wallet. The service resolves the
 *     Wallet and produces a 422 if it is missing.
 *
 *   - It does NOT check currency compatibility. The service resolves
 *     the product and the Wallet and produces a 422 on a mismatch.
 *
 *   - It does NOT accept a `disbursed_by` field. The disbursing actor is
 *     derived from the authenticated user by the controller and passed
 *     to the service as `$actorUserId`. The `disbursed_by` column on the
 *     loan row is NOT written by the frozen A.30 flow (see that file's
 *     class docblock for the note on the column's unused status); the
 *     actor is preserved on the Wallet obligation's `created_by` column
 *     instead.
 *
 *   - It does NOT accept a `disbursement_date` field. The disbursement
 *     moment is set by the service from `Carbon::now()` inside the
 *     transaction.
 *
 *   - It does NOT accept a `wallet_id` field. The Wallet is resolved by
 *     the service from the borrower's relations, not supplied by the
 *     caller. Allowing a caller-supplied Wallet id would be a
 *     security defect: a caller could redirect the disbursement to an
 *     arbitrary Wallet in the tenant.
 *
 *   - It does NOT accept a `currency` field. The loan's currency is
 *     copied from the borrower's Wallet by the service (Q8), not
 *     supplied by the caller.
 *
 *   - It does NOT accept a `principal_amount` override. The disbursement
 *     always records the loan's full principal (A.30 §"What this service
 *     deliberately does NOT do": partial disbursement is not in Phase 2C
 *     scope). Allowing a caller-supplied amount would break the
 *     reconciliation invariant.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D129, B1, B2
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C A.18                                — `Loan` (the affected
 *                                                  aggregate)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.30                                — `LoanDisbursementService::disburse()`
 *                                                  (the service that
 *                                                  consumes this request,
 *                                                  and that generates the
 *                                                  default description)
 *   Phase 2C A.52                                — `LoanPolicy::disburse()`
 *                                                  (the authorization
 *                                                  ability)
 *   Phase 2C A.60                                — `StoreLoanRequest`
 *                                                  (the shape precedent)
 *   Phase 2C A.61                                — `ApproveLoanRequest`
 *                                                  (the sibling with an
 *                                                  optional reason)
 *   Phase 2C A.77                                — `LoanController::disburse()`
 *                                                  (the consumer)
 *   Phase 2A 2A.22                               — `IssueCertificateRequest`
 *                                                  (the shape precedent)
 *   Step 5 (manifest) A.63                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Requests
 * @version 1.0.0
 */
final class DisburseLoanRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is enforced by the
     * controller via `Gate::authorize('disburse', $loan)`. See the class
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
            'description' => [
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
            'description.string' => __('loan::validation.description.string'),
            'description.max'    => __('loan::validation.description.max'),
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
            'description' => __('loan::attributes.description'),
        ];
    }

    /**
     * Normalize input before validation.
     *
     * Trims leading and trailing whitespace from `description`. An
     * empty-after-trim value becomes `null`, so the service's
     * default-description fallback (`'Loan disbursement #%d'`) is used
     * consistently regardless of whether the caller submitted an empty
     * string or omitted the field.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('description')) {
            return;
        }

        $value = $this->input('description');

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                $value = null;
            }
        }

        $this->merge(['description' => $value]);
    }
}