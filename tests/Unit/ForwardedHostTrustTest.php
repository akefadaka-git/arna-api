<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Tenancy\TrustedProxyPolicy;
use PHPUnit\Framework\TestCase;

final class ForwardedHostTrustTest extends TestCase
{
    public function test_bootstrap_uses_the_shared_trusted_proxy_policy(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__, 2) . '/bootstrap/app.php');

        self::assertNotFalse($bootstrap);
        self::assertMatchesRegularExpression(
            '/trustProxies\s*\(\s*at:\s*TrustedProxyPolicy::trustedProxies\(\)\s*,\s*headers:\s*TrustedProxyPolicy::trustedHeaders\(\)\s*,?\s*\)/',
            $bootstrap,
            'Laravel bootstrap must consume the same policy exercised by this test.',
        );
    }

    public function test_policy_trusts_only_expected_proxy_ranges_and_excludes_forwarded_host(): void
    {
        self::assertSame(
            ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            TrustedProxyPolicy::trustedProxies(),
        );

        $headers = TrustedProxyPolicy::trustedHeaders();

        self::assertSame(0, $headers & Request::HEADER_X_FORWARDED_HOST);
        self::assertNotSame(0, $headers & Request::HEADER_X_FORWARDED_FOR);
        self::assertNotSame(0, $headers & Request::HEADER_X_FORWARDED_PORT);
        self::assertNotSame(0, $headers & Request::HEADER_X_FORWARDED_PROTO);
        self::assertNotSame(0, $headers & Request::HEADER_X_FORWARDED_AWS_ELB);
    }

    public function test_forwarded_host_cannot_override_origin_host_under_bootstrap_policy(): void
    {
        $request = Request::create(
            'https://tenant-a.maalearna.com.et/resource',
            'GET',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '10.0.0.10',
                'HTTP_HOST' => 'tenant-a.maalearna.com.et',
                'HTTP_X_FORWARDED_HOST' => 'tenant-b.maalearna.com.et',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
        );

        $request->setTrustedProxies(
            TrustedProxyPolicy::trustedProxies(),
            TrustedProxyPolicy::trustedHeaders(),
        );

        self::assertSame('tenant-a.maalearna.com.et', $request->getHost());
        self::assertSame('https', $request->getScheme());
    }
}
