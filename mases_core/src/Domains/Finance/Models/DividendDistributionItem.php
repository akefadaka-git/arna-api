<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DividendDistributionItem extends Model
{
    protected $table = 'finance_dividend_distribution_items';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'tenant_id' => 'integer',
        'distribution_id' => 'integer',
        'shareholder_id' => 'integer',
        'eligible_share_quantity' => 'decimal:4',
        'weighted_share_quantity' => 'decimal:4',
        'gross_amount' => 'decimal:4',
        'withholding_amount' => 'decimal:4',
        'net_amount' => 'decimal:4',
        'payable_id' => 'integer',
        'tax_assessment_id' => 'integer',
        'tax_rate_bps' => 'integer',
    ];

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(DividendDistribution::class, 'distribution_id');
    }
}
