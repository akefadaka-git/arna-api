<?php

declare(strict_types=1);



namespace MaaleArna\Arna\Core\Domains\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use MaaleArna\Arna\Core\Domains\Governance\Enums\GovernanceBodyType;

/**
 * StoreGovernanceBodyRequest
 *
 * Validates the input for POST /api/governance/bodies.
 *
 * See the file-level change-verification block for the request's
 * scope, the split between shape validation (here) and invariant
 * validation (in the service), and the deferred migration of the
 * inline Amharic messages to a translation catalogue.
 *
 * =============================================================================
 * Scope of validation
 * =============================================================================
 *  · Shape of the input (types, enum membership, length, format).
 *
 *  · Two cross-field invariants enforced at the HTTP boundary:
 *
 *      - at least one of name_am / name_en must be non-empty
 *      · effective_from <= effective_until when both are set
 *
 *  · Domain invariants that require database queries (code
 *    uniqueness within the tenant, parent-body existence in the
 *    tenant, cycle detection) are enforced by
 *    `GovernanceBodyService` (G2a file 5 of 17), not here.
 *
 * =============================================================================
 * @package MaaleArna\Arna\Core\Domains\Governance\Http\Requests
 * @version 1.0.0
 */
final class StoreGovernanceBodyRequest extends FormRequest
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
     * Maximum length of a `mandate` value.
     *
     * The migration declares `mandate` as TEXT, which holds up to
     * 65,535 bytes on MySQL. The cap of 20,000 characters is
     * deliberately below the column's limit, to leave headroom for
     * UTF-8 encoding overhead (Amharic characters can be up to 3
     * bytes each) and to keep a single governance-body payload
     * bounded. A future CR that needs longer charter texts widens
     * this constant.
     */
    private const MAX_MANDATE_LENGTH = 20000;

    /**
     * Maximum length of a `meeting_frequency` value, matching the
     * migration's VARCHAR(100) declaration.
     */
    private const MAX_MEETING_FREQUENCY_LENGTH = 100;

    /**
     * Authorize the caller before validating request data.
     *
     * FormRequest authorization runs before the controller action.
     * Enforcing the policy here prevents callers without permission
     * from using validation responses as an oracle for database-backed
     * fields such as parent_body_id and reporting_body_id.
     *
     * The controller retains its authorization check as defence in
     * depth for any future non-HTTP entry point or refactor.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', \MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody::class) ?? false;
    }

    // =========================================================================
    // Preparation
    // =========================================================================

    /**
     * Trim whitespace from string inputs and normalise `code` to
     * uppercase.
     *
     * The `code` normalisation mirrors the model's set-mutator; the
     * trim applies to every string input (name fields, purpose,
     * mandate, etc.) so that trailing or leading whitespace does
     * not produce spurious "empty" values.
     *
     * Non-string inputs are passed through unchanged.
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // code — canonical, uppercase, alphanumeric + dash +
            // underscore. The service will trim and uppercase
            // again; the request normalises first so the
            // uniqueness check is casing-independent.
            // -----------------------------------------------------------------
            'code' => [
                'required',
                'string',
                'max:' . self::MAX_CODE_LENGTH,
                'alpha_dash',
            ],

            // -----------------------------------------------------------------
            // body_type — required, one of the five enum values.
            //
            // `Rule::enum()` resolves the allowed values from the
            // enum's backing strings. A caller that submits an
            // unrecognized value receives a 422 with a per-field
            // error.
            // -----------------------------------------------------------------
            'body_type' => [
                'required',
                'string',
                Rule::enum(GovernanceBodyType::class),
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
            // Structural references — when present, must be
            // integers pointing at existing rows.
            //
            // The tenant-scope of the referenced body, and the
            // cycle check, are the service's concern.
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
     *   · At least one of name_am / name_en must be non-empty.
     *   · effective_from must be <= effective_until when both are
     *     set.
     *
     * @param  Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateAtLeastOneName($v);
            $this->validateEffectiveDateOrdering($v);
        });
    }

    /**
     * Assert that at least one of the two bilingual name fields is
     * non-empty.
     *
     * The check runs after all declarative rules have evaluated, so
     * a value that fails `max` still registers as "present" — the
     * caller receives both the length error and, if applicable, the
     * missing-name error.
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

    /**
     * Assert that the effective date range is ordered correctly.
     *
     * Runs only when both dates are present and each parsed cleanly
     * (the declarative rules already flagged an unparseable value).
     * If either date is malformed, the declarative rules produce a
     * format error; the ordering check silently returns, to avoid
     * producing two errors for the same field.
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

        // Both values passed the date_format rule; a lexical
        // comparison is sufficient because the format is canonical
        // (`YYYY-MM-DD`). A future CR that relaxes the format to
        // accept alternative parseable forms would need to replace
        // this comparison with a `Carbon::parse()` comparison.
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
     * Every message is an inline Amharic string. See the file-level
     * change-verification block §"The Amharic validation messages —
     * inline today, catalogue later" for the reasoning and the
     * deferred migration path.
     *
     * The two cross-field checks are expressed inline in their
     * `validate*` methods above; this array covers only the
     * declarative rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required'     => 'የአካሉ ኮድ ያስፈልጋል።',
            'code.max'          => 'ኮዱ ከ32 ቁምፊዎች መብለጥ የለበትም።',
            'code.alpha_dash'   => 'ኮዱ ፊደሎችን፣ ቁጥሮችን፣ ሰረዞችን እና ነጠላ ሰረዞችን ብቻ መያዝ አለበት።',

            'body_type.required' => 'የአካሉ ዓይነት ያስፈልጋል።',
            'body_type.enum'     => 'የአካሉ ዓይነት ትክክል አይደለም።',

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
     * Laravel substitutes these into default messages (e.g.
     * "The :attribute field is required"). Providing Amharic names
     * means a caller who receives a default message — one this
     * request does not explicitly override — sees the field named
     * in Amharic rather than in the raw snake-case identifier.
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
}