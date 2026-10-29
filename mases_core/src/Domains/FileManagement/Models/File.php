<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\FileManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * File Model
 *
 * Represents a file metadata record in the MASES platform.
 * Stores information about uploaded files, including ownership,
 * storage details, versioning, and localization of display names.
 *
 * Supports:
 * - Bilingual display names (Amharic and English) for user‑facing presentation.
 * - Original filename preservation.
 * - File versioning via self‑referencing parent_file_id.
 * - Soft deletes for archival.
 * - Status workflow: draft, published, archived.
 * - Public/private classification.
 * - Additional metadata stored as JSON.
 *
 * @property int           $id
 * @property int|null      $user_id             Owner user ID (nullable)
 * @property int|null      $tenant_id           Tenant ID (nullable)
 * @property string        $original_name       Original file name
 * @property string|null   $display_name_am     Display name in Amharic
 * @property string|null   $display_name_en     Display name in English
 * @property string        $name                Stored file name (hashed/UUID)
 * @property string|null   $mime_type           MIME type
 * @property string|null   $extension           File extension
 * @property int           $size                File size in bytes
 * @property string        $path                Storage path
 * @property string        $disk                Storage disk name
 * @property int           $version             Version number
 * @property int|null      $parent_file_id      Parent file ID (for versioning)
 * @property string        $status              File status ('draft','published','archived')
 * @property bool          $is_public           Whether the file is public
 * @property array|null    $metadata            Additional metadata (JSON)
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @package MaaleArna\MASES\Core\Domains\FileManagement\Models
 */
final class File extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'files';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'tenant_id',
        'original_name',
        'display_name_am',
        'display_name_en',
        'name',
        'mime_type',
        'extension',
        'size',
        'path',
        'disk',
        'version',
        'parent_file_id',
        'status',
        'is_public',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata'   => 'array',
        'is_public'  => 'boolean',
        'version'    => 'integer',
        'size'       => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the owner of the file.
     *
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the tenant context of the file.
     *
     * @return BelongsTo<Tenant, self>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Get the parent file (previous version).
     *
     * @return BelongsTo<File, self>
     */
    public function parentFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'parent_file_id');
    }

    /**
     * Get all child files (newer versions).
     *
     * @return HasMany<File>
     */
    public function childFiles(): HasMany
    {
        return $this->hasMany(File::class, 'parent_file_id');
    }

    /**
     * Scope a query to only include files with a specific status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include published files.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope a query to only include public files.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope a query to only include files owned by a specific user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include files within a specific tenant.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include the latest version of each file.
     * This is a placeholder; actual logic may require grouping.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLatestVersions($query)
    {
        // In a real implementation, this would use a subquery to get max version per group.
        // For simplicity, we assume the latest version has parent_file_id = null.
        return $query->whereNull('parent_file_id');
    }

    /**
     * Determine if the file is published.
     *
     * @return bool
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Determine if the file is public.
     *
     * @return bool
     */
    public function isPublic(): bool
    {
        return $this->is_public === true;
    }

    /**
     * Get the file's display name based on current locale.
     * Falls back to the other language if the preferred one is missing.
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        $locale = app()->getLocale();

        if ($locale === 'am' && $this->display_name_am) {
            return $this->display_name_am;
        }

        if ($this->display_name_en) {
            return $this->display_name_en;
        }

        if ($this->display_name_am) {
            return $this->display_name_am;
        }

        // Fallback to original name if no display name is set.
        return $this->original_name;
    }
}