<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * StoreAllocationRequest
 *
 * Validates input for recording a payment allocation against an obligation.
 *
 * =============================================================================
 * What this request produces
 * =============================================================================
 * The validated payload drives AllocationService::allocate() (2B.18) via
 * the store() action of AllocationController (2B.29):
 *
 *     AllocationService::allocate(
 *         wallet:            <bound by route-model binding, tenant-scoped>,
 *         paymentId:         <from the validated payload>,
 *         obligationId:      <from the validated payload>,
 *         amount:            <from the validated payload>,
 *         createdBy:         <from auth()->id()>,
 *         policyVersionId:   <from the validated payload, or null>,
 *     )
 *
 * The wallet is resolved by the controller through
 * `WalletRepository::findForTenant()` (2B.11) BEFORE this request's
 * validated payload reaches the service. The two ids (`payment_id` and
 * `obligation_id`) are then resolved through their respective scoped
 * repository lookups (`PaymentRepository::findForWallet` and
 * `ObligationRepository::findForWallet`), also by the service (2B.18
 * §"allocate" — pre-flight resolution). See the class docblock
 * §"Tenant scoping" below.
 *
 * =============================================================================
 * Fields accepted by this request
 * =============================================================================
 *     payment_id                 — required. Integer; the id of the payment
 *                                  to allocate from. Shape-checked here;
 *                                  wallet-scoped by the service.
 *
 *     obligation_id              — required. Integer; the id of the
 *                                  obligation to allocate to. Shape-
 *                                  checked here; wallet-scoped by the
 *                                  service.
 *
 *     amount                     — required. A POSITIVE decimal string with
 *                                  at most four decimal places. The sign
 *                                  is NOT accepted: the direction of an
 *                                  allocation is a property of the
 *                                  operation (applying a payment to a
 *                                  debt), not of the value.
 *
 *                                  Zero is rejected. A zero-amount
 *                                  allocation has no meaning: it would
 *                                  produce a row that changes no
 *                                  projection.
 *
 *     allocation_policy_version_id — optional. Integer; the id of a
 *                                  `VersionedPolicy` row. Recorded on the
 *                                  allocation as historical metadata
 *                                  (2B.8 §"allocationPolicy"). NOT
 *                                  evaluated; the policy payload is
 *                                  never read in 2B (Architecture §10).
 *
 *                                  Type compatibility (that the
 *                                  referenced policy's `policy_type` is
 *                                  `'allocation'`) is NOT enforced by
 *                                  this request, and is deferred to a
 *                                  future policy-attachment service
 *                                  (Architecture §8.6).
 *
 * Four fields, no more. The following are NOT accepted by this request:
 *
 *     wallet_id       — resolved from the route parameter.
 *
 *     allocated_at    — hardcoded to `now()` by the service.
 *
 *     allocated_by    — resolved from `auth()->id()` by the controller.
 *                       Accepting a different actor from the payload
 *                       would defeat actor attribution.
 *
 *     currency        — a `PaymentAllocation` has no currency column
 *                       (2B.8 §"No currency column"). The currency of an
 *                       allocation is unambiguous from its parents; a
 *                       caller-supplied currency would have no target
 *                       column and would be a validation error waiting
 *                       to be silently dropped.
 *
 * =============================================================================
 * Cross-field checks
 * =============================================================================
 * One check is performed in `withValidator()` after the base rules:
 *
 *   - The amount must be non-zero at scale 4. The regex in `rules()`
 *     guarantees the format, but does not guarantee non-zero; the
 *     explicit bcmath check closes the gap. The service (2B.18)
 *     re-enforces the same rule as a defence.
 *
 * The `payment_id` and `obligation_id` fields are NOT cross-checked for
 * equality, nor for mutual relationship — the service (2B.18) is the
 * authority on the six allocation invariants of Architecture §6.3,
 * including "payment.wallet_id == obligation.wallet_id" and the Q8
 * currency equality. Reproducing those checks here would duplicate
 * business logic and risk divergence.
 *
 * =============================================================================
 * Tenant scoping (Step 7 v1.1.0 §C.6) — critical for this request
 * =============================================================================
 * The cross-tenant protection for this endpoint is the strongest test of
 * the Step 7 v1.1.0 §C.6 invariant. The endpoint accepts TWO cross-entity
 * references (`payment_id`, `obligation_id`), each of which could be
 * forged to point at a tenantB object.
 *
 * The layered enforcement:
 *
 *   1. This request validates SHAPE only:
 *
 *          payment_id    -> integer, min:1, exists:wallet_payments,id
 *          obligation_id -> integer, min:1, exists:wallet_obligations,id
 *
 *      The `exists` rules check that the ids reference SOME row anywhere
 *      in the database. They do NOT enforce the wallet boundary.
 *
 *   2. The controller resolves the target wallet via
 *      `WalletRepository::findForTenant($tenantId, $walletId)` (2B.11).
 *      A cross-tenant wallet id yields `null` and the controller
 *      short-circuits with 404.
 *
 *   3. The service (2B.18 §"allocate") resolves `payment_id` and
 *      `obligation_id` through `PaymentRepository::findForWallet()` and
 *      `ObligationRepository::findForWallet()` — both scoped to the
 *      wallet resolved in step 2. A cross-wallet `payment_id` or
 *      `obligation_id` yields `null` and the service throws
 *      `ModelNotFoundException`, rendered as 404.
 *
 * Step 7 v1.1.0 §C.3 test methods T8 and T9 exercise the two
 * indirect-reference vectors (tenantB payment_id, tenantB obligation_id);
 * both rely on step 3 for their 404.
 *
 * The `allocation_policy_version_id` field is shape-checked for existence
 * against `versioned_policies.id` but is NOT wallet-scoped. Policies are
 * cross-tenant reference data (they are not owned by a wallet; see
 * Architecture §8). The FK proves existence only; type compatibility is
 * deferred (see the field description above).
 *
 * =============================================================================
 * No authorization here
 * =============================================================================
 * `authorize()` returns true. The route-level
 * `auth.permission:wallet.allocation.create` middleware (Step 7 v1.1.0
 * §C.1, route #7) enforces the required permission before the request is
 * even instantiated. The controller may additionally invoke the
 * WalletPolicy (2B.37) for its own reasons. The FormRequest itself
 * performs no authorization.
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
 *   Architecture v1.2.0 §3.5    — PaymentAllocation entity
 *   Architecture v1.2.0 §3.6    — PaymentAllocationReversal entity
 *   Architecture v1.2.0 §6.3    — Allocation invariants
 *   Architecture v1.2.0 §8.6    — Policy reference integrity
 *   Architecture v1.2.0 §10     — Allocation-policy contract boundary
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.24     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs (wallet.allocation.create)
 *   Step 7 v1.1.0 §C.1          — Route #7
 *                                 (POST /wallets/{wallet}/allocations)
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T7, T8, T9)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.6 (conventions)          — FormRequest conventions
 *   §2.14 (conventions)         — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Requests
 * @version 1.0.0
 */
