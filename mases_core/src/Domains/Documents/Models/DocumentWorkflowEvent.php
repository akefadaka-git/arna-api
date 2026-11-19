<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class DocumentWorkflowEvent extends Model
{
    public $timestamps = false;

    protected $table = 'document_workflow_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Document workflow events are append-only.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Document workflow events are append-only.');
        });
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(DocumentWorkflowAggregate::class, 'workflow_id');
    }
}
