<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StorePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return function_exists('mases_tenant_id') && mases_tenant_id() !== null;
    }

    public function rules(): array
    {
        return [
            'first_name_am' => ['nullable', 'string', 'max:120'],
            'father_name_am' => ['nullable', 'string', 'max:120'],
            'grandfather_name_am' => ['nullable', 'string', 'max:120'],
            'first_name_en' => ['nullable', 'string', 'max:120'],
            'father_name_en' => ['nullable', 'string', 'max:120'],
            'grandfather_name_en' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(static function ($validator): void {
            $names = ['first_name_am', 'father_name_am', 'grandfather_name_am', 'first_name_en', 'father_name_en', 'grandfather_name_en'];
            foreach ($names as $name) {
                if (is_string(request()->input($name)) && trim(request()->input($name)) !== '') {
                    return;
                }
            }
            $validator->errors()->add('name', 'At least one Amharic or English name component is required.');
        });
    }
}