final class StoreAllocationRequest extends FormRequest
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.52 and matches the
     * service's own bound (2B.18 §MAX_DECIMAL_SCALE).
     */
    private const MAX_DECIMAL_SCALE = 4;

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
     * Normalise the amount before validation.
     *
     * The `amount` field is trimmed of leading and trailing whitespace.
     * The digits and decimal point are preserved; no reformatting is
     * performed. Any non-string input falls through to the `string` rule,
     * producing the appropriate 422.
     *
     * The two id fields (`payment_id`, `obligation_id`) and the optional
     * `allocation_policy_version_id` are left untouched: they are
     * integers, and any non-numeric input is rejected by the `integer`
     * rule with the standard 422.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('amount')) {
            return;
        }

        $raw = $this->input('amount');

        if (!is_string($raw)) {
            return;
        }

        $this->merge([
            'amount' => trim($raw),
        ]);
    }

    // =========================================================================
    // Rules
    // =========================================================================

    /**
     * Get the validation rules that apply to the request.
     *
     * Four rules, one per accepted field. See the class docblock
     * §"Fields accepted by this request" for the exhaustive list of what
     * is and is not accepted.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // Payment reference — shape-only.
            //
            // `exists:wallet_payments,id` checks that the id references
            // SOME payment row anywhere in the database. The wallet
            // boundary is enforced by the service's pre-flight
            // `PaymentRepository::findForWallet()` lookup, which produces
            // a 404 for a cross-wallet id. See the class docblock
            // §"Tenant scoping".
            // -----------------------------------------------------------------
            'payment_id' => [
                'required',
                'integer',
                'min:1',
                'exists:wallet_payments,id',
            ],

            // -----------------------------------------------------------------
            // Obligation reference — shape-only.
            //
            // `exists:wallet_obligations,id` checks that the id references
            // SOME obligation row anywhere in the database. The wallet
            // boundary is enforced by the service's pre-flight
            // `ObligationRepository::findForWallet()` lookup, which
            // produces a 404 for a cross-wallet id.
            // -----------------------------------------------------------------
            'obligation_id' => [
                'required',
                'integer',
                'min:1',
                'exists:wallet_obligations,id',
            ],

            // -----------------------------------------------------------------
            // Amount — required, positive decimal, at most 4 decimals.
            //
            // The regex accepts an UNSIGNED decimal string. A sign is
            // deliberately not permitted: an allocation's direction is a
            // property of the operation, not of the value. The non-zero
            // check is performed in `withValidator()` below.
            // -----------------------------------------------------------------
            'amount' => [
                'required',
                'string',
                'regex:/^\d+(\.\d{1,4})?$/',
            ],

            // -----------------------------------------------------------------
            // Allocation policy version reference — optional.
            //
            // `exists:versioned_policies,id` checks that the id references
            // SOME policy row. The FK proves existence only; type
            // compatibility (that the referenced policy's `policy_type`
            // is `'allocation'`) is deferred to a future policy-attachment
            // service (Architecture §8.6).
            //
            // The field is NOT wallet-scoped: policies are cross-tenant
            // reference data.
            // -----------------------------------------------------------------
            'allocation_policy_version_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:versioned_policies,id',
            ],
        ];
    }

    // =========================================================================
    // Post-rule conditional checks
    // =========================================================================

    /**
     * Configure the validator instance.
     *
     * Adds one cross-field check after the base rules: the amount must be
     * non-zero at scale 4. The check is guarded against running when the
     * format rule already failed, so that a malformed value does not
     * reach bcmath.
     *
     * The `payment_id`, `obligation_id`, and `allocation_policy_version_id`
     * fields are NOT cross-checked. The service (2B.18) is the authority
     * on the six allocation invariants of Architecture §6.3, including
     * the wallet and currency equalities. Reproducing those checks here
     * would duplicate business logic and risk divergence.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('amount')) {
                return;
            }

            $amount = $this->input('amount');

            if (!is_string($amount)) {
                return;
            }

            if (bccomp($amount, '0', self::MAX_DECIMAL_SCALE) === 0) {
                $v->errors()->add(
                    'amount',
                    __('wallet::validation.allocation.amount.non_zero'),
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
            'payment_id.required' => __('wallet::validation.allocation.payment_id.required'),
            'payment_id.integer'  => __('wallet::validation.allocation.payment_id.integer'),
            'payment_id.min'      => __('wallet::validation.allocation.payment_id.min'),
            'payment_id.exists'   => __('wallet::validation.allocation.payment_id.exists'),

            'obligation_id.required' => __('wallet::validation.allocation.obligation_id.required'),
            'obligation_id.integer'  => __('wallet::validation.allocation.obligation_id.integer'),
            'obligation_id.min'      => __('wallet::validation.allocation.obligation_id.min'),
            'obligation_id.exists'   => __('wallet::validation.allocation.obligation_id.exists'),

            'amount.required' => __('wallet::validation.allocation.amount.required'),
            'amount.string'   => __('wallet::validation.allocation.amount.string'),
            'amount.regex'    => __('wallet::validation.allocation.amount.regex'),

            'allocation_policy_version_id.integer' => __('wallet::validation.allocation.allocation_policy_version_id.integer'),
            'allocation_policy_version_id.min'     => __('wallet::validation.allocation.allocation_policy_version_id.min'),
            'allocation_policy_version_id.exists'  => __('wallet::validation.allocation.allocation_policy_version_id.exists'),
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
            'payment_id'                   => __('wallet::attributes.allocation.payment_id'),
            'obligation_id'                => __('wallet::attributes.allocation.obligation_id'),
            'amount'                       => __('wallet::attributes.allocation.amount'),
            'allocation_policy_version_id' => __('wallet::attributes.allocation.allocation_policy_version_id'),
        ];
    }
}