<?php

declare(strict_types=1);

namespace Tests\Unit;

use MaaleArna\MASES\Core\Authorization\Models\Permission;
use Database\Seeders\RolePermissionSeeder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RolePermissionSeederScopeExclusionTest extends TestCase
{
    public function test_scope_grant_can_exclude_sensitive_permission_prefixes(): void
    {
        $permissions = [
            'file.view' => $this->permission(11, 'file.view', 'tenant'),
            'ocr.result.verify' => $this->permission(12, 'ocr.result.verify', 'tenant'),
            'ocr.engine.manage' => $this->permission(13, 'ocr.engine.manage', 'tenant'),
            'tenant.list' => $this->permission(14, 'tenant.list', 'global'),
        ];

        $method = new ReflectionMethod(RolePermissionSeeder::class, 'resolvePermissionIds');
        $method->setAccessible(true);
        $ids = $method->invoke(new RolePermissionSeeder(), ['scope:tenant:exclude:ocr.'], $permissions);

        self::assertSame([11], $ids);
    }

    public function test_existing_scope_grant_syntax_remains_backward_compatible(): void
    {
        $permissions = [
            'file.view' => $this->permission(21, 'file.view', 'tenant'),
            'ocr.result.verify' => $this->permission(22, 'ocr.result.verify', 'tenant'),
            'tenant.list' => $this->permission(23, 'tenant.list', 'global'),
        ];

        $method = new ReflectionMethod(RolePermissionSeeder::class, 'resolvePermissionIds');
        $ids = $method->invoke(new RolePermissionSeeder(), ['scope:tenant'], $permissions);

        self::assertSame([21, 22], $ids);
    }

    private function permission(int $id, string $slug, string $scope): Permission
    {
        $permission = new Permission();
        $permission->setRawAttributes(['id' => $id, 'slug' => $slug, 'scope' => $scope]);
        return $permission;
    }
}
