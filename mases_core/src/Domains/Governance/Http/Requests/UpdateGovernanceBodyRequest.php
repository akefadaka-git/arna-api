<?php

declare(strict_types=1);



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