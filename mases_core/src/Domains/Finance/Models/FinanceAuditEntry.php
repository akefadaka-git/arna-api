<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;

final class FinanceAuditEntry extends Model
{
    protected $table = 'finance_audit_entries';
    protected $fillable = ['tenant_id','legal_entity_id','actor_id','event_type','subject_type','subject_id',
        'before_state','after_state','metadata','occurred_at'];
    protected $casts = ['tenant_id'=>'integer','legal_entity_id'=>'integer','actor_id'=>'integer','subject_id'=>'integer',
        'before_state'=>'array','after_state'=>'array','metadata'=>'array','occurred_at'=>'datetime'];
}
