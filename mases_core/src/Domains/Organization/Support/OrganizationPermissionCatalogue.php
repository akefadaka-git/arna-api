<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Support;

/**
 * Canonical tenant-scoped permission rows owned by the Organization domain.
 *
 * The platform PermissionCatalogue imports this list into its aggregate
 * catalogue so existing central permission seeding remains compatible.
 */
final class OrganizationPermissionCatalogue
{
    /** @var array<int, array<string, mixed>> */
    public const ROWS = [
        ['slug' => 'organization.unit.view', 'name_am' => 'የድርጅት ክፍሎችን ማየት', 'name_en' => 'View Organization Units', 'category' => 'organization', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'organization.unit.manage', 'name_am' => 'የድርጅት ክፍሎችን ማስተዳደር', 'name_en' => 'Manage Organization Units', 'category' => 'organization', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'organization.assignment.view', 'name_am' => 'የድርጅት ምደባዎችን ማየት', 'name_en' => 'View Organization Assignments', 'category' => 'organization', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'organization.assignment.manage', 'name_am' => 'የድርጅት ምደባዎችን ማስተዳደር', 'name_en' => 'Manage Organization Assignments', 'category' => 'organization', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'organization.audit.view', 'name_am' => 'የድርጅት የኦዲት ታሪክን ማየት', 'name_en' => 'View Organization Audit History', 'category' => 'organization', 'scope' => 'tenant', 'is_system' => false],
    ];
}
