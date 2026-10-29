<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PersonRelationshipResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $related = $this->relatedPerson;

        return [
            'id' => (int) $this->getKey(),
            'related_public_id' => $related?->public_id,
            'related_name_am' => $related ? trim(implode(' ', array_filter([$related->first_name_am, $related->father_name_am, $related->grandfather_name_am]))) : null,
            'related_name_en' => $related ? trim(implode(' ', array_filter([$related->first_name_en, $related->father_name_en, $related->grandfather_name_en]))) : null,
            'relationship_type' => $this->relationship_type,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'status' => $this->status,
        ];
    }
}
