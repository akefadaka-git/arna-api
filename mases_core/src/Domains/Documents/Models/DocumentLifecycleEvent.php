<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DocumentLifecycleEvent extends Model
{
    protected $table = 'document_lifecycle_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
