<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Source-level guardrails for register row locking and tenant-scoped mutations.
 * Runtime lock semantics still require database integration tests on MySQL.
 */
final class AgeVoterRegisterLockingContractTest extends TestCase
{
    private function source(string $path): string
    {
        $fullPath = base_path($path);
        self::assertFileExists($fullPath);

        return (string) file_get_contents($fullPath);
    }

    public function test_reconciliation_locks_scoped_voter_rows_before_digest_verification(): void
    {
        $service = $this->source('mases_core/src/Domains/Governance/Services/GovernanceElectionRegisterService.php');
        $start = strpos($service, 'public function reconcile(');
        $end = strpos($service, 'public function hasValidSnapshotIntegrity(', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($service, $start, $end - $start);

        self::assertStringContainsString("where('tenant_id', $tenantId)", $method);
        self::assertStringContainsString("where('election_id', $electionId)", $method);
        self::assertStringContainsString("where('snapshot_id', $snapshotId)", $method);
        self::assertStringContainsString('->lockForUpdate()', $method);
        self::assertStringContainsString('snapshotContentHash(', $method);
    }

    public function test_approval_holds_voter_row_locks_while_rechecking_integrity_and_freezing(): void
    {
        $service = $this->source('mases_core/src/Domains/Governance/Services/GovernanceElectionRegisterService.php');

        self::assertStringContainsString('hasValidSnapshotIntegrity($tenantId, $electionId, $snapshotId, true)', $service);
        self::assertStringContainsString('if ($lockRows)', $service);
        self::assertStringContainsString('$voterRows->lockForUpdate();', $service);
        self::assertStringContainsString("'register_status' => 'frozen'", $service);
    }

    public function test_register_mutations_and_integrity_checks_are_tenant_and_election_scoped(): void
    {
        $service = $this->source('mases_core/src/Domains/Governance/Services/GovernanceElectionRegisterService.php');

        self::assertGreaterThanOrEqual(3, substr_count($service, "where('tenant_id', $tenantId)"));
        self::assertGreaterThanOrEqual(3, substr_count($service, "where('election_id', $electionId)"));
        self::assertStringContainsString("->where('id', $snapshotId)->first()", $service);
    }
}
