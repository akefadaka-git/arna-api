<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Platform\Contracts;

use Illuminate\Http\Request;

/**
 * TenantContextResolverInterface
 *
 * This interface defines the contract for resolving the current tenant context
 * from an HTTP request. Implementations are responsible for inspecting the
 * request (e.g., domain, subdomain, header, or session) to determine the
 * active tenant identifier.
 *
 * The resolver returns a tenant ID (integer or string) rather than the
 * full Tenant model to avoid coupling the resolution layer to the concrete
 * Tenancy model. A separate Tenancy service will use this ID to fetch and
 * hydrate the tenant model, maintaining a clean separation of concerns.
 *
 * This interface is consumed by the TenancyContext service and middleware
 * to establish tenant isolation before any tenant-sensitive operations.
 *
 * @package MaaleArna\MASES\Core\Platform\Contracts
 */
interface TenantContextResolverInterface
{
    /**
     * Resolve the current tenant identifier from the HTTP request.
     *
     * The resolver should examine the request and determine the tenant that
     * should be active for this request. If no tenant can be resolved
     * (e.g., the request is for a public resource or system endpoint),
     * the method should return null.
     *
     * Implementations must be stateless and idempotent; they should not
     * modify the request or perform side effects.
     *
     * @param Request $request The current HTTP request.
     *
     * @return int|string|null The tenant ID, or null if none could be resolved.
     */
    public function resolve(Request $request): int|string|null;
}