<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

final class StoreLedgerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(TenantContextService::class)->getTenantId() !== null;
    }

    public function rules(): array
    {
        $tenantId = (int) app(TenantContextService::class)->getTenantId();
        $entityId = (int) $this->input('legal_entity_id', 0);

        return [
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
                Rule::unique('ledger_accounts', 'code')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId))],
            'name_am' => ['nullable', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
            'account_type' => ['required', Rule::in(LedgerAccount::TYPES)],
            'normal_balance' => ['required', Rule::in(LedgerAccount::NORMAL_BALANCES)],
            'is_posting_allowed' => ['sometimes', 'boolean'],
        ];
    }
}
