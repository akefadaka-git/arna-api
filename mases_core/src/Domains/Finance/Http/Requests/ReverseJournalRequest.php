<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReverseJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'journal_number' => ['required', 'string', 'max:64'],
            'effective_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
