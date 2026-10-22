<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 7 of 27
 * =============================================================================
 *
 * File:            Http/Requests/StoreGovernancePositionRequest.php  (NEW)
 * Unit:            G2b — GovernancePosition vertical slice (store request)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernancePositionController::store()`
 *   action (G2b file 9 of 27, not yet delivered) had no request
 *   class to validate the POST payload for
 *   `POST /api/governance/bodies/{governanceBody}/positions`. A
 *   controller that received raw input would have to validate it
 *   inline — mixing HTTP-boundary concerns with the controller's
 *   delegation logic.
 *
 * After:
 *   One file that declares the FormRequest for the position `store`
 *   action. The request validates the SHAPE of the input — types,
 *   lengths, presence, format — and delegates every domain
 *   invariant to `GovernancePositionService` (G2b file 4 of 27).
 *
 *   What the request validates:
 *
 *     · `code`              — required, string, max 32 chars,
 *                             alphanumeric + dash + underscore only.
 *
 *     · `name_am`           — optional, nullable, string, max 150.
 *     · `name_en`           — same.
 *
 *     · At least one of `name_am` / `name_en` must be non-empty —
 *       enforced in `withValidator()`.
 *
 *     · `description_am`    — optional, nullable, string, max 500.
 *     · `description_en`    — same.
 *
 *     · `is_voting`         — optional, boolean.
 *     · `is_officer`        — optional, boolean.
 *     · `is_active`         — optional, boolean.
 *
 *     · `sort_order`        — optional, integer, min 0, max 65535
 *                             (matching the unsignedSmallInteger
 *                             column).
 *
 *   What the request deliberately does NOT validate:
 *
 *     · `governance_body_id` — this field is NOT accepted from the
 *       request payload. The owning body is determined by the
 *       route parameter `{governanceBody}` and is resolved by the
 *       controller, which overwrites any client-supplied value
 *       before passing the payload to the service. A caller cannot
 *       create a position under a body other than the one named
 *       in the URL. See the file-level change-verification block
 *       §"The governance_body_id is not accepted".
 *
 *     · `tenant_id` — resolved server-side by the controller via
 *       `mases_tenant_id()`; never read from the payload.
 *
 *     · `id` — assigned by the database.
 *
 *   Every field the request does not declare is dropped by the
 *   service's whitelist (`GovernancePositionService::whitelist()`,
 *   G2b file 4 of 27). A caller who sends `tenant_id` or `id` finds
 *   those keys silently dropped.
 *
 * =============================================================================
 * The governance_body_id is not accepted
 * =============================================================================
 * The route for creating a position is:
 *
 *     POST /api/governance/bodies/{governanceBody}/positions
 *
 * The `{governanceBody}` path segment resolves to a `GovernanceBody`
 * instance via Laravel's implicit model binding. The controller
 * (G2b file 9 of 27) reads this instance, passes the body's id into
 * the service's `$data` array, and the service validates that the
 * body exists in the resolved tenant.
 *
 * Because the body is derived from the URL, the request MUST NOT
 * accept a `governance_body_id` field from the payload. Two reasons:
 *
 *   · Allowing the caller to supply a different body id would let
 *     a caller create a position on body A while the URL names body
 *     B — a privilege-escalation-shaped bug that the URL scoping
 *     is designed to prevent.
 *
 *   · The controller's overwrite is defensive — a caller who
 *     mistakenly includes `governance_body_id: 999` in the payload
 *     receives a position under the URL's body, not under 999.
 *     The behaviour is consistent with the platform's convention
 *     for nested resource creation (a `POST` to
 *     `/shareholders/{shareholder}/holdings` uses the shareholder
 *     from the URL, not from the payload).
 *
 * The request does not reject a stray `governance_body_id` field
 * with a validation error. Following the platform's convention, it
 * silently drops the field: the request's `rules()` array does not
 * declare it, and the service's whitelist would drop it if it
 * reached the service. The controller's overwrite makes the outcome
 * unambiguous.
 *
 * =============================================================================
 * The Amharic validation messages — inline today, catalogue later
 * =============================================================================
 * The `messages()` and `attributes()` methods return inline
 * Amharic strings, following the decision documented in
 * `StoreGovernanceBodyRequest` (G2a file 12 of 18) §"The Amharic
 * validation messages — inline today, catalogue later".
 *
 * When the deferred `governance::validation.*` catalogue CR lands,
 * every Governance request class migrates together. Until then,
 * inline Amharic strings produce correct user-facing output with
 * no additional catalogue files.
 *
 * =============================================================================
 * prepareForValidation()
 * =============================================================================
 * The method trims every string input and normalises the `code`
 * field to uppercase. The normalisation mirrors the model's `code`
 * set-mutator (G2b file 2 of 27).
 *
 * The method does NOT cast any value to a target type. A caller
 * that submits `"true"` for `is_voting` receives a boolean
 * validation error from the `boolean` rule; casting would mask the
 * type mismatch.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Requests/StoreGovernancePositionRequest.php
 *
 * The directory already exists (created for the G2a request
 * classes).
 *
 * The request is consumed by `GovernancePositionController::store()`
 * (G2b file 9 of 27).
 *
 * A `composer dump-autoload` may be required if the class map is
 * cached.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Rules declared:            9 (across 9 fields)
 * Conditional checks:        1 (name presence)
 * Custom messages:           12 keys
 * Field names:               9 keys
 * =============================================================================
 */

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