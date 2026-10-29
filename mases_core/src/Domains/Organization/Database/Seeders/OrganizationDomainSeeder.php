<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * Organization's safe seed-integrity gate.
 *
 * Core reference seeders own permission and role writes. This seeder
 * verifies their result and required schema, but never invents a tenant's
 * organizational chart or overwrites operational Organization records.
 */
final class OrganizationDomainSeeder extends Seeder
{
    /**
     * @param array<string, mixed> $permissions Canonical permission map.
     * @param array<string, Role> $roles Canonical role map.
     */
    public function seed(Tenant $tenant, array $permissions, array $roles): void
    {
        foreach ([
            'organization_units',
            'organization_assignments',
            'organization_audit_events',
            'permissions',
        ] as $table) {
            if (!Schema::hasTable($table)) {
                throw new InvalidArgumentException(
                    "Organization seeding requires table [{$table}]; run the approved migrations first."
                );
            }
        }

        $organizationPermissions = (new OrganizationPermissionSeeder())->seed($permissions);
        $tenantAdmin = $roles['tenant_admin'] ?? null;

        if (!$tenantAdmin instanceof Role) {
            throw new InvalidArgumentException('Organization seeding requires the canonical tenant_admin role.');
        }

        if ((int) $tenantAdmin->tenant_id !== (int) $tenant->id) {
            throw new InvalidArgumentException('The tenant_admin role does not belong to the seeded tenant.');
        }

        $grantedSlugs = $tenantAdmin->permissions()
            ->whereIn('permissions.slug', array_keys($organizationPermissions))
            ->pluck('permissions.slug')
            ->all();

        $missingGrants = array_values(array_diff(array_keys($organizationPermissions), $grantedSlugs));

        if ($missingGrants !== []) {
            throw new InvalidArgumentException(
                'Organization tenant_admin grants are incomplete: '.implode(', ', $missingGrants)
            );
        }

        // No default unit is created: authoritative unit codes, names,
        // hierarchy, and accountable owner must be supplied by approved data.
    }
}
