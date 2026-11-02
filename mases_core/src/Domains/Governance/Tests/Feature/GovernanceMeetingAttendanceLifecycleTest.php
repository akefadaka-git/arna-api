<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class GovernanceMeetingAttendanceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'slug' => 'meeting-life-' . uniqid(),
            'name_am' => 'የስብሰባ ሙከራ',
            'name_en' => 'Meeting Lifecycle Test',
            'domain' => 'meeting-life-' . uniqid() . '.test',
            'is_active' => true,
        ]);
        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id);
        $view = $this->permission('governance.body.view');
        $manage = $this->permission('governance.body.manage');
        $this->admin = $this->user('meeting-admin');
        $this->reviewer = $this->user('meeting-reviewer');
        $this->admin->permissions()->attach([$view->id, $manage->id]);
        $this->reviewer->permissions()->attach([$view->id, $manage->id]);
    }

    public function test_late_arrival_creates_one_flat_etb_50_pending_assessment(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $body = $this->body('board');
        $position = $this->position($body, 'DIRECTOR');
        $member = $this->membership($body, $position, 'Late Member');
        $meeting = $this->meeting($body, 'board', \\Carbon\\CarbonImmutable::now('Africa/Addis_Ababa')->subMinutes(5));

        $this->postJson(route('api.governance.meetings.start', ['meeting' => $meeting->id]))
            ->assertOk();
        $this->postJson(route('api.governance.meetings.attendance.store', ['meeting' => $meeting->id]), [
            'membership_id' => $member->id,
            'arrived_at' => now()->subHour()->toDateTimeString(),
        ])->assertCreated()->assertJsonPath('data.status', 'late');

        $this->assertDatabaseHas('governance_meeting_penalties', [
            'meeting_id' => $meeting->id,
            'membership_id' => $member->id,
            'penalty_type' => 'late',
            'amount' => '50.00',
            'currency' => 'ETB',
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseCount('governance_meeting_penalties', 1);
    }

    public function test_close_creates_etb_300_only_for_unexcused_absence(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $body = $this->body('board');
        $position = $this->position($body, 'DIRECTOR');
        $present = $this->membership($body, $position, 'Present Member');
        $excused = $this->membership($body, $position, 'Excused Member');
        $absent = $this->membership($body, $position, 'Absent Member');
        $meeting = $this->meeting($body, 'board', \\Carbon\\CarbonImmutable::now('Africa/Addis_Ababa')->subMinutes(2));

        $this->postJson(route('api.governance.meetings.start', ['meeting' => $meeting->id]))->assertOk();
        $this->postJson(route('api.governance.meetings.attendance.store', ['meeting' => $meeting->id]), [
            'membership_id' => $present->id,
        ])->assertCreated();
        $this->postJson(route('api.governance.meetings.absence-authorizations.store', ['meeting' => $meeting->id]), [
            'membership_id' => $excused->id,
            'authorization_reference' => 'AUTH-2026-001',
            'reason' => 'Documented emergency.',
        ])->assertOk();
        $this->postJson(route('api.governance.meetings.close', ['meeting' => $meeting->id]))->assertOk();

        $this->assertDatabaseHas('governance_meeting_penalties', [
            'meeting_id' => $meeting->id, 'membership_id' => $absent->id,
            'penalty_type' => 'absence', 'amount' => '300.00',
            'currency' => 'ETB', 'status' => 'pending_review',
        ]);
        $this->assertDatabaseMissing('governance_meeting_penalties', [
            'meeting_id' => $meeting->id, 'membership_id' => $excused->id, 'penalty_type' => 'absence',
        ]);
        $this->assertDatabaseHas('governance_meeting_attendance', [
            'meeting_id' => $meeting->id, 'membership_id' => $excused->id, 'status' => 'excused',
        ]);
    }

    public function test_board_proxy_requires_another_director_and_accepts_only_one_proxy_per_director(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $body = $this->body('board');
        $position = $this->position($body, 'DIRECTOR');
        $principal = $this->membership($body, $position, 'Principal Director');
        $representative = $this->membership($body, $position, 'Representative Director');
        $secondPrincipal = $this->membership($body, $position, 'Second Principal');
        $meeting = $this->meeting($body, 'board', \\Carbon\\CarbonImmutable::now('Africa/Addis_Ababa')->addHours(4));

        $first = $this->postJson(route('api.governance.meetings.proxies.store', ['meeting' => $meeting->id]), [
            'principal_membership_id' => $principal->id,
            'representative_membership_id' => $representative->id,
            'representative_name' => 'Representative Director',
            'instrument_reference' => 'BOARD-PROXY-001',
            'appointed_at' => \\Carbon\\CarbonImmutable::now('Africa/Addis_Ababa')->subMinutes(10)->toDateTimeString(),
        ])->assertCreated()->json('data');

        $this->postJson(route('api.governance.meetings.proxies.review', ['meeting' => $meeting->id, 'proxy' => $first['id']]), [
            'decision' => 'accept', 'reason' => 'Validated director proxy.',
        ])->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->postJson(route('api.governance.meetings.proxies.store', ['meeting' => $meeting->id]), [
            'principal_membership_id' => $secondPrincipal->id,
            'representative_membership_id' => $representative->id,
            'representative_name' => 'Representative Director',
            'instrument_reference' => 'BOARD-PROXY-002',
            'appointed_at' => \\Carbon\\CarbonImmutable::now('Africa/Addis_Ababa')->subMinutes(5)->toDateTimeString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['representative_membership_id']);
    }

    public function test_ga_proxy_is_rejected_inside_the_three_day_submission_deadline(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $body = $this->body('assembly');
        $position = $this->position($body, 'SHAREHOLDER');
        $member = $this->membership($body, $position, 'GA Member');
        $meeting = $this->meeting($body, 'ga', \\Carbon\\CarbonImmutable::now('Africa/Addis_Ababa')->addDays(2));

        $this->postJson(route('api.governance.meetings.proxies.store', ['meeting' => $meeting->id]), [
            'principal_membership_id' => $member->id,
            'representative_name' => 'External Proxy',
            'instrument_reference' => 'GA-PROXY-LATE-001',
            'appointed_at' => \\Carbon\\CarbonImmutable::now('Africa/Addis_Ababa')->subMinutes(10)->toDateTimeString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['appointed_at']);
    }

    public function test_ga_principal_cannot_attend_personally_after_accepted_proxy(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $body = $this->body('assembly');
        $position = $this->position($body, 'SHAREHOLDER');
        $member = $this->membership($body, $position, 'GA Member');
        $meeting = $this->meeting($body, 'ga', \\Carbon\\CarbonImmutable::now('Africa/Addis_Ababa')->addDays(5));

        $proxy = $this->postJson(route('api.governance.meetings.proxies.store', ['meeting' => $meeting->id]), [
            'principal_membership_id' => $member->id,
            'representative_name' => 'External Proxy',
            'instrument_reference' => 'GA-PROXY-001',
            'appointed_at' => \\Carbon\\CarbonImmutable::now('Africa/Addis_Ababa')->subMinutes(10)->toDateTimeString(),
        ])->assertCreated()->json('data');

        $this->postJson(route('api.governance.meetings.proxies.review', ['meeting' => $meeting->id, 'proxy' => $proxy['id']]), [
            'decision' => 'accept', 'reason' => 'Instrument validated.',
        ])->assertOk();

        $this->postJson(route('api.governance.meetings.start', ['meeting' => $meeting->id]))->assertOk();
        $this->postJson(route('api.governance.meetings.attendance.store', ['meeting' => $meeting->id]), [
            'membership_id' => $member->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['membership_id']);
    }

    public function test_attendance_recorder_cannot_assess_own_penalty_and_second_reviewer_can(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $body = $this->body('board');
        $position = $this->position($body, 'DIRECTOR');
        $member = $this->membership($body, $position, 'Late Member');
        $meeting = $this->meeting($body, 'board', \\Carbon\\CarbonImmutable::now('Africa/Addis_Ababa')->subMinutes(5));
        $this->postJson(route('api.governance.meetings.start', ['meeting' => $meeting->id]))->assertOk();
        $this->postJson(route('api.governance.meetings.attendance.store', ['meeting' => $meeting->id]), [
            'membership_id' => $member->id,
        ])->assertCreated();
        $penalty = (int) \Illuminate\Support\Facades\DB::table('governance_meeting_penalties')
            ->where('meeting_id', $meeting->id)->value('id');

        $this->postJson(route('api.governance.meetings.penalties.review', ['meeting' => $meeting->id, 'penalty' => $penalty]), [
            'decision' => 'assess', 'reason' => 'Self-approval is prohibited.',
        ])->assertUnprocessable();

        Sanctum::actingAs($this->reviewer, ['*']);
        $this->postJson(route('api.governance.meetings.penalties.review', ['meeting' => $meeting->id, 'penalty' => $penalty]), [
            'decision' => 'assess', 'reason' => 'Independent evidence review.',
        ])->assertOk()->assertJsonPath('data.status', 'assessed');
    }

    private function permission(string $slug): Permission
    {
        return Permission::create([
            'slug' => $slug, 'name_am' => $slug, 'name_en' => $slug,
            'description_am' => $slug, 'description_en' => $slug,
            'category' => 'governance', 'scope' => 'tenant',
            'tenant_id' => null, 'application_id' => null,
            'is_active' => true, 'is_system' => true,
        ]);
    }

    private function user(string $prefix): User
    {
        $user = User::create([
            'email' => $prefix . '-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'first_name_en' => 'Meeting',
            'father_name_en' => $prefix,
            'default_tenant_id' => $this->tenant->id,
            'locale' => 'am',
            'is_active' => true,
        ]);
        $user->tenants()->attach($this->tenant->id, ['joined_at' => now()]);
        return $user;
    }

    private function body(string $type): GovernanceBody
    {
        return GovernanceBody::create([
            'tenant_id' => $this->tenant->id,
            'code' => strtoupper($type) . '-' . strtoupper(substr(uniqid(), -5)),
            'body_type' => $type === 'ga' ? 'assembly' : $type,
            'name_am' => $type === 'ga' ? 'ጠቅላላ ጉባኤ' : 'የዳይሬክተሮች ቦርድ',
            'name_en' => $type === 'ga' ? 'General Assembly' : 'Board of Directors',
            'is_active' => true,
        ]);
    }

    private function position(GovernanceBody $body, string $code): GovernancePosition
    {
        return GovernancePosition::create([
            'tenant_id' => $this->tenant->id, 'governance_body_id' => $body->id,
            'code' => $code, 'name_am' => 'አባል', 'name_en' => $code,
            'is_voting' => true, 'is_officer' => $code === 'DIRECTOR',
            'sort_order' => 0, 'is_active' => true,
        ]);
    }

    private function membership(GovernanceBody $body, GovernancePosition $position, string $name): GovernanceMembership
    {
        return GovernanceMembership::create([
            'tenant_id' => $this->tenant->id, 'governance_body_id' => $body->id,
            'governance_position_id' => $position->id, 'member_name_am' => 'አባል',
            'member_name_en' => $name, 'start_date' => '2026-09-18',
            'status' => 'active', 'is_ex_officio' => false,
        ]);
    }

    private function meeting(GovernanceBody $body, string $kind, \Carbon\CarbonInterface $scheduledAt): object
    {
        return (object) $this->postJson(route('api.governance.meetings.store'), [
            'governance_body_id' => $body->id,
            'meeting_kind' => $kind,
            'scheduled_at' => $scheduledAt->toDateTimeString(),
            'timezone' => 'Africa/Addis_Ababa',
            'title_en' => 'Lifecycle test meeting',
        ])->assertCreated()->json('data');
    }
}
