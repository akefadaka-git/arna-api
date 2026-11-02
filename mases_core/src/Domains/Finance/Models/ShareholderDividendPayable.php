<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

final class ShareholderDividendPayable extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAYABLE = 'payable';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_VOID = 'void';

    protected $table = 'finance_shareholder_dividend_payables';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'shareholder_id', 'distribution_reference',
        'declared_on', 'due_on', 'net_payable_amount', 'cash_paid_amount',
        'penalty_offset_amount', 'status', 'approval_reference', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'shareholder_id' => 'integer',
        'declared_on' => 'date',
        'due_on' => 'date',
        'net_payable_amount' => 'decimal:4',
        'cash_paid_amount' => 'decimal:4',
        'penalty_offset_amount' => 'decimal:4',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class, 'shareholder_id');
    }

    public function scopeForEntity(Builder $query, int $tenantId, int $entityId): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId);
    }

    public function remainingPayable(): string
    {
        return bcsub(
            bcsub((string) $this->net_payable_amount, (string) $this->cash_paid_amount, 4),
            (string) $this->penalty_offset_amount,
            4
        );
    }

    public function isEligibleForPenaltyOffset(): bool
    {
        return $this->status === self::STATUS_PAYABLE
            && $this->approved_by !== null
            && $this->approved_at !== null
            && $this->due_on !== null
            && $this->due_on->lessThanOrEqualTo(today())
            && bccomp($this->remainingPayable(), '0', 4) > 0;
    }
}
