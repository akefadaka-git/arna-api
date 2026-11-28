<?php

declare(strict_types=1);

namespace MaaleArna\\Arna\\Core\\Domains\\Finance\\Http\\Resources;

use Illuminate\\Http\\Request;
use Illuminate\\Http\\Resources\\Json\\JsonResource;

final class FinanceDimensionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_entity_id' => $this->legal_entity_id,
            'code' => $this->code,
            'dimension_type' => $this->dimension_type,
            'name_am' => $this->name_am,
            'name_en' => $this->name_en,
            'is_required' => $this->is_required,
            'status' => $this->status,
            'values_count' => $this->whenCounted('values'),
        ];
    }
}
