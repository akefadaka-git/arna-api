<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FinanceDocumentLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_entity_id' => $this->legal_entity_id,
            'document_id' => $this->document_id,
            'source_domain' => $this->source_domain,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'purpose' => $this->purpose,
            'is_primary' => $this->is_primary,
            'linked_at' => $this->linked_at,
            'document' => $this->whenLoaded('document', fn () => [
                'canonical_number' => $this->document->canonical_number,
                'document_type_code' => $this->document->document_type_code,
                'document_type_number' => $this->document->document_type_number,
                'title' => $this->document->title,
                'status' => $this->document->status,
                'issued_at' => $this->document->issued_at,
                'file_id' => $this->document->file_id,
                'file_version' => $this->document->file_version,
                'file_sha256' => $this->document->file_sha256,
            ]),
        ];
    }
}
