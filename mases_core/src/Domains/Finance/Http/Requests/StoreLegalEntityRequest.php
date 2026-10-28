<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Http\\Requests;

use Illuminate\\Foundation\\Http\\FormRequest;

/**
 * Validates the payload shape for creating a Finance legal entity.
 *
 * Tenant authorization and permission checks remain route/controller
 * responsibilities. Tenant-scoped uniqueness is enforced by the Finance
 * service and the database constraint, so concurrent requests cannot bypass it.
 */
final class StoreLegalEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('code'))) {
            $normalized['code'] = strtoupper(trim($this->input('code')));
        }

        if (is_string($this->input('name_am'))) {
            $normalized['name_am'] = trim($this->input('name_am'));
        }

        if (is_string($this->input('name_en'))) {
            $normalized['name_en'] = trim($this->input('name_en'));
        }

        if (is_string($this->input('base_currency'))) {
            $normalized['base_currency'] = strtoupper(trim($this->input('base_currency')));
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
            ],
            'name_am' => ['nullable', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
            'base_currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
                'in:ETB',
            ],
        ];
    }

    /**
     * Validation messages use the Finance translation namespace, consistent
     * with the Amharic-first conventions in established domains.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => __('finance::validation.legal_entity_code_required'),
            'code.regex' => __('finance::validation.legal_entity_code_format'),
            'name_en.required' => __('finance::validation.legal_entity_name_en_required'),
            'base_currency.in' => __('finance::validation.base_currency_must_be_etb'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => __('finance::attributes.legal_entity_code'),
            'name_am' => __('finance::attributes.legal_entity_name_am'),
            'name_en' => __('finance::attributes.legal_entity_name_en'),
            'base_currency' => __('finance::attributes.base_currency'),
        ];
    }
}
