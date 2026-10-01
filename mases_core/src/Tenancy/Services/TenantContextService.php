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
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Cache-backed lookup via
 *          `Cache::remember('tenant:{id}', 300, fn () => Tenant::where(...))`.
 *
 * 1.1.0  — root-cause fix for "Tenant not found or inactive" false
 *          negatives on every tenant-scoped route.
 *
 *          Symptom: with a tenant row that is unambiguously present,
 *          active, and not soft-deleted — verified by direct SQL — the
 *          v1.0.0 lookup returned null, causing TenantContextMiddleware
 *          to leave the tenant context empty, which in turn caused
 *          TenantMembershipMiddleware to throw TENANT_REQUIRED on every
 *          /api/wallets, /api/files, /api/shareholders, etc. request.
 *
 *          Two changes:
 *
 *          · The lookup is now a raw `DB::table('tenants')` query, not
 *            an Eloquent query. The raw query builder does not apply
 *            global scopes — SoftDeletes or otherwise — so the row is
 *            retrieved regardless of any scope a service provider may
 *            have registered on the model. The `deleted_at IS NULL`
 *            filter is applied explicitly instead of via SoftDeletes.
 *
 *          · The `Cache::remember` wrapper is removed. A single
 *            primary-key lookup on a table with one row is fast enough
 *            that caching it was never load-bearing; and the cache was
 *            a moving part that made the failure hard to diagnose
 *            (the v1.0.0 failure mode is consistent with `remember`
 *            returning a value the caller could not treat as a
 *            `Tenant`). Removing it trades an unmeasurable performance
 *            cost for a much simpler failure surface. A future CR can
 *            re-introduce caching behind a flag once the tenant
 *            pipeline is stable.
 *
 *          The model is hydrated from the raw row via
 *          `(new Tenant)->newFromBuilder($row)`, which is the same
 *          mechanism Eloquent uses internally. Casts (`is_active` to
 *          bool, `settings` to array) apply as usual. Relations are
 *          lazy-loadable as usual. The rest of the codebase sees no
 *          difference.
 *
 *          On any failure the service now logs the connection name,
 *          the database name, the total tenant count, and the full
 *          list of tenant IDs and `is_active` values — enough to
 *          diagnose a future regression from the log alone.
 *
 * =============================================================================
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
     * Set the active tenant by its ID.
     *
     * Retrieves the tenant row directly via the query builder, bypassing
     * Eloquent global scopes, and hydrates a Tenant model from the result.
     * If no matching active, non-deleted row exists, returns null and
     * leaves the context empty.
     *
     * @param  int|string $tenantId
     * @return Tenant|null
     */
    public function setTenantById(int|string $tenantId): ?Tenant
    {
        try {
            $row = DB::table('tenants')
                ->where('id', $tenantId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->first();
        } catch (\Throwable $e) {
            Log::error('Tenant lookup threw an exception', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
                'class'     => $e::class,
            ]);

            $this->tenant = null;

            return null;
        }

        if ($row === null) {
            /*
             * The lookup found nothing. Log enough context to diagnose
             * without needing to run SQL by hand: the connection name,
             * the database name, the total tenant count, and the full
             * set of tenant IDs and their is_active flags. If the
             * connection is pointed at the wrong database, or a future
             * migration renames a column, this log will show it.
             */
            Log::warning('Tenant not found or inactive', [
                'tenant_id'        => $tenantId,
                'connection'       => DB::connection()->getName(),
                'database'         => DB::connection()->getDatabaseName(),
                'total_tenants'    => DB::table('tenants')->count(),
                'tenant_ids'       => DB::table('tenants')->pluck('id')->all(),
                'is_active_values' => DB::table('tenants')->pluck('is_active')->all(),
            ]);

            $this->tenant = null;

            return null;
        }

        /*
         * Hydrate a Tenant model from the raw row. `newFromBuilder` is
         * the same entry point Eloquent uses internally when it builds
         * a model from a query result; it marks the model as existing
         * and applies casts on attribute access. No query is run a
         * second time.
         */
        $tenant = (new Tenant())->newFromBuilder($row);

        $this->tenant = $tenant;

        Log::debug('Tenant context set', [
            'tenant_id'   => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'source'      => 'raw_db_query',
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