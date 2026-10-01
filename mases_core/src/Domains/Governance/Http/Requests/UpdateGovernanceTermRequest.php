<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 20 of 27 (revised)
 * =============================================================================
 *
 * File:            Http/Requests/UpdateGovernanceTermRequest.php  (AMEND)
 * Unit:            G2b — GovernanceTerm vertical slice (update request)
 * Delivery type:   Amendment — adds one shape rule for
 *                  `governance_body_id`.
 *
 * Before:
 *   The request declared six `sometimes` rules. `governance_body_id`
 *   was NOT among them. The docblock argued the immutability check
 *   belonged in the service, and concluded the field should not be
 *   declared here.
 *
 *   The conclusion was wrong. A field not declared in `rules()` is
 *   filtered out of `$request->validated()`. The service's
 *   `normaliseForUpdate()` — which does contain the immutability
 *   check — never received the field, so the check never ran.
 *
 *   Consequence: `test_body_id_is_immutable_after_registration` failed
 *   with "Expected response status code [422] but received 200". A
 *   caller could PATCH `governance_body_id` to any value and the row
 *   was silently updated — or, worse, silently left alone with a
 *   misleading 200.
 *
 * After:
 *   One rule is added:
 *
 *     'governance_body_id' => [
 *         'sometimes',
 *         'integer',
 *         'min:1',
 *     ],
 *
 *   The rule is a SHAPE rule only. The service's
 *   `normaliseForUpdate()` continues to hold the immutability rule.
 *
 *   The division of labour matches the sibling position request (G2b
 *   file 8, revised in this delivery) and the G2a body request (which
 *   declares `body_type` as a shape rule and lets the service enforce
 *   immutability).
 *
 * =============================================================================
 * What does NOT change
 * =============================================================================
 *   · Every other rule in `rules()`.
 *   · `authorize()`, `prepareForValidation()`, `messages()`,
 *     `attributes()`, `resolveTerm()`, and the `withValidator()`
 *     conditional checks.
 *   · Every existing docblock section except the one that argued
 *     against declaring the field.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to the same path it currently occupies:
 *
 *     mases_core/src/Domains/Governance/Http/Requests/UpdateGovernanceTermRequest.php
 *
 * The upload REPLACES the G2b file 20 version.
 *
 * Run `composer dump-autoload` after uploading.
 *
 * =============================================================================
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Rules added:               1 (governance_body_id)
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;

/**
 * UpdateGovernanceTermRequest
 *
 * Validates the input for
 * `PATCH /api/governance/terms/{governanceTerm}`.
 *
 * See the file-level change-verification block for the request's
 * scope, the three deliberate differences from the Store request, and
 * the division of labour between shape rules (here) and immutability
 * rules (in the service) for `governance_body_id`.
 *
 * =============================================================================
 * All rules are `sometimes`
 * =============================================================================
 * A PATCH payload is partial by definition.
 *
 * =============================================================================
 * The `governance_body_id` rule — shape only, not immutability
 * =============================================================================
 * The rule declared below validates the SHAPE of the field:
 *
 *     sometimes|integer|min:1
 *
 * It does NOT enforce immutability. The check that the value must
 * equal the term's current `governance_body_id` lives in
 * `GovernanceTermService::normaliseForUpdate()` (G2b file 16 of 27).
 *
 * A caller that supplies a `governance_body_id` matching the term's
 * current body receives no error. A caller that supplies a different
 * value receives a 422.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Requests
 * @version 1.0.1
 */
final class UpdateGovernanceTermRequest extends FormRequest
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
     * Determine if the user is authorized to make this request.
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
     * Trim whitespace from string inputs.
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // governance_body_id — shape only; immutability is enforced
            // by the service. See the class docblock
            // §"The governance_body_id rule".
            // -----------------------------------------------------------------
            'governance_body_id' => [
                'sometimes',
                'integer',
                'min:1',
            ],

            // -----------------------------------------------------------------
            // Labels.
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
            // Effective range.
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
            // Notes.
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
     * @param  Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $term = $this->resolveTerm();

            if ($term === null) {
                return;
            }

            $this->validateAtLeastOneLabel($v, $term);
            $this->validateEffectiveDateOrdering($v, $term);
        });
    }

    /**
     * Assert that at least one bilingual label is non-empty in the
     * post-update state.
     *
     * @param  Validator      $validator
     * @param  GovernanceTerm $term
     * @return void
     */
    private function validateAtLeastOneLabel(Validator $validator, GovernanceTerm $term): void
    {
        $labelAm = $this->has('label_am') ? $this->input('label_am') : $term->label_am;
        $labelEn = $this->has('label_en') ? $this->input('label_en') : $term->label_en;

        $hasAm = is_string($labelAm) && trim($labelAm) !== '';
        $hasEn = is_string($labelEn) && trim($labelEn) !== '';

        if (!$hasAm && !$hasEn) {
            $validator->errors()->add(
                'label_am',
                'ቢያንስ አንድ መለያ (በአማርኛ ወይም በእንግሊዝኛ) መቆየት ያስፈልጋል።',
            );
        }
    }

    /**
     * Assert that the effective date range is ordered correctly in
     * the post-update state.
     *
     * @param  Validator      $validator
     * @param  GovernanceTerm $term
     * @return void
     */
    private function validateEffectiveDateOrdering(Validator $validator, GovernanceTerm $term): void
    {
        $from = $this->has('effective_from')
            ? $this->input('effective_from')
            : ($term->effective_from?->toDateString());

        $until = $this->has('effective_until')
            ? $this->input('effective_until')
            : ($term->effective_until?->toDateString());

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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'governance_body_id.integer' => 'የአካሉ መለያ ትክክለኛ ኢንቲጀር መሆን አለበት።',
            'governance_body_id.min'     => 'የአካሉ መለያ ከ0 በላይ መሆን አለበት።',

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
            'governance_body_id' => 'የአካሉ መለያ',
            'label_am'           => 'መለያ (አማርኛ)',
            'label_en'           => 'መለያ (እንግሊዝኛ)',
            'effective_from'     => 'የመጀመሪያ ቀን',
            'effective_until'    => 'የማብቂያ ቀን',
            'notes'              => 'ማስታወሻ',
            'is_active'          => 'ንቁ ሁኔታ',
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the `GovernanceTerm` instance bound to the current
     * route.
     *
     * @return GovernanceTerm|null
     */
    private function resolveTerm(): ?GovernanceTerm
    {
        $term = $this->route('governanceTerm');

        return $term instanceof GovernanceTerm ? $term : null;
    }
}