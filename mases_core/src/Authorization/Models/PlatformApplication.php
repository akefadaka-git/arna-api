<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PlatformApplication Model
 *
 * Represents a user-facing application in the MASES platform.
 * Each application has a unique slug and domain, and is used
 * for application-level authorization and the launcher.
 *
 * Applications can have roles and permissions scoped to them.
 * Display names and descriptions are stored in both Amharic and English
 * to support the Amharic-first design with locale switching.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Seven fillable columns, four casts.
 *
 * 1.1.0  — the launcher readiness and presentation columns (Unit L-10c):
 *          · $fillable gains the four columns added to
 *            `platform_applications` by the L-9 migration:
 *
 *              is_frontend_ready  — boolean; a deployable frontend
 *                                   exists at the domain
 *              icon_key           — nullable string; the Lucide icon
 *                                   name for the launcher tile
 *              category           — nullable string; the grid's
 *                                   grouping key
 *              display_order      — unsigned integer; the tile's sort
 *                                   weight within its category
 *
 *          · $casts gains `'is_frontend_ready' => 'boolean'`. The
 *            column is a TINYINT(1) on MySQL; without the cast, the
 *            model returns it as `0`/`1` (int) and the launcher
 *            endpoint's JSON response carries integers where the
 *            frontend's LauncherApplication type declares booleans.
 *
 *          · WHY THIS IS A CORRECTION, NOT A FEATURE. The seeder
 *            (Unit L-10b) already includes the four columns in its
 *            update array. Eloquent's fill() silently discards
 *            unknown keys, so between L-10b and this unit the four
 *            columns remained at the migration's defaults after
 *            every seed. This unit closes that gap.
 *
 *          · No other change. The relationships (roles, permissions),
 *            the accessors (display_name, display_description), the
 *            scopes (active, bySlug, byDomain), and the isActive()
 *            helper are unchanged.
 *
 * @property int           $id
 * @property string        $slug                 Unique application slug
 * @property string|null   $name_am              Application name in Amharic
 * @property string|null   $name_en              Application name in English
 * @property string|null   $domain               Primary domain
 * @property string|null   $description_am       Description in Amharic
 * @property string|null   $description_en       Description in English
 * @property bool          $is_active            Whether the application is active
 * @property bool          $is_frontend_ready    Whether a deployable frontend exists
 * @property string|null   $icon_key             Lucide icon name for the launcher tile
 * @property string|null   $category             Launcher grid grouping key
 * @property int           $display_order        Tile sort weight within its category
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @package MaaleArna\MASES\Core\Authorization\Models
 */
final class PlatformApplication extends Model
{
    use SoftDeletes;
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'platform_applications';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name_am',
        'name_en',
        'domain',
        'description_am',
        'description_en',
        'is_active',
        'is_frontend_ready',
        'icon_key',
        'category',
        'display_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active'         => 'boolean',
        'is_frontend_ready' => 'boolean',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
        'deleted_at'        => 'datetime',
    ];

    /**
     * Get all roles scoped to this application.
     *
     * @return HasMany<Role>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'application_id');
    }

    /**
     * Get all permissions scoped to this application.
     *
     * @return HasMany<Permission>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'application_id');
    }

    /**
     * Determine if the application is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Determine if a deployable frontend exists at the application's
     * domain.
     *
     * Distinct from isActive(): an application can be registered as
     * part of the ecosystem (is_active = true) while no frontend has
     * been deployed at its domain (is_frontend_ready = false). The
     * launcher renders a tile for such an application only when the
     * second flag is also true; a future CR can decide what to render
     * for the intermediate case.
     *
     * @return bool
     */
    public function isFrontendReady(): bool
    {
        return $this->is_frontend_ready === true;
    }

    /**
     * Scope a query to only include active applications.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include applications whose frontend is
     * deployed.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFrontendReady($query)
    {
        return $query->where('is_frontend_ready', true);
    }

    /**
     * Scope a query to only include applications with a specific slug.
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
     * Scope a query to only include applications with a specific domain.
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
     * Get the application display name based on the current locale.
     * Falls back to the other language if the preferred one is missing.
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        $locale = app()->getLocale();

        if ($locale === 'am' && $this->name_am) {
            return $this->name_am;
        }

        if ($this->name_en) {
            return $this->name_en;
        }

        if ($this->name_am) {
            return $this->name_am;
        }

        return $this->slug;
    }

    /**
     * Get the application description based on the current locale.
     * Falls back to the other language if the preferred one is missing.
     *
     * @return string|null
     */
    public function getDisplayDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();

        if ($locale === 'am' && $this->description_am) {
            return $this->description_am;
        }

        if ($this->description_en) {
            return $this->description_en;
        }

        return $this->description_am;
    }
}