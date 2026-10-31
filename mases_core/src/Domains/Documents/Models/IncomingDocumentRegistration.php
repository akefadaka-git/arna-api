<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\FileManagement\Models\File;

final class IncomingDocumentRegistration extends Model
{
    protected $table = 'incoming_document_registrations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'received_at' => 'immutable_datetime',
            'registered_at' => 'immutable_datetime',
            'file_version' => 'integer',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /** Internal identifier only; never derived from sender_reference. */
    public function getIncomingRegistrationIdAttribute(): string
    {
        return (string) $this->registration_uuid;
    }
}
