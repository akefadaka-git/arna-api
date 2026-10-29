<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PersonContactPointResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $value = (string) $this->contact_value;
        $masked = match ($this->contact_type) {
            'email' => $this->maskEmail($value),
            'phone' => $this->maskPhone($value),
            default => '••••••',
        };

        return [
            'id' => (int) $this->getKey(),
            'contact_type' => $this->contact_type,
            'label' => $this->label,
            'masked_value' => $masked,
            'is_primary' => (bool) $this->is_primary,
            'is_verified' => $this->verified_at !== null,
            'verified_at' => $this->verified_at?->toISOString(),
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function maskEmail(string $value): string
    {
        $parts = explode('@', $value, 2);
        return count($parts) === 2 ? mb_substr($parts[0], 0, 1).'•••@'.$parts[1] : '••••••';
    }

    private function maskPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return $digits === '' ? '••••••' : '••••'.substr($digits, -min(3, strlen($digits)));
    }
}
