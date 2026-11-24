<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Support\\Facades\\Cache;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use MaaleArna\\MASES\\Core\\Authorization\\Services\\AuthorizationService;
use Tests\\TestCase;

final class AuthorizationTenantCacheInvalidationTest extends TestCase
{
    public function test_tenant_invalidation_removes_only_matching_tenant_permission_entries(): void
    {
        $user = new User();
        $user->id = 77;

        $cache = Cache::store('array');
        $tenantA = 'auth:permission:77:finance.journal.post:101:4';
        $tenantB = 'auth:permission:77:finance.journal.post:202:4';
        $global = 'auth:permission:77:user.profile.view:null:null';
        $registry = 'auth:user_keys:77';

        $cache->put($tenantA, true, 300);
        $cache->put($tenantB, true, 300);
        $cache->put($global, true, 300);
        $cache->put($registry, [$tenantA, $tenantB, $global], 600);

        (new AuthorizationService($cache))->clearUserTenantCache($user, 101);

        $this->assertNull($cache->get($tenantA));
        $this->assertTrue($cache->get($tenantB));
        $this->assertTrue($cache->get($global));
        $this->assertSame([$tenantB, $global], $cache->get($registry));
    }

    public function test_tenant_invalidation_forgets_registry_when_no_entries_remain(): void
    {
        $user = new User();
        $user->id = 78;

        $cache = Cache::store('array');
        $tenantKey = 'auth:permission:78:tenant.membership.manage:101:null';
        $registry = 'auth:user_keys:78';
        $cache->put($tenantKey, true, 300);
        $cache->put($registry, [$tenantKey], 600);

        (new AuthorizationService($cache))->clearUserTenantCache($user, 101);

        $this->assertNull($cache->get($tenantKey));
        $this->assertNull($cache->get($registry));
    }
}
