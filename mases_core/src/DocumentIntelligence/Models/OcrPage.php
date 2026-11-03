<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OcrPage extends Model
{
    protected $table = 'ocr_pages';
    protected $fillable = ['tenant_id', 'result_id', 'page_number', 'text', 'mean_confidence', 'blocks'];
    protected $casts = ['page_number' => 'integer', 'mean_confidence' => 'float', 'blocks' => 'array'];

    public function result(): BelongsTo { return $this->belongsTo(OcrResult::class, 'result_id'); }
}
