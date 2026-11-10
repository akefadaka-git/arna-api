<?php

declare(strict_types=1);

namespace Tests\\Unit;

use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Config;
use MaaleArna\\MASES\\Core\\Tenancy\\Services\\RequestTenantResolver;
use Tests\\TestCase;

final class TenantResolverTest extends TestCase
{
    private RequestTenantResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RequestTenantResolver();
    }

    public function test_resolve_from_numeric_primary_key_header(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X_TENANT_ID' => '123',
        ]);

        $this->assertSame('123', $this->resolver->resolve($request));
    }

    public function test_resolve_rejects_non_numeric_header_values(): void
    {
        foreach (['tenant-123', '0', '-1', '+1', '1.0', ' 1', '1 ', '1,2'] as $value) {
            $request = Request::create('/test', 'GET', [], [], [], [
                'HTTP_X_TENANT_ID' => $value,
            ]);

            $this->assertNull(
                $this->resolver->resolve($request),
                "Malformed X-Tenant-ID value must fail closed: {$value}"
            );
        }
    }

    public function test_invalid_explicit_header_does_not_fall_back_to_valid_subdomain(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_HOST' => 'tenant-a.maalearna.com.et',
            'HTTP_X_TENANT_ID' => 'tenant-a',
        ]);

        $this->assertNull($this->resolver->resolve($request));
    }

    public function test_resolve_from_header_empty(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X_TENANT_ID' => '',
        ]);

        $this->assertNull($this->resolver->resolve($request));
    }

    public function test_resolve_from_header_missing(): void
    {
        $request = Request::create('/test', 'GET');
        $this->assertNull($this->resolver->resolve($request));
    }

    public function test_resolve_from_subdomain(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_HOST' => 'tenant-a.maalearna.com.et',
        ]);

        $this->assertSame('tenant-a', $this->resolver->resolve($request));
    }

    public function test_resolve_rejects_nested_tenant_subdomains(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_HOST' => 'tenant-a.child.maalearna.com.et',
        ]);

        $this->assertNull($this->resolver->resolve($request));
    }

    public function test_resolve_rejects_platform_suffix_lookalikes(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_HOST' => 'tenant-a.maalearna.com.et.attacker.example',
        ]);

        $this->assertNull($this->resolver->resolve($request));
    }

    public function test_resolve_ignores_reserved_domains(): void
    {
        foreach ([
            'www', 'api', 'auth', 'app', 'portal', 'finance',
            'hr', 'admin', 'arna', 'ai', 'file', 'launcher',
        ] as $domain) {
            $host = $domain . '.maalearna.com.et';
            $request = Request::create('/test', 'GET', [], [], [], [
                'HTTP_HOST' => $host,
            ]);

            $this->assertNull($this->resolver->resolve($request), "Failed for host: {$host}");
        }
    }

    public function test_resolve_from_root_domain(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_HOST' => 'maalearna.com.et',
        ]);

        $this->assertNull($this->resolver->resolve($request));
    }

    public function test_resolve_uses_configured_reserved_domains(): void
    {
        Config::set('mases.tenant.reserved_domains', ['custom', 'api']);
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_HOST' => 'custom.maalearna.com.et',
        ]);

        $this->assertNull($this->resolver->resolve($request));
    }
}
