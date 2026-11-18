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
 * Allocates the next number for the exact visible identifier scope:
 * tenant + BB + TT + full Ethiopian year.
 *
 * Call only inside the same DB transaction that inserts the issued document.
 */
final class DocumentNumberAllocator
{
    public function __construct(private readonly DocumentNumberFormatter $formatter)
    {
    }

    /**
     * @return array{bb_code:string,document_type_code:string,document_type_number:string,ec_year:int,sequence_number:int,canonical_number:string}
     */
    public function allocate(
        int $tenantId,
        int $legalEntityId,
        int $organizationUnitId,
        string $bbCode,
        string $documentTypeCode,
        int $ethiopianYear,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Document numbers must be allocated inside the document issuance transaction.');
        }

        $bb = trim($bbCode);
        if (!preg_match('/^[1-9][1-9]$/', $bb)) {
            throw new UnprocessableEntityHttpException('A registered two-digit BB code is required.');
        }

        $type = DocumentTypeRegistry::numericCode($documentTypeCode);
        $catalogueEntry = DB::table('document_type_catalogue')
            ->where('numeric_code', $type)
            ->where('is_active', true)
            ->first(['numeric_code']);
        if ($catalogueEntry === null) {
            throw new ConflictHttpException('The document type is not active in the controlled catalogue.');
        }
        if ($ethiopianYear < 1) {
            throw new UnprocessableEntityHttpException('A valid full Ethiopian Calendar year is required.');
        }

        $unit = DB::table('organization_units')
            ->where('tenant_id', $tenantId)
            ->where('id', $organizationUnitId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first(['id']);

        $entity = DB::table('legal_entities')
            ->where('tenant_id', $tenantId)
            ->where('id', $legalEntityId)
            ->where('status', 'active')
            ->first(['id']);

        if ($unit === null || $entity === null) {
            throw new UnprocessableEntityHttpException('The issuing unit must belong to the active legal entity in the current tenant.');
        }

        $assignment = DB::table('document_numbering_assignments')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('organization_unit_id', $organizationUnitId)
            ->where('bb_code', $bb)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first(['id']);

        if ($assignment === null) {
            throw new ConflictHttpException('The BB code is not actively assigned to this legal entity and organization unit.');
        }

        $scope = [
            'tenant_id' => $tenantId,
            'bb_code' => $bb,
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
            ->where($scope)
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
            'bb_code' => $bb,
            'document_type_code' => DocumentTypeRegistry::legacyCode($type),
            'document_type_number' => $type,
            'ec_year' => $ethiopianYear,
            'sequence_number' => $sequence,
            'canonical_number' => $this->formatter->format($bb, $type, $sequence, $ethiopianYear),
        ];
    }
}
