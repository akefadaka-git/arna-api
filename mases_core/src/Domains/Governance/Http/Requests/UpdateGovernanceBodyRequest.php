<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 13 of 17
 * =============================================================================
 *
 * File:            Http/Requests/UpdateGovernanceBodyRequest.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (update request)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceBodyController::update()`
 *   action (G2a file 14 of 17, not yet delivered) had no request
 *   class to validate the PATCH payload for
 *   `PATCH /api/governance/bodies/{body}`. A partial-update call
 *   would either bypass validation entirely or receive validation
 *   designed for a full create (which is wrong — a PATCH payload
 *   is intentionally partial, and rules written for POST would
 *   reject a legitimate partial update as "missing required
 *   fields").
 *
 * After:
 *   One file that declares the FormRequest for the governance-body
 *   `update` action. It is structurally similar to
 *   `StoreGovernanceBodyRequest` (G2a file 12 of 17) with three
 *   deliberate differences:
 *
 *     1. Every rule is `sometimes`. A PATCH payload is partial by
 *        definition; a caller that sends only `{ "name_en": "…" }`
 *        should not receive errors for the eleven fields it did
 *        not include.
 *
 *     2. The two conditional checks — name presence and effective-
 *        date ordering — evaluate the POST-update state, not the
 *        incoming payload alone. A PATCH that clears `name_am`
 *        while `name_en` remains set is accepted; a PATCH that
 *        clears `name_am` while `name_en` is already null is
 *        rejected. Likewise, a PATCH that sets only
 *        `effective_until` compares the new value against the
 *        body's existing `effective_from`.
 *
 *     3. The `code` rule does not enforce case-insensitive
 *        uniqueness at the request layer. Uniqueness is a
 *        database-dependent invariant (the same `code` may exist
 *        in another tenant); it is checked by
 *        `GovernanceBodyService::update()` (G2a file 5 of 17),
 *        which has the resolved tenant context.
 *
 *   What the request validates:
 *
 *     · `code`            — sometimes, string, max 32, alpha_dash.
 *     · `body_type`       — sometimes, one of the five enum values.
 *                           The service rejects a change; the
 *                           request validates only that the value,
 *                           if sent, is a recognized member of the
 *                           enum.
 *     · `name_am`         — sometimes, nullable, string, max 150.
 *     · `name_en`         — same.
 *     · `parent_body_id`  — sometimes, nullable, integer, min 1,
 *                           exists.
 *     · `reporting_body_id` — same.
 *     · `purpose`         — sometimes, nullable, string, max 500.
 *     · `mandate`         — sometimes, nullable, string, max 20000.
 *     · `quorum_rule_json` — sometimes, nullable, array.
 *     · `meeting_frequency` — sometimes, nullable, string, max 100.
 *     · `effective_from`  — sometimes, nullable, date Y-m-d.
 *     · `effective_until` — same.
 *     · `is_active`       — sometimes, boolean.
 *
 *     Post-rule conditional checks:
 *       · At least one of name_am / name_en must be non-empty in
 *         the post-update state.
 *       · effective_from <= effective_until in the post-update
 *         state, when both are set.
 *
 *   What the request deliberately does NOT do:
 *
 *     · It does NOT call `Gate::authorize()`. The request's
 *       `authorize()` method returns `true`; the controller
 *       invokes `Gate::authorize('update', $body)` against the
 *       `GovernanceBodyPolicy` before resolving the request.
 *
 *     · It does NOT reject a `body_type` field merely because it
 *       is present. A caller might legitimately re-send the
 *       current `body_type` (many HTTP clients PATCH with the
 *       full object). The service's `normaliseForUpdate()` compares
 *       the incoming value against the current one and rejects
 *       only when they differ. Enforcing "body_type must not be
 *       sent" at the request layer would reject a PATCH that
 *       re-sends the same value, which is unnecessary friction.
 *
 *     · It does NOT enforce code uniqueness within the tenant.
 *       That is a database-dependent invariant; the request does
 *       not have (and should not have) tenant context. The service
 *       owns the check.
 *
 *     · It does NOT whitelist fields. The service's `whitelist()`
 *       drops any key not in `ALLOWED_FIELDS`.
 *
 * =============================================================================
 * The post-update state — how the conditional checks work
 * =============================================================================
 * The route model binding resolves the `GovernanceBody` instance
 * before the request is validated. The request reads the body from
 * `$this->route('governanceBody')`, merges the incoming payload
 * over the body's current values, and evaluates the two cross-field
 * rules against the merged state.
 *
 * The merge is done by inspection — the request decides for each
 * field whether the payload supplied a value (`$this->has($field)`)
 * or whether the current value should be used as the fallback. This
 * avoids mutating the model (which would happen if the request
 * called `$body->fill($payload)`), keeping the request a pure
 * read of the resolved body.
 *
 * The route parameter name is `governanceBody`, matching the
 * convention the routes file (G2a file 16 of 17) will use:
 *
 *     Route::patch('/bodies/{governanceBody}', …)
 *
 * If the future controller (G2a file 14) uses a different route
 * parameter name, both the routes file and the request's route-
 * binding read must be updated together. The `$this->route(…)`
 * parameter in this file is the string `'governanceBody'`; a
 * mismatch would result in a `null` body and the conditional
 * checks silently returning without error, which is a defect that
 * the G2a feature test (file 17) catches.
 *
 * =============================================================================
 * The Amharic validation messages — same decision as file 12
 * =============================================================================
 * The `messages()` and `attributes()` methods return inline
 * Amharic strings, mirroring the decision documented in
 * `StoreGovernanceBodyRequest` (G2a file 12 of 17) §"The Amharic
 * validation messages — inline today, catalogue later". When the
 * deferred `governance::validation.*` catalogue CR lands, both
 * files migrate together.
 *
 * The message set is identical to file 12's, with one difference:
 * this file does not declare `code.required` or `body_type.required`
 * messages, because the corresponding rules are `sometimes` here —
 * a missing field is not an error on a PATCH. The other messages
 * (`code.max`, `name_am.max`, `parent_body_id.exists`, etc.) apply
 * identically to both request classes.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Requests/UpdateGovernanceBodyRequest.php
 *
 * The directory `mases_core/src/Domains/Governance/Http/Requests/`
 * must exist — it was created for file 12. If your environment
 * somehow lacks it, create it before uploading.
 *
 * The request is consumed by `GovernanceBodyController::update()`
 * (G2a file 14 of 17). Until that controller is delivered and its
 * routes registered, this file is inert.
 *
 * A `composer dump-autoload` may be required if the class map is
 * cached.
 *
 * Verification (after the controller is delivered):
 *
 *     php artisan tinker
 *     >>> use MaaleArna\MASES\Core\Domains\Governance\Http\Requests\UpdateGovernanceBodyRequest;
 *     >>> class_exists(UpdateGovernanceBodyRequest::class)
 *     => true
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Rules declared:            13 (all `sometimes`)
 * Conditional checks:        2 (name presence, date ordering —
 *                               both evaluated against the
 *                               post-update state)
 * Custom messages:           12 keys
 * Field names:               13 keys
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceBodyType;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;

/**
 * UpdateGovernanceBodyRequest
 *
 * Validates the input for PATCH /api/governance/bodies/{body}.
 *
 * See the file-level change-verification block for the request's
 * scope, the three deliberate differences from the Store request,
 * and the post-update state evaluation the conditional checks use.
 *
 * =============================================================================
 * All rules are `sometimes`
 * =============================================================================
 * A PATCH payload is partial by definition. Every rule in this
 * request carries `sometimes`, which Laravel interprets as
 * "validate this field only when it is present in the input".
 * A caller that sends only `{ "name_en": "…" }` receives
 * validation for exactly that field; the other twelve fields are
 * ignored.
 *
 * The convention is uniform across the platform: every
 * `Update*Request` in the Shareholder, Wallet, and Loan domains
 * marks all rules as `sometimes`.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Requests
 * @version 1.0.0
 */
final class UpdateGovernanceBodyRequest extends FormRequest
{
    /**
     * Maximum length of a `code` value, matching the migration's
     * VARCHAR(32) declaration.
     */
    private const MAX_CODE_LENGTH = 32;

    /**
     * Maximum length of a bilingual name column, matching the
     * migration's VARCHAR(150) declarations.
     */
    private const MAX_NAME_LENGTH = 150;

    /**
     * Maximum length of a `purpose` value, matching the migration's
     * VARCHAR(500) declaration.
     */
    private const MAX_PURPOSE_LENGTH = 500;

    /**
     * Maximum length of a `mandate` value. See the counterpart
     * constant in `StoreGovernanceBodyRequest` for the reasoning.
     */
    private const MAX_MANDATE_LENGTH = 20000;

    /**
     * Maximum length of a `meeting_frequency` value, matching the
     * migration's VARCHAR(100) declaration.
     */
    private const MAX_MEETING_FREQUENCY_LENGTH = 100;

    /**
     * Authorize the caller before validating update data.
     *
     * The route model must already be resolved so the policy can
     * enforce both the update permission and tenant boundary before
     * validation reads or compares any model state.
     *
     * The controller retains its authorization check as defence in
     * depth. Missing or unresolved route models fail closed.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $body = $this->route('governanceBody');

        return $body instanceof GovernanceBody
            && ($this->user()?->can('update', $body) ?? false);
    }

    // =========================================================================
    // Preparation
    // =========================================================================

    /**
     * Trim whitespace from string inputs and normalise `code` to
     * uppercase.
     *
     * Mirrors `StoreGovernanceBodyRequest::prepareForValidation()`.
     * See that method's docblock for the reasoning.
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
     * Every rule is `sometimes`. See the file-level change-
     * verification block §"All rules are `sometimes`".
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // code — optional; when sent, must satisfy the same shape
            // constraints as on Store.
            // -----------------------------------------------------------------
            'code' => [
                'sometimes',
                'string',
                'max:' . self::MAX_CODE_LENGTH,
                'alpha_dash',
            ],

            // -----------------------------------------------------------------
            // body_type — optional; when sent, must be one of the
            // five enum values. The service rejects a change; the
            // request accepts a same-value re-send.
            // -----------------------------------------------------------------
            'body_type' => [
                'sometimes',
                'string',
                Rule::enum(GovernanceBodyType::class),
            ],

            // -----------------------------------------------------------------
            // Names — each individually optional; at least one must
            // remain non-empty in the post-update state (enforced in
            // withValidator()).
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
            // Structural references.
            // -----------------------------------------------------------------
            'parent_body_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:governance_bodies,id',
            ],
            'reporting_body_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:governance_bodies,id',
            ],

            // -----------------------------------------------------------------
            // Descriptive metadata.
            // -----------------------------------------------------------------
            'purpose' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_PURPOSE_LENGTH,
            ],
            'mandate' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_MANDATE_LENGTH,
            ],
            'meeting_frequency' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_MEETING_FREQUENCY_LENGTH,
            ],

            // -----------------------------------------------------------------
            // Quorum rule — shape (array) validated here; internal
            // structure validated by the service.
            // -----------------------------------------------------------------
            'quorum_rule_json' => [
                'sometimes',
                'nullable',
                'array',
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
     * Adds the two cross-field checks, evaluated against the
     * POST-update state — i.e. the incoming payload merged over the
     * resolved body's current values.
     *
     * @param  Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $body = $this->resolveBody();

            if ($body === null) {
                // The route did not resolve a body — the controller
                // will produce a 404 before the request's conditional
                // checks would matter. Silently returning avoids
                // producing misleading "missing name" errors for a
                // request that is about to be rejected for a
                // different reason.
                return;
            }

            $this->validateAtLeastOneName($v, $body);
            $this->validateEffectiveDateOrdering($v, $body);
        });
    }

    /**
     * Assert that at least one bilingual name is non-empty in the
     * post-update state.
     *
     * The check reads each field from the payload when the payload
     * supplied it, and from the resolved body otherwise. A partial
     * update that clears `name_am` while `name_en` remains set is
     * accepted; a partial update that clears `name_am` while
     * `name_en` is already null is rejected.
     *
     * @param  Validator      $validator
     * @param  GovernanceBody $body
     * @return void
     */
    private function validateAtLeastOneName(Validator $validator, GovernanceBody $body): void
    {
        $nameAm = $this->has('name_am') ? $this->input('name_am') : $body->name_am;
        $nameEn = $this->has('name_en') ? $this->input('name_en') : $body->name_en;

        $hasAm = is_string($nameAm) && trim($nameAm) !== '';
        $hasEn = is_string($nameEn) && trim($nameEn) !== '';

        if (!$hasAm && !$hasEn) {
            $validator->errors()->add(
                'name_am',
                'ቢያንስ አንድ ስም (በአማርኛ ወይም በእንግሊዝኛ) መቆየት ያስፈልጋል።',
            );
        }
    }

    /**
     * Assert that the effective date range is ordered correctly in
     * the post-update state.
     *
     * The check reads each date from the payload when present and
     * from the resolved body otherwise. It runs only when both
     * dates are non-null strings that passed the declarative format
     * rule.
     *
     * @param  Validator      $validator
     * @param  GovernanceBody $body
     * @return void
     */
    private function validateEffectiveDateOrdering(Validator $validator, GovernanceBody $body): void
    {
        $from  = $this->has('effective_from')
            ? $this->input('effective_from')
            : ($body->effective_from?->toDateString());

        $until = $this->has('effective_until')
            ? $this->input('effective_until')
            : ($body->effective_until?->toDateString());

        if (!is_string($from) || !is_string($until)) {
            return;
        }

        if ($from === '' || $until === '') {
            return;
        }

        // Both values passed the date_format rule; a lexical
        // comparison is sufficient because the format is canonical
        // (`YYYY-MM-DD`). See the counterpart method in
        // StoreGovernanceBodyRequest for the reasoning.
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
     * Every message is an inline Amharic string. The set is
     * identical to `StoreGovernanceBodyRequest`'s except that
     * `code.required` and `body_type.required` are omitted,
     * because the corresponding rules are `sometimes` here and a
     * missing field is not an error on a PATCH.
     *
     * See the file-level change-verification block §"The Amharic
     * validation messages — same decision as file 12" for the
     * reasoning and the deferred migration path.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.max'        => 'ኮዱ ከ32 ቁምፊዎች መብለጥ የለበትም።',
            'code.alpha_dash' => 'ኮዱ ፊደሎችን፣ ቁጥሮችን፣ ሰረዞችን እና ነጠላ ሰረዞችን ብቻ መያዝ አለበት።',

            'body_type.enum' => 'የአካሉ ዓይነት ትክክል አይደለም።',

            'name_am.max' => 'የአማርኛው ስም ከ150 ቁምፊዎች መብለጥ የለበትም።',
            'name_en.max' => 'የእንግሊዝኛው ስም ከ150 ቁምፊዎች መብለጥ የለበትም።',

            'parent_body_id.exists'    => 'የተጠቀሰው የወላጅ አካል አልተገኘም።',
            'reporting_body_id.exists' => 'የተጠቀሰው የሪፖርት አካል አልተገኘም።',

            'purpose.max'           => 'ዓላማው ከ500 ቁምፊዎች መብለጥ የለበትም።',
            'mandate.max'           => 'ሥልጣኑ ከ20000 ቁምፊዎች መብለጥ የለበትም።',
            'meeting_frequency.max' => 'የስብሰባ ድግግሞሹ ከ100 ቁምፊዎች መብለጥ የለበትም።',

            'quorum_rule_json.array' => 'የ quorum ደንብ ትክክለኛ ቅርጸት መሆን አለበት።',

            'effective_from.date_format'  => 'የመጀመሪያው ቀን በ YYYY-MM-DD ቅርጸት መሆን አለበት።',
            'effective_until.date_format' => 'የማብቂያው ቀን በ YYYY-MM-DD ቅርጸት መሆን አለበት።',

            'is_active.boolean' => 'የንቁ ሁኔታው ትክክለኛ ቡሊያን መሆን አለበት።',
        ];
    }

    /**
     * Get human-readable field names for error messages.
     *
     * Identical to `StoreGovernanceBodyRequest::attributes()`; see
     * that file for the reasoning.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code'               => 'ኮድ',
            'body_type'          => 'ዓይነት',
            'name_am'            => 'ስም (አማርኛ)',
            'name_en'            => 'ስም (እንግሊዝኛ)',
            'parent_body_id'     => 'የወላጅ አካል',
            'reporting_body_id'  => 'የሪፖርት አካል',
            'purpose'            => 'ዓላማ',
            'mandate'            => 'ሥልጣን',
            'quorum_rule_json'   => 'የ quorum ደንብ',
            'meeting_frequency'  => 'የስብሰባ ድግግሞሽ',
            'effective_from'     => 'የመጀመሪያ ቀን',
            'effective_until'    => 'የማብቂያ ቀን',
            'is_active'          => 'ንቁ ሁኔታ',
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the `GovernanceBody` instance bound to the current
     * route.
     *
     * Reads from the route's `governanceBody` parameter. Returns
     * null when the parameter is absent or does not resolve to a
     * `GovernanceBody` instance — a case that should not occur in
     * normal flow (the route uses implicit model binding), but is
     * defensively handled so the conditional checks above do not
     * dereference a null.
     *
     * @return GovernanceBody|null
     */
    private function resolveBody(): ?GovernanceBody
    {
        $body = $this->route('governanceBody');

        return $body instanceof GovernanceBody ? $body : null;
    }
}