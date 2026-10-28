<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LegalEntity extends Model
{
    protected $table = 'legal_entities';

    protected $fillable = [
        'tenant_id', 'code', 'name_am', 'name_en', 'base_currency', 'status',
    ];

    protected $casts = ['tenant_id' => 'integer'];

    public function accounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class, 'legal_entity_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class, 'legal_entity_id');
    }

    public function journals(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'legal_entity_id');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
