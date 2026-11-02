<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use Tests\TestCase;

final class GovernanceElectionEndpointsTest extends TestCase
{
    public function test_election_collection_requires_authenticated_session(): void
    {
        $this->getJson('/api/governance/elections')->assertUnauthorized();
    }

    public function test_election_creation_requires_authenticated_session(): void
    {
        $this->postJson('/api/governance/elections', [
            'code' => 'BOARD-TEST-01',
            'title' => 'Test election',
        ])->assertUnauthorized();
    }
}
