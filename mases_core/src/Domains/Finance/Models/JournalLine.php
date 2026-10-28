<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class JournalLine extends Model
{
    protected $table = 'journal_lines';

    protected $fillable = [
        'tenant_id', 'legal_entity_id', 'journal_entry_id', 'account_id',
        'line_number', 'description', 'debit_amount', 'credit_amount',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'legal_entity_id' => 'integer',
        'journal_entry_id' => 'integer',
        'account_id' => 'integer',
        'line_number' => 'integer',
        'debit_amount' => 'decimal:4',
        'credit_amount' => 'decimal:4',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}
