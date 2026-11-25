<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates payment receipt data. Wallet is tenant-scoped and actor identity is server-derived; allocation is a separate operation.
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Requests
 * @version 1.0.0
 */
final class StorePaymentRequest extends FormRequest
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.51 and matches the
     * service's own bound (2B.17 §MAX_DECIMAL_SCALE).
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * Maximum length of the `reference_type` and `reference_id` fields.
     *
     * Matches the `wallet_payments.reference_type` and
     * `wallet_payments.reference_id` column widths (2B.51).
     */
    private const MAX_REFERENCE_LENGTH = 64;

    /**
     * Maximum length of the `description` field.
     *
     * Matches the `wallet_payments.description` column width (2B.51).
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
     * Normalise string fields before validation.
     *
     * The following fields are trimmed of leading and trailing whitespace:
     *
     *     amount          — preserved as a decimal string after trimming.
     *     reference_type  — trimmed; an empty-after-trim value is
     *                       normalised to null.
     *     reference_id    — trimmed; an empty-after-trim value is
     *                       normalised to null.
     *     description     — trimmed; an empty-after-trim value is
     *                       normalised to null.
     *
     * Normalising empty strings to null has two consequences:
     *
     *   - The all-or-nothing reference-pair check treats "absent" and
     *     "explicitly empty" identically, so a caller sending
     *     `reference_type: ""` and no `reference_id` is not falsely
     *     rejected as providing half a pair.
     *
     *   - The service's `assertReferencePairIsConsistent()` (2B.17) has
     *     the same null-versus-empty behaviour, so a value that passes
     *     this request passes the service.
     *
     * Non-string input for any of these fields falls through to the
     * corresponding base rule, producing the appropriate 422.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach (['amount', 'reference_type', 'reference_id', 'description'] as $field) {
            if (!$this->has($field)) {
                continue;
            }

            $raw = $this->input($field);

            if (!is_string($raw)) {
                continue;
            }

            $trimmed = trim($raw);

            if ($trimmed === '' && $field !== 'amount') {
                $merged[$field] = null;
                continue;
            }

            $merged[$field] = $trimmed;
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
     * Five rules, one per accepted field. See the class docblock
     * §"Fields accepted by this request" for the exhaustive list of what
     * is and is not accepted.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // Amount — required, positive decimal, at most 4 decimals.
            //
            // The regex accepts an UNSIGNED decimal string. A sign is
            // deliberately not permitted: a payment's direction is a
            // property of the operation, not of the value (see the class
            // docblock §"Fields accepted by this request"). The non-zero
            // check is performed in `withValidator()` below.
            // -----------------------------------------------------------------
            'amount' => [
                'required',
                'string',
                'regex:/^\d+(\.\d{1,4})?$/',
            ],

            // -----------------------------------------------------------------
            // Business timestamp — optional ISO 8601 date-time.
            //
            // A payment received at a past time is a legitimate case (a
            // backfill of a historical receipt). The service does not
            // enforce a lower or upper bound; the timestamp is stored as
            // supplied.
            // -----------------------------------------------------------------
            'received_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            // -----------------------------------------------------------------
            // Reference pair — optional, all-or-nothing, opaque.
            //
            // Each half is independently capped at 64 characters. The
            // pairing rule is enforced in `withValidator()` below.
            // -----------------------------------------------------------------
            'reference_type' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_REFERENCE_LENGTH,
            ],

            'reference_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_REFERENCE_LENGTH,
            ],

            // -----------------------------------------------------------------
            // Description — optional free text.
            // -----------------------------------------------------------------
            'description' => [
                'sometimes',
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
     * Adds two cross-field checks after the base rules:
     *
     *   1. `amount` must be non-zero at scale 4. The regex above
     *      guarantees the format but not non-zero.
     *
     *   2. `reference_type` and `reference_id` must be supplied together
     *      or omitted together.
     *
     * Both checks are guarded against running when the corresponding
     * format rule already failed, so that a malformed value does not
     * reach bcmath or produce duplicate error messages.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // -----------------------------------------------------------------
            // 1. Amount must be non-zero.
            // -----------------------------------------------------------------
            if (!$v->errors()->has('amount')) {
                $amount = $this->input('amount');

                if (is_string($amount) && bccomp($amount, '0', self::MAX_DECIMAL_SCALE) === 0) {
                    $v->errors()->add(
                        'amount',
                        __('wallet::validation.payment.amount.non_zero'),
                    );
                }
            }

            // -----------------------------------------------------------------
            // 2. Reference pair must be all-or-nothing.
            // -----------------------------------------------------------------
            if (
                !$v->errors()->has('reference_type')
                && !$v->errors()->has('reference_id')
            ) {
                $type = $this->input('reference_type');
                $id   = $this->input('reference_id');

                $hasType = is_string($type) && $type !== '';
                $hasId   = is_string($id) && $id !== '';

                if ($hasType !== $hasId) {
                    $v->errors()->add(
                        'reference_type',
                        __('wallet::validation.payment.reference.pair'),
                    );
                }
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
            'amount.required' => __('wallet::validation.payment.amount.required'),
            'amount.string'   => __('wallet::validation.payment.amount.string'),
            'amount.regex'    => __('wallet::validation.payment.amount.regex'),

            'received_at.date' => __('wallet::validation.payment.received_at.date'),

            'reference_type.string' => __('wallet::validation.payment.reference_type.string'),
            'reference_type.max'    => __('wallet::validation.payment.reference_type.max'),
            'reference_id.string'   => __('wallet::validation.payment.reference_id.string'),
            'reference_id.max'      => __('wallet::validation.payment.reference_id.max'),

            'description.string' => __('wallet::validation.payment.description.string'),
            'description.max'    => __('wallet::validation.payment.description.max'),
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
            'amount'         => __('wallet::attributes.payment.amount'),
            'received_at'    => __('wallet::attributes.payment.received_at'),
            'reference_type' => __('wallet::attributes.payment.reference_type'),
            'reference_id'   => __('wallet::attributes.payment.reference_id'),
            'description'    => __('wallet::attributes.payment.description'),
        ];
    }
}