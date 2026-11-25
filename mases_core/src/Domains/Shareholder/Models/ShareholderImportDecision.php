<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class ShareholderImportDecision extends Model
{
    protected $table = 'shareholder_import_decisions';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'submission_id',
        'sequence',
        'decision_type',
        'from_status',
        'to_status',
        'actor_user_id',
        'note',
        'authority_reference',
        'source_references',
        'evidence',
        'payload_sha256',
        'created_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'submission_id' => 'integer',
        'sequence' => 'integer',
        'actor_user_id' => 'integer',
        'source_references' => 'array',
        'evidence' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Shareholder import decisions are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Shareholder import decisions are immutable.');
        });
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ShareholderImportSubmission::class, 'submission_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
