<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class ForwardedHostTrustTest extends TestCase
{
    public function test_forwarded_host_cannot_override_the_origin_host(): void
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

        // Keep the bootstrap's trusted forwarded headers in sync: host is
        // intentionally excluded, while protocol/port/client-IP remain usable.
        $request->setTrustedProxies(
            ['10.0.0.0/8'],
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        self::assertSame('tenant-a.maalearna.com.et', $request->getHost());
        self::assertSame('https', $request->getScheme());
    }
}
