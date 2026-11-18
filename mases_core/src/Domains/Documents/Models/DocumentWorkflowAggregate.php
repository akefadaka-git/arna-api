<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DocumentWorkflowAggregate extends Model
{
    protected $table = 'document_workflow_aggregates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'legal_entity_id' => 'integer',
            'organization_unit_id' => 'integer',
            'governance_body_id' => 'integer',
            'governance_position_id' => 'integer',
            'author_user_id' => 'integer',
            'submitted_file_id' => 'integer',
            'submitted_file_version' => 'integer',
            'issued_document_id' => 'integer',
            'last_transition_at' => 'immutable_datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentWorkflowEvent::class, 'workflow_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(\MaaleArna\MASES\Core\Auth\Models\User::class, 'author_user_id');
    }

    public function issuedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'issued_document_id');
    }
}
