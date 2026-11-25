<?php

declare(strict_types=1);



namespace MaaleArna\MASES\Core\Domains\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * StoreGovernancePositionRequest
 *
 * Validates the input for
 * `POST /api/governance/bodies/{governanceBody}/positions`.
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
 *  · One cross-field invariant enforced at the HTTP boundary:
 *
 *      - at least one of name_am / name_en must be non-empty
 *
 *  · Domain invariants that require database queries (code
 *    uniqueness within the body, body existence in the tenant) are
 *    enforced by `GovernancePositionService` (G2b file 4 of 27),
 *    not here.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Requests
 * @version 1.0.0
 */
final class StoreGovernancePositionRequest extends FormRequest
{
    /**
     * Maximum length of a `code` value.
     */
    private const MAX_CODE_LENGTH = 32;

    /**
     * Maximum length of a bilingual name column.
     */
    private const MAX_NAME_LENGTH = 150;

    /**
     * Maximum length of a bilingual description column.
     */
    private const MAX_DESCRIPTION_LENGTH = 500;

    /**
     * Maximum value permitted for `sort_order`.
     *
     * Matches the `unsignedSmallInteger` column's upper bound.
     */
    private const MAX_SORT_ORDER = 65535;

    /**
     * Authorize the owning governance body before validation.
     * Missing authentication or an unexpected route value fails closed.
     */
    public function authorize(): bool
    {
        $resource = $this->route('governanceBody');

        return $resource instanceof \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody
            && ($this->user()?->can('create', $resource) ?? false);
    }

    // =========================================================================
    // Preparation
    // =========================================================================

    /**
     * Trim whitespace from string inputs and normalise `code` to
     * uppercase.
     *
     * Mirrors `StoreGovernanceBodyRequest::prepareForValidation()`.
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

            $trimmed = trim($value);

            if ($key === 'code') {
                $trimmed = strtoupper($trimmed);
            }

            $merged[$key] = $trimmed;
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
            // code — canonical, uppercase, alphanumeric + dash +
            // underscore.
            // -----------------------------------------------------------------
            'code' => [
                'required',
                'string',
                'max:' . self::MAX_CODE_LENGTH,
                'alpha_dash',
            ],

            // -----------------------------------------------------------------
            // Names — each individually optional; at least one
            // must be non-empty (enforced in withValidator()).
            // -----------------------------------------------------------------
            'name_am' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_NAME_LENGTH,
            ],
            'name_en' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_NAME_LENGTH,
            ],

            // -----------------------------------------------------------------
            // Descriptions.
            // -----------------------------------------------------------------
            'description_am' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_DESCRIPTION_LENGTH,
            ],
            'description_en' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_DESCRIPTION_LENGTH,
            ],

            // -----------------------------------------------------------------
            // Semantic flags.
            // -----------------------------------------------------------------
            'is_voting' => [
                'sometimes',
                'boolean',
            ],
            'is_officer' => [
                'sometimes',
                'boolean',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],

            // -----------------------------------------------------------------
            // sort_order — non-negative integer within the
            // unsignedSmallInteger range.
            // -----------------------------------------------------------------
            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
                'max:' . self::MAX_SORT_ORDER,
            ],
        ];
    }

    // =========================================================================
    // Post-rule conditional checks
    // =========================================================================

    /**
     * Configure the validator instance.
     *
     * Adds the cross-field name-presence check that cannot be
     * expressed declaratively.
     *
     * @param  Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateAtLeastOneName($v);
        });
    }

    /**
     * Assert that at least one of the two bilingual name fields is
     * non-empty.
     *
     * @param  Validator $validator
     * @return void
     */
    private function validateAtLeastOneName(Validator $validator): void
    {
        $nameAm = $this->input('name_am');
        $nameEn = $this->input('name_en');

        $hasAm = is_string($nameAm) && trim($nameAm) !== '';
        $hasEn = is_string($nameEn) && trim($nameEn) !== '';

        if (!$hasAm && !$hasEn) {
            $validator->errors()->add(
                'name_am',
                'ቢያንስ አንድ ስም (በአማርኛ ወይም በእንግሊዝኛ) መስጠት ያስፈልጋል።',
            );
        }
    }

    // =========================================================================
    // Messages and field names
    // =========================================================================

    /**
     * Get the custom validation messages.
     *
     * Inline Amharic strings, following the G2a convention. See the
     * file-level change-verification block §"The Amharic validation
     * messages — inline today, catalogue later".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required'     => 'የቦታው ኮድ ያስፈልጋል።',
            'code.max'          => 'ኮዱ ከ32 ቁምፊዎች መብለጥ የለበትም።',
            'code.alpha_dash'   => 'ኮዱ ፊደሎችን፣ ቁጥሮችን፣ ሰረዞችን እና ነጠላ ሰረዞችን ብቻ መያዝ አለበት።',

            'name_am.max' => 'የአማርኛው ስም ከ150 ቁምፊዎች መብለጥ የለበትም።',
            'name_en.max' => 'የእንግሊዝኛው ስም ከ150 ቁምፊዎች መብለጥ የለበትም።',

            'description_am.max' => 'የአማርኛው መግለጫ ከ500 ቁምፊዎች መብለጥ የለበትም።',
            'description_en.max' => 'የእንግሊዝኛው መግለጫ ከ500 ቁምፊዎች መብለጥ የለበትም።',

            'is_voting.boolean'  => 'የድምጽ መስጠት ሁኔታው ትክክለኛ ቡሊያን መሆን አለበት።',
            'is_officer.boolean' => 'የባለሥልጣን ሁኔታው ትክክለኛ ቡሊያን መሆን አለበት።',
            'is_active.boolean'  => 'የንቁ ሁኔታው ትክክለኛ ቡሊያን መሆን አለበት።',

            'sort_order.integer' => 'የቅደም ተከተል ቁጥሩ ትክክለኛ ኢንቲጀር መሆን አለበት።',
            'sort_order.min'     => 'የቅደም ተከተል ቁጥሩ ከ0 በታች መሆን የለበትም።',
            'sort_order.max'     => 'የቅደም ተከተል ቁጥሩ ከ65535 መብለጥ የለበትም።',
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
            'code'           => 'ኮድ',
            'name_am'        => 'ስም (አማርኛ)',
            'name_en'        => 'ስም (እንግሊዝኛ)',
            'description_am' => 'መግለጫ (አማርኛ)',
            'description_en' => 'መግለጫ (እንግሊዝኛ)',
            'is_voting'      => 'የድምጽ መስጠት ሁኔታ',
            'is_officer'     => 'የባለሥልጣን ሁኔታ',
            'is_active'      => 'ንቁ ሁኔታ',
            'sort_order'     => 'የቅደም ተከተል ቁጥር',
        ];
    }
}