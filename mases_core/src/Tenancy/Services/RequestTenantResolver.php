<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Tenancy\Services;

use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Platform\Contracts\TenantContextResolverInterface;

/**
 * RequestTenantResolver
 *
 * Resolves the current tenant identifier from the HTTP request using a combination
 * of headers and subdomain parsing. This is the default resolver configured in
 * mases.php under 'tenant.context_resolver'.
 *
 * Resolution strategy (in order of precedence):
 *   1. Check the 'X-Tenant-ID' header (useful for API clients).
 *   2. If the header is absent, examine the request host. If the host is a subdomain
 *      that is not reserved (e.g., 'www', 'api', 'auth'), extract the subdomain as
 *      the tenant identifier.
 *   3. If no tenant can be resolved, return null.
 *
 * Reserved domains (configured via `mases.tenant.reserved_domains`) are ignored
 * to prevent treating platform-level subdomains as tenant identifiers.
 *
 * This resolver is stateless and idempotent, as required by the interface contract.
 *
 * @package MaaleArna\MASES\Core\Tenancy\Services
 */
final class RequestTenantResolver implements TenantContextResolverInterface
{
    /**
     * Default list of reserved domains that should not be treated as tenant subdomains.
     * These are typically platform-level or application-level domains.
     * Can be overridden via configuration.
     *
     * @var list<string>
     */
    private const DEFAULT_RESERVED_DOMAINS = [
        'www',
        'api',
        'auth',
        'app',
        'portal',
        'finance',
        'hr',
        'admin',
        'arna',
        'ai',
        'file',
        'launcher',
    ];

    /**
     * The domain suffix for the platform (e.g., 'maalearna.com.et').
     * This is used to detect subdomains.
     *
     * @var string
     */
    private const DOMAIN_SUFFIX = 'maalearna.com.et';

    /**
     * Resolve the current tenant identifier from the HTTP request.
     *
     * @param Request $request The current HTTP request.
     *
     * @return int|string|null The tenant ID (as a string), or null if none resolved.
     */
    public function resolve(Request $request): int|string|null
    {
        // Strategy 1: Check the X-Tenant-ID header.
        $headerTenant = $request->header('X-Tenant-ID');
        if ($headerTenant !== null && $headerTenant !== '') {
            return $headerTenant;
        }

        // Strategy 2: Parse exactly one tenant DNS label from the trusted
        // platform suffix. Reject nested labels, malformed labels, and
        // suffix lookalikes rather than treating them as tenant identifiers.
        $host = strtolower($request->getHost());
        $suffix = '.' . self::DOMAIN_SUFFIX;
        $reservedDomains = array_map('strtolower', $this->getReservedDomains());

        if (str_ends_with($host, $suffix)) {
            $subdomain = substr($host, 0, -strlen($suffix));

            // A tenant subdomain is exactly one DNS label. This excludes
            // "a.b.maalearna.com.et", leading/trailing hyphens, and
            // hostnames that could be parsed ambiguously.
            if (
                preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $subdomain) === 1
                && !in_array($subdomain, $reservedDomains, true)
            ) {
                return $subdomain;
            }
        }

        // No tenant resolved.
        return null;
    }

    /**
     * Get the list of reserved domains to ignore when resolving tenants.
     * Merges defaults with any configured overrides.
     *
     * @return list<string>
     */
    private function getReservedDomains(): array
    {
        $configured = config('mases.tenant.reserved_domains', []);
        if (is_array($configured) && !empty($configured)) {
            // Ensure we merge without duplicates and return a list.
            return array_values(array_unique(array_merge(self::DEFAULT_RESERVED_DOMAINS, $configured)));
        }

        return self::DEFAULT_RESERVED_DOMAINS;
    }
}