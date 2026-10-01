<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 23 of 31
 * =============================================================================
 *
 * File:            Http/Requests/StoreGovernanceMembershipRequest.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (store request)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `FormRequest` validated the payload of
 *   a `POST /api/governance/bodies/{governanceBody}/memberships`
 *   request. A controller action that wanted to accept a membership
 *   creation would have had to inline its own validation — the
 *   pattern the platform's convention explicitly rejects.
 *
 * After:
 *   One file that declares the canonical validation rules for
 *   creating a `GovernanceMembership`. The request follows the
 *   conventions the seven sibling store/update requests in this
 *   domain established at G2a, G2b, and G2c's appointment half.
 *
 *   What the request validates:
 *
 *     governance_position_id    —  required integer, min 1
 *     governance_term_id        —  optional nullable integer, min 1
 *     governance_appointment_id —  optional nullable integer, min 1
 *     user_id                   —  optional nullable integer, min 1
 *     member_name_am            —  optional nullable string, max 150
 *     member_name_en            —  optional nullable string, max 150
 *     external_reference        —  optional nullable string, max 191
 *     start_date                —  required date (Y-m-d)
 *     end_date                  —  optional nullable date (Y-m-d)
 *     is_ex_officio             —  optional boolean
 *     notes_am                  —  optional nullable string, max 500
 *     notes_en                  —  optional nullable string, max 500
 *
 *   What the request deliberately does NOT validate:
 *
 *     · `governance_body_id`. The controller's `store()` action
 *       merges this value from the URL route parameter
 *       (`{governanceBody}`) into the payload BEFORE calling the
 *       service. The client does not supply it in the body; the URL
 *       is authoritative. Same convention every nested-resource
 *       store request in this domain uses.
 *
 *     · `status`. The service (`GovernanceMembershipService`, G2c
 *       file 20 of 31) forces `status = 'active'` on every
 *       registration. A client cannot create a membership directly
 *       in a terminal or suspended state. The request does NOT
 *       declare a rule for `status`; if a client supplies the key,
 *       `validated()` strips it.
 *
 *     · `ended_reason`. Populated only by the service's lifecycle
 *       methods (end, resign, remove, expire) when the membership
 *       transitions to a terminal state. Never supplied at
 *       registration. Not declared here.
 *
 *     · `tenant_id`. The service receives the resolved tenant as a
 *       separate argument.
 *
 *     · `id`, `created_at`, `updated_at`, `deleted_at`. Database-
 *       managed columns.
 *
 *   What the request deliberately does NOT check cross-field:
 *
 *     · The body/position alignment, body/term alignment, or
 *       body/appointment alignment. The service layer verifies
 *       each (INV-3, INV-5, INV-7). Those checks require database
 *       reads. The domain's rule is: the service owns cross-entity
 *       invariants; the request owns shape and format.
 *
 *     · The overlap invariant (ruling B4). The overlap check
 *       requires a database read against the ongoing memberships
 *       of the position, and must run inside the write transaction
 *       to close the concurrency window. The service performs it;
 *       the request does not.
 *
 *   What the request DOES check cross-field:
 *
 *     · Member-identity presence — at least one of `user_id`,
 *       `external_reference`, `member_name_am`, `member_name_en`
 *       must be present (INV-10, ruling B1). The check is performed
 *       in `withValidator()` because Laravel's base rule set has no
 *       portable "at least one of these four" rule.
 *
 *     · Date ordering — `start_date <= end_date` when both are
 *       supplied. Checked in `withValidator()` for the same reason.
 *
 *   Both cross-field checks are RE-VALIDATED by the service layer.
 *   The request-side checks exist to produce per-field 422
 *   responses with the correct field name and Amharic message.
 *
 * =============================================================================
 * Member-identity check — the four-column rule
 * =============================================================================
 * The ruling B1 design identifies the member by whichever of four
 * columns is present. The schema does not enforce the presence of
 * any of them; the service does. The request-side check produces a
 * clean 422 with a message pointing at the most likely candidate
 * field (`member_name_am`), rather than letting the service's own
 * `ValidationException` bubble up from a transactional path.
 *
 * The check runs in `withValidator()` after the base rules. If the
 * base rules already failed — for example, because `start_date` is
 * missing — the cross-field check is skipped so the caller receives
 * only the base failure messages. This matches the convention the
 * sibling requests use.
 *
 * The rule:
 *
 *     hasUser   = user_id is present AND not null
 *     hasExt    = external_reference is a non-empty trimmed string
 *     hasNameAm = member_name_am is a non-empty trimmed string
 *     hasNameEn = member_name_en is a non-empty trimmed string
 *
 *     if (!hasUser && !hasExt && !hasNameAm && !hasNameEn) {
 *         → add error on member_name_am
 *     }
 *
 * The error is attached to `member_name_am` because that is the
 * most common missing field when a caller supplies no identity —
 * the caller supplied a `user_id` and thinks they are done, but
 * either did not supply one or supplied an invalid value that got
 * stripped by the base `integer` rule. A reviewer looking at the
 * response sees the field name and knows where to look.
 *
 * =============================================================================
 * authorize() returns true unconditionally
 * =============================================================================
 * Authorization is the controller's concern. The controller calls
 * `Gate::authorize('create', $body)` before instantiating this
 * request's validated data. Same convention as every sibling
 * request in the domain.
 *
 * =============================================================================
 * prepareForValidation() trims strings
 * =============================================================================
 * The free-text fields are trimmed; a whitespace-only value becomes
 * `null`. The five trimmed fields:
 *
 *     member_name_am, member_name_en, external_reference,
 *     notes_am, notes_en
 *
 * The two date fields are NOT trimmed. Laravel's `date` rule
 * validates the raw input; the service parses it.
 *
 * The `is_ex_officio` boolean is NOT trimmed — Laravel's `boolean`
 * rule accepts the standard truthy/falsy representations
 * (`true`, `false`, `1`, `0`, `"1"`, `"0"`).
 *
 * =============================================================================
 * Amharic messages and attribute names
 * =============================================================================
 * Every rule carries an inline Amharic error message and an inline
 * Amharic attribute name. Same convention as the sibling requests.
 *
 * The field vocabulary follows the domain's translation catalogue
 * (G1 files 2, 3 of 10) for the resource nouns and the same verb
 * forms the sibling requests use.
 *
 * =============================================================================
 * Native-speaker review needed
 * =============================================================================
 * Two renderings are candidates for adjustment by a native
 * reviewer. They mirror the two flagged in the sibling requests,
 * applied to the membership context.
 *
 * `user_id` — `የተጠቃሚ መለያ`
 *   "User identifier". Standard rendering; a reviewer may prefer
 *   the fuller `የተጠቃሚ መታወቂያ` ("user identification"). The
 *   shorter form is used here for consistency with the sibling
 *   requests' pattern of preferring the shorter noun form.
 *
 * `external_reference` — `የውጭ ማጣቀሻ`
 *   "External reference". Idiomatic. A reviewer may prefer
 *   `የውጭ መለያ ቁጥር` ("external identifier number") if the domain's
 *   user-facing vocabulary converges on the "identifier" framing
 *   rather than the "reference" framing. The two are one-line
 *   changes.
 *
 * The remaining strings — `የአቋም መለያ` (position id), `የሥራ ዘመን መለያ`
 * (term id), `የሹመት መለያ` (appointment id), `የአባል ስም (አማርኛ)` and
 * `የአባል ስም (እንግሊዝኛ)` (member name in each language), `የመጀመሪያ
 * ቀን` (start date), `የማብቂያ ቀን` (end date), `በስልጣን መሠረት` (ex
 * officio — reusing the catalogue's `membership_type.ex_officio`
 * rendering), and `ማስታወሻ` (notes) — use standard vocabulary that
 * appears elsewhere in the platform's Amharic catalogue.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Requests/StoreGovernanceMembershipRequest.php
 *
 * The directory already exists.
 *
 * After uploading, run:
 *
 *     composer dump-autoload
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Rules declared:            12
 * Cross-field checks:        2 (member identity, date ordering)
 * Inline messages declared:  every rule + both cross-field errors
 * Inline attributes declared: every rule
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Requests;

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
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Requests
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