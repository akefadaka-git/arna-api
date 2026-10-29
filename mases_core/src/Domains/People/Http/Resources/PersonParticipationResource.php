<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PersonParticipationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'participation_type' => $this->participation_type,
            'title' => $this->title,
            'reference_code' => $this->reference_code,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'status' => $this->status,
        ];
    }
}
