<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class GovernanceMeetingAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $plainUser;
    private GovernanceBody $board;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'meeting-test-' . uniqid(),
            'name_am' => 'የሙከራ ቴናንት',
            'name_en' => 'Meeting Test Tenant',
            'domain' => 'meeting-' . uniqid() . '.test',
            'is_active' => true,
        ]);

        $view = $this->permission('governance.body.view');
        $manage = $this->permission('governance.body.manage');

        $this->admin = User::create([
            'email' => 'meeting-admin-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'first_name_en' => 'Meeting',
            'father_name_en' => 'Admin',
            'default_tenant_id' => $this->tenant->id,
            'locale' => 'am',
            'is_active' => true,
        ]);
        $this->admin->tenants()->attach($this->tenant->id, ['joined_at' => now()]);
        $this->admin->permissions()->attach([$view->id, $manage->id]);

        $this->plainUser = User::create([
            'email' => 'meeting-plain-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'first_name_en' => 'Meeting',
            'father_name_en' => 'Plain',
            'default_tenant_id' => $this->tenant->id,
            'locale' => 'am',
            'is_active' => true,
        ]);
        $this->plainUser->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->board = GovernanceBody::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'BOARD-' . strtoupper(substr(uniqid(), -6)),
            'body_type' => 'board',
            'name_am' => 'የዳይሬክተሮች ቦርድ',
            'name_en' => 'Board of Directors',
            'is_active' => true,
        ]);

        $this->app->instance('tenant', $this->tenant);
        $this->app->instance('mases.tenant', $this->tenant);
        $this->app->instance('current_tenant', $this->tenant);
    }

    public function test_authorized_user_can_create_a_board_meeting(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.store'), [
                'governance_body_id' => $this->board->id,
                'meeting_kind' => 'board',
                'scheduled_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
                'timezone' => 'Africa/Addis_Ababa',
                'title_am' => 'የቦርድ ስብሰባ',
            ]);

        $response->assertCreated()->assertJsonPath('success', true)
            ->assertJsonPath('data.meeting_kind', 'board')
            ->assertJsonPath('data.status', 'scheduled');

        $this->assertDatabaseHas('governance_meetings', [
            'tenant_id' => $this->tenant->id,
            'governance_body_id' => $this->board->id,
            'meeting_kind' => 'board',
            'status' => 'scheduled',
        ]);
    }

    public function test_meeting_kind_must_match_the_governance_body_type(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.store'), [
                'governance_body_id' => $this->board->id,
                'meeting_kind' => 'ga',
                'scheduled_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meeting_kind']);

        $this->assertDatabaseCount('governance_meetings', 0);
    }

    public function test_user_without_governance_manage_permission_cannot_create_meeting(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);

        $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.store'), [
                'governance_body_id' => $this->board->id,
                'meeting_kind' => 'board',
                'scheduled_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('governance_meetings', 0);
    }

    private function permission(string $slug): Permission
    {
        return Permission::create([
            'slug' => $slug,
            'name_am' => $slug,
            'name_en' => $slug,
            'description_am' => $slug,
            'description_en' => $slug,
            'category' => 'governance',
            'scope' => 'tenant',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => true,
            'is_system' => true,
        ]);
    }
}
