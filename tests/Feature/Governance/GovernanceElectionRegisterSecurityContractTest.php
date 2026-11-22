<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use Tests\TestCase;

final class GovernanceElectionRegisterSecurityContractTest extends TestCase
{
    public function test_register_routes_are_tenant_scoped_and_use_dedicated_permission(): void
    {
        $routes = file_get_contents(base_path('mases_core/src/Domains/Governance/Routes/api.php'));

        self::assertIsString($routes);
        self::assertStringContainsString("'/elections/{election}/registers'", $routes);
        self::assertStringContainsString("'/elections/{election}/registers/{snapshot}/reconcile'", $routes);
        self::assertStringContainsString("'/elections/{election}/registers/{snapshot}/approve-freeze'", $routes);
        self::assertStringContainsString('auth.permission:governance.election.register.manage', $routes);
        self::assertStringContainsString('GovernanceElectionRegisterController::class', $routes);
    }

    public function test_register_controller_resolves_tenant_and_never_accepts_client_calculated_voters(): void
    {
        $controller = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionRegisterController.php'
        ));

        self::assertIsString($controller);
        self::assertStringContainsString('forTenant($tenantId)', $controller);
        self::assertStringContainsString('mases_tenant_id()', $controller);
        self::assertStringContainsString('reconstructAsOf(', $controller);
        self::assertStringNotContainsString("'entries' =>", $controller);
        self::assertStringNotContainsString("'voters' =>", $controller);
    }

    public function test_register_requires_independent_preparation_reconciliation_and_approval(): void
    {
        $service = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Services/GovernanceElectionRegisterService.php'
        ));

        self::assertIsString($service);
        self::assertStringContainsString('The register preparer cannot independently reconcile the register.', $service);
        self::assertStringContainsString('The approver must be independent of both preparation and reconciliation.', $service);
        self::assertStringContainsString('hasValidSnapshotIntegrity', $service);
        self::assertStringContainsString('AgeRegisterAttestation::verify', $service);
        self::assertStringContainsString('AgeReceiptOutbox::enqueue', $service);
    }

    public function test_ballot_custody_and_manual_count_routes_use_separate_permissions_and_do_not_accept_weighted_totals(): void
    {
        $routes = file_get_contents(base_path('mases_core/src/Domains/Governance/Routes/api.php'));
        $controller = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Http/Controllers/GovernanceElectionManualCountController.php'
        ));
        $service = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Services/GovernanceElectionManualCountService.php'
        ));
        $roles = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Database/Seeders/GovernanceRolesSeeder.php'
        ));

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertIsString($service);
        self::assertStringContainsString('governance.election.ballot.issue', $routes);
        self::assertStringContainsString('governance.election.ballot.receive', $routes);
        self::assertStringContainsString('governance.election.count.record', $routes);
        self::assertStringContainsString('governance.election.count.witness', $routes);
        self::assertStringNotContainsString('share_vote_weight', $controller);
        self::assertStringNotContainsString('share_vote_weight', $service);
        self::assertStringContainsString('appointment_evidence_id', $service);
        self::assertStringContainsString('person_tenant_accesses', $service);
        self::assertStringNotContainsString('governance.election.ballot.issue', $roles);
        self::assertStringNotContainsString('governance.election.count.record', $roles);
    }

    public function test_no_share_class_voting_rate_or_voting_method_is_activated_by_default(): void
    {
        $governance = require base_path('mases_core/src/Domains/Governance/Config/governance.php');

        self::assertSame([], $governance['election_voting']['verified_class_entitlements']);
        self::assertSame(['physical', 'electronic', 'proxy', 'hybrid'], $governance['election_voting']['methods_in_scope']);
        self::assertFalse($governance['features']['physical_voting_enabled']);
        self::assertFalse($governance['features']['electronic_voting_enabled']);
        self::assertFalse($governance['features']['proxy_voting_enabled']);
        self::assertFalse($governance['features']['hybrid_voting_enabled']);
    }

    public function test_register_permission_is_registered_but_not_implicitly_granted_to_roles(): void
    {
        $permissions = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Database/Seeders/GovernancePermissionsSeeder.php'
        ));
        $roles = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Database/Seeders/GovernanceRolesSeeder.php'
        ));

        self::assertIsString($permissions);
        self::assertIsString($roles);
        self::assertStringContainsString('governance.election.register.manage', $permissions);
        self::assertStringNotContainsString('governance.election.register.manage', $roles);
    }

    public function test_register_schema_enforces_tenant_scoped_parent_relationships_and_preserves_evidence(): void
    {
        $migration = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Database/Migrations/2026_11_22_100000_create_governance_election_register_tables.php'
        ));

        self::assertIsString($migration);
        self::assertStringContainsString("references(['tenant_id', 'id'])->on('governance_elections')", $migration);
        self::assertStringContainsString("references(['tenant_id', 'id'])->on('governance_election_register_snapshots')", $migration);
        self::assertStringContainsString('Refusing to remove voter-register evidence', $migration);
    }
}
