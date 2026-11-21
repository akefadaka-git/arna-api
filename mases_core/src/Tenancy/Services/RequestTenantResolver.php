<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Tenancy\Services;

use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Platform\Contracts\TenantContextResolverInterface;

/**
 * Resolves tenant context from an explicit numeric tenant primary key or a
 * single validated tenant subdomain label.
 *
 * A supplied X-Tenant-ID is authoritative: malformed values fail closed and
 * MUST NOT fall through to a host-derived tenant. When the header is absent,
 * exactly one non-reserved label under the platform suffix may identify a
 * tenant by slug. Tenant existence, activity and membership are verified by
 * downstream context and membership services.
 */
final class RequestTenantResolver implements TenantContextResolverInterface
{
    /** @var list<string> */
    private const DEFAULT_RESERVED_DOMAINS = [
        'www', 'api', 'auth', 'app', 'portal', 'finance',
        'hr', 'admin', 'arna', 'ai', 'file', 'launcher',
    ];

    private const DOMAIN_SUFFIX = 'maalearna.com.et';

    public function resolve(Request $request): int|string|null
    {
        // The header contract is the tenants.id primary key (BIGINT), not a
        // slug. Treat any supplied but invalid header as an explicit failed
        // selection; never silently switch to the Host-derived tenant.
        $headerTenant = $request->header('X-Tenant-ID');
        if ($headerTenant !== null && $headerTenant !== '') {
            if (
                ! is_string($headerTenant)
                || preg_match('/\A[1-9][0-9]*\z/D', $headerTenant) !== 1
                || filter_var($headerTenant, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]) === false
            ) {
                return null;
            }

            return $headerTenant;
        }

        // Only one valid DNS label below the exact platform suffix can
        // resolve a tenant slug. Nested labels and suffix lookalikes fail.
        $host = strtolower($request->getHost());
        $suffix = '.' . self::DOMAIN_SUFFIX;
        $reservedDomains = array_map('strtolower', $this->getReservedDomains());

        if (str_ends_with($host, $suffix)) {
            $subdomain = substr($host, 0, -strlen($suffix));

            if (
                preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $subdomain) === 1
                && ! in_array($subdomain, $reservedDomains, true)
            ) {
                return $subdomain;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function getReservedDomains(): array
    {
        $configured = config('mases.tenant.reserved_domains', []);
        if (is_array($configured) && $configured !== []) {
            return array_values(array_unique(array_merge(self::DEFAULT_RESERVED_DOMAINS, $configured)));
        }

        return self::DEFAULT_RESERVED_DOMAINS;
    }
}
