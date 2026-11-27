<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use MaaleArna\Arna\Core\Domains\Wallet\Enums\ObligationComponent;

/**
 * Validates obligation component, amount, dates and reference. Wallet is tenant-scoped; service enforces currency and ledger invariants.
 *
 * @package MaaleArna\Arna\Core\Domains\Wallet\Http\Requests
 * @version 1.0.0
 */
final class StoreObligationRequest extends FormRequest
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.50 and matches the
     * service's own bound (2B.16 §MAX_DECIMAL_SCALE).
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * Maximum length of the `reference_type` and `reference_id` fields.
     *
     * Matches the `wallet_obligations.reference_type` and
     * `wallet_obligations.reference_id` column widths (2B.50).
     */
    private const MAX_REFERENCE_LENGTH = 64;

    /**
     * Maximum length of the `description` field.
     *
     * Matches the `wallet_obligations.description` column width (2B.50).
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
     *   - The service's `assertReferencePairIsConsistent()` (2B.16) has
     *     the same null-versus-empty behaviour, so a value that passes
     *     this request passes the service.
     *
     * Non-string input for any of these fields falls through to the
     * corresponding base rule (e.g. `string`), producing the appropriate
     * 422. The request does not attempt to coerce non-string input.
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

            // For the reference pair and description, an empty-after-trim
            // string is normalised to null. For `amount`, an empty string
            // is left as an empty string so that the `required` rule
            // catches it clearly.
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
     * Seven rules, one per accepted field. See the class docblock
     * §"Fields accepted by this request" for the exhaustive list of what
     * is and is not accepted.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // Component — required. Closed enum vocabulary.
            //
            // `Rule::enum(ObligationComponent::class)` accepts the case
            // NAME, the case VALUE, or the case instance. A value outside
            // the closed six-case vocabulary produces a 422.
            // -----------------------------------------------------------------
            'component' => [
                'required',
                Rule::enum(ObligationComponent::class),
            ],

            // -----------------------------------------------------------------
            // Amount — required, positive decimal, at most 4 decimals.
            //
            // The regex accepts an UNSIGNED decimal string. A sign is
            // deliberately not permitted: an obligation's direction is a
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
            // Business timestamps — optional ISO 8601 date-times.
            //
            // No ordering constraint between `occurred_at` and `due_at`
            // is enforced. A retrospective obligation with a past due
            // date is a legitimate case (2B.16 §"What this service
            // deliberately does NOT do").
            // -----------------------------------------------------------------
            'occurred_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'due_at' => [
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
                        __('wallet::validation.obligation.amount.non_zero'),
                    );
                }
            }

            // -----------------------------------------------------------------
            // 2. Reference pair must be all-or-nothing.
            //
            // The check runs only when neither half has failed its own
            // format rule; a half that is malformed (e.g. an integer
            // `reference_id`) is reported by that rule, and the pairing
            // check would produce a confusing second error on the same
            // field.
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
                        __('wallet::validation.obligation.reference.pair'),
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
     * current locale. No English string is hard-coded. See the class
     * docblock §"Amharic-first error messages".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'component.required' => __('wallet::validation.obligation.component.required'),
            'component.enum'     => __('wallet::validation.obligation.component.enum'),

            'amount.required' => __('wallet::validation.obligation.amount.required'),
            'amount.string'   => __('wallet::validation.obligation.amount.string'),
            'amount.regex'    => __('wallet::validation.obligation.amount.regex'),

            'occurred_at.date' => __('wallet::validation.obligation.occurred_at.date'),
            'due_at.date'      => __('wallet::validation.obligation.due_at.date'),

            'reference_type.string' => __('wallet::validation.obligation.reference_type.string'),
            'reference_type.max'    => __('wallet::validation.obligation.reference_type.max'),
            'reference_id.string'   => __('wallet::validation.obligation.reference_id.string'),
            'reference_id.max'      => __('wallet::validation.obligation.reference_id.max'),

            'description.string' => __('wallet::validation.obligation.description.string'),
            'description.max'    => __('wallet::validation.obligation.description.max'),
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
            'component'      => __('wallet::attributes.obligation.component'),
            'amount'         => __('wallet::attributes.obligation.amount'),
            'occurred_at'    => __('wallet::attributes.obligation.occurred_at'),
            'due_at'         => __('wallet::attributes.obligation.due_at'),
            'reference_type' => __('wallet::attributes.obligation.reference_type'),
            'reference_id'   => __('wallet::attributes.obligation.reference_id'),
            'description'    => __('wallet::attributes.obligation.description'),
        ];
    }
}