<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Auth\Models\User;

/**
 * Explicit tenant visibility grant for a global Person record.
 */
final class PersonTenantAccess extends Model
{
    protected $table = 'person_tenant_accesses';

    protected $fillable = ['person_id', 'tenant_id', 'created_by', 'status'];

    protected $casts = [
        'person_id' => 'integer',
        'tenant_id' => 'integer',
        'created_by' => 'integer',
    ];

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
