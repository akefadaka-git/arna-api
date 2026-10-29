<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;

final class FinanceConsolidationAccountMapping extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $table = 'finance_consolidation_account_mappings';
    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'ledger_account_id', 'consolidation_account_code',
        'account_type', 'status', 'created_by', 'reviewed_by', 'reviewed_at',
        'mapping_reason', 'review_reason',
    ];
    protected $casts = [
        'tenant_id' => 'integer', 'legal_entity_id' => 'integer', 'ledger_account_id' => 'integer',
        'created_by' => 'integer', 'reviewed_by' => 'integer', 'reviewed_at' => 'datetime',
    ];
}
