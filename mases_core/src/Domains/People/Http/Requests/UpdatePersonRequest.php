<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return function_exists('mases_tenant_id') && mases_tenant_id() !== null;
    }

    public function rules(): array
    {
        return [
            'first_name_am' => ['sometimes', 'nullable', 'string', 'max:120'],
            'father_name_am' => ['sometimes', 'nullable', 'string', 'max:120'],
            'grandfather_name_am' => ['sometimes', 'nullable', 'string', 'max:120'],
            'first_name_en' => ['sometimes', 'nullable', 'string', 'max:120'],
            'father_name_en' => ['sometimes', 'nullable', 'string', 'max:120'],
            'grandfather_name_en' => ['sometimes', 'nullable', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(static function ($validator): void {
            $names = ['first_name_am', 'father_name_am', 'grandfather_name_am', 'first_name_en', 'father_name_en', 'grandfather_name_en'];
            $current = \MaaleArna\MASES\Core\Domains\People\Models\Person::query()
                ->where('public_id', request()->route('publicId'))
                ->where('status', 'active')
                ->whereHas('tenantAccesses', static fn ($query) => $query
                    ->where('tenant_id', mases_tenant_id())
                    ->where('status', 'active'))
                ->first();
            if ($current === null) {
                return;
            }
            $hasName = false;
            foreach ($names as $name) {
                $value = array_key_exists($name, request()->all()) ? request()->input($name) : $current->{$name};
                if (is_string($value) && trim($value) !== '') {
                    $hasName = true;
                    break;
                }
            }
            if (! $hasName) {
                $validator->errors()->add('name', 'At least one Amharic or English name component must remain.');
            }
        });
    }
}
