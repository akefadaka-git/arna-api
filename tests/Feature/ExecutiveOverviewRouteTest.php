<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExecutiveOverviewRouteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ceo_can_open_the_overview_but_receives_no_finance_or_hidden_widget_metadata(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id);

        $entryPermission = Permission::factory()->create([
            'slug' => 'dashboard.executive.view',
            'scope' => 'tenant',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $ceo = Role::create([
            'name_am' => 'fixture',
            'name_en' => 'fixture',
            'slug' => 'ceo',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);
        $ceo->permissions()->attach($entryPermission->id);
        $user->roles()->attach($ceo->id);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.widgets', [])
            ->assertJsonMissingPath('data.omitted_widgets')
            ->assertJsonMissingPath('data.unavailable_widgets');
        $this->assertStringNotContainsString('shareholder', $response->getContent());
        $this->assertStringNotContainsString('governance', $response->getContent());
    }

    #[Test]
    public function a_direct_entry_permission_does_not_admit_an_unapproved_role(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id);

        $entryPermission = Permission::factory()->create([
            'slug' => 'dashboard.executive.view',
            'scope' => 'tenant',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $user->permissions()->attach($entryPermission->id);

        $manager = Role::create([
            'name_am' => 'fixture',
            'name_en' => 'fixture',
            'slug' => 'manager',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);
        $user->roles()->attach($manager->id);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }
}
