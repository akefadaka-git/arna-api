<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Documents\Models\DocumentWorkflowAggregate;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentWorkflowAggregateService;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentWorkflowAuthorizationService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Mockery;
use Tests\TestCase;

final class DocumentWorkflowAggregateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_submission_review_return_and_resume_are_persisted_as_atomic_audited_transitions(): void
    {
        [$tenantId, $legalEntityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $fileId = $this->file($tenantId, (int) $actor->getKey());

        $authorization = Mockery::mock(DocumentWorkflowAuthorizationService::class);
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantId, 'documents.create');
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantId, 'documents.submit');
        $authorization->shouldReceive('requireGovernedAction')->once()
            ->with($actor, $tenantId, 9, 4, 'documents.review', (int) $actor->getKey());
        $authorization->shouldReceive('requireGovernedAction')->once()
            ->with($actor, $tenantId, 9, 4, 'documents.return', (int) $actor->getKey());
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantId, 'documents.update');
        $this->app->instance(DocumentWorkflowAuthorizationService::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft(
            $actor,
            $tenantId,
            $legalEntityId,
            $unitId,
            '01',
            'Board correspondence',
            9,
            4,
        );

        self::assertSame('draft', $workflow->status);
        self::assertSame(0, (int) $workflow->revision);

        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'submit', 0, null, [
            'submitted_file_id' => $fileId,
            'submitted_file_version' => 1,
        ]);
        self::assertSame('submitted', $workflow->status);
        self::assertSame(1, (int) $workflow->revision);
        self::assertSame($fileId, (int) $workflow->submitted_file_id);

        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'start_review', 1);
        self::assertSame('in_review', $workflow->status);

        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'return', 2, 'Correct the reference date.');
        self::assertSame('returned', $workflow->status);

        $workflow = $service->transition($actor, $tenantId, (int) $workflow->id, 'return_to_draft', 3);
        self::assertSame('draft', $workflow->status);
        self::assertSame(4, (int) $workflow->revision);
        self::assertSame(5, $workflow->events()->count());
    }

    public function test_stale_revision_and_cross_tenant_access_are_rejected(): void
    {
        [$tenantA, $entityA, $unitA] = $this->issuer();
        [$tenantB] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);

        $authorization = Mockery::mock(DocumentWorkflowAuthorizationService::class);
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantA, 'documents.create');
        $authorization->shouldReceive('requirePermission')->once()->with($actor, $tenantA, 'documents.submit');
        $this->app->instance(DocumentWorkflowAuthorizationService::class, $authorization);

        $service = $this->app->make(DocumentWorkflowAggregateService::class);
        $workflow = $service->createDraft($actor, $tenantA, $entityA, $unitA, '01', 'Tenant A record');

        try {
            $service->transition($actor, $tenantA, (int) $workflow->id, 'submit', 99);
            self::fail('A stale aggregate revision must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            self::assertSame('draft', $workflow->fresh()->status);
        }

        $this->expectException(ModelNotFoundException::class);
        $service->transition($actor, $tenantB, (int) $workflow->id, 'submit', 0);
    }

    public function test_workflow_event_rows_are_immutable_at_the_database_layer(): void
    {
        [$tenantId, $legalEntityId, $unitId] = $this->issuer();
        $actor = User::factory()->create(['is_active' => true]);
        $authorization = Mockery::mock(DocumentWorkflowAuthorizationService::class);
        $authorization->shouldReceive('requirePermission')->once();
        $this->app->instance(DocumentWorkflowAuthorizationService::class, $authorization);

        $workflow = $this->app->make(DocumentWorkflowAggregateService::class)
            ->createDraft($actor, $tenantId, $legalEntityId, $unitId, '01', 'Immutable event test');
        $eventId = (int) $workflow->events()->value('id');

        try {
            DB::table('document_workflow_events')->where('id', $eventId)->update(['reason' => 'tampered']);
            self::fail('The database must reject workflow event updates.');
        } catch (QueryException) {
            self::assertDatabaseHas('document_workflow_events', ['id' => $eventId, 'reason' => null]);
        }

        $this->expectException(QueryException::class);
        DB::table('document_workflow_events')->where('id', $eventId)->delete();
    }

    /** @return array{int,int,int} */
    private function issuer(): array
    {
        $tenantId = (int) Tenant::factory()->create(['is_active' => true])->getKey();
        $entityId = (int) DB::table('legal_entities')->insertGetId([
            'tenant_id' => $tenantId,
            'code' => 'DOC-'.uniqid(),
            'name_en' => 'Workflow Test Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('organization_units')->insertGetId([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $entityId,
            'code' => 'UNIT-'.uniqid(),
            'unit_type' => 'department',
            'name_en' => 'Records',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantId, $entityId, $unitId];
    }

    private function file(int $tenantId, int $userId): int
    {
        return (int) DB::table('files')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'original_name' => 'source.pdf',
            'name' => 'source.pdf',
            'size' => 12,
            'path' => 'documents/source.pdf',
            'disk' => 'local',
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
