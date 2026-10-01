<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ReverseAllocationRequest
 *
 * Validates input for reversing a payment allocation.
 *
 * =============================================================================
 * What this request produces
 * =============================================================================
 * The validated payload drives AllocationService::reverse() (2B.18) via
 * the reverse() action of AllocationController (2B.29):
 *
 *     AllocationService::reverse(
 *         wallet:         <bound by route-model binding, tenant-scoped>,
 *         allocation:     <bound by route-model binding, wallet-scoped>,
 *         reversedAmount: <from the validated payload>,
 *         reason:         <from the validated payload>,
 *         reversedBy:     <from auth()->id()>,
 *     )
 *
 * The wallet is resolved by the controller through
 * `WalletRepository::findForTenant()` (2B.11) BEFORE this request's
 * validated payload reaches the service. The allocation is resolved
 * through `AllocationRepository::findForWallet($wallet->id, $allocationId)`
 * (2B.14), also by the controller. If either resolution yields `null`,
 * the controller short-circuits with the same 404 a non-existent id would
 * produce. See the class docblock §"Tenant scoping — critical for this
 * request" below.
 *
 * =============================================================================
 * Fields accepted by this request
 * =============================================================================
 *     reversed_amount   — required. A POSITIVE decimal string with at
 *                         most four decimal places. The sign is NOT
 *                         accepted: a reversal's direction is implicit
 *                         (it undoes the parent allocation's contribution),
 *                         not a property of the value. The service (2B.18
 *                         §"reverse") applies the reversal's effect in the
 *                         opposite direction of the parent allocation at
 *                         the projection layer.
 *
 *                         Zero is rejected. A zero-amount reversal has no
 *                         meaning: it would produce a row that changes no
 *                         projection.
 *
 *                         The field is named `reversed_amount` (not
 *                         `amount`) to match the column name on
 *                         `wallet_payment_allocation_reversals` (2B.53)
 *                         and the service parameter `$reversedAmount`.
 *
 *     reason            — required. Free text explaining why the reversal
 *                         is being made. Non-empty after trimming.
 *                         Capped at 500 characters, matching the
 *                         `wallet_payment_allocation_reversals.reason`
 *                         column width (2B.53).
 *
 *                         The `reason` is the metadata that distinguishes
 *                         a reversal from a mere mutation (Architecture
 *                         §3.6). It is REQUIRED — a reversal without a
 *                         recorded reason would undermine the attribution
 *                         audit trail that the reversal entity was
 *                         introduced to preserve.
 *
 * Two fields, no more. The following are NOT accepted by this request:
 *
 *     wallet_id       — resolved from the route parameter.
 *
 *     allocation_id   — resolved from the route parameter.
 *
 *     reversed_at     — hardcoded to `now()` by the service.
 *
 *     reversed_by     — resolved from `auth()->id()` by the controller.
 *                       Accepting a different actor from the payload
 *                       would defeat actor attribution.
 *
 * =============================================================================
 * Cross-field checks
 * =============================================================================
 * One check is performed in `withValidator()` after the base rules:
 *
 *   - The `reversed_amount` must be non-zero at scale 4. The regex in
 *     `rules()` guarantees the format, but does not guarantee non-zero;
 *     the explicit bcmath check closes the gap. The service (2B.18)
 *     re-enforces the same rule as a defence.
 *
 * The request does NOT verify that `reversed_amount` does not exceed the
 * allocation's effective allocated amount. That check is a business
 * invariant of Architecture §6.5 enforced by the service (2B.18
 * §"assertReversalDoesNotExceedEffective"), computed inside the
 * transaction against the locked allocation and its existing reversals.
 * Reproducing the check here would require a read of the allocation's
 * reversals before the transaction opens, and would race against
 * concurrent writers — the check would be against a stale value.
 *
 * =============================================================================
 * Tenant scoping (Step 7 v1.1.0 §C.6) — critical for this request
 * =============================================================================
 * The reverse endpoint accepts TWO route parameters, both of which must
 * be resolved under the tenant-scoping composition:
 *
 *     {wallet}      — the owning wallet.
 *     {allocation}  — the allocation to reverse.
 *
 * The layered enforcement:
 *
 *   1. This request validates SHAPE only: `reversed_amount` (format),
 *      `reason` (non-empty, capped). It accepts no id fields; there are
 *      none in the payload to validate.
 *
 *   2. The controller resolves the target wallet via
 *      `WalletRepository::findForTenant($tenantId, $walletId)` (2B.11).
 *      A cross-tenant wallet id yields `null` and the controller
 *      short-circuits with 404.
 *
 *   3. The controller resolves the allocation via
 *      `AllocationRepository::findForWallet($wallet->id, $allocationId)`
 *      (2B.14). A cross-wallet allocation id (or one belonging to a
 *      wallet the caller cannot access) yields `null` and the controller
 *      short-circuits with 404.
 *
 *   4. The service (2B.18 §"reverse") re-verifies the allocation-to-wallet
 *      correspondence as a defence against callers that bypass the
 *      controller (tests, artisan scripts).
 *
 * Step 7 v1.1.0 §C.3 test methods T10 and T11 exercise the two vectors
 * (tenantB wallet id, tenantB allocation id); both rely on layers 2 and 3
 * for their 404.
 *
 * The request itself does no tenant resolution; both route parameters are
 * resolved before the request's rules are evaluated. The request's job is
 * to validate the payload's shape, nothing more.
 *
 * =============================================================================
 * No authorization here
 * =============================================================================
 * `authorize()` returns true. The route-level
 * `auth.permission:wallet.allocation.reverse` middleware (Step 7 v1.1.0
 * §C.1, route #8) enforces the required permission before the request is
 * even instantiated. This permission is privileged — it is held only by
 * `super_admin` and `platform_admin` in the frozen RBAC matrix (Step 7
 * v1.1.0 §A.2.1), and is NOT bundled with `wallet.allocation.create`.
 * The controller may additionally invoke the WalletPolicy (2B.37) for its
 * own reasons.
 *
 * =============================================================================
 * Amharic-first error messages
 * =============================================================================
 * No hard-coded English strings. Every message and attribute name is a
 * translation key resolved through Laravel's translator in the current
 * locale, using the `wallet::validation.*` and `wallet::attributes.*`
 * namespaces.
 *
 * As of Phase 2B, the `wallet` translation files are NOT yet authored;
 * keys resolve to their own string until a future CR (analogous to
 * CR-009 for the Shareholder domain) supplies the Amharic and English
 * strings.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.6    — PaymentAllocationReversal entity (A1)
 *   Architecture v1.2.0 §4.7    — Deterministic lock acquisition order
 *   Architecture v1.2.0 §6.5    — Allocation-reversal invariants
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.25     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs (wallet.allocation.reverse)
 *   Step 7 v1.1.0 §A.2.1        — Rationale for the privileged scope
 *   Step 7 v1.1.0 §C.1          — Route #8
 *                                 (POST /wallets/{wallet}/allocations/{allocation}/reverse)
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T10, T11)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.6 (conventions)          — FormRequest conventions
 *   §2.14 (conventions)         — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Requests
 * @version 1.0.0
 */
final class ReverseAllocationRequest extends FormRequest
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.53 and matches the
     * service's own bound (2B.18 §MAX_DECIMAL_SCALE).
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * Maximum length of the `reason` field.
     *
     * Matches the `wallet_payment_allocation_reversals.reason` column
     * width (2B.53).
     */
    private const MAX_REASON_LENGTH = 500;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Always true — see the class docblock §"No authorization here".
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    // =========================================================================
    // Preparation
    // =========================================================================

    /**
     * Normalise string fields before validation.
     *
     * The following fields are trimmed of leading and trailing whitespace:
     *
     *     reversed_amount — preserved as a decimal string after trimming.
     *     reason          — trimmed. An empty-after-trim value is left as
     *                       an empty string; the `required` rule (which
     *                       performs its own whitespace-trim test) then
     *                       reports a clear "required" error rather than
     *                       the request needing a custom check.
     *
     * Non-string input for either field falls through to the corresponding
     * base rule (e.g. `string`), producing the appropriate 422.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach (['reversed_amount', 'reason'] as $field) {
            if (!$this->has($field)) {
                continue;
            }

            $raw = $this->input($field);

            if (!is_string($raw)) {
                continue;
            }

            $merged[$field] = trim($raw);
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    // =========================================================================
    // Rules
    // =========================================================================

    /**
     * Get the validation rules that apply to the request.
     *
     * Two rules, one per accepted field. See the class docblock
     * §"Fields accepted by this request" for the exhaustive list of what
     * is and is not accepted.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // Reversed amount — required, positive decimal, at most 4
            // decimals.
            //
            // The regex accepts an UNSIGNED decimal string. A sign is
            // deliberately not permitted: a reversal's direction is
            // implicit in the parent allocation it corrects. The non-zero
            // check is performed in `withValidator()` below.
            // -----------------------------------------------------------------
            'reversed_amount' => [
                'required',
                'string',
                'regex:/^\d+(\.\d{1,4})?$/',
            ],

            // -----------------------------------------------------------------
            // Reason — required, non-empty, capped.
            //
            // The `reason` column is NOT NULL in the schema (2B.53), and
            // the service (2B.18 §"assertReasonIsProvided") rejects a
            // whitespace-only value. The `required` rule performs its own
            // trim check, so an empty-after-trim value produces a clear
            // "reason is required" error.
            // -----------------------------------------------------------------
            'reason' => [
                'required',
                'string',
                'max:' . self::MAX_REASON_LENGTH,
            ],
        ];
    }

    // =========================================================================
    // Post-rule conditional checks
    // =========================================================================

    /**
     * Configure the validator instance.
     *
     * Adds one cross-field check after the base rules: the
     * `reversed_amount` must be non-zero at scale 4. The check is guarded
     * against running when the format rule already failed, so that a
     * malformed value does not reach bcmath.
     *
     * The check does NOT verify that the reversed amount is within the
     * allocation's effective allocated amount. That is a business
     * invariant of Architecture §6.5 enforced by the service (2B.18)
     * against the locked allocation and its existing reversals, inside
     * the transaction. See the class docblock §"Cross-field checks".
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('reversed_amount')) {
                return;
            }

            $amount = $this->input('reversed_amount');

            if (!is_string($amount)) {
                return;
            }

            if (bccomp($amount, '0', self::MAX_DECIMAL_SCALE) === 0) {
                $v->errors()->add(
                    'reversed_amount',
                    __('wallet::validation.allocation.reverse.reversed_amount.non_zero'),
                );
            }
        });
    }

    // =========================================================================
    // Messages and attributes
    // =========================================================================

    /**
     * Get the custom validation messages.
     *
     * Every message is a translation key resolved via `__()` in the
     * current locale. No English string is hard-coded.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reversed_amount.required' => __('wallet::validation.allocation.reverse.reversed_amount.required'),
            'reversed_amount.string'   => __('wallet::validation.allocation.reverse.reversed_amount.string'),
            'reversed_amount.regex'    => __('wallet::validation.allocation.reverse.reversed_amount.regex'),

            'reason.required' => __('wallet::validation.allocation.reverse.reason.required'),
            'reason.string'   => __('wallet::validation.allocation.reverse.reason.string'),
            'reason.max'      => __('wallet::validation.allocation.reverse.reason.max'),
        ];
    }

    /**
     * Get human-readable field names for error messages.
     *
     * Every attribute name is a translation key resolved via `__()` in
     * the current locale.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reversed_amount' => __('wallet::attributes.allocation.reverse.reversed_amount'),
            'reason'          => __('wallet::attributes.allocation.reverse.reason'),
        ];
    }
}