<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Database\Seeders;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Authorization\Models\Permission;

/**
 * Validates Organization permission records produced by the platform's
 * canonical PermissionSeeder. The platform catalogue remains the only
 * write source, avoiding a second permission upsert path.
 */
final class OrganizationPermissionSeeder
{
    /** @var list<string> */
    private const REQUIRED_SLUGS = [
        'organization.unit.view',
        'organization.unit.manage',
        'organization.numbering.manage',
        'organization.assignment.view',
        'organization.assignment.manage',
        'organization.audit.view',
    ];

    /**
     * @param array<string, Permission> $permissions Indexed by canonical slug.
     * @return array<string, Permission>
     */
    public function seed(array $permissions): array
    {
        $resolved = [];

        foreach (self::REQUIRED_SLUGS as $slug) {
            $permission = $permissions[$slug] ?? null;

            if (!$permission instanceof Permission) {
                throw new InvalidArgumentException(
                    "Organization permission [{$slug}] was not created by the canonical permission seeder."
                );
            }

            if ($permission->scope !== 'tenant' || $permission->category !== 'organization') {
                throw new InvalidArgumentException(
                    "Organization permission [{$slug}] must remain tenant-scoped and categorized as organization."
                );
            }

            $resolved[$slug] = $permission;
        }

        return $resolved;
    }
}
