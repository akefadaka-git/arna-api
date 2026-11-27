<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates payment, obligation and positive allocation amount. Service enforces same-wallet, currency and transactional invariants.
 *
 * @package MaaleArna\Arna\Core\Domains\Wallet\Http\Requests
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