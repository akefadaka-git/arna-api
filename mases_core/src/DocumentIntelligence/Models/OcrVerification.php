<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\DocumentIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;

final class OcrVerification extends Model
{
    protected $table = 'ocr_verifications';
    protected $fillable = ['tenant_id', 'result_id', 'verified_by', 'decision', 'notes', 'verified_at'];
    protected $casts = ['verified_at' => 'datetime'];

    public function result(): BelongsTo { return $this->belongsTo(OcrResult::class, 'result_id'); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
}
