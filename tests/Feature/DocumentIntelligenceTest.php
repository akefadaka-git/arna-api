<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\DocumentIntelligence\Jobs\ProcessOcrDocument;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrPage;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrProcessingJob;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrResult;
use MaaleArna\MASES\Core\Files\Models\File;
use MaaleArna\MASES\Core\Tenancy\Middleware\TenantMembershipMiddleware;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use Tests\TestCase;

final class DocumentIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mases.tenant.context_resolver', RequestTenantResolver::class);
        Config::set('mases.tenant.isolation_enabled', true);
        Config::set('ocr.enabled', true);
        $this->app['router']->aliasMiddleware('tenant.membership', TenantMembershipMiddleware::class);
    }

    public function test_ocr_submission_is_idempotent_and_records_tenant_lifecycle(): void
    {
        [$tenant, $user] = $this->tenantUser(['ocr.job.create', 'file.download']);
        $file = $this->sourceFile($tenant, $user);
        Bus::fake();
        $payload = [
            'file_id' => $file->id,
            'languages' => 'amh+eng',
            'idempotency_key' => 'test-ocr-idempotency-key-001',
        ];

        $first = $this->tenantRequest($user, $tenant)->postJson('/api/ocr/jobs', $payload)->assertStatus(202);
        $second = $this->tenantRequest($user, $tenant)->postJson('/api/ocr/jobs', $payload)->assertStatus(202);

        $jobId = $first->json('data.id');
        $this->assertSame($jobId, $second->json('data.id'));
        $this->assertSame(1, OcrProcessingJob::query()->count());
        $this->assertDatabaseHas('ocr_processing_events', [
            'tenant_id' => $tenant->id,
            'job_id' => $jobId,
            'actor_id' => $user->id,
            'event' => 'job.created',
        ]);
        Bus::assertDispatchedTimes(ProcessOcrDocument::class, 1);
    }

    public function test_reusing_idempotency_key_for_a_different_request_returns_conflict(): void
    {
        [$tenant, $user] = $this->tenantUser(['ocr.job.create', 'file.download']);
        $firstFile = $this->sourceFile($tenant, $user);
        $secondFile = $this->sourceFile($tenant, $user);
        Bus::fake();
        $key = 'same-tenant-wide-idempotency-key';
        $this->tenantRequest($user, $tenant)->postJson('/api/ocr/jobs', [
            'file_id' => $firstFile->id, 'languages' => 'amh+eng', 'idempotency_key' => $key,
        ])->assertStatus(202);

        $this->tenantRequest($user, $tenant)->postJson('/api/ocr/jobs', [
            'file_id' => $secondFile->id, 'languages' => 'amh+eng', 'idempotency_key' => $key,
        ])->assertStatus(409);

        $this->assertSame(1, OcrProcessingJob::query()->count());
    }

    public function test_ocr_result_cannot_be_read_across_tenant_boundary(): void
    {
        [$tenantA, $reader] = $this->tenantUser(['ocr.result.view', 'file.download']);
        [$tenantB, $owner] = $this->tenantUser();
        $file = $this->sourceFile($tenantB, $owner);
        $job = OcrProcessingJob::query()->create([
            'tenant_id' => $tenantB->id,
            'file_id' => $file->id,
            'file_version' => $file->version,
            'requested_by' => $owner->id,
            'idempotency_key' => hash('sha256', 'foreign-tenant-job-key'),
            'request_hash' => hash('sha256', 'foreign-request-hash'),
            'languages' => 'amh+eng',
            'status' => 'awaiting_review',
        ]);
        $result = OcrResult::query()->create([
            'tenant_id' => $tenantB->id,
            'job_id' => $job->id,
            'file_id' => $file->id,
            'file_version' => $file->version,
            'engine_name' => 'tesseract',
            'engine_version' => 'test',
            'languages' => 'amh+eng',
            'full_text' => 'Confidential tenant B content',
            'mean_confidence' => 90,
            'source_checksum' => str_repeat('a', 64),
            'result_checksum' => hash('sha256', 'Confidential tenant B content'),
            'metadata' => ['verification_status' => 'pending'],
        ]);

        $this->tenantRequest($reader, $tenantA)
            ->getJson('/api/ocr/results/'.$result->id)
            ->assertNotFound();

        $this->assertDatabaseHas('ocr_results', [
            'id' => $result->id,
            'tenant_id' => $tenantB->id,
        ]);
    }

    public function test_job_status_permission_does_not_expose_recognized_document_content(): void
    {
        [$tenant, $reader] = $this->tenantUser(['ocr.job.view', 'file.view']);
        $file = $this->sourceFile($tenant, $reader);
        $job = OcrProcessingJob::query()->create([
            'tenant_id' => $tenant->id, 'file_id' => $file->id, 'file_version' => 1,
            'requested_by' => $reader->id, 'idempotency_key' => hash('sha256', 'status-only-key'),
            'request_hash' => hash('sha256', 'status-only-request'),
            'languages' => 'amh+eng', 'status' => 'awaiting_review',
        ]);
        $result = OcrResult::query()->create([
            'tenant_id' => $tenant->id, 'job_id' => $job->id, 'file_id' => $file->id,
            'file_version' => 1, 'engine_name' => 'tesseract', 'engine_version' => 'test',
            'languages' => 'amh+eng', 'full_text' => 'Sensitive recognized content',
            'mean_confidence' => 92, 'source_checksum' => str_repeat('b', 64),
            'result_checksum' => hash('sha256', 'Sensitive recognized content'),
            'metadata' => ['verification_status' => 'pending'],
        ]);
        OcrPage::query()->create([
            'tenant_id' => $tenant->id, 'result_id' => $result->id, 'page_number' => 1,
            'text' => 'Sensitive recognized content', 'mean_confidence' => 92, 'blocks' => [],
        ]);

        $this->tenantRequest($reader, $tenant)->getJson('/api/ocr/jobs/'.$job->id)
            ->assertOk()
            ->assertJsonMissing(['full_text' => 'Sensitive recognized content'])
            ->assertJsonMissing(['text' => 'Sensitive recognized content']);
    }

    public function test_appending_a_correction_invalidates_previous_ocr_verification(): void
    {
        [$tenant, $reviewer] = $this->tenantUser(['ocr.result.correct', 'file.download']);
        $file = $this->sourceFile($tenant, $reviewer);
        $job = OcrProcessingJob::query()->create([
            'tenant_id' => $tenant->id, 'file_id' => $file->id, 'file_version' => 1,
            'requested_by' => $reviewer->id, 'idempotency_key' => hash('sha256', 'correction-test-key'),
            'request_hash' => hash('sha256', 'correction-test-request'),
            'languages' => 'amh+eng', 'status' => 'awaiting_review',
        ]);
        $result = OcrResult::query()->create([
            'tenant_id' => $tenant->id, 'job_id' => $job->id, 'file_id' => $file->id,
            'file_version' => 1, 'engine_name' => 'tesseract', 'engine_version' => 'test',
            'languages' => 'amh+eng', 'full_text' => 'Recognized amount 100',
            'mean_confidence' => 90, 'source_checksum' => str_repeat('c', 64),
            'result_checksum' => hash('sha256', 'Recognized amount 100'),
            'metadata' => ['verification_status' => 'verified'],
        ]);
        OcrPage::query()->create([
            'tenant_id' => $tenant->id, 'result_id' => $result->id, 'page_number' => 1,
            'text' => 'Recognized amount 100', 'mean_confidence' => 90, 'blocks' => [],
        ]);

        $this->tenantRequest($reviewer, $tenant)->postJson('/api/ocr/results/'.$result->id.'/corrections', [
            'page_number' => 1,
            'original_text' => '100',
            'corrected_text' => '1,000',
            'reason' => 'Verified against the source image',
        ])->assertCreated();

        $this->assertDatabaseHas('ocr_results', [
            'id' => $result->id,
            'metadata->verification_status' => 'pending',
        ]);
        $this->assertDatabaseHas('ocr_corrections', [
            'result_id' => $result->id,
            'original_text' => '100',
            'corrected_text' => '1,000',
        ]);
    }

    private function tenantUser(array $permissions = []): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);

        foreach ($permissions as $slug) {
            $permission = Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name_am' => $slug,
                    'name_en' => $slug,
                    'description_am' => $slug,
                    'description_en' => $slug,
                    'category' => 'file',
                    'scope' => 'tenant',
                    'tenant_id' => null,
                    'application_id' => null,
                    'is_active' => true,
                    'is_system' => false,
                ],
            );
            $role = Role::create([
                'name_en' => 'OCR test '.$slug,
                'slug' => 'ocr-test-'.$user->id.'-'.str_replace('.', '-', $slug),
                'scope' => 'tenant',
                'tenant_id' => $tenant->id,
                'application_id' => null,
                'is_active' => true,
            ]);
            $role->permissions()->attach($permission->id, ['assigned_at' => now()]);
            $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => $user->id]);
        }

        return [$tenant, $user];
    }

    private function tenantRequest(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id);
    }

    private function sourceFile(Tenant $tenant, User $user): File
    {
        $suffix = (string) Str::uuid();
        return File::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'original_name' => 'test-document.pdf',
            'name' => 'test-document-'.$suffix.'.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 1024,
            'path' => 'tenant-'.$tenant->id.'/'.$suffix.'-test-document.pdf',
            'disk' => 'local',
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
            'metadata' => [],
        ]);
    }
}
