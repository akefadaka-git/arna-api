<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MaaleArna\MASES\Core\Files\Models\File;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class OcrResult extends Model
{
    protected $table = 'ocr_results';

    protected $fillable = [
        'tenant_id', 'job_id', 'file_id', 'file_version', 'engine_name',
        'engine_version', 'languages', 'full_text', 'mean_confidence',
        'source_checksum', 'result_checksum', 'metadata',
    ];

    protected $casts = ['file_version' => 'integer', 'mean_confidence' => 'float', 'metadata' => 'array'];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function file(): BelongsTo { return $this->belongsTo(File::class); }
    public function job(): BelongsTo { return $this->belongsTo(OcrProcessingJob::class, 'job_id'); }
    public function pages(): HasMany { return $this->hasMany(OcrPage::class, 'result_id'); }
    public function verifications(): HasMany { return $this->hasMany(OcrVerification::class, 'result_id'); }
    public function corrections(): HasMany { return $this->hasMany(OcrCorrection::class, 'result_id'); }
}
