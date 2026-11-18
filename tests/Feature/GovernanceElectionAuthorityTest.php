<?php

declare(strict_types=1);

namespace Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionAuthorityService;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class GovernanceElectionAuthorityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function election_creation_is_planned_and_does_not_claim_authority(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);

        $election = app(GovernanceElectionAuthorityService::class)->createPlanned(
            (int) $tenant->getKey(),
            'BOARD-2026-01',
            'Board of Directors Election',
            null,
            null,
            $actor,
        );

        $this->assertSame('planned', $election->status);
        $this->assertSame('unverified', $election->authority_status);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->getKey(),
            'action' => 'governance.election.created',
            'resource_type' => 'governance_election',
            'resource_id' => (string) $election->getKey(),
        ]);
    }

    #[Test]
    public function authority_evidence_is_digest_bound_and_remains_pending_after_recording(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $service = app(GovernanceElectionAuthorityService::class);
        $election = $service->createPlanned((int) $tenant->getKey(), 'BOARD-2026-02', 'Board Election', null, null, $actor);

        $evidence = $service->recordAuthorityEvidence(
            (int) $tenant->getKey(),
            (int) $election->getKey(),
            'general_assembly_resolution',
            'GA-RES-0001',
            '2026-11-01',
            'private-documents/election/ga-resolution-0001.pdf',
            str_repeat('a', 64),
            $actor,
        );

        $this->assertSame('pending_review', $evidence->status);
        $this->assertSame('unverified', $election->fresh()->authority_status);
        $this->assertSame('planned', $election->fresh()->status);
    }

    #[Test]
    public function evidence_recorder_cannot_review_their_own_evidence(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $service = app(GovernanceElectionAuthorityService::class);
        $election = $service->createPlanned((int) $tenant->getKey(), 'BOARD-2026-03', 'Board Election', null, null, $actor);
        $evidence = $service->recordAuthorityEvidence(
            (int) $tenant->getKey(),
            (int) $election->getKey(),
            'articles_of_association',
            'AOA-CERTIFIED-COPY',
            null,
            'private-documents/election/aoa.pdf',
            str_repeat('b', 64),
            $actor,
        );

        $this->expectException(DomainException::class);
        $service->reviewAuthorityEvidence(
            (int) $tenant->getKey(),
            (int) $election->getKey(),
            (int) $evidence->getKey(),
            'reviewed',
            'Evidence record checked against the source.',
            $actor,
        );
    }

    #[Test]
    public function independent_reviewer_can_review_evidence_without_certifying_election_authority(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $recorder = User::factory()->create(['is_active' => true]);
        $reviewer = User::factory()->create(['is_active' => true]);
        $service = app(GovernanceElectionAuthorityService::class);
        $election = $service->createPlanned((int) $tenant->getKey(), 'BOARD-2026-04', 'Board Election', null, null, $recorder);
        $evidence = $service->recordAuthorityEvidence(
            (int) $tenant->getKey(),
            (int) $election->getKey(),
            'board_resolution',
            'BOARD-RES-0001',
            null,
            'private-documents/election/board-resolution.pdf',
            str_repeat('c', 64),
            $recorder,
        );

        $reviewed = $service->reviewAuthorityEvidence(
            (int) $tenant->getKey(),
            (int) $election->getKey(),
            (int) $evidence->getKey(),
            'reviewed',
            'Reference and stored digest were reviewed.',
            $reviewer,
        );

        $this->assertSame('reviewed', $reviewed->status);
        $this->assertSame('unverified', $election->fresh()->authority_status);
        $this->assertNull($election->fresh()->authority_verified_at ?? null);
    }

    #[Test]
    public function missing_audit_storage_rolls_back_election_creation(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        config(['mases.features.audit_enabled' => false]);

        $this->expectException(RuntimeException::class);
        try {
            app(GovernanceElectionAuthorityService::class)->createPlanned(
                (int) $tenant->getKey(),
                'BOARD-2026-05',
                'Board Election',
                null,
                null,
                $actor,
            );
        } finally {
            $this->assertDatabaseMissing('governance_elections', [
                'tenant_id' => $tenant->getKey(),
                'code' => 'BOARD-2026-05',
            ]);
        }
    }
}
