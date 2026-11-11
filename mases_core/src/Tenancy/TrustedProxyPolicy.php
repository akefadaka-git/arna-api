<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Tenancy;

use Illuminate\Http\Request;

/**
 * Single source of truth for Laravel's trusted-proxy boundary.
 *
 * X-Forwarded-Host is intentionally excluded: tenant selection must use the
 * origin Host header, not a client-controlled forwarded host value.
 */
final class TrustedProxyPolicy
{
    /** @return list<string> */
    public static function trustedProxies(): array
    {
        return [
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ];
    }

    public static function trustedHeaders(): int
    {
        return Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_AWS_ELB;
    }
}
