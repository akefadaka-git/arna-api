<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;

final class FinanceAiProposal extends Model
{
    public const PROPOSED = 'proposed';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $table = 'finance_ai_proposals';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'requested_by', 'reviewed_by', 'agent_key',
        'task_type', 'task_template_version', 'model_provider', 'model_name',
        'model_version', 'status', 'title', 'summary', 'proposal', 'evidence_refs',
        'evidence_sha256', 'confidence', 'limitations', 'review_reason', 'reviewed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'requested_by' => 'integer',
        'reviewed_by' => 'integer',
        'proposal' => 'array',
        'evidence_refs' => 'array',
        'limitations' => 'array',
        'confidence' => 'decimal:4',
        'reviewed_at' => 'datetime',
    ];
}
