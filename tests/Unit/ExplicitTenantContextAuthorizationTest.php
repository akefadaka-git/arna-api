<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Routing\Route;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Tests\TestCase;

final class ExplicitTenantContextAuthorizationTest extends TestCase
{
    public function test_mismatched_explicit_tenant_is_denied_for_permission_and_role_checks(): void
    {
        $this->setTrustedTenant(10);
        $authorization = app(AuthorizationService::class);
        $user = new User();

        $this->assertFalse($authorization->hasPermission('probe.permission', $user, 20));
        $this->assertFalse($authorization->hasRole('probe.role', $user, 20));
    }

    public function test_explicit_tenant_is_denied_when_routed_http_context_is_missing(): void
    {
        $this->setTrustedTenant(null);
        $this->setRoutedHttpRequest();

        $authorization = app(AuthorizationService::class);
        $user = new User();

        $this->assertFalse($authorization->hasPermission('probe.permission', $user, 10));
        $this->assertFalse($authorization->hasRole('probe.role', $user, 10));
    }

    public function test_explicit_tenant_matches_trusted_context(): void
    {
        $this->setTrustedTenant(10);
        $authorization = app(AuthorizationService::class);
        $method = new \ReflectionMethod($authorization, 'explicitTenantMatchesTrustedContext');

        $this->assertTrue($method->invoke($authorization, 10));
    }

    public function test_non_http_console_context_preserves_explicit_tenant_scope(): void
    {
        $this->setTrustedTenant(null);
        $this->app['request']->setRouteResolver(static fn () => null);

        $authorization = app(AuthorizationService::class);
        $method = new \ReflectionMethod($authorization, 'explicitTenantMatchesTrustedContext');

        $this->assertSame(app()->runningInConsole(), $method->invoke($authorization, 10));
    }

    private function setTrustedTenant(int|string|null $tenantId): void
    {
        $context = new TenantContextService();

        if ($tenantId !== null) {
            $tenant = new Tenant();
            $tenant->setRawAttributes(['id' => $tenantId], true);

            $property = new \ReflectionProperty(TenantContextService::class, 'tenant');
            $property->setValue($context, $tenant);
        }

        $this->app->instance(TenantContextService::class, $context);
    }

    private function setRoutedHttpRequest(): void
    {
        $route = new Route('GET', '/u3-context-probe', static fn () => null);
        $this->app['request']->setRouteResolver(static fn () => $route);
    }
}
