<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class ShareholderImportSubmission extends Model
{
    protected $table = 'shareholder_import_submissions';

    protected $fillable = [
        'tenant_id',
        'status',
        'payload',
        'source_references',
        'row_count',
        'payload_sha256',
        'version',
        'submitted_by',
        'reviewed_by',
        'approved_by',
        'reviewed_row_count',
        'review_note',
        'review_evidence',
        'approval_authority_reference',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'payload' => 'array',
        'source_references' => 'array',
        'row_count' => 'integer',
        'version' => 'integer',
        'submitted_by' => 'integer',
        'reviewed_by' => 'integer',
        'approved_by' => 'integer',
        'reviewed_row_count' => 'integer',
        'review_evidence' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ShareholderImportDecision::class, 'submission_id')
            ->orderBy('sequence');
    }
}
