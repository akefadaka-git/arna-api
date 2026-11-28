<?php

declare(strict_types=1);

namespace MaaleArna\\Arna\\Core\\Domains\\Finance\\Services;

use Illuminate\\Database\\QueryException;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Validation\\ValidationException;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Events\\LegalEntityCreated;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Models\\FinanceEntityProfile;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Models\\LegalEntity;

/**
 * Owns legal-entity write invariants for the Finance domain.
 * Entity and its conservative accounting profile are created atomically.
 */
final class LegalEntityService
{
    /** @param array<string, mixed> $data Validated data. */
    public function create(int $tenantId, array $data, int $actorId): LegalEntity
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
            return DB::transaction(function () use ($tenantId, $code, $nameAm, $nameEn, $currency, $actorId): LegalEntity {
                $entity = LegalEntity::query()->create([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name_am' => $nameAm === '' ? null : $nameAm,
                    'name_en' => $nameEn,
                    'base_currency' => $currency,
                    'status' => 'active',
                ]);

                FinanceEntityProfile::query()->create([
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $entity->id,
                    'functional_currency' => 'ETB',
                    'reporting_currency' => 'ETB',
                    'transaction_currency_mode' => 'base_only',
                    'display_calendar' => 'EC',
                    'timezone' => 'Africa/Addis_Ababa',
                    'fiscal_year_start_month_ec' => null,
                    'fiscal_year_start_day_ec' => null,
                    'tax_profile_status' => 'unconfigured',
                    'accounting_policy_status' => 'owner_review_required',
                    'document_numbering_policy_version' => 'ARNA-DOC-STD-001',
                ]);

                event(new LegalEntityCreated($entity, $actorId));

                return $entity->load('profile');
            });
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            $message = strtolower($exception->getMessage());
            $isUniqueViolation = (
                in_array($sqlState, ['23000', '23505'], true)
                || in_array($driverCode, [19, 1062, 2601, 2627], true)
            ) && (
                str_contains($message, 'unique')
                || str_contains($message, 'duplicate')
            );

            if ($isUniqueViolation && str_contains($message, 'legal_entities')) {
                throw ValidationException::withMessages([
                    'code' => [__('finance::validation.legal_entity_code_unique')],
                ]);
            }

            throw $exception;
        }
    }
}
