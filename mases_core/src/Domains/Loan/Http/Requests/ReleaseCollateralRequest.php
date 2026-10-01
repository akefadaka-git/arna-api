<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ReleaseCollateralRequest
 *
 * The HTTP request validator for the collateral-release endpoint:
 * `POST /loans/{loan}/collateral/{collateral}/release` (A.79,
 * `LoanCollateralController::release()`).
 *
 * This is the LAST of the nine Loan-domain HTTP requests (A.60–A.68).
 * Structurally it is the simplest of the set: a single REQUIRED `reason`
 * field. It mirrors `StoreWriteOffRequest` (A.66) and
 * `RejectLoanRequest` (A.62) in shape.
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
 *     `LoanCollateralController::release()` (A.79) via
 *     `LoanRepository::findForTenantOrFail()` (A.55). Whether
 *     `{collateral}` references an existing collateral belonging to that
 *     loan is resolved via
 *     `LoanCollateralRepository::findForLoan()` (A.58). Both produce a
 *     404 for a non-existent or cross-tenant reference.
 *
 *   - State checks. Whether the collateral is currently in
 *     `CollateralStatus::Registered` (the only status from which the
 *     release transition is structurally legal, per the
 *     `CollateralStatus::canTransitionTo()` matrix, A.3) is validated by
 *     `LoanCollateralService::release()` (A.37). A collateral that has
 *     already been released produces a 422 with a `status` field error.
 *
 *   - Loan-state checks. Whether the loan is in a state that permits
 *     the release (the frozen A.10 freeze permits release for any
 *     non-terminal loan, plus certain terminal cases; see A.37's class
 *     docblock for the exact guard) is validated by the service.
 *
 *   - Reason-required re-checks at the service layer. The service
 *     (`LoanCollateralService::release()`, A.37) performs its OWN
 *     pre-transaction reason check, independent of this request. See
 *     the sibling note on `RejectLoanRequest` (A.62) for the rationale
 *     on the double enforcement.
 *
 * The separation matches the shape/service split documented on
 * `StoreLoanRequest` (A.60).
 *
 * =============================================================================
 * The REQUIRED `reason` field
 * =============================================================================
 * A collateral release is not gated by `LoanStateMachine` — the release
 * does not change the LOAN's status, only the COLLATERAL's status. The
 * `REASON_REQUIRED_TRANSITIONS` set on the state machine (A.26) is
 * therefore not the source of the reason requirement here.
 *
 * The reason is required by the frozen A10 decision and by
 * `LoanCollateralService::release()` (A.37), which is the operation's
 * authoritative enforcement point. The request's required-reason rule
 * mirrors the service's enforcement at the HTTP boundary:
 *
 *   - The business needs to know WHY the security interest was
 *     discharged — whether because the loan was repaid, because the
 *     collateral was replaced, because an error in registration was
 *     corrected, or for any other reason the operator can articulate.
 *
 *   - The reason is persisted on the collateral row's `release_reason`
 *     column (migration A.11) and carried on the `LoanCollateralReleased`
 *     event (A.50) as a separate scalar.
 *
 * This request enforces the requirement at the HTTP boundary with the
 * same `required` + trim-and-null-normalization combination used by
 * `RejectLoanRequest` (A.62), `StoreRestructureRequest` (A.65), and
 * `StoreWriteOffRequest` (A.66). A payload that omits `reason`, sends
 * `null`, sends `""`, or sends `"   "` fails the `required` rule.
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
 * `released_at` override, a `released_by` override, a `status` override)
 * finds them silently ignored by the validation layer and never passed
 * to the service.
 *
 * The release metadata is derived by the service:
 *
 *   - `status` transitions from `registered` to `released` via the
 *     service's status update.
 *   - `released_at` is set from `Carbon::now()` inside the service
 *     transaction (or from a caller-supplied `$asOf` in a future CR; no
 *     Phase 2C endpoint exposes that override).
 *   - `release_reason` is copied from the request's `reason` field by
 *     the controller, which passes it to the service.
 *   - `released_by` is derived from the authenticated user by the
 *     controller and passed to the service as `$actorUserId`.
 *
 * Accepting any of the other three as caller inputs would let a caller
 * falsify the release record.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * `authorize()` returns `true` unconditionally. Authorization is enforced
 * by the controller (A.79) via
 * `Gate::authorize('release', $collateral)`, which resolves through
 * `LoanCollateralPolicy::release()` (A.54). Same posture as A.60–A.67.
 *
 * Note that `loan.collateral.release` is one of the two permissions the
 * frozen RBAC matrix (B7) withholds from the `platform_admin` role (the
 * other being `loan.writeoff`); only `super_admin` holds it among the
 * platform-level roles, and no tenant-scoped role holds it. This is a
 * policy-level distinction; the request does not encode it.
 *
 * =============================================================================
 * What this request deliberately does NOT do
 * =============================================================================
 *   - It does NOT authorize. See §"Authorization".
 *
 *   - It does NOT check the loan's or the collateral's existence. The
 *     controller resolves both through the tenant-scoped repositories.
 *
 *   - It does NOT check the collateral's current status. The service is
 *     the authoritative state boundary.
 *
 *   - It does NOT accept a `status` field. The target status is
 *     `released`, hardcoded by the service; the caller cannot choose a
 *     different one.
 *
 *   - It does NOT accept a `released_at` field. The moment is set by the
 *     service from `Carbon::now()` inside the transaction.
 *
 *   - It does NOT accept a `released_by` field. The actor is derived from
 *     the authenticated user.
 *
 *   - It does NOT accept a `release_reason` field (only `reason`). The
 *     request's field name is `reason` (the operator's input); the
 *     column it populates is `release_reason`. The naming asymmetry is
 *     intentional: request fields use the operation-agnostic name
 *     `reason`, matching A.62, A.65, and A.66; the database column
 *     carries the collateral-specific `release_reason` name to
 *     distinguish it from other potential reasons on the same table.
 *
 *   - It does NOT interact with the Shareholder ledger. A shareholding-
 *     typed collateral's release in Phase 2C is a Loan-domain-only
 *     operation (per A.10, B4, and the deferred-FK decision
 *     CR-2C-COLLATERAL-PLEDGE-FK-001). It does NOT mutate
 *     `share_holdings` or `share_holding_entries`. A future CR that
 *     introduces a Shareholder-side pledge relationship would extend
 *     the service, not this request.
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
 *   Phase 2C A.11                                — `loan_collaterals`
 *                                                  migration (the
 *                                                  release_reason column)
 *   Phase 2C A.18                                — `Loan` (the parent
 *                                                  aggregate)
 *   Phase 2C A.20                                — `LoanCollateral`
 *   Phase 2C A.37                                — `LoanCollateralService::release()`
 *                                                  (the service that
 *                                                  consumes this request)
 *   Phase 2C A.50                                — `LoanCollateralReleased`
 *                                                  (the event dispatched
 *                                                  from the release flow)
 *   Phase 2C A.52                                — `LoanPolicy`
 *   Phase 2C A.54                                — `LoanCollateralPolicy::release()`
 *                                                  (the authorization
 *                                                  ability)
 *   Phase 2C A.60                                — `StoreLoanRequest`
 *                                                  (the shape precedent)
 *   Phase 2C A.62                                — `RejectLoanRequest`
 *                                                  (the required-reason
 *                                                  precedent)
 *   Phase 2C A.66                                — `StoreWriteOffRequest`
 *                                                  (the most recent
 *                                                  required-reason
 *                                                  sibling)
 *   Phase 2C A.67                                — `StoreCollateralRequest`
 *                                                  (the sibling
 *                                                  collateral request)
 *   Phase 2C A.79                                — `LoanCollateralController::release()`
 *                                                  (the consumer)
 *   Phase 2A 2A.22                               — `ApproveCertificateRequest`
 *                                                  (the shape precedent)
 *   CR-2C-COLLATERAL-PLEDGE-FK-001               — deferred FK
 *   Step 5 (manifest) A.68                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Requests
 * @version 1.0.0
 */
final class ReleaseCollateralRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is enforced by the
     * controller via `Gate::authorize('release', $collateral)`. See the
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