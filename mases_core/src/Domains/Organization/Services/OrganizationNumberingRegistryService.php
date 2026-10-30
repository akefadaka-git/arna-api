<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Authoritative tenant-scoped BB registry.
 *
 * Allocations are append-only: retirement changes status/effective_until;
 * a branch, department or BB code is never reassigned.
 */
final class OrganizationNumberingRegistryService
{
    public function registerBranch(
        int $tenantId,
        int $organizationUnitId,
        string $branchCode,
        string $reason,
        ?int $actorId = null
    ): object {
        $branchCode = trim($branchCode);
        if ($tenantId < 1 || !preg_match('/^[1-9]$/', $branchCode) || mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['branch_code' => ['A branch code from 1 to 9 and a reason of at least five characters are required.']]);
        }

        return DB::transaction(function () use ($tenantId, $organizationUnitId, $branchCode, $reason, $actorId): object {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first() ?? throw new ConflictHttpException('Tenant not found.');
            $unit = DB::table('organization_units')
                ->where('tenant_id', $tenantId)->where('id', $organizationUnitId)
                ->where('status', 'active')->whereNull('deleted_at')->lockForUpdate()->first();
            if (!$unit) {
                throw ValidationException::withMessages(['organization_unit_id' => ['An active organization unit in this tenant is required.']]);
            }

            try {
                $id = DB::table('organization_branch_number_codes')->insertGetId([
                    'tenant_id' => $tenantId,
                    'organization_unit_id' => $organizationUnitId,
                    'branch_code' => $branchCode,
                    'status' => 'active',
                    'effective_from' => now('Africa/Addis_Ababa')->toDateString(),
                    'lifecycle_reason' => trim($reason),
                    'created_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $exception) {
                throw new ConflictHttpException('Branch code or branch unit is already registered; historical codes cannot be reused.', $exception);
            }

            return DB::table('organization_branch_number_codes')->where('id', $id)->first();
        }, 3);
    }

    public function registerDepartment(
        int $tenantId,
        int $organizationUnitId,
        ?int $legalEntityId,
        string $branchCode,
        string $departmentCode,
        string $reason,
        ?int $actorId = null
    ): object {
        $branchCode = trim($branchCode);
        $departmentCode = trim($departmentCode);
        if ($tenantId < 1 || !preg_match('/^[1-9]$/', $branchCode)
            || !preg_match('/^[1-9]$/', $departmentCode) || mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['bb_code' => ['Branch and department digits must each be from 1 to 9, with a reason of at least five characters.']]);
        }

        return DB::transaction(function () use ($tenantId, $organizationUnitId, $legalEntityId, $branchCode, $departmentCode, $reason, $actorId): object {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first() ?? throw new ConflictHttpException('Tenant not found.');
            $branch = DB::table('organization_branch_number_codes')
                ->where('tenant_id', $tenantId)->where('branch_code', $branchCode)
                ->where('status', 'active')->whereNull('effective_until')->lockForUpdate()->first();
            if (!$branch) {
                throw ValidationException::withMessages(['branch_code' => ['An active branch code in this tenant is required.']]);
            }

            $unit = DB::table('organization_units')
                ->where('tenant_id', $tenantId)->where('id', $organizationUnitId)
                ->where('status', 'active')->whereNull('deleted_at')->lockForUpdate()->first();
            if (!$unit || ($legalEntityId !== null && (int) $unit->legal_entity_id !== $legalEntityId)) {
                throw ValidationException::withMessages(['organization_unit_id' => ['The department unit must be active in this tenant and belong to the selected legal entity.']]);
            }
            if ($legalEntityId !== null) {
                $entityExists = DB::table('legal_entities')->where('tenant_id', $tenantId)
                    ->where('id', $legalEntityId)->where('status', 'active')->exists();
                if (!$entityExists) {
                    throw ValidationException::withMessages(['legal_entity_id' => ['An active legal entity in this tenant is required.']]);
                }
            }

            $bbCode = $branchCode . $departmentCode;
            try {
                $id = DB::table('organization_department_number_codes')->insertGetId([
                    'tenant_id' => $tenantId,
                    'branch_code' => $branchCode,
                    'department_code' => $departmentCode,
                    'bb_code' => $bbCode,
                    'organization_unit_id' => $organizationUnitId,
                    'legal_entity_id' => $legalEntityId,
                    'status' => 'active',
                    'effective_from' => now('Africa/Addis_Ababa')->toDateString(),
                    'lifecycle_reason' => trim($reason),
                    'created_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $exception) {
                throw new ConflictHttpException('BB code or department unit is already registered; historical codes cannot be reused.', $exception);
            }

            return DB::table('organization_department_number_codes')->where('id', $id)->first();
        }, 3);
    }

    /** @return array{organization_unit_id:int, legal_entity_id:?int, bb_code:string, branch_code:string, department_code:string} */
    public function resolveForIssuance(int $tenantId, int $legalEntityId, string $bbCode, bool $lockRows = false): array
    {
        if (!preg_match('/^[1-9][1-9]$/', $bbCode)) {
            throw ValidationException::withMessages(['bb_code' => ['BB must contain exactly two digits, each from 1 to 9.']]);
        }

        $query = DB::table('organization_department_number_codes as dc')
            ->join('organization_units as ou', function ($join): void {
                $join->on('ou.id', '=', 'dc.organization_unit_id')->on('ou.tenant_id', '=', 'dc.tenant_id');
            })
            ->where('dc.tenant_id', $tenantId)
            ->where('dc.legal_entity_id', $legalEntityId)
            ->where('dc.bb_code', $bbCode)
            ->where('dc.status', 'active')
            ->whereNull('dc.effective_until')
            ->where('ou.status', 'active')
            ->whereNull('ou.deleted_at')
            ->select(['dc.organization_unit_id', 'dc.legal_entity_id', 'dc.bb_code', 'dc.branch_code', 'dc.department_code']);

        if ($lockRows) {
            $query->lockForUpdate();
        }

        $record = $query->first();
        if (!$record) {
            throw ValidationException::withMessages(['bb_code' => ['BB must resolve to an active authoritative Organization registry entry for this tenant and legal entity.']]);
        }

        return [
            'organization_unit_id' => (int) $record->organization_unit_id,
            'legal_entity_id' => (int) $record->legal_entity_id,
            'bb_code' => (string) $record->bb_code,
            'branch_code' => (string) $record->branch_code,
            'department_code' => (string) $record->department_code,
        ];
    }
}
