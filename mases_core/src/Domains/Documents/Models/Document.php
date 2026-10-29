<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Models;

use Illuminate\Database\Eloquent\Model;

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
        ];
    }
}
