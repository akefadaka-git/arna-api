<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LinkPersonDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return function_exists('mases_tenant_id') && mases_tenant_id() !== null;
    }

    public function rules(): array
    {
        return [
            'file_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ];
    }
}
