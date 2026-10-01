<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * StoreShareClassRequest
 *
 * Validates input for creating a share class.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * This request validates the shape of an administrative share-class
 * creation. In Phase 2A the domain ships with exactly two seeded classes
 * (FOUNDERS and STANDARD) that are inserted by ShareClassSeeder (CR-008,
 * planned). This request exists for three reasons:
 *
 *   1. Completeness of the request/controller pattern. Every write endpoint
 *      in the domain has a FormRequest; having this one means the ShareClass
 *      controller (2A.22) can expose a create endpoint without inventing
 *      validation rules inline.
 *
 *   2. Future admin interfaces. A later phase may expose share-class
 *      management through the admin application; the request is the
 *      ready-made validator for that surface.
 *
 *   3. Testing. Feature tests (2A.46–2A.49) can construct a payload and
 *      exercise the validation path directly without depending on the
 *      seeder.
 *
 * =============================================================================
 * Code vocabulary (D3)
 * =============================================================================
 * `code` is validated against `config('shareholder.share_classes.codes')`
 * — the authoritative vocabulary declared in file 2A.1. A caller cannot
 * invent a code that is not on that list. Adding a new code is a Change
 * Request that:
 *
 *   1. Amends the config's `codes` array.
 *   2. Updates the ShareClass model's allowed set if a per-class rule is
 *      needed.
 *   3. Updates the seeder to insert the new row.
 *   4. Updates any resource-level label mapping for the code.
 *
 * The request therefore acts as the domain's first gate against arbitrary
 * codes, but the last-word enforcement is the config array itself. The
 * request does not accept a code that the deployment's config does not
 * already declare.
 *
 * Codes are UPPERCASE. `prepareForValidation()` upper-cases the input so
 * that a caller typing `"founders"` receives the same treatment as one
 * typing `"FOUNDERS"`. This mirrors the model's `code()` set-mutator
 * (2A.6); the two layers agree.
 *
 * =============================================================================
 * Par value and currency
 * =============================================================================
 * `par_value` is submitted as a STRING, not a number. The rule chain is:
 *
 *   - `string`      — reject JSON numbers so the client must be explicit
 *                     about the decimal representation.
 *   - regex         — the digits-before-the-point count must be 1–16 and
 *                     the digits-after-the-point count must be 0–4, matching
 *                     the DECIMAL(20,4) column that will store the value.
 *   - `gt:0`        — the par value must be strictly positive.
 *
 * A par value of zero is rejected. A share whose par value is zero has no
 * meaning in a company's capital structure and would break every downstream
 * capital-calculation feature (future phases). If the business ever wants
 * a zero-par-value class, that is a CR — not a request relaxation.
 *
 * `currency` is a three-character uppercase code. The rule accepts any
 * three-letter code (ISO 4217 style) because that is the general shape of a
 * currency code. The domain's current policy (D5) restricts the actual
 * value to `ETB`. This request does not enforce ETB, because:
 *
 *   - The decision to restrict the domain to one currency is a business
 *     policy that belongs to the service or controller, where the reason
 *     for the restriction (and any exceptions) can be expressed.
 *   - Keeping the request's currency rule generic means that a future
 *     multi-currency policy is a service change, not a request change.
 *
 * See F-2A18-4 for the corresponding note.
 *
 * =============================================================================
 * Names (Amharic-first)
 * =============================================================================
 * `name_am` and `name_en` are both individually nullable, but at least one
 * must be supplied. The enforcement is in `withValidator()` because
 * Laravel's declarative rules cannot cleanly express "A or B, but not
 * neither". The check mirrors the pattern used for Shareholder names in
 * StoreShareholderRequest (2A.16).
 *
 * The `name_am` field is declared before `name_en` in the rules array to
 * reflect the Amharic-first presentation order used by the resource layer
 * (2A.26). The order of keys in `rules()` has no runtime effect.
 *
 * =============================================================================
 * No authorization here
 * =============================================================================
 * `authorize()` returns true. The controller is responsible for verifying
 * that the caller has the `share_class.manage` permission (see the RBAC
 * plan in the frozen manifest). Splitting authorization from validation
 * keeps the policy in one place — the controller — rather than distributed
 * across request classes.
 *
 * =============================================================================
 * Amharic-first error messages
 * =============================================================================
 * Same policy as every other request in this domain: no hard-coded English
 * strings. Every message is a translation key resolved through Laravel's
 * translator in the current locale.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests
 */
final class StoreShareClassRequest extends FormRequest
{
    /**
     * Maximum length of the `code` column, per migration 2A.37.
     */
    private const MAX_CODE_LENGTH = 32;

