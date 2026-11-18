<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Database\Seeders;

/**
 * Tenant-scoped RBAC capabilities for the formal Documents workflow.
 *
 * These permissions are necessary but not sufficient for governance-
 * controlled decisions. Approval, signing, issuance, revocation and
 * supersession also require a matching active Governance authority grant.
 */
final class DocumentsPermissionCatalogue
{
    /** @var array<int, array<string, mixed>> */
    public const ROWS = [
        ['slug' => 'documents.view', 'name_am' => 'ሰነዶችን ማየት', 'name_en' => 'View Documents', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.create', 'name_am' => 'ሰነድ መፍጠር', 'name_en' => 'Create Document Drafts', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.update', 'name_am' => 'ረቂቅ ሰነድ ማሻሻል', 'name_en' => 'Update Document Drafts', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.submit', 'name_am' => 'ሰነድ ለግምገማ ማስገባት', 'name_en' => 'Submit Documents', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.review', 'name_am' => 'ሰነድ መገምገም', 'name_en' => 'Review Documents', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.return', 'name_am' => 'ሰነድ ለማስተካከያ መመለስ', 'name_en' => 'Return Documents', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.reject', 'name_am' => 'ሰነድ ውድቅ ማድረግ', 'name_en' => 'Reject Documents', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.approve', 'name_am' => 'ሰነድ ማጽደቅ', 'name_en' => 'Approve Documents', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.sign', 'name_am' => 'ሰነድ መፈረም', 'name_en' => 'Sign Documents', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.issue', 'name_am' => 'የመጨረሻ ሰነድ ማውጣት', 'name_en' => 'Issue Documents', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.withdraw', 'name_am' => 'ሰነድ ማንሳት', 'name_en' => 'Withdraw Documents', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.revoke', 'name_am' => 'የተሰጠ ሰነድ መሻር', 'name_en' => 'Revoke Issued Documents', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'documents.supersede', 'name_am' => 'ሰነድን በአዲስ ሰነድ መተካት', 'name_en' => 'Supersede Issued Documents', 'category' => 'documents', 'scope' => 'tenant', 'is_system' => false],
    ];
}
