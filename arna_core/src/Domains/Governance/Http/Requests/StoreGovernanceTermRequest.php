<?php

declare(strict_types=1);



namespace MaaleArna\Arna\Core\Domains\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * StoreGovernanceTermRequest
 *
 * Validates the input for
 * `POST /api/governance/bodies/{governanceBody}/terms`.
 *
 * See the file-level change-verification block for the request's
 * scope, the decision not to accept `governance_body_id` from the
 * payload, and the inline-Amharic messages convention.
 *
 * =============================================================================
 * Scope of validation
 * =============================================================================
 *  · Shape of the input (types, length, format).
 *
 *  · Two cross-field invariants enforced at the HTTP boundary:
 *
 *      - at least one of label_am / label_en must be non-empty
 *      - effective_from <= effective_until when both are set
 *
 *  · Domain invariants that require database queries (body existence
 *    in the tenant) are enforced by `GovernanceTermService` (G2b
 *    file 16 of 27), not here.
 *
 * =============================================================================
 * @package MaaleArna\Arna\Core\Domains\Governance\Http\Requests
 * @version 1.0.0
 */
final class StoreGovernanceTermRequest extends FormRequest
{
    /**
     * Maximum length of a bilingual label column.
     */
    private const MAX_LABEL_LENGTH = 150;

    /**
     * Maximum length of the `notes` column.
     */
    private const MAX_NOTES_LENGTH = 500;

    /**
     * Authorize the owning governance body before validation.
     * Missing authentication or an unexpected route value fails closed.
     */
    public function authorize(): bool
    {
        $resource = $this->route('governanceBody');

        return $resource instanceof \MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody
            && ($this->user()?->can('create', $resource) ?? false);
    }

    // =========================================================================
    // Preparation
    // =========================================================================

    /**
     * Trim whitespace from string inputs.
     *
     * There is no `code` field to uppercase — terms have no code. The
     * method trims every string input so that trailing or leading
     * whitespace does not produce spurious "empty" values.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach ($this->all() as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            $merged[$key] = trim($value);
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
     * The rules cover only the fields the caller may supply. The
     * owning body is derived from the route; the tenant is derived
     * from the server-side context. Neither appears in the rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // Labels — each individually optional; at least one
            // must be non-empty (enforced in withValidator()).
            // -----------------------------------------------------------------
            'label_am' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_LABEL_LENGTH,
            ],
            'label_en' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_LABEL_LENGTH,
            ],

            // -----------------------------------------------------------------
            // Effective range — each individually optional; the
            // ordering check is in withValidator().
            //
            // The `date_format` rule ensures the value is a
            // canonical `YYYY-MM-DD` string, matching the migration's
            // DATE column and the model's `date` cast.
            // -----------------------------------------------------------------
            'effective_from' => [
                'sometimes',
                'nullable',
                'date',
                'date_format:Y-m-d',
            ],
            'effective_until' => [
                'sometimes',
                'nullable',
                'date',
                'date_format:Y-m-d',
            ],

            // -----------------------------------------------------------------
            // Notes — optional free text.
            // -----------------------------------------------------------------
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_NOTES_LENGTH,
            ],

            // -----------------------------------------------------------------
            // Active flag.
            // -----------------------------------------------------------------
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    // =========================================================================
    // Post-rule conditional checks
    // =========================================================================

    /**
     * Configure the validator instance.
     *
     * Adds the two cross-field checks that cannot be expressed
     * declaratively:
     *
     *   · At least one of label_am / label_en must be non-empty.
     *   · effective_from must be <= effective_until when both are
     *     set.
     *
     * @param  Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateAtLeastOneLabel($v);
            $this->validateEffectiveDateOrdering($v);
        });
    }

    /**
     * Assert that at least one of the two bilingual label fields is
     * non-empty.
     *
     * @param  Validator $validator
     * @return void
     */
    private function validateAtLeastOneLabel(Validator $validator): void
    {
        $labelAm = $this->input('label_am');
        $labelEn = $this->input('label_en');

        $hasAm = is_string($labelAm) && trim($labelAm) !== '';
        $hasEn = is_string($labelEn) && trim($labelEn) !== '';

        if (!$hasAm && !$hasEn) {
            $validator->errors()->add(
                'label_am',
                'ቢያንስ አንድ መለያ (በአማርኛ ወይም በእንግሊዝኛ) መስጠት ያስፈልጋል።',
            );
        }
    }

    /**
     * Assert that the effective date range is ordered correctly.
     *
     * Runs only when both dates are present and each parsed cleanly
     * (the declarative rules already flagged an unparseable value).
     *
     * Both values passed the `date_format:Y-m-d` rule, so a lexical
     * comparison is sufficient.
     *
     * @param  Validator $validator
     * @return void
     */
    private function validateEffectiveDateOrdering(Validator $validator): void
    {
        $from  = $this->input('effective_from');
        $until = $this->input('effective_until');

        if (!is_string($from) || !is_string($until)) {
            return;
        }

        if ($from === '' || $until === '') {
            return;
        }

        if (strcmp($from, $until) > 0) {
            $validator->errors()->add(
                'effective_until',
                'የማብቂያ ቀን ከመጀመሪያ ቀን በፊት መሆን የለበትም።',
            );
        }
    }

    // =========================================================================
    // Messages and field names
    // =========================================================================

    /**
     * Get the custom validation messages.
     *
     * Inline Amharic strings, following the G2a convention.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label_am.max' => 'የአማርኛው መለያ ከ150 ቁምፊዎች መብለጥ የለበትም።',
            'label_en.max' => 'የእንግሊዝኛው መለያ ከ150 ቁምፊዎች መብለጥ የለበትም።',

            'effective_from.date_format'  => 'የመጀመሪያው ቀን በ YYYY-MM-DD ቅርጸት መሆን አለበት።',
            'effective_until.date_format' => 'የማብቂያው ቀን በ YYYY-MM-DD ቅርጸት መሆን አለበት።',

            'notes.max' => 'ማስታወሻው ከ500 ቁምፊዎች መብለጥ የለበትም።',

            'is_active.boolean' => 'የንቁ ሁኔታው ትክክለኛ ቡሊያን መሆን አለበት።',
        ];
    }

    /**
     * Get human-readable field names for error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label_am'        => 'መለያ (አማርኛ)',
            'label_en'        => 'መለያ (እንግሊዝኛ)',
            'effective_from'  => 'የመጀመሪያ ቀን',
            'effective_until' => 'የማብቂያ ቀን',
            'notes'           => 'ማስታወሻ',
            'is_active'       => 'ንቁ ሁኔታ',
        ];
    }
}