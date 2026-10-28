<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * Tenant-scoped contact data. Values are encrypted at rest and are never
 * treated as verified unless a separate verification workflow sets verified_at.
 */
final class PersonContactPoint extends Model
{
    protected $table = 'person_contact_points';

    protected $fillable = [
        'tenant_id',
        'person_id',
        'contact_type',
        'label',
        'contact_value',
        'is_primary',
        'verified_at',
        'status',
        'deactivation_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'contact_value' => 'encrypted',
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
