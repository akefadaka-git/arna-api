<?php

declare(strict_types=1);



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