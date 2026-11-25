<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates shareholder and currency input; tenant identity comes from server context and is rechecked by WalletService.
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Requests
 * @version 1.0.0
 */
final class OpenWalletRequest extends FormRequest
{
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
     * Normalise the currency code before validation.
     *
     * The `currency` field is uppercased and trimmed of leading and
     * trailing whitespace. This lets a caller send `etb`, ` ETB`, or
     * `Etb` and have all three resolve to the canonical `ETB`.
     *
     * The `shareholder_id` field is left untouched: it is an integer, and
     * any non-numeric input is rejected by the `integer` rule with the
     * standard 422.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('currency')) {
            return;
        }

        $raw = $this->input('currency');

        if (!is_string($raw)) {
            // Non-string input falls through to the `string` rule, which
            // produces the appropriate 422. The request does not attempt
            // to coerce a non-string into a string.
            return;
        }

        $this->merge([
            'currency' => strtoupper(trim($raw)),
        ]);
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
            // Shareholder reference — shape-only.
            //
            // `exists:shareholders,id` checks that the id references SOME
            // shareholder row anywhere in the database. The tenant boundary
            // is enforced by the controller's tenant-scoped resolution
            // before the service is invoked. See the class docblock
            // §"Tenant scoping".
            // -----------------------------------------------------------------
            'shareholder_id' => [
                'required',
                'integer',
                'min:1',
                'exists:shareholders,id',
            ],

            // -----------------------------------------------------------------
            // Currency — ISO 4217 shape only.
            //
            // The regex `/^[A-Za-z]{3}$/` enforces exactly three ASCII
            // letters. `prepareForValidation()` uppercases the value, so
            // in practice only `[A-Z]{3}` reaches this rule; the `[A-Za-z]`
            // character class is retained for defensive explicitness.
            //
            // No allow-list, no minor-unit precision check. See the class
            // docblock §"Currency format vs. minor-unit precision".
            // -----------------------------------------------------------------
            'currency' => [
                'required',
                'string',
                'regex:/^[A-Za-z]{3}$/',
            ],
        ];
    }

    // =========================================================================
    // Post-rule conditional checks
    // =========================================================================

    /**
     * Configure the validator instance.
     *
     * Currently no cross-field checks are needed — the two fields are
     * independent. The method is retained so that a future CR (e.g. an
     * optional `representative_user_id` for a legal-entity-shareholder
     * wallet, or a policy-attachment field once that operation is added)
     * has an obvious extension point.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        // No additional checks in Phase 2B.
    }

    // =========================================================================
    // Messages and attributes
    // =========================================================================

    /**
     * Get the custom validation messages.
     *
     * Every message is a translation key resolved via `__()` in the
     * current locale. No English string is hard-coded. See the class
     * docblock §"Amharic-first error messages".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shareholder_id.required' => __('wallet::validation.open.shareholder_id.required'),
            'shareholder_id.integer'  => __('wallet::validation.open.shareholder_id.integer'),
            'shareholder_id.min'      => __('wallet::validation.open.shareholder_id.min'),
            'shareholder_id.exists'   => __('wallet::validation.open.shareholder_id.exists'),

            'currency.required' => __('wallet::validation.open.currency.required'),
            'currency.string'   => __('wallet::validation.open.currency.string'),
            'currency.regex'    => __('wallet::validation.open.currency.regex'),
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
            'shareholder_id' => __('wallet::attributes.open.shareholder_id'),
            'currency'       => __('wallet::attributes.open.currency'),
        ];
    }
}