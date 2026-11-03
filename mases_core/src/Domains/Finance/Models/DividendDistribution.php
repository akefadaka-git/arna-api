<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DividendDistribution extends Model
{
    protected $table = 'finance_dividend_distributions';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'resolution_date' => 'date',
        'declared_on' => 'date',
        'record_date' => 'date',
        'payment_due_on' => 'date',
        'declared_amount' => 'decimal:4',
        'withholding_rate_bps' => 'integer',
        'snapshot_row_count' => 'integer',
        'eligible_share_quantity' => 'decimal:4',
        'gross_total' => 'decimal:4',
        'withholding_total' => 'decimal:4',
        'net_total' => 'decimal:4',
        'snapshot_at' => 'datetime',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DividendDistributionItem::class, 'distribution_id');
    }
}
