<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates reversal amount and reason. Wallet and allocation are route-bound and scoped; actor identity is server-derived.
 *
 * @package MaaleArna\Arna\Core\Domains\Wallet\Http\Requests
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