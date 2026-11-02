<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    private int $directorMembershipId;
    private int $shareholderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'meeting-tenant',
            'name_am' => 'የስብሰባ ቴናንት',
            'name_en' => 'Meeting Tenant',
            'domain' => 'meeting.test',
            'is_active' => true,
        ]);

        $view = $this->createPermission('governance.body.view');
        $manage = $this->createPermission('governance.body.manage');

        $this->admin = $this->createUser('meeting-admin');
        $this->admin->tenants()->attach($this->tenant->id, ['joined_at' => now()]);
        $this->admin->permissions()->attach([$view->id, $manage->id]);
        $this->admin->load('permissions');

        $this->plainUser = $this->createUser('meeting-plain');
        $this->plainUser->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->board = GovernanceBody::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'MEETING-BOARD',
            'body_type' => 'board',
            'name_am' => 'የማሌ ዓርና ቦርድ',
            'name_en' => 'Maale Arna Board',
            'is_active' => true,
        ]);

        $director = $this->createUser('meeting-director');
        $director->tenants()->attach($this->tenant->id, ['joined_at' => now()]);
        $positionId = (int) DB::table('governance_positions')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'governance_body_id' => $this->board->id,
            'code' => 'DIRECTOR',
            'name_am' => 'ዳይሬክተር',
            'name_en' => 'Director',
            'is_voting' => true,
            'is_officer' => true,
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->directorMembershipId = (int) DB::table('governance_memberships')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'governance_body_id' => $this->board->id,
            'governance_position_id' => $positionId,
            'user_id' => $director->id,
            'member_name_am' => 'ዳይሬክተር',
            'member_name_en' => 'Director',
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'is_ex_officio' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->shareholderId = (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'shareholder_number' => 'SH-000027',
            'shareholder_type' => 'individual',
            'first_name_am' => 'ባለአክሲዮን',
            'first_name_en' => 'Shareholder',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app->instance('tenant', $this->tenant);
        $this->app->instance('mases.tenant', $this->tenant);
        $this->app->instance('current_tenant', $this->tenant);
    }

    public function test_meeting_creation_is_tenant_scoped_and_audited(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $assembly = GovernanceBody::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'MEETING-GA',
            'body_type' => 'assembly',
            'name_am' => 'ጠቅላላ ጉባኤ',
            'name_en' => 'General Assembly',
            'is_active' => true,
        ]);
        $response = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.store'), [
                'governance_body_id' => $assembly->id,
                'meeting_code' => 'GA-2019-001',
                'meeting_kind' => 'general_assembly',
                'title_am' => 'ዓመታዊ ጠቅላላ ጉባኤ',
                'starts_at' => now()->addDays(10)->setTime(9, 0)->toDateTimeString(),
            ]);

        $response->assertCreated()->assertJsonPath('success', true);
        $meetingId = (int) $response->json('data.id');
        $this->assertDatabaseHas('governance_meetings', [
            'id' => $meetingId,
            'tenant_id' => $this->tenant->id,
            'meeting_code' => 'GA-2019-001',
        ]);
        $this->assertDatabaseHas('governance_meeting_activity', [
            'meeting_id' => $meetingId,
            'tenant_id' => $this->tenant->id,
            'action' => 'meeting.created',
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_arrival_at_or_before_meeting_start_is_not_late(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $meetingId = $this->createMeeting('BOARD-ON-TIME');
        $startsAt = now()->addDays(2)->setTime(9, 0);
        $response = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.attendance.store', ['meeting' => $meetingId]), [
                'subject_type' => 'director',
                'subject_id' => $this->directorMembershipId,
                'attendance_status' => 'late',
                'arrived_at' => $startsAt->toDateTimeString(),
            ]);
        $response->assertCreated()->assertJsonPath('data.attendance_status', 'present');
    }

    public function test_late_penalty_is_flat_fifty_birr_not_hourly(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $meetingId = $this->createMeeting('BOARD-LATE');
        $startsAt = now()->addDays(2)->setTime(9, 0);

        $attendance = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.attendance.store', ['meeting' => $meetingId]), [
                'subject_type' => 'director',
                'subject_id' => $this->directorMembershipId,
                'attendance_status' => 'present',
                'arrived_at' => $startsAt->addMinutes(5)->toDateTimeString(),
            ])->assertCreated()->assertJsonPath('data.attendance_status', 'late');

        $attendanceId = (int) $attendance->json('data.id');
        $penalty = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.penalties.store', ['meeting' => $meetingId]), [
                'attendance_id' => $attendanceId,
                'penalty_type' => 'late',
            ])->assertCreated();

        $this->assertSame(5000, (int) $penalty->json('data.amount_cents'));
        $this->assertSame('ETB', $penalty->json('data.currency'));
    }

    public function test_absence_penalty_is_three_hundred_birr_and_requires_absent_status(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $assembly = GovernanceBody::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'MEETING-GA-ABSENT',
            'body_type' => 'assembly',
            'name_am' => 'ጠቅላላ ጉባኤ',
            'name_en' => 'General Assembly',
            'is_active' => true,
        ]);
        $meetingId = (int) DB::table('governance_meetings')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'governance_body_id' => $assembly->id,
            'meeting_code' => 'GA-ABSENT',
            'meeting_kind' => 'general_assembly',
            'starts_at' => now()->addDays(2)->setTime(9, 0)->toDateTimeString(),
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attendance = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.attendance.store', ['meeting' => $meetingId]), [
                'subject_type' => 'shareholder',
                'subject_id' => $this->shareholderId,
                'attendance_status' => 'absent',
            ])->assertCreated();

        $penalty = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.penalties.store', ['meeting' => $meetingId]), [
                'attendance_id' => (int) $attendance->json('data.id'),
                'penalty_type' => 'absence',
            ])->assertCreated();

        $this->assertSame(30000, (int) $penalty->json('data.amount_cents'));
    }

    public function test_general_assembly_proxy_can_be_a_non_shareholder_without_an_arna_user_account(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $assembly = GovernanceBody::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'MEETING-GA-PROXY',
            'body_type' => 'assembly',
            'name_am' => 'ጠቅላላ ጉባኤ',
            'name_en' => 'General Assembly',
            'is_active' => true,
        ]);
        $shareholderId = $this->shareholderId;
        $meetingId = (int) DB::table('governance_meetings')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'governance_body_id' => $assembly->id,
            'meeting_code' => 'GA-EXTERNAL-PROXY',
            'meeting_kind' => 'general_assembly',
            'starts_at' => now()->addDays(5)->setTime(9, 0)->toDateTimeString(),
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.proxies.store', ['meeting' => $meetingId]), [
                'principal_type' => 'shareholder',
                'principal_id' => $shareholderId,
                'proxy_name_am' => 'ውክልና ተወካይ',
                'proxy_reference' => 'ID-EXTERNAL-01',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.proxy_user_id', null)
            ->assertJsonPath('data.proxy_name_am', 'ውክልና ተወካይ')
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_caller_without_manage_permission_cannot_record_attendance(): void
    {
        Sanctum::actingAs($this->plainUser, ['*']);
        $meetingId = $this->createMeeting('BOARD-FORBIDDEN');

        $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.attendance.store', ['meeting' => $meetingId]), [
                'subject_type' => 'director',
                'subject_id' => 1,
                'attendance_status' => 'absent',
            ])->assertForbidden();
    }

    public function test_attendance_correction_requires_independent_review_and_preserves_original_until_approval(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $meetingId = $this->createMeeting('BOARD-CORRECTION');
        $attendance = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.attendance.store', ['meeting' => $meetingId]), [
                'subject_type' => 'director',
                'subject_id' => $this->directorMembershipId,
                'attendance_status' => 'present',
                'arrived_at' => now()->addDays(2)->setTime(8, 55)->toDateTimeString(),
            ])->assertCreated();
        $attendanceId = (int) $attendance->json('data.id');

        $request = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.attendance.corrections.store', ['meeting' => $meetingId]), [
                'attendance_id' => $attendanceId,
                'proposed_status' => 'late',
                'proposed_arrived_at' => now()->addDays(2)->setTime(9, 5)->toDateTimeString(),
                'reason' => 'Original arrival time was entered incorrectly.',
            ])->assertCreated();

        $correctionId = (int) $request->json('data.id');
        $this->assertDatabaseHas('governance_meeting_attendance', [
            'id' => $attendanceId, 'attendance_status' => 'present',
        ]);

        $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->patchJson(route('api.governance.attendance-corrections.review', ['correction' => $correctionId]), [
                'decision' => 'approved', 'reason' => 'Verified against the signed arrival register.',
            ])->assertConflict();

        $reviewer = $this->createUser('meeting-reviewer');
        $reviewer->tenants()->attach($this->tenant->id, ['joined_at' => now()]);
        $reviewer->permissions()->attach($this->admin->permissions->pluck('id')->all());
        $reviewer->load('permissions');
        Sanctum::actingAs($reviewer, ['*']);
        $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->patchJson(route('api.governance.attendance-corrections.review', ['correction' => $correctionId]), [
                'decision' => 'approved', 'reason' => 'Verified against the signed arrival register.',
            ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('governance_meeting_attendance', [
            'id' => $attendanceId, 'attendance_status' => 'late',
        ]);
    }

    public function test_penalty_dispute_blocks_settlement_until_reviewed(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $meetingId = $this->createMeeting('BOARD-DISPUTE');
        $attendance = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.attendance.store', ['meeting' => $meetingId]), [
                'subject_type' => 'director',
                'subject_id' => $this->directorMembershipId,
                'attendance_status' => 'present',
                'arrived_at' => now()->addDays(2)->setTime(9, 5)->toDateTimeString(),
            ])->assertCreated();
        $penalty = $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.meetings.penalties.store', ['meeting' => $meetingId]), [
                'attendance_id' => (int) $attendance->json('data.id'), 'penalty_type' => 'late',
            ])->assertCreated();
        $penaltyId = (int) $penalty->json('data.id');

        $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.penalties.disputes.store', ['penalty' => $penaltyId]), [
                'reason' => 'The recorded arrival time is disputed.',
                'evidence_reference' => 'attendance-register-page-2',
            ])->assertCreated();

        $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson(route('api.governance.penalties.settlements.store', ['penalty' => $penaltyId]), [
                'method' => 'cash', 'amount_cents' => 5000,
            ])->assertConflict();

        $this->assertDatabaseHas('governance_meeting_penalties', [
            'id' => $penaltyId, 'status' => 'disputed',
        ]);
    }

    private function createMeeting(string $code): int
    {
        return (int) DB::table('governance_meetings')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'governance_body_id' => $this->board->id,
            'meeting_code' => $code,
            'meeting_kind' => 'board',
            'starts_at' => now()->addDays(2)->setTime(9, 0)->toDateTimeString(),
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(string $prefix): User
    {
        return User::create([
            'email' => $prefix . '-' . uniqid() . '@meeting.test',
            'password' => bcrypt('password'),
            'first_name_en' => 'Meeting',
            'father_name_en' => ucfirst($prefix),
            'default_tenant_id' => $this->tenant->id,
            'locale' => 'am',
            'is_active' => true,
        ]);
    }

    private function createPermission(string $slug): Permission
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
