<?php

declare(strict_types=1);

namespace MaaleArna\\Arna\\Core\\Domains\\Finance\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

final class FinanceEntityProfile extends Model
{
    protected $table = 'finance_entity_profiles';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'functional_currency', 'reporting_currency',
        'transaction_currency_mode', 'display_calendar', 'timezone',
        'fiscal_year_start_month_ec', 'fiscal_year_start_day_ec',
        'tax_profile_status', 'accounting_policy_status',
        'document_numbering_policy_version',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'fiscal_year_start_month_ec' => 'integer',
        'fiscal_year_start_day_ec' => 'integer',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function scopeForEntity($query, int $tenantId, int $entityId)
    {
        return $query->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId);
    }
}
