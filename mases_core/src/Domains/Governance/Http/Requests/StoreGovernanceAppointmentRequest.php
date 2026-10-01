<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 10 of 31
 * =============================================================================
 *
 * File:            Http/Requests/StoreGovernanceAppointmentRequest.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (store request)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `FormRequest` validated the payload of
 *   a `POST /api/governance/bodies/{governanceBody}/appointments`
 *   request. A controller action that wanted to accept an appointment
 *   creation would have had to inline its own validation — the
 *   pattern the platform's convention explicitly rejects, because it
 *   scatters the request-shape contract across the controller and
 *   duplicating it in a second consumer (an Artisan command, a test
 *   helper) becomes a maintenance hazard.
 *
 * After:
 *   One file that declares the canonical validation rules for
 *   creating a `GovernanceAppointment`. The request is a
 *   `FormRequest` and follows the conventions the six sibling
 *   store/update requests in this domain established at G2a and G2b.
 *
 *   What the request validates:
 *
 *     governance_position_id  —  optional integer, min 1
 *     governance_term_id      —  optional integer, min 1
 *     instrument_type         —  required, one of the five
 *                                GovernanceAppointmentInstrumentType
 *                                values
 *     instrument_reference    —  optional string, max 191
 *     instrument_date         —  optional date (Y-m-d)
 *     effective_date          —  required date (Y-m-d)
 *     appointment_mode        —  required, one of the four
 *                                GovernanceAppointmentMode values
 *     title_am                —  optional string, max 150
 *     title_en                —  optional string, max 150
 *     notes_am                —  optional string, max 500
 *     notes_en                —  optional string, max 500
 *
 *   What the request deliberately does NOT validate:
 *
 *     · `governance_body_id`. The controller's `store()` action
 *       merges this value from the URL route parameter
 *       (`{governanceBody}`) into the payload BEFORE calling the
 *       service. The client does not supply it in the body; the URL
 *       is authoritative. This matches the convention every
 *       nested-resource store request in this domain uses — see
 *       `StoreGovernancePositionRequest` (G2b file 7 of 27) and
 *       `StoreGovernanceTermRequest` (G2b file 19 of 27).
 *
 *     · `status`. The service (`GovernanceAppointmentService`,
 *       G2c file 7 of 31) forces `status = 'draft'` on every
 *       registration, regardless of what the caller supplied. A
 *       client cannot create an appointment directly in the
 *       `active`, `revoked`, or `superseded` state — it becomes
 *       active through the service's explicit `activate()` method.
 *       The request therefore does NOT declare a rule for
 *       `status`; if a client supplies the key, `validated()`
 *       strips it, and the merge the controller performs does not
 *       resurrect it.
 *
 *     · `tenant_id`. The service receives the resolved tenant as a
 *       separate argument. The client never supplies it in the
 *       body. This is the platform's convention — see every other
 *       store request in this domain.
 *
 *     · `id`, `created_at`, `updated_at`, `deleted_at`. These are
 *       database-managed columns; the model's `$fillable` array
 *       excludes them, and no rule here declares them.
 *
 *   What the request deliberately does NOT check cross-field:
 *
 *     · The body / position alignment. The service layer's
 *       `normaliseForRegister()` (INV-3 in its docblock) verifies
 *       that the position, when supplied, belongs to the
 *       appointment's body. That check requires a database read
 *       (`SELECT governance_body_id FROM governance_positions
 *       WHERE id = ? AND tenant_id = ?`). Performing it in the
 *       request would duplicate the service's invariant enforcement
 *       and would race against a concurrent write between the
 *       request's validation and the service's transaction. The
 *       rule of this domain is: the service owns cross-entity
 *       invariants; the request owns shape and format.
 *
 *     · The body / term alignment. Same reasoning.
 *
 *     · The position / term tenant existence. Same reasoning.
 *
 *   What the request DOES check cross-field:
 *
 *     · `effective_date >= instrument_date` — when both are
 *       supplied and both parse as valid dates. The check is
 *       performed in `withValidator()` because Laravel's base rule
 *       set has no portable "field A ≥ field B" rule. The
 *       corresponding service-layer check (INV-8) re-validates
 *       the same condition; the request-side check exists to
 *       produce a per-field 422 error message with the correct
 *       field name and translation, rather than letting the
 *       service's `ValidationException` bubble up with a generic
 *       shape.
 *
 * =============================================================================
 * authorize() returns true unconditionally
 * =============================================================================
 * The request's `authorize()` method returns `true` for every caller.
 * Authorization is the controller's concern, not the request's. The
 * controller calls `Gate::authorize('create', $body)` before
 * instantiating this request's validated data — the policy
 * (`GovernanceAppointmentPolicy`, G2c file 8 of 31) is what refuses
 * an unauthorized caller. The request's `authorize()` returning
 * `true` unconditionally is therefore not a security hole; it is the
 * platform's convention, mirrored by every sibling request in this
 * domain.
 *
 * A reader who is unfamiliar with the convention may find this
 * surprising. The convention's rationale: a `FormRequest` is a
 * validation class, not an authorization class. Mixing the two
 * concerns into one class makes the authorization decision hard to
 * find (the controller's `Gate::authorize` call is the canonical
 * location) and would require the request to know about the
 * specific model instance being operated on. The two concerns stay
 * separate.
 *
 * =============================================================================
 * prepareForValidation() trims strings
 * =============================================================================
 * Whitespace-only strings are treated as absent. This is important
 * because the service's invariant checks treat an empty string
 * differently from `null` in some cases — specifically, the
 * bilingual title and notes fields are nullable, and a
 * whitespace-only title would pass `strlen() > 0` but fail the
 * `trim() !== ''` check in the model's `hasTitle()` helper. The
 * request normalises the input so the service sees a consistent
 * shape.
 *
 * The normalisation applies to the following fields:
 *
 *     instrument_reference, title_am, title_en, notes_am, notes_en
 *
 * It does NOT apply to `instrument_type` or `appointment_mode` —
 * those are enum-typed columns whose valid values are enumerated
 * constants; trimming them would mask a client's shape error rather
 * than surface it as a 422.
 *
 * It does NOT apply to the two dates — Laravel's `date` rule
 * validates the raw input and the service parses it.
 *
 * =============================================================================
 * The date ordering check — withValidator()
 * =============================================================================
 * The cross-field check is:
 *
 *     effective_date >= instrument_date
 *
 * when BOTH are supplied and non-null. The check is skipped
 * silently when either is null — the migration declares
 * `instrument_date` as nullable, and a null instrument date is a
 * valid state (a by-law appointment typically has no instrument
 * date).
 *
 * When `effective_date` is null, the base rule already produces a
 * 422 for the `required` failure, and the cross-field check does
 * not need to duplicate it.
 *
 * When `instrument_date` is null but `effective_date` is set, the
 * cross-field check does not apply — there is nothing to compare
 * against.
 *
 * The check compares parsed `Carbon` instances, not raw strings.
 * A string comparison of ISO dates works for the Y-m-d format but
 * is fragile against future format changes. The Carbon comparison
 * is the correct semantic check.
 *
 * =============================================================================
 * Amharic messages and attribute names
 * =============================================================================
 * Every rule carries an inline Amharic error message and an inline
 * Amharic attribute name. This follows the pattern the domain's
 * sibling requests established at G2a and G2b: the messages and
 * attribute names live in the request's own `messages()` and
 * `attributes()` methods, NOT in a `governance::validation.*`
 * translation catalogue.
 *
 * The catalogue-based approach was considered and deferred. The
 * rationale: the platform's validation-message convention is not
 * yet centralised for any domain; every domain's requests currently
 * carry inline messages. A future CR that introduces a shared
 * validation-message catalogue would migrate all domains together,
 * not just Governance. The inline messages are therefore the
 * correct shape for the current state of the platform.
 *
 * The Amharic attribute names use the same nouns the domain's
 * translation catalogue (G1 files 2, 3 of 10) declares for the
 * governance resource nouns, and the same verb forms the sibling
 * requests use. A native-speaker reviewer is the correct final
 * gate; the two flagged renderings (see the "Native-speaker review"
 * note at the end of this block) are refinements, not blockers.
 *
 * =============================================================================
 * Native-speaker review needed
 * =============================================================================
 * The following Amharic attribute-name renderings are the most
 * likely candidates for adjustment. They are idiomatic as far as
 * this author can determine, but native review is the correct
 * final gate.
 *
 * `instrument_type` — `የመሣሪያ ዓይነት`
 *   Literally "type of instrument". The noun `መሣሪያ` is a standard
 *   rendering of "instrument" in the legal-document sense, but it
 *   also carries the ordinary meaning of "tool" or "device". A
 *   reviewer may prefer `የሰነድ ዓይነት` ("type of document") — the
 *   emphasis shifts from the instrument to the recorded artefact —
 *   or a longer construction like `የውሳኔ ዓይነት` ("type of resolution")
 *   if the domain's user-facing vocabulary converges on the
 *   resolution-centric framing. The three are one-line changes.
 *
 * `instrument_reference` — `የመሣሪያ ማጣቀሻ`
 *   "Reference of the instrument". Idiomatic, but subject to the
 *   same `መሣሪያ` vs. `ሰነድ` choice as `instrument_type`. If one is
 *   changed, the other should change with it, so the two labels
 *   stay parallel.
 *
 * The remaining strings — `የሹመት ዘዴ` (appointment mode), `የጸናበት ቀን`
 * (effective date), `የመሣሪያ ቀን` (instrument date), `ርዕስ` (title),
 * `ማስታወሻ` (notes), `የአቋም መለያ` (position id), `የሥራ ዘመን መለያ`
 * (term id) — use standard vocabulary that appears elsewhere in
 * the platform's Amharic catalogue. They are not flagged
 * individually.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Requests/StoreGovernanceAppointmentRequest.php
 *
 * The directory already exists.
 *
 * After uploading, run:
 *
 *     composer dump-autoload
 *
 * The request is instantiated by Laravel's dependency-injection
 * machinery when a controller action type-hints it. No provider
 * registration is required — `FormRequest` resolution is automatic.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Rules declared:            11
 * Cross-field checks:        1 (date ordering)
 * Translation keys used:     3 (via Rule::enum(), framework-provided)
 * Inline messages declared:  every rule
 * Inline attributes declared: every rule
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Requests;

