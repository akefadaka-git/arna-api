<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PersonDocumentLinkResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $file = $this->file;

        return [
            'file_id' => (int) $this->file_id,
            'display_name_am' => $file?->display_name_am,
            'display_name_en' => $file?->display_name_en,
            'original_name' => $file?->original_name,
            'mime_type' => $file?->mime_type,
            'size' => $file?->size,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
