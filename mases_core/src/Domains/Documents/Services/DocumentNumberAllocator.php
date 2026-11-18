<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use MaaleArna\MASES\Core\Domains\Documents\Registries\DocumentTypeRegistry;

/**
 * Allocates within tenant + EE + DD + TT + full EC year.
 * Must run inside the same transaction that persists document issuance.
 */
final class DocumentNumberAllocator
{
    public function __construct(private readonly DocumentNumberFormatter $formatter)
    {
    }

    /**
     * @return array{
     *   legal_entity_id:int,
     *   organization_unit_id:int,
     *   entity_code:string,
     *   department_code:string,
     *   document_type_code:string,
     *   document_type_number:string,
     *   ec_year:int,
     *   sequence_number:int,
     *   canonical_number:string
     * }
     */
    public function allocate(
        int $tenantId,
        int $legalEntityId,
        int $organizationUnitId,
        string $documentTypeCode,
        int $ethiopianYear,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Document numbers must be allocated inside the document issuance transaction.');
        }

        if ($tenantId < 1 || $legalEntityId < 1 || $organizationUnitId < 1) {
            throw new UnprocessableEntityHttpException('A valid tenant, legal entity and issuing department are required.');
        }

        if ($ethiopianYear < 1000 || $ethiopianYear > 9999) {
            throw new UnprocessableEntityHttpException('A full four-digit Ethiopian Calendar year is required.');
        }

        $type = DocumentTypeRegistry::numericCode($documentTypeCode);
        $catalogueEntry = DB::table('document_type_catalogue')
            ->where('numeric_code', $type)
            ->where('is_active', true)
            ->first(['numeric_code']);

        if ($catalogueEntry === null) {
            throw new ConflictHttpException('The document type is not active in the controlled catalogue.');
        }

        // Lock the source records before resolving the stable code assignments.
        $entity = DB::table('legal_entities')
            ->where('tenant_id', $tenantId)
            ->where('id', $legalEntityId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first(['id']);

        $unit = DB::table('organization_units')
            ->where('tenant_id', $tenantId)
            ->where('id', $organizationUnitId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['id']);

        if ($entity === null || $unit === null) {
            throw new UnprocessableEntityHttpException('The issuing department must belong to the active legal entity in the current tenant.');
        }

        $entityAssignment = DB::table('document_entity_code_assignments')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first(['entity_code']);

        $departmentAssignment = DB::table('document_department_code_assignments')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('organization_unit_id', $organizationUnitId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first(['entity_code', 'department_code']);

        if ($entityAssignment === null || $departmentAssignment === null) {
            throw new ConflictHttpException('The legal entity and issuing department must both have active approved numbering codes.');
        }

        $entityCode = (string) $entityAssignment->entity_code;
        $departmentCode = (string) $departmentAssignment->department_code;

        if ($departmentAssignment->entity_code !== $entityCode) {
            throw new ConflictHttpException('The department code assignment does not match the legal entity code assignment.');
        }

        $scope = [
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'organization_unit_id' => $organizationUnitId,
            'entity_code' => $entityCode,
            'department_code' => $departmentCode,
            'document_type_number' => $type,
            'ec_year' => $ethiopianYear,
        ];
        $now = now();

        try {
            DB::table('document_number_counters')->insertOrIgnore($scope + [
                'next_sequence' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            throw new ConflictHttpException('The canonical numbering scope could not be initialized.', $exception);
        }

        $counter = DB::table('document_number_counters')
            ->where('tenant_id', $tenantId)
            ->where('entity_code', $entityCode)
            ->where('department_code', $departmentCode)
            ->where('document_type_number', $type)
            ->where('ec_year', $ethiopianYear)
            ->lockForUpdate()
            ->first();

        if ($counter === null || !(bool) $counter->is_active) {
            throw new ConflictHttpException('The canonical numbering scope is unavailable or disabled.');
        }

        $sequence = (int) $counter->next_sequence;
        if ($sequence < 1 || $sequence > DocumentNumberFormatter::MAX_SEQUENCE) {
            throw new ConflictHttpException('The numbering scope has exhausted its four-digit sequence.');
        }

        DB::table('document_number_counters')
            ->where('id', $counter->id)
            ->update(['next_sequence' => $sequence + 1, 'updated_at' => $now]);

        return [
            'legal_entity_id' => $legalEntityId,
            'organization_unit_id' => $organizationUnitId,
            'entity_code' => $entityCode,
            'department_code' => $departmentCode,
            'document_type_code' => DocumentTypeRegistry::legacyCode($type),
            'document_type_number' => $type,
            'ec_year' => $ethiopianYear,
            'sequence_number' => $sequence,
            'canonical_number' => $this->formatter->format($entityCode, $departmentCode, $type, $ethiopianYear, $sequence),
        ];
    }
}
