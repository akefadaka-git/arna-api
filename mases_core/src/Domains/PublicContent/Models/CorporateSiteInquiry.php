<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\PublicContent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CorporateSiteInquiry extends Model
{
    protected $table = 'corporate_site_inquiries';

    protected $fillable = [
        'inquiry_type', 'name', 'email', 'phone', 'subject', 'message',
        'status', 'assigned_to', 'internal_note', 'handled_at', 'consented_at',
    ];

    protected $casts = ['handled_at' => 'datetime', 'consented_at' => 'datetime', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::creating(static function (self $inquiry): void {
            $inquiry->public_id ??= (string) Str::uuid();
        });
    }
}
