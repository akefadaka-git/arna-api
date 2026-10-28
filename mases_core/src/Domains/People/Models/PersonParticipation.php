<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * Records participation without granting roles, permissions, or governance authority.
 */
final class PersonParticipation extends Model
{
    protected $table = 'person_participations';

    protected $fillable = [
        'tenant_id',
        'person_id',
        'participation_type',
        'title',
        'reference_code',
        'effective_from',
        'effective_to',
        'status',
        'reason',
        'deactivation_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
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