    /**
     * Maximum length of a `name_*` column, per migration 2A.37.
     */
    private const MAX_NAME_LENGTH = 150;

    /**
     * Regex for par_value: 1–16 integer digits, 0–4 decimal digits.
     *
     * Matches the DECIMAL(20,4) column capacity. Does not allow a leading
     * sign, scientific notation, or a leading or trailing decimal point.
     */
    private const PAR_VALUE_PATTERN = '/^\d{1,16}(\.\d{1,4})?$/';

    /**
     * Regex for currency: exactly three uppercase ASCII letters.
     *
     * Matches the ISO 4217 shape. The specific allowed code is a policy
     * question handled by the controller/service, not the request.
     */
    private const CURRENCY_PATTERN = '/^[A-Z]{3}$/';

    /**
     * Determine if the user is authorized to make this request.
     *
     * Always true — see the class docblock.
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
     * Normalise input before validation.
     *
     *   - Trims every string input.
     *   - Upper-cases `code` and `currency` so that a caller typing
     *     `"founders"` / `"etb"` receives the same treatment as one typing
     *     the canonical `"FOUNDERS"` / `"ETB"`.
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

            if ($key === 'code' || $key === 'currency') {
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
        $allowedCodes = array_map(
            'strtoupper',
            (array) config('shareholder.share_classes.codes', []),
        );

        return [
            // -----------------------------------------------------------------
            // code — canonical, upper-case, restricted to the config vocabulary.
            // -----------------------------------------------------------------
            'code' => [
                'required',
                'string',
                'max:' . self::MAX_CODE_LENGTH,
                Rule::in($allowedCodes),
            ],

            // -----------------------------------------------------------------
            // Names — Amharic first. Both nullable; at least one required
            // (enforced in withValidator).
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
            // Par value — decimal string, strictly positive, DECIMAL(20,4).
            // -----------------------------------------------------------------
            'par_value' => [
                'required',
                'string',
                'regex:' . self::PAR_VALUE_PATTERN,
                'gt:0',
            ],

            // -----------------------------------------------------------------
            // Currency — ISO 4217 shape.
            // -----------------------------------------------------------------
            'currency' => [
                'required',
                'string',
                'size:3',
                'regex:' . self::CURRENCY_PATTERN,
            ],

            // -----------------------------------------------------------------
            // Flags.
            // -----------------------------------------------------------------
            'is_active' => [
                'sometimes',
                'boolean',
            ],
            'is_treasury_eligible' => [
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
     * Runs after the declarative rules. Adds the "at least one name" check
     * that cannot be expressed through Laravel's rule syntax.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (!$this->anyFilled(['name_am', 'name_en'])) {
                $v->errors()->add(
                    'name_am',
                    __('shareholder::validation.share_class_name_required'),
                );
            }
        });
    }

    // =========================================================================
    // Messages and attributes
    // =========================================================================

    /**
     * Get the custom validation messages.
     *
     * Every message is a translation key resolved via `__()` in the current
     * locale. No English string is hard-coded.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => __('shareholder::validation.share_class.code.required'),
            'code.in'       => __('shareholder::validation.share_class.code.in'),

            'par_value.required' => __('shareholder::validation.share_class.par_value.required'),
            'par_value.regex'    => __('shareholder::validation.share_class.par_value.regex'),
            'par_value.gt'       => __('shareholder::validation.share_class.par_value.gt'),

            'currency.required' => __('shareholder::validation.share_class.currency.required'),
            'currency.size'     => __('shareholder::validation.share_class.currency.size'),
            'currency.regex'    => __('shareholder::validation.share_class.currency.regex'),

            'is_active.boolean'            => __('shareholder::validation.is_active.boolean'),
            'is_treasury_eligible.boolean' => __('shareholder::validation.is_treasury_eligible.boolean'),
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
            'code'                 => __('shareholder::attributes.share_class.code'),
            'name_am'              => __('shareholder::attributes.share_class.name_am'),
            'name_en'              => __('shareholder::attributes.share_class.name_en'),
            'par_value'            => __('shareholder::attributes.share_class.par_value'),
            'currency'             => __('shareholder::attributes.share_class.currency'),
            'is_active'            => __('shareholder::attributes.is_active'),
            'is_treasury_eligible' => __('shareholder::attributes.share_class.is_treasury_eligible'),
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Whether any of the named fields has a non-empty value.
     *
     * @param array<int, string> $fields
     * @return bool
     */
    private function anyFilled(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->filled($field)) {
                return true;
            }
        }

        return false;
    }
}