<?php

declare(strict_types=1);



namespace MaaleArna\Arna\Core\Domains\Governance\Http\Requests;

use Throwable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * StoreGovernanceMembershipRequest
 *
 * Validates the payload of a `POST` request that creates a new
 * governance membership.
 *
 * See the file-level change-verification block for the full set of
 * validation rules, the two cross-field checks, the deliberate
 * omission of `governance_body_id` (merged by the controller from
 * the URL) and `status` (forced by the service), and the Amharic
 * messages.
 *
 * @package MaaleArna\Arna\Core\Domains\Governance\Http\Requests
 * @version 1.0.0
 */
final class StoreGovernanceMembershipRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is the
     * controller's concern.
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
     * normalised to `null`. See the file-level change-verification
     * block §"prepareForValidation() trims strings".
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach (
            [
                'member_name_am',
                'member_name_en',
                'external_reference',
                'notes_am',
                'notes_en',
            ] as $field
        ) {
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
            // Position — required. A membership without a position has
            // no structural meaning.
            //
            // The `exists` check is deferred to the service, which also
            // verifies the tenant boundary and the body alignment.
            // -----------------------------------------------------------------
            'governance_position_id' => [
                'required',
                'integer',
                'min:1',
            ],

            // -----------------------------------------------------------------
            // Optional FK references — integer ids, min 1.
            // -----------------------------------------------------------------
            'governance_term_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            'governance_appointment_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            'user_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            // -----------------------------------------------------------------
            // Member identity — free-text names and external reference.
            // At least one identity field is required overall; the check
            // is performed in `withValidator()` because the "at least one
            // of" relationship is not expressible as a base rule.
            // -----------------------------------------------------------------
            'member_name_am' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'member_name_en' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'external_reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
            ],

            // -----------------------------------------------------------------
            // Temporal bounds.
            //
            // `start_date` is required; `end_date` is optional (a null
            // end date means an open-ended membership).
            // -----------------------------------------------------------------
            'start_date' => [
                'required',
                'date',
            ],

            'end_date' => [
                'sometimes',
                'nullable',
                'date',
            ],

            // -----------------------------------------------------------------
            // Ex-officio flag.
            // -----------------------------------------------------------------
            'is_ex_officio' => [
                'sometimes',
                'boolean',
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
     * Configure the validator with the two cross-field checks.
     *
     * See the file-level change-verification block §"Member-identity
     * check — the four-column rule" and §"The date ordering check".
     *
     * @param  Validator $validator
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

            // -----------------------------------------------------------------
            // 1. Member-identity presence.
            // -----------------------------------------------------------------
            $userId = $this->input('user_id');
            $external = $this->input('external_reference');
            $nameAm = $this->input('member_name_am');
            $nameEn = $this->input('member_name_en');

            $hasUser = $userId !== null && $userId !== '';
            $hasExternal = is_string($external) && trim($external) !== '';
            $hasNameAm = is_string($nameAm) && trim($nameAm) !== '';
            $hasNameEn = is_string($nameEn) && trim($nameEn) !== '';

            if (!$hasUser && !$hasExternal && !$hasNameAm && !$hasNameEn) {
                $validator->errors()->add(
                    'member_name_am',
                    'ቢያንስ አንድ የአባል መለያ ያስፈልጋል፡ የተጠቃሚ መለያ፣ የውጭ ማጣቀሻ፣ ወይም ስም።',
                );

                return;
            }

            // -----------------------------------------------------------------
            // 2. Date ordering.
            // -----------------------------------------------------------------
            $startDate = $this->input('start_date');
            $endDate = $this->input('end_date');

            if ($startDate === null || $endDate === null) {
                return;
            }

            try {
                $from = Carbon::parse($startDate);
                $to   = Carbon::parse($endDate);
            } catch (Throwable) {
                return;
            }

            if ($from->greaterThan($to)) {
                $validator->errors()->add(
                    'end_date',
                    'የማብቂያ ቀን ከመጀመሪያ ቀን ቀደም ማለት የለበትም።',
                );
            }
        });
    }

    /**
     * Get the inline Amharic error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'governance_position_id.required' => 'የአቋም መለያ ያስፈልጋል።',
            'governance_position_id.integer'  => 'የአቋም መለያ ቁጥር መሆን አለበት።',
            'governance_position_id.min'      => 'የአቋም መለያ ከ 1 መብለጥ የለበትም።',

            'governance_term_id.integer'      => 'የሥራ ዘመን መለያ ቁጥር መሆን አለበት።',
            'governance_term_id.min'          => 'የሥራ ዘመን መለያ ከ 1 መብለጥ የለበትም።',

            'governance_appointment_id.integer' => 'የሹመት መለያ ቁጥር መሆን አለበት።',
            'governance_appointment_id.min'     => 'የሹመት መለያ ከ 1 መብለጥ የለበትም።',

            'user_id.integer'                 => 'የተጠቃሚ መለያ ቁጥር መሆን አለበት።',
            'user_id.min'                     => 'የተጠቃሚ መለያ ከ 1 መብለጥ የለበትም።',

            'member_name_am.string'           => 'የአባል ስም (አማርኛ) ጽሑፍ መሆን አለበት።',
            'member_name_am.max'              => 'የአባል ስም (አማርኛ) ከ 150 ቁምፊ መብለጥ የለበትም።',

            'member_name_en.string'           => 'የአባል ስም (እንግሊዝኛ) ጽሑፍ መሆን አለበት።',
            'member_name_en.max'              => 'የአባል ስም (እንግሊዝኛ) ከ 150 ቁምፊ መብለጥ የለበትም።',

            'external_reference.string'       => 'የውጭ ማጣቀሻ ጽሑፍ መሆን አለበት።',
            'external_reference.max'          => 'የውጭ ማጣቀሻ ከ 191 ቁምፊ መብለጥ የለበትም።',

            'start_date.required'             => 'የመጀመሪያ ቀን ያስፈልጋል።',
            'start_date.date'                 => 'የመጀመሪያ ቀን ትክክለኛ ቀን መሆን አለበት።',

            'end_date.date'                   => 'የማብቂያ ቀን ትክክለኛ ቀን መሆን አለበት።',

            'is_ex_officio.boolean'           => 'የ ex-officio ምልክት እውነት ወይም ሐሰት መሆን አለበት።',

            'notes_am.string'                 => 'ማስታወሻ (አማርኛ) ጽሑፍ መሆን አለበት።',
            'notes_am.max'                    => 'ማስታወሻ (አማርኛ) ከ 500 ቁምፊ መብለጥ የለበትም።',

            'notes_en.string'                 => 'ማስታወሻ (እንግሊዝኛ) ጽሑፍ መሆን አለበት።',
            'notes_en.max'                    => 'ማስታወሻ (እንግሊዝኛ) ከ 500 ቁምፊ መብለጥ የለበትም።',
        ];
    }

    /**
     * Get the inline Amharic attribute names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'governance_position_id'    => 'የአቋም መለያ',
            'governance_term_id'        => 'የሥራ ዘመን መለያ',
            'governance_appointment_id' => 'የሹመት መለያ',
            'user_id'                   => 'የተጠቃሚ መለያ',
            'member_name_am'            => 'የአባል ስም (አማርኛ)',
            'member_name_en'            => 'የአባል ስም (እንግሊዝኛ)',
            'external_reference'        => 'የውጭ ማጣቀሻ',
            'start_date'                => 'የመጀመሪያ ቀን',
            'end_date'                  => 'የማብቂያ ቀን',
            'is_ex_officio'             => 'የ ex-officio ምልክት',
            'notes_am'                  => 'ማስታወሻ (አማርኛ)',
            'notes_en'                  => 'ማስታወሻ (እንግሊዝኛ)',
        ];
    }
}