<?php

declare(strict_types=1);

namespace MaaleArna\\Arna\\Core\\Domains\\Finance\\Http\\Resources;

use Illuminate\\Http\\Request;
use Illuminate\\Http\\Resources\\Json\\JsonResource;

final class FinanceDimensionValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dimension_id' => $this->dimension_id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name_am' => $this->name_am,
            'name_en' => $this->name_en,
            'status' => $this->status,
            'effective_from' => $this->effective_from,
            'effective_to' => $this->effective_to,
        ];
    }
}
