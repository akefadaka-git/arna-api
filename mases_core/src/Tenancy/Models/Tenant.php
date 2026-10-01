<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Tenancy\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\App;
use Database\Factories\TenantFactory;

/**
 * Tenant Model
 *
 * Represents a tenant in the MASES multi‑tenant platform.
 * Each tenant is an isolated organisational unit with its own users,
 * roles, and data.
 *
 * The model supports:
 * - Bilingual display names (Amharic and English) with locale‑aware fallback.
 * - Soft deletes (tenants can be archived without permanent deletion).
 * - A settings field for tenant‑specific configuration (stored as JSON).
 * - Relationships to users via the tenant_memberships pivot table.
 * - Scopes for active tenants and for filtering by slug or domain.
 *
 * @property int           $id                 The tenant identifier.
 * @property string|null   $name_am            Tenant name in Amharic.
 * @property string|null   $name_en            Tenant name in English.
 * @property string        $slug               Unique slug for URL/domain identification.
 * @property string|null   $domain             Primary domain (e.g., tenant.maalearna.com.et).
 * @property array         $settings           JSON settings for the tenant.
 * @property bool          $is_active          Whether the tenant is active.
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @package MaaleArna\MASES\Core\Tenancy\Models
 */
final class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tenants';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name_am',
        'name_en',
        'slug',
        'domain',
        'settings',
        'is_active',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'settings'   => 'array',
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        // No sensitive data by default; settings may contain
        // additional information but is intentionally exposed.
    ];

    /**
     * Get the users that belong to this tenant.
     *
     * This is a many‑to‑many relationship through the tenant_memberships table.
     * Each user may belong to multiple tenants, and each tenant may have many users.
     *
     * @return BelongsToMany<\MaaleArna\MASES\Core\Auth\Models\User>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            \MaaleArna\MASES\Core\Auth\Models\User::class,
            'tenant_memberships',
            'tenant_id',
            'user_id'
        )->withTimestamps();
    }

    /**
     * Get the membership records for this tenant.
     *
     * If a dedicated TenantMembership model is used, this relationship
     * provides access to pivot‑specific data (joined_at, settings, etc.).
     *
     * @return HasMany<\MaaleArna\MASES\Core\Tenancy\Models\TenantMembership>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class, 'tenant_id');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    protected static function newFactory()
    {
        return TenantFactory::new();
    }

    /**
     * Scope a query to only include active tenants.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by tenant slug.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $slug
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }

    /**
     * Scope a query to filter by tenant domain.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $domain
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    /**
     * Get the display name of the tenant based on the current application locale.
     * Falls back to the other language if the preferred one is missing.
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        $locale = App::getLocale();

        if ($locale === 'am' && $this->name_am) {
            return $this->name_am;
        }

        if ($this->name_en) {
            return $this->name_en;
        }

        if ($this->name_am) {
            return $this->name_am;
        }

        // Last resort: use the slug as a readable identifier.
        return $this->slug;
    }

    /**
     * Get the tenant's primary domain.
     *
     * @return string|null
     */
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * Determine if the tenant is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Determine if the tenant is archived (soft deleted).
     *
     * @return bool
     */
    public function isArchived(): bool
    {
        return $this->trashed();
    }
}