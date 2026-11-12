<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Finance\Support\FinanceCalendarDateAdapter;

final class StoreAccountingPeriodRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['starts_on', 'ends_on'] as $field) {
            $ethiopianField = $field . '_ec';
            if (!$this->filled($ethiopianField)) {
                continue;
            }

            if ($this->filled($field)) {
                throw ValidationException::withMessages([
                    $ethiopianField => 'Provide either the Gregorian date or the Ethiopian date, not both.',
                ]);
            }

            try {
                $this->merge([$field => app(FinanceCalendarDateAdapter::class)
                    ->toGregorianDate((string) $this->input($ethiopianField))]);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    $ethiopianField => 'The Ethiopian date is invalid. Use YYYY-MM-DD and a valid Ethiopian calendar day.',
                ]);
            }
        }
    }

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
            'starts_on_ec' => ['sometimes', 'string', 'regex:/^\d{1,4}-\d{1,2}-\d{1,2}$/'],
            'ends_on_ec' => ['sometimes', 'string', 'regex:/^\d{1,4}-\d{1,2}-\d{1,2}$/'],
        ];
    }
}
