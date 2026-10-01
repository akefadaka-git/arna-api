<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\ShareholderType;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * UpdateShareholderRequest
 *
 * Validates input for PATCH/PUT /shareholders/{shareholder}.
 *
 * =============================================================================
 * Helper naming — hasAnyFilledField vs anyFilled
 * =============================================================================
 * Same rationale as StoreShareholderRequest: the private helper is named
 * `hasAnyFilledField()` to avoid the visibility collision with
 * `Illuminate\Http\Request::anyFilled()`.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Requests
 */
final class UpdateShareholderRequest extends FormRequest
{
    private const MAX_NAME_LENGTH = 100;
    private const MAX_ENTITY_NAME_LENGTH = 200;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim whitespace from supplied string inputs.
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
     * Validation rules for an update.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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

            'is_active' => [
                'sometimes', 'boolean',
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
            $shareholder = $this->route('shareholder');

            if (!$shareholder instanceof Shareholder) {
                return;
            }

            $type = $shareholder->type();

            if ($type === null) {
                return;
            }

            if ($type === ShareholderType::Individual) {
                $this->validateIndividualUpdate($v);

                return;
            }

            $this->validateLegalEntityUpdate($v);
        });
    }

    /**
     * Conditional checks for updating an individual shareholder.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateIndividualUpdate(Validator $validator): void
    {
        if ($this->hasAnyFilledField(['entity_name_am', 'entity_name_en'])) {
            $validator->errors()->add(
                'entity_name_am',
                __('shareholder::validation.entity_name_forbidden_for_individual'),
            );
        }

        if ($this->wouldLeaveIndividualWithNoName()) {
            $validator->errors()->add(
                'first_name_am',
                __('shareholder::validation.individual_name_required'),
            );
        }
    }

    /**
     * Conditional checks for updating a legal-entity shareholder.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateLegalEntityUpdate(Validator $validator): void
    {
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

        if ($this->exists('representative_user_id') && $this->input('representative_user_id') === null) {
            $validator->errors()->add(
                'representative_user_id',
                __('shareholder::validation.representative_cannot_be_cleared'),
            );
        }

        if ($this->wouldLeaveEntityWithNoName()) {
            $validator->errors()->add(
                'entity_name_am',
                __('shareholder::validation.entity_name_required'),
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
            'user_id.exists'                => __('shareholder::validation.user_id.exists'),
            'representative_user_id.exists' => __('shareholder::validation.representative_user_id.exists'),
            'is_active.boolean'             => __('shareholder::validation.is_active.boolean'),
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
     * Whether any of the named fields has a non-empty value in the input.
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

    /**
     * Whether the update would leave the individual shareholder with no
     * name component at all.
     *
     * @return bool
     */
    private function wouldLeaveIndividualWithNoName(): bool
    {
        $shareholder = $this->route('shareholder');

        if (!$shareholder instanceof Shareholder) {
            return false;
        }

        $fields = [
            'first_name_am',
            'first_name_en',
            'father_name_am',
            'father_name_en',
        ];

        foreach ($fields as $field) {
            $value = $this->has($field)
                ? $this->input($field)
                : $shareholder->{$field};

            if (is_string($value) && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the update would leave the legal-entity shareholder with no
     * entity name at all.
     *
     * @return bool
     */
    private function wouldLeaveEntityWithNoName(): bool
    {
        $shareholder = $this->route('shareholder');

        if (!$shareholder instanceof Shareholder) {
            return false;
        }

        $fields = [
            'entity_name_am',
            'entity_name_en',
        ];

        foreach ($fields as $field) {
            $value = $this->has($field)
                ? $this->input($field)
                : $shareholder->{$field};

            if (is_string($value) && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}