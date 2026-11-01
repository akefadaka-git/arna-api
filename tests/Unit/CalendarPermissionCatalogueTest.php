<?php

declare(strict_types=1);

namespace Tests\Unit;

use Database\Seeders\Data\PermissionCatalogue;
use Database\Seeders\Data\RolePermissionGrants;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CalendarPermissionCatalogueTest extends TestCase
{
    #[Test]
    public function calendar_permissions_are_canonical_and_tenant_scoped(): void
    {
        $rows = collect(PermissionCatalogue::ROWS)->keyBy('slug');
        foreach (['calendar.view', 'calendar.create', 'calendar.update', 'calendar.reserve'] as $slug) {
            $this->assertArrayHasKey($slug, $rows);
            $this->assertSame('tenant', $rows[$slug]['scope']);
            $this->assertSame('calendar', $rows[$slug]['category']);
            $this->assertFalse($rows[$slug]['is_system']);
        }
    }

    #[Test]
    public function calendar_role_grants_follow_least_privilege(): void
    {
        $grants = RolePermissionGrants::MAP;
        $this->assertContains('calendar.view', $grants['shareholder']);
        $this->assertContains('calendar.view', $grants['board']);
        $this->assertContains('calendar.create', $grants['manager']);
        $this->assertNotContains('calendar.reserve', $grants['manager']);
        $this->assertNotContains('calendar.reserve', $grants['shareholder']);
        $this->assertNotContains('calendar.reserve', $grants['board']);
        $this->assertContains('calendar.reserve', $grants['ceo']);
        $this->assertContains('scope:tenant', $grants['tenant_admin']);
    }
}
