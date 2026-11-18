<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Files\Models\File;

final class GovernanceElectionCandidateEvidence extends Model
{
    protected $table = 'governance_election_candidate_evidence';

    protected $fillable = [
        'tenant_id', 'election_id', 'candidate_id', 'file_id', 'evidence_category',
        'content_sha256', 'submitted_by', 'status', 'reviewed_by', 'reviewed_at', 'review_reason',
    ];

    protected $casts = [
        'tenant_id' => 'integer', 'election_id' => 'integer', 'candidate_id' => 'integer',
        'file_id' => 'integer', 'submitted_by' => 'integer', 'reviewed_by' => 'integer',
        'reviewed_at' => 'immutable_datetime',
    ];

    public function candidate(): BelongsTo { return $this->belongsTo(GovernanceElectionCandidate::class, 'candidate_id'); }
    public function file(): BelongsTo { return $this->belongsTo(File::class, 'file_id'); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
