<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\PublicContent\Models\PublicContent;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PublicContentModerationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'content_type' => 'news',
            'title' => 'A public corporate update',
            'body' => str_repeat('This is a proposed public update. ', 3),
            'author_name' => 'Community Contributor',
            'author_email' => 'writer@example.com',
            'consent' => true,
        ], $overrides);
    }

    private function reviewer(): User
    {
        $user = User::factory()->create(['is_active' => true]);
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

    public function test_guest_submission_is_private_and_created_pending_review(): void
    {
        $response = $this->postJson('/api/public/content/submissions', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending_review');

        $this->assertDatabaseHas('public_contents', [
            'public_id' => $response->json('data.reference'),
            'status' => 'pending_review',
            'submitted_by' => null,
        ]);

        $this->getJson('/api/public/content')->assertOk()->assertJsonCount(0, 'data.data');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'public_content.submitted',
            'resource_type' => 'public_content',
        ]);
    }

    public function test_only_published_content_is_visible_publicly(): void
    {
        $pending = PublicContent::create($this->payload());
        $published = PublicContent::create($this->payload([
            'title' => 'Approved public announcement',
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

    public function test_reviewer_cannot_moderate_anonymous_submission_using_their_account_email(): void
    {
        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);
        $item = PublicContent::create($this->payload(['author_email' => $reviewer->email]));

        $this->postJson('/api/admin/public-content/' . $item->id . '/approve', [
            'expected_version' => 1,
        ])->assertForbidden();

        $this->assertDatabaseHas('public_contents', [
            'id' => $item->id,
            'status' => 'pending_review',
        ]);
    }

    public function test_reviewer_must_approve_before_publish_and_actions_are_audited(): void
    {
        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);
        $item = PublicContent::create($this->payload());

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

    public function test_stale_version_is_rejected_and_material_edit_resets_approval(): void
    {
        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);
        $item = PublicContent::create($this->payload());
        $item->status = 'approved';
        $item->reviewed_by = $reviewer->id;
        $item->reviewed_at = now();
        $item->save();

        $this->putJson('/api/admin/public-content/' . $item->id, [
            'expected_version' => 1,
            ...$this->payload(['title' => 'A materially revised corporate update']),
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
        ]);
    }

    public function test_due_scheduled_content_is_published_and_audited_by_scheduler(): void
    {
        $item = PublicContent::create($this->payload());
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
