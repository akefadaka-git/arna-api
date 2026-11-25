<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class GovernanceMeetingBusinessLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $reviewer;
    private int $meetingId;
    private int $shareholderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'ga-business-lifecycle',
            'name_am' => 'የጠቅላላ ጉባኤ ቴናንት',
            'name_en' => 'GA Business Lifecycle',
            'domain' => 'ga-business.test',
            'is_active' => true,
        ]);

        $manage = $this->createPermission('governance.meeting.manage');
        $meetingView = $this->createPermission('governance.meeting.view');
        $approve = $this->createPermission('governance.meeting.approve');
        $bodyManage = $this->createPermission('governance.body.manage');

        $this->admin = $this->createUser('ga-admin');
        $this->reviewer = $this->createUser('ga-reviewer');
        foreach ([$this->admin, $this->reviewer] as $user) {
            $user->tenants()->attach($this->tenant->id, ['joined_at' => now()]);
            $user->permissions()->attach([$manage->id, $meetingView->id, $approve->id, $bodyManage->id]);
            $user->load('permissions');
        }

        $bodyId = DB::table('governance_bodies')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'code' => 'GA-LIFECYCLE',
            'body_type' => 'assembly',
            'name_am' => 'ጠቅላላ ጉባኤ',
            'name_en' => 'General Assembly',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->meetingId = (int) DB::table('governance_meetings')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'governance_body_id' => $bodyId,
            'meeting_code' => 'GA-LIFECYCLE-001',
            'title_am' => 'ዓመታዊ ጠቅላላ ጉባኤ',
            'title_en' => 'Annual General Meeting',
            'meeting_kind' => 'general_assembly',
            'starts_at' => now()->addDays(60)->setTime(10, 0)->toDateTimeString(),
            'ends_at' => now()->addDays(60)->setTime(15, 0)->toDateTimeString(),
            'status' => 'scheduled',
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shareholderId = (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am' => 'ባለአክሲዮን',
            'first_name_en' => 'Shareholder',
            'user_id' => $this->admin->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $classId = (int) DB::table('share_classes')->insertGetId([
            'code' => 'STANDARD',
            'name_am' => 'መደበኛ',
            'name_en' => 'Standard',
            'par_value' => '10000.0000',
            'currency' => 'ETB',
            'is_active' => true,
            'is_treasury_eligible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $holdingId = (int) DB::table('share_holdings')->insertGetId([
            'shareholder_id' => $this->shareholderId,
            'share_class_id' => $classId,
            'current_quantity' => '5.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('share_holding_entries')->insert([
            'share_holding_id' => $holdingId,
            'entry_type' => 'subscription',
            'quantity' => '5.0000',
            'balance_before' => '0.0000',
            'balance_after' => '5.0000',
            'reference_type' => 'test_fixture',
            'reference_id' => 'GA-LIFECYCLE-001',
            'description' => 'Synthetic test opening balance',
            'occurred_at' => now()->subMinute(),
            'created_by' => $this->admin->id,
            'created_at' => now(),
        ]);

        $this->registerVerifiedLegalEvidence();
        $this->app->instance('tenant', $this->tenant);
        $this->app->instance('mases.tenant', $this->tenant);
        $this->app->instance('current_tenant', $this->tenant);
    }

    public function test_ga_notice_quorum_weighted_vote_resolution_and_minutes_complete_end_to_end(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $notice = $this->withTenant()->postJson(route('api.governance.meetings.notices.store', ['meeting' => $this->meetingId]), [
            'subject_am' => 'የጠቅላላ ጉባኤ ጥሪ',
            'subject_en' => 'General Assembly Notice',
            'body_am' => 'የጠቅላላ ጉባኤው በተገለጸው ቀን ይካሄዳል።',
            'body_en' => 'The General Assembly will be held on the stated date.',
        ])->assertCreated();
        $noticeId = (int) $notice->json('data.id');

        Sanctum::actingAs($this->reviewer, ['*']);
        $this->withTenant()->patchJson(route('api.governance.notices.review', ['notice' => $noticeId]), [
            'decision' => 'approved', 'reason' => 'Content and authority checked independently.',
        ])->assertOk();

        Sanctum::actingAs($this->admin, ['*']);
        $this->withTenant()->postJson(route('api.governance.meetings.notices.issue', [
            'meeting' => $this->meetingId, 'notice' => $noticeId,
        ]))->assertOk()->assertJsonPath('delivery.dispatch_status', 'not_sent_by_this_endpoint');

        $recipient = (int) DB::table('governance_meeting_notice_recipients')->where('notice_id', $noticeId)->value('id');
        $this->withTenant()->patchJson(route('api.governance.notice-recipients.delivery', ['recipient' => $recipient]), [
            'status' => 'delivered', 'delivery_reference' => 'portal-receipt:GA-LIFECYCLE-001:1',
        ])->assertOk();

        $agenda = $this->withTenant()->postJson(route('api.governance.meetings.agenda-items.store', ['meeting' => $this->meetingId]), [
            'sort_order' => 1, 'title_am' => 'የዓመታዊ ሪፖርት ማጽደቅ', 'title_en' => 'Approve annual report',
        ])->assertCreated();
        $agendaId = (int) $agenda->json('data.id');

        $snapshot = $this->withTenant()->postJson(route('api.governance.meetings.electorate-snapshots.store', ['meeting' => $this->meetingId]), [
            'record_date' => now()->toDateString(),
            'source_reference' => 'verified-register-snapshot:GA-LIFECYCLE-001',
            'source_sha256' => str_repeat('b', 64),
        ])->assertCreated();
        $snapshotId = (int) $snapshot->json('data.id');

        Sanctum::actingAs($this->reviewer, ['*']);
        $this->withTenant()->patchJson(route('api.governance.electorate-snapshots.review', ['snapshot' => $snapshotId]), [
            'decision' => 'verified', 'reason' => 'Register snapshot reconciled to share ledger.',
        ])->assertOk();

        Sanctum::actingAs($this->admin, ['*']);
        $this->withTenant()->postJson(route('api.governance.meetings.attendance.store', ['meeting' => $this->meetingId]), [
            'subject_type' => 'shareholder',
            'subject_id' => $this->shareholderId,
            'user_id' => $this->admin->id,
            'attendance_status' => 'present',
            'arrived_at' => now()->toDateTimeString(),
        ])->assertCreated();

        $quorum = $this->withTenant()->postJson(route('api.governance.meetings.quorum-assessments.store', [
            'meeting' => $this->meetingId, 'snapshot' => $snapshotId,
        ]))->assertCreated();
        $quorumId = (int) $quorum->json('data.id');
        $this->assertTrue((bool) $quorum->json('data.quorum_met'));

        Sanctum::actingAs($this->reviewer, ['*']);
        $this->withTenant()->patchJson(route('api.governance.quorum-assessments.review', ['assessment' => $quorumId]), [
            'decision' => 'verified', 'reason' => 'Quorum calculation independently checked.',
        ])->assertOk();

        Sanctum::actingAs($this->admin, ['*']);
        $motion = $this->withTenant()->postJson(route('api.governance.meetings.motions.store', ['meeting' => $this->meetingId]), [
            'agenda_item_id' => $agendaId, 'motion_number' => 1,
            'text_am' => 'ዓመታዊ ሪፖርቱ ይጽደቅ።', 'text_en' => 'The annual report is approved.',
        ])->assertCreated();
        $motionId = (int) $motion->json('data.id');

        Sanctum::actingAs($this->reviewer, ['*']);
        $this->withTenant()->patchJson(route('api.governance.motions.second', ['motion' => $motionId]))->assertOk();

        Sanctum::actingAs($this->admin, ['*']);
        $this->withTenant()->postJson(route('api.governance.motions.voting.open', ['motion' => $motionId]))->assertOk();
        $this->withTenant()->postJson(route('api.governance.motions.ballots.store', ['motion' => $motionId]), [
            'shareholder_id' => $this->shareholderId, 'choice' => 'for',
        ])->assertCreated();

        $resolution = $this->withTenant()->postJson(route('api.governance.motions.voting.close', ['motion' => $motionId]), [
            'resolution_number' => 'GA-RES-TEST-001',
        ])->assertCreated();
        $this->assertSame('passed', $resolution->json('data.result'));

        $minutes = $this->withTenant()->postJson(route('api.governance.meetings.minutes.store', ['meeting' => $this->meetingId]), [
            'content_am' => 'የጉባኤው ቃለ ጉባኤ። ሪፖርቱ በድምጽ ተወስኗል።',
            'content_en' => 'Minutes of the meeting. The report was decided by vote.',
        ])->assertCreated();
        $minutesId = (int) $minutes->json('data.id');
        $this->withTenant()->postJson(route('api.governance.minutes.submit', ['minutes' => $minutesId]))->assertOk();

        Sanctum::actingAs($this->reviewer, ['*']);
        $this->withTenant()->patchJson(route('api.governance.minutes.review', ['minutes' => $minutesId]), [
            'decision' => 'approved', 'reason' => 'Minutes and resolutions checked against the meeting record.',
        ])->assertOk();

        $this->assertDatabaseHas('governance_meeting_resolutions', [
            'motion_id' => $motionId, 'resolution_number' => 'GA-RES-TEST-001', 'result' => 'passed',
        ]);
        $this->assertDatabaseHas('governance_meeting_minutes', ['id' => $minutesId, 'status' => 'approved']);
    }

    private function registerVerifiedLegalEvidence(): void
    {
        $basisId = DB::table('governance_meeting_legal_bases')->insertGetId([
            'tenant_id' => $this->tenant->id, 'meeting_id' => $this->meetingId,
            'legal_form' => 'share_company', 'meeting_category' => 'ordinary',
            'source_reference' => 'test:verified-operative-instruments',
            'source_sha256' => str_repeat('a', 64), 'status' => 'verified',
            'created_by' => $this->admin->id, 'verified_by' => $this->reviewer->id,
            'verified_at' => now(), 'review_reason' => 'Synthetic test evidence only.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $items = [
            ['notice', 'notice_rule', ['notice_period_days' => 30]],
            ['quorum', 'quorum_rule', ['threshold_numerator' => 1, 'threshold_denominator' => 2, 'basis' => 'shares']],
            ['quorum', 'meeting_category_rule', []],
            ['voting', 'voting_rights_rule', ['votes_per_share_by_class' => ['STANDARD' => 1]]],
            ['voting', 'share_register_basis', []],
            ['resolution', 'resolution_approval_rule', ['threshold_numerator' => 1, 'threshold_denominator' => 2, 'basis' => 'votes_cast']],
            ['minutes', 'minutes_approval_rule', []],
            ['minutes', 'minutes_signatory_authority', ['authorized_user_ids' => [$this->reviewer->id]]],
        ];
        foreach ($items as [$action, $key, $parameters]) {
            DB::table('governance_meeting_legal_evidence')->insert([
                'tenant_id' => $this->tenant->id, 'legal_basis_id' => $basisId,
                'action' => $action, 'evidence_key' => $key,
                'source_reference' => 'test:'.$action.':'.$key,
                'source_sha256' => str_repeat('c', 64),
                'rule_parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                'status' => 'verified', 'created_by' => $this->admin->id,
                'approved_by' => $this->reviewer->id, 'approved_at' => now(),
                'review_reason' => 'Synthetic test evidence only.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function withTenant(): self
    {
        return $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id]);
    }

    private function createUser(string $prefix): User
    {
        return User::create([
            'email' => $prefix.'-'.uniqid().'@meeting.test',
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
            'slug' => $slug, 'name_am' => $slug, 'name_en' => $slug,
            'description_am' => $slug, 'description_en' => $slug,
            'category' => 'governance', 'scope' => 'tenant',
            'tenant_id' => null, 'application_id' => null,
            'is_active' => true, 'is_system' => true,
        ]);
    }
}
