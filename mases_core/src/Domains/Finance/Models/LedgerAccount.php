<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LedgerAccount extends Model
{
    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];
    public const NORMAL_BALANCES = ['debit', 'credit'];
    public const TAX_CATEGORIES = ['taxable', 'non_taxable', 'exempt', 'zero_rated', 'out_of_scope', 'undetermined'];

    protected $table = 'ledger_accounts';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'parent_id', 'code', 'name_am', 'name_en',
        'account_type', 'normal_balance', 'is_posting_allowed', 'status',
        // Canonical/system-managed metadata is intentionally not mass assignable.
        // Only the controlled CoA seeder may write these fields directly.
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'parent_id' => 'integer',
        'is_posting_allowed' => 'boolean',
        'is_control_account' => 'boolean',
        'is_system_account' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function scopeForEntity($query, int $tenantId, int $entityId)
    {
        return $query->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId);
    }
}
