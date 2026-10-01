<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 8 of 27 (revised)
 * =============================================================================
 *
 * File:            Http/Requests/UpdateGovernancePositionRequest.php  (AMEND)
 * Unit:            G2b — GovernancePosition vertical slice (update request)
 * Delivery type:   Amendment — adds one shape rule for
 *                  `governance_body_id`.
 *
 * Before:
 *   The request declared nine `sometimes` rules. `governance_body_id`
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
 *   The rule is a SHAPE rule only — it validates that, when present,
 *   the value is a positive integer. It does NOT validate immutability.
 *   The service's `normaliseForUpdate()` continues to hold the
 *   immutability rule:
 *
 *     if the supplied value differs from the position's current
 *     `governance_body_id`, throw a 422 with a `governance_body_id`
 *     field error.
 *
 *   The division of labour is now correct:
 *
 *     Request   — shape (type, presence, numeric range).
 *     Service   — semantics (must equal the current body).
 *
 *   This matches the pattern `UpdateGovernanceBodyRequest` (G2a file
 *   13 of 18) uses for `body_type`: it declares `sometimes|string|
 *   Rule::enum(...)` as a shape rule, and the service holds the
 *   immutability rule. The G2a test for `body_type` immutability
 *   passes for exactly this reason.
 *
 * =============================================================================
 * What does NOT change
 * =============================================================================
 *   · Every other rule in `rules()`.
 *   · `authorize()`, `prepareForValidation()`, `messages()`,
 *     `attributes()`, `resolvePosition()`, and the `withValidator()`
 *     conditional checks.
 *   · Every existing docblock section except the one that argued
 *     against declaring the field (now corrected below).
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to the same path it currently occupies:
 *
 *     mases_core/src/Domains/Governance/Http/Requests/UpdateGovernancePositionRequest.php
 *
 * The upload REPLACES the G2b file 8 version.
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
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;

/**
 * UpdateGovernancePositionRequest
 *
 * Validates the input for
 * `PATCH /api/governance/positions/{governancePosition}`.
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
 * equal the position's current `governance_body_id` lives in
 * `GovernancePositionService::normaliseForUpdate()` (G2b file 4 of
 * 27), which has direct access to the resolved model.
 *
 * The rule exists here so that a client-supplied `governance_body_id`
 * survives `$request->validated()` and reaches the service. Without
 * it, the field would be filtered out and the service's immutability
 * check would be unreachable — the exact defect the file-level
 * change-verification block describes.
 *
 * A caller that supplies a `governance_body_id` matching the
 * position's current body receives no error — the service drops the
 * field silently and the update proceeds. This supports the common
 * PATCH-the-whole-object pattern.
 *
 * A caller that supplies a different value receives a 422 with a
 * `governance_body_id` field error — a position cannot be moved
 * between bodies.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Requests
 * @version 1.0.1
 */
final class UpdateGovernancePositionRequest extends FormRequest
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
     */
    private const MAX_SORT_ORDER = 65535;

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
     * Trim whitespace from string inputs and normalise `code` to
     * uppercase.
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
     * Every rule is `sometimes`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // governance_body_id — shape only; immutability is enforced
            // by the service. See the class docblock §"The
            // governance_body_id rule".
            // -----------------------------------------------------------------
            'governance_body_id' => [
                'sometimes',
                'integer',
                'min:1',
            ],

            // -----------------------------------------------------------------
            // code.
            // -----------------------------------------------------------------
            'code' => [
                'sometimes',
                'string',
                'max:' . self::MAX_CODE_LENGTH,
                'alpha_dash',
            ],

            // -----------------------------------------------------------------
            // Names.
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
            // sort_order.
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
     * @param  Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $position = $this->resolvePosition();

            if ($position === null) {
                return;
            }

            $this->validateAtLeastOneName($v, $position);
        });
    }

    /**
     * Assert that at least one bilingual name is non-empty in the
     * post-update state.
     *
     * @param  Validator          $validator
     * @param  GovernancePosition $position
     * @return void
     */
    private function validateAtLeastOneName(Validator $validator, GovernancePosition $position): void
    {
        $nameAm = $this->has('name_am') ? $this->input('name_am') : $position->name_am;
        $nameEn = $this->has('name_en') ? $this->input('name_en') : $position->name_en;

        $hasAm = is_string($nameAm) && trim($nameAm) !== '';
        $hasEn = is_string($nameEn) && trim($nameEn) !== '';

        if (!$hasAm && !$hasEn) {
            $validator->errors()->add(
                'name_am',
                'ቢያንስ አንድ ስም (በአማርኛ ወይም በእንግሊዝኛ) መቆየት ያስፈልጋል።',
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

            'code.max'        => 'ኮዱ ከ32 ቁምፊዎች መብለጥ የለበትም።',
            'code.alpha_dash' => 'ኮዱ ፊደሎችን፣ ቁጥሮችን፣ ሰረዞችን እና ነጠላ ሰረዞችን ብቻ መያዝ አለበት።',

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
            'governance_body_id' => 'የአካሉ መለያ',
            'code'               => 'ኮድ',
            'name_am'            => 'ስም (አማርኛ)',
            'name_en'            => 'ስም (እንግሊዝኛ)',
            'description_am'     => 'መግለጫ (አማርኛ)',
            'description_en'     => 'መግለጫ (እንግሊዝኛ)',
            'is_voting'          => 'የድምጽ መስጠት ሁኔታ',
            'is_officer'         => 'የባለሥልጣን ሁኔታ',
            'is_active'          => 'ንቁ ሁኔታ',
            'sort_order'         => 'የቅደም ተከተል ቁጥር',
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the `GovernancePosition` instance bound to the current
     * route.
     *
     * @return GovernancePosition|null
     */
    private function resolvePosition(): ?GovernancePosition
    {
        $position = $this->route('governancePosition');

        return $position instanceof GovernancePosition ? $position : null;
    }
}