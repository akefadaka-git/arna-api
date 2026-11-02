<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\DocumentIntelligence\Jobs\ProcessOcrDocument;
use MaaleArna\MASES\Core\DocumentIntelligence\Models\OcrProcessingEvent;
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
        [$tenant, $user] = $this->tenantUser(['ocr.job.create']);
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
        Bus::assertDispatched(ProcessOcrDocument::class, 1);
    }

    public function test_ocr_result_cannot_be_read_across_tenant_boundary(): void
    {
        [$tenantA, $reader] = $this->tenantUser(['ocr.result.view']);
        [$tenantB, $owner] = $this->tenantUser();
        $file = $this->sourceFile($tenantB, $owner);
        $job = OcrProcessingJob::query()->create([
            'tenant_id' => $tenantB->id,
            'file_id' => $file->id,
            'file_version' => $file->version,
            'requested_by' => $owner->id,
            'idempotency_key' => hash('sha256', 'foreign-tenant-job-key'),
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
        return File::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'original_name' => 'test-document.pdf',
            'name' => 'test-document.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 1024,
            'path' => 'tenant-'.$tenant->id.'/test-document.pdf',
            'disk' => 'local',
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
            'metadata' => [],
        ]);
    }
}
