<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-oriented adapter for the existing employees table.
 *
 * Employee lifecycle writes remain outside this identity model.
 */
final class EmployeeRecord extends Model
{
    protected $table = 'employees';

    protected $guarded = ['*'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }
}
