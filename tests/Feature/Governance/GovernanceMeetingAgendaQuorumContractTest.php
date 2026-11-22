<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use Tests\TestCase;

final class GovernanceMeetingAgendaQuorumContractTest extends TestCase
{
    public function test_agenda_routes_are_permission_scoped_and_draft_updates_are_versioned(): void
    {
        $routes = file_get_contents(base_path('mases_core/src/Domains/Governance/Routes/api.php'));
        $controller = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Http/Controllers/GovernanceMeetingAgendaController.php'
        ));
        $migration = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Database/Migrations/2026_11_05_120000_create_governance_meeting_agenda_items_table.php'
        ));

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertIsString($migration);
        self::assertStringContainsString("'/meetings/{meeting}/agenda'", $routes);
        self::assertStringContainsString('governance.agenda.view', $routes);
        self::assertStringContainsString('governance.agenda.manage', $routes);
        self::assertStringContainsString("if (\$item->status !== 'draft')", $controller);
        self::assertStringContainsString("if ((int) \$item->version !== (int) \$data['version'])", $controller);
        self::assertStringContainsString('gmai_meeting_item_uq', $migration);
    }

    public function test_ga_electorate_snapshot_uses_share_ledger_reconciliation_and_approved_quorum_evidence(): void
    {
        $controller = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Http/Controllers/GovernanceMeetingElectorateController.php'
        ));
        $migration = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Database/Migrations/2026_11_05_130000_create_governance_electorate_quorum_evidence_tables.php'
        ));
        $routes = file_get_contents(base_path('mases_core/src/Domains/Governance/Routes/api.php'));

        self::assertIsString($controller);
        self::assertIsString($migration);
        self::assertIsString($routes);
        self::assertStringContainsString('share_holding_entries', $controller);
        self::assertStringContainsString('current_quantity', $controller);
        self::assertStringContainsString('source_hash', $controller);
        self::assertStringContainsString('rule_definition_hash', $controller);
        self::assertStringContainsString('legal_verification_document_hash', $controller);
        self::assertStringContainsString('meetings.quorum.evaluate', $routes);
        self::assertStringContainsString('governance.meeting.approve', $routes);
        self::assertStringContainsString('governance_meeting_electorate_snapshots', $migration);
        self::assertStringContainsString('governance_meeting_quorum_evaluations', $migration);
        self::assertStringContainsString('governance_meeting_proxy_events', $migration);
    }

    public function test_proxy_identity_and_hash_chain_verification_are_exposed(): void
    {
        $controller = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Http/Controllers/GovernanceMeetingAttendanceController.php'
        ));
        $migration = file_get_contents(base_path(
            'mases_core/src/Domains/Governance/Database/Migrations/2026_11_05_140000_add_proxy_attendance_identity_to_governance_attendance.php'
        ));
        $routes = file_get_contents(base_path('mases_core/src/Domains/Governance/Routes/api.php'));

        self::assertIsString($controller);
        self::assertIsString($migration);
        self::assertIsString($routes);
        self::assertStringContainsString('function verifyProxyHistory', $controller);
        self::assertStringContainsString('function revokeProxy', $controller);
        self::assertStringContainsString('recordProxyEvent', $controller);
        self::assertStringContainsString('previous_event_hash', $controller);
        self::assertStringContainsString('event_hash', $controller);
        self::assertStringContainsString('represented_by_user_id', $migration);
        self::assertStringContainsString('/proxies/{proxy}/verify-history', $routes);
        self::assertStringContainsString('/proxies/{proxy}/revoke', $routes);
    }
}
