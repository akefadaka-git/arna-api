<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use Tests\TestCase;

final class AdminControlPlaneAuthorizationNegativeTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_domain_read_is_denied_without_its_global_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/platform/domains')
            ->assertForbidden();
    }

    public function test_domain_read_permission_does_not_authorize_domain_registration(): void
    {
        $user = User::factory()->create();
        $permission = Permission::factory()->create([
            'slug' => 'platform.domains.read',
            'scope' => 'global',
            'is_active' => true,
        ]);
        $user->permissions()->attach($permission->id);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/platform/domains', [
                'fqdn' => 'admin.example.org',
                'owner_type' => 'platform',
                'reason' => 'Negative permission test',
            ])
            ->assertForbidden();
    }

    public function test_settings_manager_cannot_approve_without_the_separate_approval_permission(): void
    {
        $user = User::factory()->create();
        $permission = Permission::factory()->create([
            'slug' => 'platform.settings.manage',
            'scope' => 'global',
            'is_active' => true,
        ]);
        $user->permissions()->attach($permission->id);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/platform/settings/change-requests/1/approve', [
                'approval_note' => 'Attempt to approve without approval grant',
            ])
            ->assertForbidden();
    }

    public function test_platform_domain_read_does_not_require_tenant_membership_when_global_permission_is_granted(): void
    {
        $user = User::factory()->create();
        $permission = Permission::factory()->create([
            'slug' => 'platform.domains.read',
            'scope' => 'global',
            'is_active' => true,
        ]);
        $user->permissions()->attach($permission->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/platform/domains')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
