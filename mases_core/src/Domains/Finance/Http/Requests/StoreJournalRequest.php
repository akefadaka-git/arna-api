<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Finance\Support\FinanceCalendarDateAdapter;

final class StoreJournalRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (!$this->filled('effective_date_ec')) {
            return;
        }

        if ($this->filled('effective_date')) {
            throw ValidationException::withMessages([
                'effective_date_ec' => 'Provide either the Gregorian effective date or the Ethiopian effective date, not both.',
            ]);
        }

        try {
            $this->merge(['effective_date' => app(FinanceCalendarDateAdapter::class)
                ->toGregorianDate((string) $this->input('effective_date_ec'))]);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'effective_date_ec' => 'The Ethiopian effective date is invalid. Use YYYY-MM-DD and a valid Ethiopian calendar day.',
            ]);
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
            'journal_number' => ['required', 'string', 'max:64'],
            'effective_date' => ['required', 'date'],
            'effective_date_ec' => ['sometimes', 'string', 'regex:/^\d{1,4}-\d{1,2}-\d{1,2}$/'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['ETB'])],
            'description' => ['required', 'string', 'max:500'],
            'source_type' => ['nullable', 'string', 'max:64'],
            'source_id' => ['nullable', 'string', 'max:128'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit_amount' => ['required', 'string', 'regex:/^\d{1,16}(?:\.\d{1,4})?$/'],
            'lines.*.credit_amount' => ['required', 'string', 'regex:/^\d{1,16}(?:\.\d{1,4})?$/'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
