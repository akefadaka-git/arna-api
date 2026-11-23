<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\PublicContent\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class CorporateSiteEntry extends Model
{
    use SoftDeletes;
    protected $table = 'corporate_site_entries';

    protected $fillable = [
        'entry_type', 'slug_am', 'slug_en', 'translations', 'payload', 'seo',
        'status', 'version', 'is_enabled', 'archived_at', 'moderation_note', 'asset_path', 'asset_mime',
        'asset_size', 'scheduled_at', 'published_at', 'created_by', 'updated_by', 'reviewed_by',
    ];

    protected $casts = [
        'translations' => 'array',
        'payload' => 'array',
        'seo' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'asset_size' => 'integer',
        'version' => 'integer',
        'is_enabled' => 'boolean',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $entry): void {
            $entry->public_id ??= (string) Str::uuid();
            $entry->status = 'draft';
            $entry->version = 1;
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where('is_enabled', true)
            ->whereNull('archived_at');
    }
}
