<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Events\LedgerAccountCreated;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;

final class LedgerAccountService
{
    public function create(int $tenantId, int $actorId, array $data): LedgerAccount
    {
        return DB::transaction(function () use ($tenantId, $actorId, $data): LedgerAccount {
            $entity = LegalEntity::query()->where('tenant_id', $tenantId)
                ->where('status', 'active')->lockForUpdate()->findOrFail((int)$data['legal_entity_id']);

            $parentId = isset($data['parent_id']) ? (int)$data['parent_id'] : null;
            if ($parentId !== null) {
                $parent = LedgerAccount::query()->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $entity->id)->findOrFail($parentId);
                if ($parent->account_type !== $data['account_type']) {
                    throw ValidationException::withMessages(['parent_id'=>'Parent and child accounts must use the same account type.']);
                }
            }

            try {
                $account = LedgerAccount::query()->create([
                    ...$data,
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => (int)$entity->id,
                    'parent_id' => $parentId,
                    'code' => strtoupper(trim((string)$data['code'])),
                    'name_en' => trim((string)$data['name_en']),
                    'name_am' => isset($data['name_am']) ? trim((string)$data['name_am']) : null,
                    'status' => 'active',
                    'is_posting_allowed' => $data['is_posting_allowed'] ?? true,
                ]);
            } catch (QueryException $exception) {
                $message = strtolower($exception->getMessage());
                $state = (string)($exception->errorInfo[0] ?? $exception->getCode());
                $driver = (int)($exception->errorInfo[1] ?? 0);
                $unique = (in_array($state, ['23000','23505'], true) || in_array($driver,[19,1062,2601,2627],true))
                    && (str_contains($message,'unique') || str_contains($message,'duplicate'));
                if ($unique && str_contains($message,'ledger_accounts')) {
                    throw ValidationException::withMessages(['code'=>'This account code already exists for the legal entity.']);
                }
                throw $exception;
            }

            event(new LedgerAccountCreated($account, $actorId));
            return $account;
        });
    }
}
