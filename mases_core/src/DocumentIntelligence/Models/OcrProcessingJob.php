<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Files\Models\File;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class OcrProcessingJob extends Model
{
    protected $table = 'ocr_processing_jobs';

    protected $fillable = [
        'tenant_id', 'file_id', 'file_version', 'requested_by',
        'idempotency_key', 'languages', 'status', 'attempts',
        'failure_code', 'failure_message', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'file_version' => 'integer',
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function file(): BelongsTo { return $this->belongsTo(File::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function result(): HasOne { return $this->hasOne(OcrResult::class, 'job_id'); }
}
