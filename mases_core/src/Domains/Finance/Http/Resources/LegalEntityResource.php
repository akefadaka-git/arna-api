<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Http\\Resources;

use Illuminate\\Http\\Request;
use Illuminate\\Http\\Resources\\Json\\JsonResource;

/**
 * Stable API representation for a Finance legal entity.
 *
 * Keeps the existing model-backed response fields explicit rather than
 * exposing future internal model attributes automatically.
 *
 * @mixin \\MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\LegalEntity
 */
final class LegalEntityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'code' => $this->code,
            'name_am' => $this->name_am,
            'name_en' => $this->name_en,
            'base_currency' => $this->base_currency,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
