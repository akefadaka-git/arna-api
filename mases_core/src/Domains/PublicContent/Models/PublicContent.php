<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\PublicContent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PublicContent extends Model
{
    public const TYPES = ['news', 'article', 'announcement', 'press_release'];
    public const STATUSES = [
        'pending_review', 'changes_requested', 'approved',
        'rejected', 'scheduled', 'published', 'unpublished',
    ];

    protected $table = 'public_contents';

    protected $fillable = [
        'content_type', 'title', 'body', 'author_name', 'author_email',
        'author_email_verified_at', 'contributor_verification_id', 'consented_at',
        'submitted_by', 'status', 'version', 'reviewed_by', 'reviewed_at',
        'review_note', 'scheduled_at', 'published_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'consented_at' => 'datetime',
        'author_email_verified_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $content): void {
            $content->public_id ??= (string) Str::uuid();
            $content->status = 'pending_review';
            $content->version = 1;
        });
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
