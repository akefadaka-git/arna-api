<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 19 of 27
 * =============================================================================
 *
 * File:            Http/Requests/StoreGovernanceTermRequest.php  (NEW)
 * Unit:            G2b — GovernanceTerm vertical slice (store request)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceTermController::store()`
 *   action (G2b file 21 of 27, not yet delivered) had no request class
 *   to validate the POST payload for
 *   `POST /api/governance/bodies/{governanceBody}/terms`. A controller
 *   that received raw input would have to validate it inline — mixing
 *   HTTP-boundary concerns with the controller's delegation logic.
 *
 * After:
 *   One file that declares the FormRequest for the term `store`
 *   action. The request validates the SHAPE of the input — types,
 *   lengths, presence, format — and delegates every domain invariant
 *   to `GovernanceTermService` (G2b file 16 of 27).
 *
 *   What the request validates:
 *
 *     · `label_am`         — optional, nullable, string, max 150.
 *     · `label_en`         — same.
 *
 *     · At least one of `label_am` / `label_en` must be non-empty —
 *       enforced in `withValidator()`.
 *
 *     · `effective_from`   — optional, nullable, date, format
 *                            `Y-m-d`.
 *     · `effective_until`  — same.
 *
 *     · `effective_from <= effective_until` when both are set —
 *       enforced in `withValidator()`.
 *
 *     · `notes`            — optional, nullable, string, max 500.
 *
 *     · `is_active`        — optional, boolean.
 *
 *   What the request deliberately does NOT validate:
 *
 *     · `governance_body_id` — this field is NOT accepted from the
 *       request payload. The owning body is determined by the route
 *       parameter `{governanceBody}` and is resolved by the
 *       controller, which overwrites any client-supplied value
 *       before passing the payload to the service. A caller cannot
 *       create a term under a body other than the one named in the
 *       URL. See the file-level change-verification block
 *       §"The governance_body_id is not accepted".
 *
 *     · `tenant_id` — resolved server-side by the controller via
 *       `mases_tenant_id()`; never read from the payload.
 *
 *     · `id` — assigned by the database.
 *
 *   Every field the request does not declare is dropped by the
 *   service's whitelist (`GovernanceTermService::whitelist()`,
 *   G2b file 16 of 27).
 *
 * =============================================================================
 * The governance_body_id is not accepted
 * =============================================================================
 * The route for creating a term is:
 *
 *     POST /api/governance/bodies/{governanceBody}/terms
 *
 * The `{governanceBody}` path segment resolves to a `GovernanceBody`
 * instance via Laravel's implicit model binding. The controller
 * (G2b file 21 of 27) reads this instance, passes the body's id into
 * the service's `$data` array, and the service validates that the
 * body exists in the resolved tenant.
 *
 * Because the body is derived from the URL, the request MUST NOT
 * accept a `governance_body_id` field from the payload. The reasoning
 * is identical to `StoreGovernancePositionRequest` (G2b file 7 of
 * 27) §"The governance_body_id is not accepted": URL-derived scope
 * prevents privilege-escalation-shaped bugs where a caller creates
 * a child under a body other than the one named in the URL.
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
 * The `messages()` and `attributes()` methods return inline Amharic
 * strings, following the decision documented in
 * `StoreGovernanceBodyRequest` (G2a file 12 of 18). When the deferred
 * `governance::validation.*` catalogue CR lands, every Governance
 * request class migrates together.
 *
 * =============================================================================
 * prepareForValidation()
 * =============================================================================
 * The method trims every string input. Unlike the body and position
 * requests, there is no `code` field to uppercase — terms have no
 * code. The method's body is therefore simpler than the sibling
 * requests'.
 *
 * The method does NOT cast any value to a target type. A caller that
 * submits `"true"` for `is_active` receives a boolean validation
 * error from the `boolean` rule.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Requests/StoreGovernanceTermRequest.php
 *
 * The directory already exists.
 *
 * The request is consumed by `GovernanceTermController::store()` (G2b
 * file 21 of 27).
 *
 * A `composer dump-autoload` may be required if the class map is
 * cached.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Rules declared:            5 (across 5 fields)
 * Conditional checks:        2 (label presence, date ordering)
 * Custom messages:           8 keys
 * Field names:               5 keys
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Requests;

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
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Requests
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
     * Determine if the user is authorized to make this request.
     *
     * Always true — the controller (G2b file 21 of 27) invokes
     * `Gate::authorize('create', $body)` before the request is
     * validated.
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