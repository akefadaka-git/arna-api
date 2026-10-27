<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;

/**
 * Canonical natural-person identity, deliberately separate from credentials,
 * tenant memberships, employment, ownership and governance authority.
 */
final class Person extends Model
{
    use SoftDeletes;

    protected $table = 'people';

    protected $fillable = [
        'first_name_am',
        'father_name_am',
        'grandfather_name_am',
        'first_name_en',
        'father_name_en',
        'grandfather_name_en',
        'status',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Person $person): void {
            if (blank($person->public_id)) {
                $person->public_id = (string) Str::uuid();
            }
        });

        static::updating(function (Person $person): void {
            if ($person->isDirty('public_id')) {
                throw new \DomainException('A Person public UUID is immutable.');
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(\Illuminate\Database\Eloquent\Model::class, 'person_id')
            ->setModel(new class extends Model {
                protected $table = 'employees';
            });
    }

    public function shareholders(): HasMany
    {
        return $this->hasMany(\MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder::class, 'person_id');
    }

    public function governanceMemberships(): HasMany
    {
        return $this->hasMany(\MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership::class, 'person_id');
    }

    public function scopeWithPublicId(Builder $query, string $publicId): Builder
    {
        return $query->where('public_id', $publicId);
    }
}
