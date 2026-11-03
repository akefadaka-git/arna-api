<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;

final class OcrProcessingEvent extends Model
{
    protected $table = 'ocr_processing_events';
    protected $fillable = ['tenant_id', 'job_id', 'actor_id', 'event', 'details'];
    protected $casts = ['details' => 'array'];

    public function job(): BelongsTo { return $this->belongsTo(OcrProcessingJob::class, 'job_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
