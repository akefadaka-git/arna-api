<?php

declare(strict_types=1);

namespace Tests\Unit;

use Database\Seeders\Data\PermissionCatalogue;
use PHPUnit\Framework\TestCase;

final class AdminPlatformPermissionCatalogueTest extends TestCase
{
    public function test_platform_console_read_permissions_are_global_and_additive(): void
    {
        $rows = [];
        foreach (PermissionCatalogue::ROWS as $row) {
            $rows[$row['slug']] = $row;
        }

        foreach ([
            'platform.overview.read',
            'platform.applications.read',
            'platform.domains.read',
            'platform.audit.read',
            'platform.security.read',
        ] as $slug) {
            $this->assertArrayHasKey($slug, $rows, sprintf('Missing permission %s', $slug));
            $this->assertSame('global', $rows[$slug]['scope'], sprintf('Permission %s must be global', $slug));
            $this->assertSame('platform', $rows[$slug]['category'], sprintf('Permission %s must be categorized as platform', $slug));
        }
    }
}
