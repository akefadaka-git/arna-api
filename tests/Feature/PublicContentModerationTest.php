<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Str;
use MaaleArna\\MASES\\Core\\Audit\\Models\\AuditLog;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Permission;
use MaaleArna\\MASES\\Core\\Domains\\PublicContent\\Models\\PublicContent;
use Laravel\\Sanctum\\Sanctum;
use Tests\\TestCase;

final class PublicContentModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \\Illuminate\\Support\\Facades\\Mail::shouldReceive('raw')->zeroOrMoreTimes()->andReturnNull();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'content_type' => 'news',
            'title' => 'A public corporate update',
            'body' => str_repeat('This is a proposed public update. ', 3),
            'author_name' => 'Community Contributor',
            'consent' => true,
        ], $overrides);
    }

    private function verifiedGuestProof(string $email = 'writer@example.com'): array
    {
        $challengeId = (string) Str::uuid();
        DB::table('public_content_email_verifications')->insert([
            'id' => $challengeId,
            'email' => mb_strtolower($email),
            'code_hash' => Hash::make('123456'),
            'verification_token_hash' => null,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(15),
            'verified_at' => null,
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/public/content/verification-challenges/verify', [
            'challenge_id' => $challengeId,
            'email' => $email,
            'code' => '123456',
        ])->assertOk();

        return [
            'verification_challenge_id' => $challengeId,
            'verification_token' => $response->json('data.verification_token'),
        ];
    }

    private function reviewer(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes));
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'public-content.review'],
            [
                'name_am' => 'ይፋዊ ይዘት መገምገምና ማተም',
                'name_en' => 'Review and publish public content',
                'scope' => 'global',
                'is_active' => true,
            ],
        );
        $user->permissions()->attach($permission->id);

        return $user;
    }

    public function test_guest_submission_requires_verified_email_and_is_private_pending_review(): void
    {
        $this->postJson('/api/public/content/submissions', $this->payload())
            ->assertUnprocessable();

        $response = $this->postJson('/api/public/content/submissions', [
            ...$this->payload(),
            ...$this->verifiedGuestProof(),
            'author_email' => 'attacker-controlled@example.com',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending_review');
        $this->assertDatabaseHas('public_contents', [
            'public_id' => $response->json('data.reference'),
            'status' => 'pending_review',
            'submitted_by' => null,
            'author_email' => 'writer@example.com',
        ]);
        $this->assertNotNull(DB::table('public_contents')->where('public_id', $response->json('data.reference'))->value('author_email_verified_at'));

        $this->getJson('/api/public/content')->assertOk()->assertJsonCount(0, 'data.data');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'public_content.submitted',
            'resource_type' => 'public_content',
        ]);
    }

    public function test_verification_code_is_rate_limited_attempt_limited_and_single_use(): void
    {
        $proof = $this->verifiedGuestProof();
        $payload = [
            ...$this->payload(),
            ...$proof,
        ];
        $this->postJson('/api/public/content/submissions', $payload)->assertCreated();
        $this->postJson('/api/public/content/submissions', $payload)->assertUnprocessable();

        $challenge = DB::table('public_content_email_verifications')
            ->where('id', $proof['verification_challenge_id'])->first();
        $this->assertNotNull($challenge->consumed_at);
    }

    public function test_only_published_content_is_visible_publicly(): void
    {
        $pending = PublicContent::create($this->payload(['author_email' => 'writer@example.com']));
        $published = PublicContent::create($this->payload([
            'title' => 'Approved public announcement',
            'author_email' => 'writer@example.com',
        ]));
        $published->status = 'published';
        $published->published_at = now()->subMinute();
        $published->save();

        $this->getJson('/api/public/content')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $published->public_id)
            ->assertJsonMissing(['id' => $pending->public_id]);

        $this->getJson('/api/public/content/' . $pending->public_id)->assertNotFound();
    }

    public function test_authenticated_submission_uses_verified_account_email_not_client_input(): void
    {
        $author = $this->reviewer();
        Sanctum::actingAs($author);

        $response = $this->postJson('/api/public/content/submissions', [
            ...$this->payload(),
            'author_email' => 'spoofed@example.com',
        ])->assertCreated();

        $this->assertDatabaseHas('public_contents', [
            'public_id' => $response->json('data.reference'),
            'author_email' => mb_strtolower($author->email),
            'submitted_by' => $author->id,
        ]);
    }

    public function test_author_cannot_approve_own_submission_even_with_review_permission(): void
    {
        $author = $this->reviewer();
        Sanctum::actingAs($author);

        $submission = $this->postJson('/api/public/content/submissions', $this->payload())
            ->assertCreated()
            ->json('data.reference');
        $item = PublicContent::query()->where('public_id', $submission)->firstOrFail();

        $this->postJson('/api/admin/public-content/' . $item->id . '/approve', [
            'expected_version' => 1,
        ])->assertForbidden();
        $this->postJson('/api/admin/public-content/' . $item->id . '/reject', [
            'expected_version' => 1,
            'reason' => 'Cannot self-review.',
        ])->assertForbidden();

        $this->assertDatabaseHas('public_contents', [
            'id' => $item->id,
            'status' => 'pending_review',
        ]);
    }

    public function test_reviewer_cannot_moderate_guest_submission_from_their_verified_email(): void
    {
        $reviewer = $this->reviewer();
        $proof = $this->verifiedGuestProof($reviewer->email);
        $response = $this->postJson('/api/public/content/submissions', [
            ...$this->payload(),
            ...$proof,
        ])->assertCreated();

        Sanctum::actingAs($reviewer);
        $item = PublicContent::query()->where('public_id', $response->json('data.reference'))->firstOrFail();
        $this->postJson('/api/admin/public-content/' . $item->id . '/approve', [
            'expected_version' => 1,
        ])->assertForbidden();
    }

    public function test_reviewer_must_approve_before_publish_and_actions_are_audited(): void
    {
        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);
        $item = PublicContent::create($this->payload(['author_email' => 'writer@example.com']));

        $this->postJson('/api/admin/public-content/' . $item->id . '/publish', [
            'expected_version' => 1,
        ])->assertStatus(409);

        $this->postJson('/api/admin/public-content/' . $item->id . '/approve', [
            'expected_version' => 1,
            'reason' => 'Reviewed and approved.',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->postJson('/api/admin/public-content/' . $item->id . '/publish', [
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'public_content.approve',
            'resource_id' => (string) $item->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'public_content.publish',
            'resource_id' => (string) $item->id,
        ]);
    }

    public function test_stale_version_is_rejected_and_material_edit_preserves_verified_identity(): void
    {
        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);
        $item = PublicContent::create($this->payload([
            'author_email' => 'writer@example.com',
            'author_email_verified_at' => now(),
        ]));
        $item->status = 'approved';
        $item->reviewed_by = $reviewer->id;
        $item->reviewed_at = now();
        $item->save();

        $this->putJson('/api/admin/public-content/' . $item->id, [
            'expected_version' => 1,
            ...$this->payload(['title' => 'A materially revised corporate update']),
            'author_email' => 'changed@example.com',
        ])->assertOk()->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.version', 2);

        $this->postJson('/api/admin/public-content/' . $item->id . '/approve', [
            'expected_version' => 1,
        ])->assertStatus(409);

        $this->assertDatabaseHas('public_contents', [
            'id' => $item->id,
            'status' => 'pending_review',
            'version' => 2,
            'reviewed_by' => null,
            'author_email' => 'writer@example.com',
        ]);
    }

    public function test_due_scheduled_content_is_published_and_audited_by_scheduler(): void
    {
        $item = PublicContent::create($this->payload(['author_email' => 'writer@example.com']));
        $item->status = 'scheduled';
        $item->scheduled_at = now()->subMinute();
        $item->save();

        $this->artisan('public-content:publish-scheduled')->assertSuccessful();

        $this->assertDatabaseHas('public_contents', [
            'id' => $item->id,
            'status' => 'published',
            'scheduled_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'public_content.scheduled_publish',
            'resource_id' => (string) $item->id,
        ]);
    }

    public function test_admin_queue_requires_the_dedicated_permission(): void
    {
        $ordinaryUser = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($ordinaryUser);

        $this->getJson('/api/admin/public-content')->assertForbidden();
    }
}
