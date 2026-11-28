<?php

declare(strict_types=1);

namespace MaaleArna\\Arna\\Core\\Domains\\Finance\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

final class JournalLineDimension extends Model
{
    protected $table = 'journal_line_dimensions';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'journal_entry_id',
        'line_number', 'dimension_id', 'dimension_value_id',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'journal_entry_id' => 'integer',
        'line_number' => 'integer',
        'dimension_id' => 'integer',
        'dimension_value_id' => 'integer',
    ];

    public function dimension(): BelongsTo
    {
        return $this->belongsTo(FinanceDimension::class, 'dimension_id');
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(FinanceDimensionValue::class, 'dimension_value_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
