<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\Data\PermissionCatalogue;
use Database\Seeders\Data\RolePermissionGrants;
use MaaleArna\MASES\Core\Domains\Documents\Database\Seeders\DocumentsPermissionCatalogue;
use MaaleArna\MASES\Core\Domains\Documents\Database\Seeders\DocumentsRolePermissionGrants;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAuthorityGrantService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceActingAssignmentService;
use Tests\TestCase;

final class DocumentsWorkflowAuthorizationCatalogueTest extends TestCase
{
    public function test_document_workflow_permissions_are_registered_as_tenant_scoped_capabilities(): void
    {
        $catalogue = collect(PermissionCatalogue::ROWS)->keyBy('slug');

        $this->assertCount(13, DocumentsPermissionCatalogue::ROWS);
        foreach (DocumentsPermissionCatalogue::ROWS as $row) {
            $this->assertSame('documents', $row['category']);
            $this->assertSame('tenant', $row['scope']);
            $this->assertFalse($row['is_system']);
            $this->assertTrue($catalogue->has($row['slug']), 'Missing permission '.$row['slug']);
        }
        $this->assertCount(73, PermissionCatalogue::ROWS);
    }

    public function test_document_role_grants_are_least_privilege_and_composed_into_the_canonical_seed_map(): void
    {
        $this->assertSame(
            DocumentsRolePermissionGrants::MAP['manager'],
            array_values(array_intersect(
                DocumentsRolePermissionGrants::MAP['manager'],
                RolePermissionGrants::MAP['manager'],
            )),
        );

        $this->assertContains('documents.create', RolePermissionGrants::MAP['manager']);
        $this->assertNotContains('documents.approve', RolePermissionGrants::MAP['manager']);
        $this->assertContains('documents.submit', RolePermissionGrants::MAP['secretary']);
        $this->assertNotContains('documents.approve', RolePermissionGrants::MAP['secretary']);
        $this->assertContains('documents.approve', RolePermissionGrants::MAP['board']);
        $this->assertContains('documents.sign', RolePermissionGrants::MAP['president']);
        $this->assertContains('documents.issue', RolePermissionGrants::MAP['president']);
        $this->assertSame(['*'], RolePermissionGrants::MAP['super_admin']);
    }

    public function test_governed_document_decisions_require_an_explicit_governance_action_grant(): void
    {
        foreach (DocumentsRolePermissionGrants::MAP['president'] as $permission) {
            if (in_array($permission, [
                'documents.review',
                'documents.return',
                'documents.reject',
                'documents.approve',
                'documents.sign',
                'documents.issue',
                'documents.withdraw',
                'documents.revoke',
                'documents.supersede',
            ], true)) {
                $this->assertContains($permission, GovernanceAuthorityGrantService::ACTIONS);
            }
        }

        $this->assertNotContains('documents.create', GovernanceAuthorityGrantService::ACTIONS);
        $this->assertNotContains('documents.submit', GovernanceAuthorityGrantService::ACTIONS);
        foreach (GovernanceAuthorityGrantService::ACTIONS as $action) {
            if (str_starts_with($action, 'documents.')) {
                $this->assertContains($action, GovernanceActingAssignmentService::ACTIONS);
            }
        }
        $this->assertNotContains('documents.create', GovernanceActingAssignmentService::ACTIONS);
    }
}
