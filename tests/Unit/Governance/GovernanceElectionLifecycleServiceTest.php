<?php

declare(strict_types=1);

namespace Tests\Unit\Governance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionLifecycleService;
use RuntimeException;
use Tests\TestCase;

final class GovernanceElectionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_election_is_planned_and_physical_only(): void
    {
        $election = app(GovernanceElectionLifecycleService::class)
            ->createPlanned(1, 'BOARD-2026-01', 'Board election');

        self::assertSame('planned', $election->status);
        self::assertSame('physical', $election->voting_method);
        self::assertFalse($election->isAuthorityVerified());
    }

    public function test_nominations_cannot_open_without_verified_authority(): void
    {
        $service = app(GovernanceElectionLifecycleService::class);
        $election = $service->createPlanned(1, 'BOARD-2026-02', 'Board election');

        $this->expectException(RuntimeException::class);
        $service->openNominations(1, (int) $election->id, 'approved-rules-v1', '2026-12-01 09:00:00', '2026-12-10 17:00:00');
    }

    public function test_physical_voting_cannot_open_without_frozen_register(): void
    {
        $service = app(GovernanceElectionLifecycleService::class);
        $election = $service->createPlanned(1, 'BOARD-2026-03', 'Board election');
        $service->verifyAuthority(1, (int) $election->id, 'SIGNED-GA-RESOLUTION-REF', 99);
        $service->openNominations(1, (int) $election->id, 'approved-rules-v1', '2026-12-01 09:00:00', '2026-12-10 17:00:00');

        $this->expectException(RuntimeException::class);
        $service->openPhysicalVoting(1, (int) $election->id, '2026-12-15 09:00:00', '2026-12-15 17:00:00');
    }
}
