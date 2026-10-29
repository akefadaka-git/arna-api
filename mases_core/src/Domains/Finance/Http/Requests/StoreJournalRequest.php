<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreJournalRequest extends FormRequest
{
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
            'lines.*.cash_flow_activity' => ['nullable', 'string', Rule::in(array_column(CashFlowActivity::cases(), 'value'))],
            'lines.*.cash_flow_classification_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
