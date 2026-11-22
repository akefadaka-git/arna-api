<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Identifies first-party SPA requests eligible for cross-subdomain SSO.
 *
 * Both allow-lists are required: CORS alone is not an authentication
 * boundary, and Sanctum statefulness alone must not expand approved origins.
 */
final class FirstPartySpaOrigin
{
    public static function isTrusted(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        if (! is_string($origin) || $origin === '') {
            return false;
        }

        $origin = rtrim($origin, '/');
        $allowedOrigins = array_map(
            static fn ($value): string => rtrim((string) $value, '/'),
            (array) config('cors.allowed_origins', []),
        );

        if (! in_array($origin, $allowedOrigins, true)) {
            return false;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);

        if (! is_string($host) || $host === '') {
            return false;
        }

        $statefulDomains = array_map(
            static fn ($value): string => trim((string) $value),
            (array) config('sanctum.stateful', []),
        );
        $hostWithPort = $port === null ? $host : $host.':'.$port;

        return in_array($hostWithPort, $statefulDomains, true)
            || in_array($host, $statefulDomains, true);
    }
}
