<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalEntityResource extends JsonResource
{
    /** @return array<string, mixed> */
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
            'accounting_profile' => $this->whenLoaded('profile', fn () => [
                'functional_currency' => $this->profile->functional_currency,
                'reporting_currency' => $this->profile->reporting_currency,
                'transaction_currency_mode' => $this->profile->transaction_currency_mode,
                'display_calendar' => $this->profile->display_calendar,
                'timezone' => $this->profile->timezone,
                'fiscal_year_start_month_ec' => $this->profile->fiscal_year_start_month_ec,
                'fiscal_year_start_day_ec' => $this->profile->fiscal_year_start_day_ec,
                'tax_profile_status' => $this->profile->tax_profile_status,
                'accounting_policy_status' => $this->profile->accounting_policy_status,
                'document_numbering_policy_version' => $this->profile->document_numbering_policy_version,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
