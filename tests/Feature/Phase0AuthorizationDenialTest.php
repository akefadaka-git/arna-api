<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Middleware\TenantMembershipMiddleware;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Phase0AuthorizationDenialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mases.tenant.context_resolver', RequestTenantResolver::class);
        Config::set('mases.tenant.isolation_enabled', true);
        $this->app['router']->aliasMiddleware(
            'tenant.membership',
            TenantMembershipMiddleware::class
        );
    }

    #[Test]
    public function authenticated_non_member_is_denied_cross_tenant_file_access(): void
    {
        $memberTenant = Tenant::factory()->create(['is_active' => true]);
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->tenants()->attach($memberTenant->id);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $otherTenant->id)
            ->getJson('/api/files');

        $response->assertForbidden();
    }

    #[Test]
    public function tenant_member_without_file_list_permission_is_denied(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/files');

        $response->assertForbidden();
    }
}