use Illuminate\Validation\Validator;
use Throwable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentInstrumentType;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentMode;

/**
 * StoreGovernanceAppointmentRequest
 *
 * Validates the payload of a `POST` request that creates a new
 * governance appointment.
 *
 * See the file-level change-verification block for the full set of
 * validation rules, the cross-field date-ordering check, the
 * deliberate omission of `governance_body_id` (merged by the
 * controller from the URL), and the Amharic messages.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Requests
 * @version 1.0.0
 */
final class StoreGovernanceAppointmentRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is the
     * controller's concern — the `GovernanceAppointmentController`
     * (G2c file 12 of 31) calls `Gate::authorize('create', $body)`
     * before invoking the controller action that type-hints this
     * class. See the file-level change-verification block
     * §"authorize() returns true unconditionally" for the reasoning.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     *
     * Trims the free-text string fields. A whitespace-only string is
     * normalised to `null` so the service sees a consistent shape.
     * See the file-level change-verification block
     * §"prepareForValidation() trims strings".
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (['instrument_reference', 'title_am', 'title_en', 'notes_am', 'notes_en'] as $field) {
            if (!$this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);
                $trimmed[$field] = $value === '' ? null : $value;
            }
        }

        if ($trimmed !== []) {
            $this->merge($trimmed);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // -----------------------------------------------------------------
            // Optional FK references — integer ids, min 1.
            // The `exists` checks are deferred to the service layer,
            // which also verifies the tenant boundary and the
            // body alignment. See the file-level change-verification
            // block §"What the request deliberately does NOT check
            // cross-field".
            // -----------------------------------------------------------------
            'governance_position_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            'governance_term_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            // -----------------------------------------------------------------
            // Instrument type — required; must resolve to a declared
            // GovernanceAppointmentInstrumentType case.
            // -----------------------------------------------------------------
            'instrument_type' => [
                'required',
                'string',
                Rule::enum(GovernanceAppointmentInstrumentType::class),
            ],

            // -----------------------------------------------------------------
            // Instrument reference — optional; free-text identifier.
            // -----------------------------------------------------------------
            'instrument_reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            // -----------------------------------------------------------------
            // Instrument issue date — optional; a calendar day.
            // -----------------------------------------------------------------
            'instrument_date' => [
                'sometimes',
                'nullable',
                'date',
            ],

            // -----------------------------------------------------------------
            // Effective date — required; a calendar day.
            // -----------------------------------------------------------------
            'effective_date' => [
                'required',
                'date',
            ],

            // -----------------------------------------------------------------
            // Appointment mode — required; must resolve to a declared
            // GovernanceAppointmentMode case.
            // -----------------------------------------------------------------
            'appointment_mode' => [
                'required',
                'string',
                Rule::enum(GovernanceAppointmentMode::class),
            ],

            // -----------------------------------------------------------------
            // Bilingual titles.
            // -----------------------------------------------------------------
            'title_am' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'title_en' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            // -----------------------------------------------------------------
            // Bilingual notes.
            // -----------------------------------------------------------------
            'notes_am' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],

            'notes_en' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * Configure the validator instance with cross-field checks.
     *
     * The only cross-field check in this request is the date
     * ordering: `effective_date >= instrument_date` when both are
     * supplied. See the file-level change-verification block
     * §"The date ordering check — withValidator()".
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Skip when the base rules already failed — the caller
            // receives the base failure without a confusing
            // cross-field message.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $instrumentDate = $this->input('instrument_date');
            $effectiveDate  = $this->input('effective_date');

            if ($instrumentDate === null || $effectiveDate === null) {
                return;
            }

            try {
                $from = Carbon::parse($instrumentDate);
                $to   = Carbon::parse($effectiveDate);
            } catch (Throwable) {
                // Base `date` rule already validated both; this
                // catch is defensive.
                return;
            }

            if ($to->lessThan($from)) {
                $validator->errors()->add(
                    'effective_date',
                    'የጸናበት ቀን ከመሣሪያው ቀን ቀደም ማለት የለበትም።',
                );
            }
        });
    }

    /**
     * Get the inline Amharic error messages.
     *
     * Every rule carries its own message. The strings follow the
     * domain's sibling requests' vocabulary and verb forms.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'governance_position_id.integer' => 'የአቋም መለያ ቁጥር መሆን አለበት።',
            'governance_position_id.min'     => 'የአቋም መለያ ከ 1 መብለጥ የለበትም።',

            'governance_term_id.integer'     => 'የሥራ ዘመን መለያ ቁጥር መሆን አለበት።',
            'governance_term_id.min'         => 'የሥራ ዘመን መለያ ከ 1 መብለጥ የለበትም።',

            'instrument_type.required'       => 'የመሣሪያ ዓይነት ያስፈልጋል።',
            'instrument_type.string'         => 'የመሣሪያ ዓይነት ጽሑፍ መሆን አለበት።',

            'instrument_reference.string'    => 'የመሣሪያ ማጣቀሻ ጽሑፍ መሆን አለበት።',
            'instrument_reference.max'       => 'የመሣሪያ ማጣቀሻ ከ 191 ቁምፊ መብለጥ የለበትም።',

            'instrument_date.date'           => 'የመሣሪያ ቀን ትክክለኛ ቀን መሆን አለበት።',

            'effective_date.required'        => 'የጸናበት ቀን ያስፈልጋል።',
            'effective_date.date'            => 'የጸናበት ቀን ትክክለኛ ቀን መሆን አለበት።',

            'appointment_mode.required'      => 'የሹመት ዘዴ ያስፈልጋል።',
            'appointment_mode.string'        => 'የሹመት ዘዴ ጽሑፍ መሆን አለበት።',

            'title_am.string'                => 'ርዕስ (አማርኛ) ጽሑፍ መሆን አለበት።',
            'title_am.max'                   => 'ርዕስ (አማርኛ) ከ 150 ቁምፊ መብለጥ የለበትም።',

            'title_en.string'                => 'ርዕስ (እንግሊዝኛ) ጽሑፍ መሆን አለበት።',
            'title_en.max'                   => 'ርዕስ (እንግሊዝኛ) ከ 150 ቁምፊ መብለጥ የለበትም።',

            'notes_am.string'                => 'ማስታወሻ (አማርኛ) ጽሑፍ መሆን አለበት።',
            'notes_am.max'                   => 'ማስታወሻ (አማርኛ) ከ 500 ቁምፊ መብለጥ የለበትም።',

            'notes_en.string'                => 'ማስታወሻ (እንግሊዝኛ) ጽሑፍ መሆን አለበት።',
            'notes_en.max'                   => 'ማስታወሻ (እንግሊዝኛ) ከ 500 ቁምፊ መብለጥ የለበትም።',
        ];
    }

    /**
     * Get the inline Amharic attribute names.
     *
     * The names appear in Laravel's default error messages for rules
     * that this request does not override. They also serve as a
     * human-readable map of the request's field vocabulary.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'governance_position_id' => 'የአቋም መለያ',
            'governance_term_id'     => 'የሥራ ዘመን መለያ',
            'instrument_type'        => 'የመሣሪያ ዓይነት',
            'instrument_reference'   => 'የመሣሪያ ማጣቀሻ',
            'instrument_date'        => 'የመሣሪያ ቀን',
            'effective_date'         => 'የጸናበት ቀን',
            'appointment_mode'       => 'የሹመት ዘዴ',
            'title_am'               => 'ርዕስ (አማርኛ)',
            'title_en'               => 'ርዕስ (እንግሊዝኛ)',
            'notes_am'               => 'ማስታወሻ (አማርኛ)',
            'notes_en'               => 'ማስታወሻ (እንግሊዝኛ)',
        ];
    }
}