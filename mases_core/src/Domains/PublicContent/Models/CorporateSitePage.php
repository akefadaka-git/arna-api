<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\PublicContent\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CorporateSitePage extends Model
{
    protected $table = 'corporate_site_pages';

    protected $fillable = [
        'page_key', 'slug_am', 'slug_en', 'translations', 'sections', 'seo',
        'status', 'version', 'scheduled_at', 'published_at', 'created_by',
        'updated_by', 'reviewed_by',
    ];

    protected $casts = [
        'translations' => 'array',
        'sections' => 'array',
        'seo' => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $page): void {
            $page->public_id ??= (string) Str::uuid();
            $page->status = 'draft';
            $page->version = 1;
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(function (Builder $window): void {
                $window->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            });
    }
}
