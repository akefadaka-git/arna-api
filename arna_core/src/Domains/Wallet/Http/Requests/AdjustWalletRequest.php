<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates signed adjustment amount and description. Wallet is route-bound within the tenant; actor identity comes from authentication.
 *
 * @package MaaleArna\Arna\Core\Domains\Wallet\Http\Requests
 * @version 1.0.0
 */
final class AdjustWalletRequest extends FormRequest
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.54 and matches
     * the service's own bound (2B.15 §MAX_DECIMAL_SCALE).
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * Maximum length of the `description` field.
     *
     * Matches the `wallet_entries.description` column width (2B.54). The
     * same bound applies to `WalletService::adjust()` via the shared
     * column contract.
     */
    private const MAX_DESCRIPTION_LENGTH = 500;

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
     * Normalise the signed amount before validation.
     *
     * The `signed_amount` field is trimmed of leading and trailing
     * whitespace. The sign, if present, is preserved; digits and decimal
     * point are preserved; no reformatting is performed. Any coercion of
     * the string (e.g. removing a redundant leading `+`) is deliberately
     * avoided — the string is passed to the service, which parses it
     * with bcmath at scale 4.
     *
     * Non-string input falls through to the `string` rule, which produces
     * the appropriate 422. The request does not attempt to coerce a
     * non-string into a string.
     *
     * The `description` field is trimmed of leading and trailing
     * whitespace. An empty-after-trim description is left as an empty
     * string; the `nullable` rule does not treat it as null, but the
     * service accepts an empty string harmlessly (it is stored as-is and
     * rendered as empty in the statement projection).
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        if ($this->has('signed_amount')) {
            $raw = $this->input('signed_amount');

            if (is_string($raw)) {
                $merged['signed_amount'] = trim($raw);
            }
        }

        if ($this->has('description')) {
            $raw = $this->input('description');

            if (is_string($raw)) {
                $merged['description'] = trim($raw);
            }
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
            // Signed amount — required, signed decimal, at most 4 decimals.
            //
            // The regex accepts an optional leading sign, then digits,
            // then an optional fractional part of at most four digits. It
            // mirrors the service's own regex (2B.15
            // §"assertSignedQuantityIsWellFormed") exactly, so a value
            // that passes here will always pass there. The non-zero check
            // is performed in `withValidator()` below, using bcmath, to
            // avoid depending on regex-only zero detection.
            // -----------------------------------------------------------------
            'signed_amount' => [
                'required',
                'string',
                'regex:/^[+-]?\d+(\.\d{1,4})?$/',
            ],

            // -----------------------------------------------------------------
            // Description — optional free text.
            //
            // Capped at 500 characters, matching the
            // `wallet_entries.description` column width (2B.54). The
            // `sometimes` rule is not needed: `nullable` covers both the
            // "field absent" and "field null" cases.
            // -----------------------------------------------------------------
            'description' => [
                'nullable',
                'string',
                'max:' . self::MAX_DESCRIPTION_LENGTH,
            ],
        ];
    }

    // =========================================================================
    // Post-rule conditional checks
    // =========================================================================

    /**
     * Configure the validator instance.
     *
     * Adds one cross-rule check: the signed amount must be non-zero. The
     * check is performed here (rather than as a custom rule) so that the
     * regex above remains the single source of truth for the value's
     * format, and the non-zero check is expressed explicitly and legibly.
     *
     * The comparison uses bcmath at scale 4, mirroring the service's own
     * zero check (2B.15 §"assertSignedQuantityIsWellFormed"). A value of
     * `"0"`, `"0.0000"`, `"+0.0000"`, or `"-0.0000"` fails. A value of
     * `"0.0001"` passes — it is non-zero at scale 4.
     *
     * If the format rule has already failed, the amount field will not
     * match the regex and may be a non-numeric string; the guard below
     * checks that the field passes the format rule before invoking
     * bcmath, so that a malformed value does not raise a bcmath
     * `ValueError`.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('signed_amount')) {
                return;
            }

            $amount = $this->input('signed_amount');

            if (!is_string($amount)) {
                return;
            }

            if (bccomp($amount, '0', self::MAX_DECIMAL_SCALE) === 0) {
                $v->errors()->add(
                    'signed_amount',
                    __('wallet::validation.adjust.signed_amount.non_zero'),
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
     * current locale. No English string is hard-coded. See the class
     * docblock §"Amharic-first error messages".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'signed_amount.required' => __('wallet::validation.adjust.signed_amount.required'),
            'signed_amount.string'   => __('wallet::validation.adjust.signed_amount.string'),
            'signed_amount.regex'    => __('wallet::validation.adjust.signed_amount.regex'),

            'description.string' => __('wallet::validation.adjust.description.string'),
            'description.max'    => __('wallet::validation.adjust.description.max'),
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
            'signed_amount' => __('wallet::attributes.adjust.signed_amount'),
            'description'   => __('wallet::attributes.adjust.description'),
        ];
    }
}