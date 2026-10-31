<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Document extends Model
{
    protected $table = 'documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'issued_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'ec_year' => 'integer',
            'sequence_number' => 'integer',
            'file_version' => 'integer',
            'replaces_document_id' => 'integer',
        ];
    }
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_document_id');
    }

    /** Always expose the persisted canonical value; never reconstruct it. */
    public function getDisplayNumberAttribute(): string
    {
        return (string) $this->canonical_number;
    }

}
