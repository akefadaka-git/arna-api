<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * Resolve a login identifier to exactly one active User and tenant membership.
 *
 * All domain-specific identifiers are looked up inside an explicit tenant.
 * Missing, inactive, non-member, or ambiguous results fail closed.
 *
 * @internal Candidate implementation; callers must still apply credential
 *           verification and must never reveal which lookup branch matched.
 */
final class LoginIdentifierResolver
{
    /**
     * @return array{user: User, tenant_id: int}|null
     */
    public function resolve(string $identifier, ?int $requestedTenantId = null): ?array
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return null;
        }

        $normalized = strtolower($trimmed);
        $tenantId = $requestedTenantId !== null && $this->isActiveTenant($requestedTenantId)
            ? $requestedTenantId
            : null;

        if ($requestedTenantId !== null && $tenantId === null) {
            return null;
        }

        /** @var array<string, array{user: User, tenant_id: int}> $matches */
        $matches = [];

        $directUsers = User::query()
            ->where(function ($query) use ($normalized, $trimmed): void {
                $query->where('email', $normalized)
                    ->orWhere('username', $normalized)
                    ->orWhere('phone', $trimmed);
            })
            ->where('is_active', true)
            ->get();

        foreach ($directUsers as $user) {
            $resolvedTenantId = $this->resolveUserTenant($user, $tenantId);
            if ($resolvedTenantId !== null) {
                $matches[$user->getKey() . ':' . $resolvedTenantId] = [
                    'user' => $user,
                    'tenant_id' => $resolvedTenantId,
                ];
            }
        }

        // Domain identifiers must never be resolved outside a tenant.
        if ($tenantId !== null) {
            $shareholders = collect();

            if (Schema::hasTable('shareholders') && Schema::hasColumns('shareholders', [
                'tenant_id', 'is_active', 'shareholder_number', 'user_id', 'representative_user_id',
            ])) {
                $shareholders = $shareholders->merge(
                    Shareholder::query()
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', true)
                        ->where('shareholder_number', strtoupper($trimmed))
                        ->limit(2)
                        ->get()
                );
            }

            if (Schema::hasTable('shareholder_legacy_aliases') && Schema::hasColumns('shareholder_legacy_aliases', [
                'tenant_id', 'legacy_number_key', 'shareholder_id',
            ]) && Schema::hasTable('shareholders') && Schema::hasColumns('shareholders', [
                'tenant_id', 'is_active', 'user_id', 'representative_user_id',
            ])) {
                $aliasRows = DB::table('shareholder_legacy_aliases')
                    ->where('tenant_id', $tenantId)
                    ->where('legacy_number_key', strtoupper($trimmed))
                    ->limit(2)
                    ->get(['shareholder_id']);

                foreach ($aliasRows as $alias) {
                    $holder = Shareholder::query()
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', true)
                        ->find((int) $alias->shareholder_id);

                    if ($holder !== null) {
                        $shareholders->push($holder);
                    }
                }
            }

            foreach ($shareholders->unique('id') as $shareholder) {
                $linkedUserId = $shareholder->user_id ?? $shareholder->representative_user_id;
                if ($linkedUserId === null) {
                    continue;
                }

                $user = User::query()
                    ->whereKey($linkedUserId)
                    ->where('is_active', true)
                    ->first();

                if ($user !== null && $this->isMemberOfTenant($user, $tenantId)) {
                    $matches[$user->getKey() . ':' . $tenantId] = [
                        'user' => $user,
                        'tenant_id' => $tenantId,
                    ];
                }
            }

            // Employee identifiers are supported only when the domain schema is present.
            if (Schema::hasTable('employees')
                && Schema::hasColumn('employees', 'employee_number')
                && Schema::hasColumn('employees', 'user_id')
                && Schema::hasColumn('employees', 'is_active')
                && Schema::hasColumn('employees', 'tenant_id')
                && Schema::hasColumn('employees', 'deleted_at')) {
                $employees = DB::table('employees')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_number', strtoupper($trimmed))
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->whereNotNull('user_id')
                    ->limit(2)
                    ->get(['user_id']);

                foreach ($employees as $employee) {
                    $user = User::query()
                        ->whereKey((int) $employee->user_id)
                        ->where('is_active', true)
                        ->first();

                    if ($user !== null && $this->isMemberOfTenant($user, $tenantId)) {
                        $matches[$user->getKey() . ':' . $tenantId] = [
                            'user' => $user,
                            'tenant_id' => $tenantId,
                        ];
                    }
                }
            }

            // Wallet account identifiers are maintained outside the Wallet aggregate.
            // The Wallet table deliberately has no human-readable account-number column.
            if (Schema::hasTable('wallet_account_identifiers')
                && Schema::hasColumns('wallet_account_identifiers', [
                    'tenant_id', 'wallet_id', 'account_number', 'is_active', 'deleted_at',
                ])
                && Schema::hasTable('wallets')
                && Schema::hasColumns('wallets', [
                    'id', 'tenant_id', 'shareholder_id', 'is_active',
                ])
                && Schema::hasTable('shareholders')
                && Schema::hasColumns('shareholders', [
                    'tenant_id', 'is_active', 'user_id', 'representative_user_id',
                ])) {
                $wallets = DB::table('wallet_account_identifiers as identifiers')
                    ->join('wallets', function ($join) use ($tenantId): void {
                        $join->on('wallets.id', '=', 'identifiers.wallet_id')
                            ->where('wallets.tenant_id', '=', $tenantId);
                    })
                    ->where('identifiers.tenant_id', $tenantId)
                    ->where('identifiers.account_number', strtoupper($trimmed))
                    ->where('identifiers.is_active', true)
                    ->whereNull('identifiers.deleted_at')
                    ->where('wallets.is_active', true)
                    ->limit(2)
                    ->get(['wallets.shareholder_id']);

                foreach ($wallets as $wallet) {
                    $holder = Shareholder::query()
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', true)
                        ->find((int) $wallet->shareholder_id);

                    $linkedUserId = $holder?->user_id ?? $holder?->representative_user_id;
                    if ($linkedUserId === null) {
                        continue;
                    }

                    $user = User::query()
                        ->whereKey($linkedUserId)
                        ->where('is_active', true)
                        ->first();

                    if ($user !== null && $this->isMemberOfTenant($user, $tenantId)) {
                        $matches[$user->getKey() . ':' . $tenantId] = [
                            'user' => $user,
                            'tenant_id' => $tenantId,
                        ];
                    }
                }
            }
        }

        // Multiple eligible accounts or tenant memberships are ambiguous.
        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    private function resolveUserTenant(User $user, ?int $requestedTenantId): ?int
    {
        if ($requestedTenantId !== null) {
            return $this->isMemberOfTenant($user, $requestedTenantId)
                ? $requestedTenantId
                : null;
        }

        $defaultTenantId = $user->default_tenant_id === null
            ? null
            : (int) $user->default_tenant_id;

        if ($defaultTenantId !== null
            && $this->isActiveTenant($defaultTenantId)
            && $this->isMemberOfTenant($user, $defaultTenantId)) {
            return $defaultTenantId;
        }

        $tenantIds = $user->tenants()
            ->where('tenants.is_active', true)
            ->pluck('tenants.id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        return $tenantIds->count() === 1 ? $tenantIds->first() : null;
    }

    private function isActiveTenant(int $tenantId): bool
    {
        return Tenant::query()
            ->whereKey($tenantId)
            ->where('is_active', true)
            ->exists();
    }

    private function isMemberOfTenant(User $user, int $tenantId): bool
    {
        return $this->isActiveTenant($tenantId)
            && $user->tenants()->where('tenants.id', $tenantId)->exists();
    }
}
