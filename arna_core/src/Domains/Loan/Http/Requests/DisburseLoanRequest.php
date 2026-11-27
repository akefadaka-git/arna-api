<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the optional disbursement description only; tenant lookup, loan state and authorization are enforced downstream.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Http\Requests
 * @version 1.0.0
 */
final class DisburseLoanRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized to make this request.
     *
     * Returns `true` unconditionally. Authorization is enforced by the
     * controller via `Gate::authorize('disburse', $loan)`. See the class
     * docblock §"Authorization".
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The validation rules for the request.
     *
     * See the class docblock §"Field reference" for the rationale.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * The custom validation messages.
     *
     * Messages are resolved through the Loan domain's translation
     * namespace. See the class docblock of `StoreLoanRequest` (A.60)
     * §"Deployment Notes" for the namespace registration contract.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'description.string' => __('loan::validation.description.string'),
            'description.max'    => __('loan::validation.description.max'),
        ];
    }

    /**
     * The human-readable attribute names.
     *
     * Attribute names are resolved through the Loan domain's translation
     * namespace, same as the messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'description' => __('loan::attributes.description'),
        ];
    }

    /**
     * Normalize input before validation.
     *
     * Trims leading and trailing whitespace from `description`. An
     * empty-after-trim value becomes `null`, so the service's
     * default-description fallback (`'Loan disbursement #%d'`) is used
     * consistently regardless of whether the caller submitted an empty
     * string or omitted the field.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('description')) {
            return;
        }

        $value = $this->input('description');

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                $value = null;
            }
        }

        $this->merge(['description' => $value]);
    }
}