<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAccountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'legal_entity_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:32'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ];
    }
}
