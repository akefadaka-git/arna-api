<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Organization\\Http\\Requests;

use Illuminate\\Validation\\Rule;

/**
 * Canonical validation rules for Organization HTTP endpoints.
 *
 * This class deliberately exposes rule arrays instead of FormRequest
 * subclasses: controllers authorize the requested action before invoking
 * validation, preventing unauthorized callers from using validation errors
 * as an endpoint/schema oracle.
 */
final class OrganizationRequestRules
{
    /** @return array<string, array<int, mixed>> */
    public static function unitIndex(): array
    {
        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function storeUnit(int $tenantId): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'legal_entity_id' => ['prohibited'],
            'code' => [
                'required', 'string', 'max:64',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('organization_units', 'code')->where(
                    static fn ($query) => $query->where('tenant_id', $tenantId)
                ),
            ],
            'unit_type' => ['required', Rule::in(\\MaaleArna\\MASES\\Core\\Domains\\Organization\\Policies\\OrganizationHierarchyPolicy::unitTypes())],
            'name_am' => ['nullable', 'string', 'max:180'],
            'name_en' => ['nullable', 'string', 'max:180'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function updateUnit(int $tenantId, int $unitId): array
    {
        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'legal_entity_id' => ['prohibited'],
            'code' => [
                'sometimes', 'required', 'string', 'max:64',
                'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('organization_units', 'code')->where(
                    static fn ($query) => $query->where('tenant_id', $tenantId)
                )->ignore($unitId),
            ],
            'unit_type' => ['sometimes', 'required', Rule::in(\\MaaleArna\\MASES\\Core\\Domains\\Organization\\Policies\\OrganizationHierarchyPolicy::unitTypes())],
            'name_am' => ['sometimes', 'nullable', 'string', 'max:180'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:180'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function archiveUnit(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:2000']];
    }

    /** @return array<string, array<int, mixed>> */
    public static function listAssignments(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }

    /** @return array<string, array<int, mixed>> */
    public static function storeAssignment(): array
    {
        return [
            'person_id' => ['required', 'integer', 'min:1'],
            'assignment_type' => ['required', 'string', 'max:48'],
            'title_am' => ['nullable', 'string', 'max:160'],
            'title_en' => ['nullable', 'string', 'max:160'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function updateAssignment(): array
    {
        return [
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(['active', 'ended', 'cancelled'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function auditEvents(): array
    {
        return [
            'subject_type' => ['sometimes', Rule::in(['unit', 'assignment'])],
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'action' => ['sometimes', 'string', 'max:48'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
