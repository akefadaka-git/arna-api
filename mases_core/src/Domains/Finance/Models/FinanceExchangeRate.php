<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Models;

use Illuminate\\Database\\Eloquent\\Model;

final class FinanceExchangeRate extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $table = 'finance_exchange_rates';
    protected $fillable = [
        'tenant_id', 'base_currency', 'quote_currency', 'rate', 'effective_on',
        'source', 'source_reference', 'status', 'proposal_version', 'created_by', 'reviewed_by',
        'reviewed_at', 'proposal_reason', 'review_reason',
    ];
    protected $casts = [
        'tenant_id' => 'integer', 'effective_on' => 'date', 'proposal_version' => 'integer',
        'created_by' => 'integer', 'reviewed_by' => 'integer', 'reviewed_at' => 'datetime',
    ];
}
