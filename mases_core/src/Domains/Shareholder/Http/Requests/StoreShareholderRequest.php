<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\ShareholderType;

/**
 * StoreShareholderRequest
 *
 * Validates the input for POST /shareholders.
 *
 * =============================================================================
 * Helper naming — hasAnyFilledField vs anyFilled
 * =============================================================================
 * The private helper that checks whether any of a list of fields is filled is
 * named `hasAnyFilledField()`, NOT `anyFilled()`. Laravel's base
 * `Illuminate\Http\Request` declares a public `anyFilled()` method; a
 * subclass that declares a method of the same name with reduced visibility
 * causes a PHP fatal error at class-load time (not at method-call time).
 *
 * The helper's body is functionally identical to the base class method; it
 * exists here only so that the request can treat the field list as an array
 * without depending on the base method's exact type signature.
 *
 * =============================================================================
 * Scope of validation
 * =============================================================================
 *  - Shape of the input (types, enum membership, length).
 *  - Two cross-field invariants enforced at the HTTP boundary:
 *      - individual requires at least one name component
 *      - legal entity requires at least one entity name and a representative
 *
 *  Domain invariants that require database queries (uniqueness of user_id in
 *  the tenant) are enforced by ShareholderService (2A.12), not here.
 *
 * =============================================================================
 * Amharic-first error messages
 * =============================================================================
 * This file contains no hard-coded user-facing strings. Every message and
 * field label is a translation key resolved through Laravel's translator in
 * the current locale.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests
 */
final class StoreShareholderRequest extends FormRequest
{
    private const MAX_NAME_LENGTH = 100;
    private const MAX_ENTITY_NAME_LENGTH = 200;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Always true — see the class docblock of the original delivery.
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
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach ($this->all() as $key => $value) {
            if (is_string($value)) {
                $trimmed[$key] = trim($value);
            }
        }

        if ($trimmed !== []) {
            $this->merge($trimmed);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shareholder_type' => [
                'required',
                'string',
                Rule::enum(ShareholderType::class),
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'first_name_am' => [
                'sometimes', 'nullable', 'string', 'max:' . self::MAX_NAME_LENGTH,
            ],
            'first_name_en' => [
                'sometimes', 'nullable', 'string', 'max:' . self::MAX_NAME_LENGTH,
            ],
            'father_name_am' => [
                'sometimes', 'nullable', 'string', 'max:' . self::MAX_NAME_LENGTH,
            ],
            'father_name_en' => [
                'sometimes', 'nullable', 'string', 'max:' . self::MAX_NAME_LENGTH,
            ],
            'grandfather_name_am' => [
                'sometimes', 'nullable', 'string', 'max:' . self::MAX_NAME_LENGTH,
            ],
            'grandfather_name_en' => [
                'sometimes', 'nullable', 'string', 'max:' . self::MAX_NAME_LENGTH,
            ],

            'entity_name_am' => [
                'sometimes', 'nullable', 'string', 'max:' . self::MAX_ENTITY_NAME_LENGTH,
            ],
            'entity_name_en' => [
                'sometimes', 'nullable', 'string', 'max:' . self::MAX_ENTITY_NAME_LENGTH,
            ],

            'user_id' => [
                'sometimes', 'nullable', 'integer', 'exists:users,id',
            ],
            'representative_user_id' => [
                'sometimes', 'nullable', 'integer', 'exists:users,id',
            ],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $typeValue = $this->input('shareholder_type');
            $type      = ShareholderType::tryFrom((string) $typeValue);

            if ($type === null) {
                return;
            }

            if ($type === ShareholderType::Individual) {
                $this->validateIndividual($v);

                return;
            }

            $this->validateLegalEntity($v);
        });
    }

    /**
     * Apply individual-specific conditional checks.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateIndividual(Validator $validator): void
    {
        if (!$this->hasAnyFilledField([
            'first_name_am',
            'first_name_en',
            'father_name_am',
            'father_name_en',
        ])) {
            $validator->errors()->add(
                'first_name_am',
                __('shareholder::validation.individual_name_required'),
            );
        }

        if ($this->hasAnyFilledField(['entity_name_am', 'entity_name_en'])) {
            $validator->errors()->add(
                'entity_name_am',
                __('shareholder::validation.entity_name_forbidden_for_individual'),
            );
        }
    }

    /**
     * Apply legal-entity-specific conditional checks.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateLegalEntity(Validator $validator): void
    {
        if (!$this->hasAnyFilledField(['entity_name_am', 'entity_name_en'])) {
            $validator->errors()->add(
                'entity_name_am',
                __('shareholder::validation.entity_name_required'),
            );
        }

        if ($this->hasAnyFilledField([
            'first_name_am',
            'first_name_en',
            'father_name_am',
            'father_name_en',
            'grandfather_name_am',
            'grandfather_name_en',
        ])) {
            $validator->errors()->add(
                'first_name_am',
                __('shareholder::validation.individual_name_forbidden_for_entity'),
            );
        }

        if ($this->filled('user_id')) {
            $validator->errors()->add(
                'user_id',
                __('shareholder::validation.user_id_forbidden_for_legal_entity'),
            );
        }

        if (!$this->filled('representative_user_id')) {
            $validator->errors()->add(
                'representative_user_id',
                __('shareholder::validation.representative_required'),
            );
        }
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shareholder_type.required' => __('shareholder::validation.shareholder_type.required'),
            'shareholder_type.enum'     => __('shareholder::validation.shareholder_type.enum'),

            'user_id.exists'                => __('shareholder::validation.user_id.exists'),
            'representative_user_id.exists' => __('shareholder::validation.representative_user_id.exists'),

            'is_active.boolean' => __('shareholder::validation.is_active.boolean'),
        ];
    }

    /**
     * Get the human-readable field names for error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'shareholder_type'       => __('shareholder::attributes.shareholder_type'),
            'first_name_am'          => __('shareholder::attributes.first_name_am'),
            'first_name_en'          => __('shareholder::attributes.first_name_en'),
            'father_name_am'         => __('shareholder::attributes.father_name_am'),
            'father_name_en'         => __('shareholder::attributes.father_name_en'),
            'grandfather_name_am'    => __('shareholder::attributes.grandfather_name_am'),
            'grandfather_name_en'    => __('shareholder::attributes.grandfather_name_en'),
            'entity_name_am'         => __('shareholder::attributes.entity_name_am'),
            'entity_name_en'         => __('shareholder::attributes.entity_name_en'),
            'user_id'                => __('shareholder::attributes.user_id'),
            'representative_user_id' => __('shareholder::attributes.representative_user_id'),
            'is_active'              => __('shareholder::attributes.is_active'),
        ];
    }

    /**
     * Whether any of the named fields has a non-empty value.
     *
     * Named `hasAnyFilledField()` — not `anyFilled()` — to avoid the method
     * name collision with Illuminate\Http\Request::anyFilled().
     *
     * @param array<int, string> $fields
     * @return bool
     */
    private function hasAnyFilledField(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->filled($field)) {
                return true;
            }
        }

        return false;
    }
}