<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GovernanceSettlementAccountMapping extends Model
{
    public const ROLE_FINE_RECEIVABLE = 'fine_receivable';
    public const ROLE_CASH_BANK = 'cash_bank';
    public const ROLE_DIVIDEND_PAYABLE = 'dividend_payable';
    public const ROLE_PENALTY_INCOME = 'penalty_income';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REVOKED = 'revoked';

    protected $table = 'governance_settlement_account_mappings';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'account_role', 'ledger_account_id',
        'status', 'configuration_reason', 'created_by', 'approved_by',
        'approved_at', 'revoked_by', 'revoked_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'ledger_account_id' => 'integer',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'revoked_by' => 'integer',
        'revoked_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function scopeForEntity(Builder $query, int $tenantId, int $entityId): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED)
            ->whereNotNull('approved_by')->whereNotNull('approved_at');
    }

    public static function roles(): array
    {
        return [self::ROLE_FINE_RECEIVABLE, self::ROLE_CASH_BANK, self::ROLE_DIVIDEND_PAYABLE, self::ROLE_PENALTY_INCOME];
    }
}
