<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class GovernanceMeeting extends Model
{
    use SoftDeletes;

    protected $table = 'governance_meetings';

    protected $fillable = [
        'tenant_id', 'governance_body_id', 'title_am', 'title_en', 'meeting_type',
        'status', 'scheduled_at', 'started_at', 'ended_at', 'location', 'meeting_mode',
        'quorum_snapshot_json', 'quorum_rule_snapshot', 'quorum_met', 'notes', 'approved_at', 'approved_by', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'governance_body_id' => 'integer',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'approved_at' => 'datetime',
        'approved_by' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'quorum_snapshot_json' => 'array',
        'quorum_rule_snapshot' => 'array',
        'quorum_met' => 'boolean',
    ];

    public function body(): BelongsTo
    {
        return $this->belongsTo(GovernanceBody::class, 'governance_body_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(\MaaleArna\MASES\Core\Auth\Models\User::class, 'approved_by');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function getDisplayTitleAttribute(): string
    {
        return (string) ($this->title_am ?: $this->title_en ?: ('Meeting #' . $this->getKey()));
    }
}
