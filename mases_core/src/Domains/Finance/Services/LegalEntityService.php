<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;

/**
 * Owns legal-entity write invariants for the Finance domain.
 *
 * HTTP requests validate payload shape; this service normalizes values,
 * applies tenant scope, and persists changes transactionally. Database
 * constraints remain authoritative for concurrent uniqueness checks.
 *
 * Authorization is intentionally outside this service and must be enforced
 * by the route/controller policy boundary before calling it.
 */
final class LegalEntityService
{
    /**
     * Create a legal entity for an already-resolved tenant.
     *
     * @param array<string, mixed> $data Validated request data.
     */
    public function create(int $tenantId, array $data): LegalEntity
    {
        if ($tenantId < 1) {
            abort(403, 'A resolved tenant context is required.');
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $nameEn = trim((string) ($data['name_en'] ?? ''));
        $nameAm = isset($data['name_am']) ? trim((string) $data['name_am']) : null;
        $currency = strtoupper(trim((string) ($data['base_currency'] ?? 'ETB')));

        if ($code === '' || $nameEn === '') {
            throw ValidationException::withMessages([
                'code' => [__('finance::validation.legal_entity_code_required')],
                'name_en' => [__('finance::validation.legal_entity_name_en_required')],
            ]);
        }

        if ($currency !== 'ETB') {
            throw ValidationException::withMessages([
                'base_currency' => [__('finance::validation.base_currency_must_be_etb')],
            ]);
        }

        try {
            return DB::transaction(static function () use ($tenantId, $code, $nameAm, $nameEn, $currency): LegalEntity {
                return LegalEntity::query()->create([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name_am' => $nameAm === '' ? null : $nameAm,
                    'name_en' => $nameEn,
                    'base_currency' => $currency,
                    'status' => 'active',
                ]);
            });
        } catch (QueryException $exception) {
            // The tenant/code unique index is the race-safe authority. Convert
            // duplicate-key failures into the same field-level API contract.
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            $message = strtolower($exception->getMessage());
            $isUniqueViolation = in_array($sqlState, ['23000', '23505'], true)
                || in_array($driverCode, [19, 1062, 2601, 2627], true);

            if ($isUniqueViolation && str_contains($message, 'legal_entities')) {
                throw ValidationException::withMessages([
                    'code' => [__('finance::validation.legal_entity_code_unique')],
                ]);
            }

            throw $exception;
        }
    }
}
