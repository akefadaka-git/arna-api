<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\People\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\Arna\Core\Domains\FileManagement\Models\File;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;

final class PersonDocumentLink extends Model
{
    protected $table = 'person_document_links';

    protected $fillable = [
        'tenant_id',
        'person_id',
        'file_id',
        'linked_by',
        'link_reason',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
