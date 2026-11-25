<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Tenancy\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Exceptions\TenantException;

/**
 * TenantContextService
 *
 * Manages the active tenant context for the current request. This service
 * is responsible for storing, retrieving, and clearing the tenant that
 * is currently active for the request lifecycle.
 *
 * The service is bound as a singleton in the container, ensuring that the
 * tenant context is consistent throughout the request.
 *
 * =============================================================================
 * Historical changes are recorded in Git.
 * @package MaaleArna\MASES\Core\Tenancy\Services
 */
final class TenantContextService
{
    /**
     * The active tenant instance.
     *
     * @var Tenant|null
     */
    private ?Tenant $tenant = null;

    /**
     * Set the active tenant by its primary key.
     *
     * X-Tenant-ID and other identifier-based integrations use this method.
     * A slug is never implicitly interpreted as a primary key.
     */
    public function setTenantById(int|string $tenantId): ?Tenant
    {
        return $this->loadActiveTenant('id', $tenantId, 'tenant_id');
    }

    /**
     * Set the active tenant by its canonical URL slug.
     *
     * This is reserved for a validated tenant subdomain. It is deliberately
     * separate from setTenantById() so a header value cannot silently change
     * from an ID contract into a slug contract.
     */
    public function setTenantBySlug(string $tenantSlug): ?Tenant
    {
        return $this->loadActiveTenant('slug', $tenantSlug, 'tenant_slug');
    }

    /**
     * Load only active, non-deleted tenants using a caller-selected,
     * internally controlled column name. Never accepts arbitrary columns
     * from request input.
     *
     * @param 'id'|'slug' $column
     */
    private function loadActiveTenant(string $column, int|string $identifier, string $logKey): ?Tenant
    {
        try {
            $row = DB::table('tenants')
                ->where($column, $identifier)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->first();
        } catch (\Throwable $e) {
            Log::error('Tenant lookup threw an exception', [
                $logKey => $identifier,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            $this->tenant = null;

            return null;
        }

        if ($row === null) {
            Log::warning('Tenant not found or inactive', [
                $logKey => $identifier,
                'connection' => DB::connection()->getName(),
                'database' => DB::connection()->getDatabaseName(),
            ]);

            $this->tenant = null;

            return null;
        }

        $tenant = (new Tenant())->newFromBuilder($row);
        $this->tenant = $tenant;

        Log::debug('Tenant context set', [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'source' => $column === 'slug' ? 'validated_subdomain_slug' : 'primary_key',
        ]);

        return $tenant;
    }

    /**
     * Get the active tenant.
     *
     * @return Tenant|null
     */
    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * Get the active tenant ID.
     *
     * @return int|string|null
     */
    public function getTenantId(): int|string|null
    {
        return $this->tenant?->id;
    }

    /**
     * Get the active tenant slug.
     *
     * @return string|null
     */
    public function getTenantSlug(): ?string
    {
        return $this->tenant?->slug;
    }

    /**
     * Check if a tenant is currently active.
     *
     * @return bool
     */
    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Clear the active tenant context.
     *
     * @return void
     */
    public function clearTenant(): void
    {
        $this->tenant = null;
        Log::debug('Tenant context cleared');
    }

    /**
     * Refresh the active tenant from the database.
     *
     * Re-queries the tenant row by ID. Returns null if no context is
     * currently set, or if the tenant can no longer be loaded.
     *
     * @return Tenant|null
     */
    public function refreshTenant(): ?Tenant
    {
        if ($this->tenant === null) {
            return null;
        }

        return $this->setTenantById($this->tenant->id);
    }

    /**
     * Require that a tenant is active; throw an exception if not.
     *
     * @throws TenantException
     * @return Tenant
     */
    public function requireTenant(): Tenant
    {
        if ($this->tenant === null) {
            throw new TenantException(
                'No active tenant context available.',
                'TENANT_REQUIRED',
                400
            );
        }

        return $this->tenant;
    }

    /**
     * Require that a tenant is active and has a specific slug; throw an
     * exception if not.
     *
     * @param  string $slug
     * @throws TenantException
     * @return Tenant
     */
    public function requireTenantWithSlug(string $slug): Tenant
    {
        $tenant = $this->requireTenant();

        if ($tenant->slug !== $slug) {
            throw new TenantException(
                sprintf('Expected tenant "%s" but got "%s".', $slug, $tenant->slug),
                'TENANT_MISMATCH',
                403
            );
        }

        return $tenant;
    }
}