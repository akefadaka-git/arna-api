<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;

final class OcrCorrection extends Model
{
    protected $table = 'ocr_corrections';
    protected $fillable = ['tenant_id', 'result_id', 'corrected_by', 'page_number', 'original_text', 'corrected_text', 'reason'];
    protected $casts = ['page_number' => 'integer'];

    public function result(): BelongsTo { return $this->belongsTo(OcrResult::class, 'result_id'); }
    public function corrector(): BelongsTo { return $this->belongsTo(User::class, 'corrected_by'); }
}
