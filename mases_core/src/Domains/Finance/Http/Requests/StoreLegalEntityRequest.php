<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Http\\Requests;

use Illuminate\\Foundation\\Http\\FormRequest;
use Illuminate\\Validation\\Rule;
use MaaleArna\\MASES\\Core\\Tenancy\\Services\\TenantContextService;

final class StoreLegalEntityRequest extends FormRequest
{
    /**
     * Route middleware owns authentication, tenant membership, and the
     * finance.entity.manage permission. This request class owns payload
     * validation; it does not replace those authorization gates.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }

        if (is_string($this->input('base_currency'))) {
            $this->merge(['base_currency' => strtoupper(trim($this->input('base_currency')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContextService::class)->getTenantId();

        abort_if($tenantId === null, 403, 'A resolved tenant context is required.');

        return [
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('legal_entities', 'code')
                    ->where(fn ($query) => $query->where('tenant_id', (int) $tenantId)),
            ],
            'name_am' => ['nullable', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
            'base_currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
                Rule::in(['ETB']),
            ],
        ];
    }

    /**
     * Keep the public API validation contract explicit and stable.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The legal entity code may contain uppercase letters, numbers, dots, underscores, and hyphens, and must start with a letter or number.',
            'code.unique' => 'This legal entity code is already in use for the current tenant.',
            'name_en.required' => 'An English legal entity name is required.',
            'base_currency.in' => 'The base currency must be ETB until multi-currency accounting policy is approved.',
        ];
    }
}
