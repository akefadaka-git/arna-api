<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use Tests\TestCase;

/**
 * TenantResolverTest
 *
 * Unit tests for the RequestTenantResolver class.
 * Verifies that tenant resolution works correctly from:
 * - X-Tenant-ID header
 * - Subdomain parsing
 * - Reserved domain ignoring
 *
 * @package Tests\Unit
 */
final class TenantResolverTest extends TestCase
{
    /**
     * The resolver instance.
     *
     * @var RequestTenantResolver
     */
    private RequestTenantResolver $resolver;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RequestTenantResolver();
    }

    /**
     * Test that the resolver picks up the tenant ID from the X-Tenant-ID header.
     *
     * @return void
     */
    public function test_resolve_from_header(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X_TENANT_ID' => 'tenant-123',
        ]);

        $tenantId = $this->resolver->resolve($request);

        $this->assertEquals('tenant-123', $tenantId);
    }

    /**
     * Test that the resolver returns null when the X-Tenant-ID header is empty.
     *
     * @return void
     */
    public function test_resolve_from_header_empty(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X_TENANT_ID' => '',
        ]);

        $tenantId = $this->resolver->resolve($request);

        $this->assertNull($tenantId);
    }

    /**
     * Test that the resolver returns null when the X-Tenant-ID header is missing.
     *
     * @return void
     */
    public function test_resolve_from_header_missing(): void
    {
        $request = Request::create('/test', 'GET');

        $tenantId = $this->resolver->resolve($request);

        $this->assertNull($tenantId);
    }

    /**
     * Test that the resolver extracts the subdomain as tenant ID when no header is present.
     *
     * @return void
     */
    public function test_resolve_from_subdomain(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_HOST' => 'tenant-a.maalearna.com.et',
        ]);

        $tenantId = $this->resolver->resolve($request);

        $this->assertEquals('tenant-a', $tenantId);
    }

    /**
     * Test that the resolver returns null for reserved subdomains.
     *
     * @return void
     */
    public function test_resolve_ignores_reserved_domains(): void
    {
        $reservedDomains = [
            'www', 'api', 'auth', 'app', 'portal', 'finance',
            'hr', 'admin', 'arna', 'ai', 'file', 'launcher'
        ];

        foreach ($reservedDomains as $domain) {
            $host = $domain . '.maalearna.com.et';
            $request = Request::create('/test', 'GET', [], [], [], [
                'HTTP_HOST' => $host,
            ]);

            $tenantId = $this->resolver->resolve($request);
            $this->assertNull($tenantId, "Failed for host: $host");
        }
    }

    /**
     * Test that the resolver returns null when the host has no subdomain.
     *
     * @return void
     */
    public function test_resolve_from_root_domain(): void
    {
        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_HOST' => 'maalearna.com.et',
        ]);

        $tenantId = $this->resolver->resolve($request);

        $this->assertNull($tenantId);
    }

    /**
     * Test that the resolver uses configured reserved domains.
     *
     * @return void
     */
    public function test_resolve_uses_configured_reserved_domains(): void
    {
        Config::set('mases.tenant.reserved_domains', ['custom', 'api']);

        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_HOST' => 'custom.maalearna.com.et',
        ]);

        $tenantId = $this->resolver->resolve($request);

        $this->assertNull($tenantId);
    }
}