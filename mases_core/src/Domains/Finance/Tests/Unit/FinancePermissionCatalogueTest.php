<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Unit;

use Database\Seeders\Data\PermissionCatalogue;
use MaaleArna\MASES\Core\Domains\Finance\Database\Seeders\FinancePermissionCatalogue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinancePermissionCatalogueTest extends TestCase
{
    #[Test]
    public function platform_catalogue_composes_each_finance_permission_exactly_once(): void
    {
        $financeSlugs = array_column(FinancePermissionCatalogue::ROWS, 'slug');
        $platformSlugs = array_column(PermissionCatalogue::ROWS, 'slug');

        $this->assertCount(12, $financeSlugs);
        $this->assertCount(count($financeSlugs), array_unique($financeSlugs));

        foreach ($financeSlugs as $slug) {
            $this->assertSame(
                1,
                count(array_filter($platformSlugs, static fn (string $candidate): bool => $candidate === $slug)),
                "Expected Finance permission [{$slug}] exactly once in the platform catalogue.",
            );
        }
    }
    
    #[Test]
    public function platform_application_and_role_registries_compose_finance_owned_definitions(): void
    {
        $this->assertSame(FinanceApplicationCatalogue::ROWS['finance'], ApplicationRegistry::ROWS['finance']);

        foreach (FinanceRoleCatalogue::ROWS as $slug => $definition) {
            $this->assertSame($definition, RoleCatalogue::ROWS[$slug] ?? null);
        }

        foreach (FinanceRolePermissionGrants::MAP as $role => $financeGrants) {
            $actual = RolePermissionGrants::MAP[$role] ?? [];
            foreach ($financeGrants as $grant) {
                $this->assertSame(
                    1,
                    count(array_filter($actual, static fn (string $candidate): bool => $candidate === $grant)),
                    "Expected Finance grant [{$grant}] exactly once for role [{$role}].",
                );
            }
        }
    }
}
