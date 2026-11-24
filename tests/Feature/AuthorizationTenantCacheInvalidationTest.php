<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use Tests\TestCase;

final class AuthorizationTenantCacheInvalidationTest extends TestCase
{
    public function test_tenant_invalidation_advances_only_the_matching_tenant_generation(): void
    {
        $user = new User();
        $user->id = 77;

        $cache = Cache::store('array');
        $cache->put('auth:user_tenant_version:77:101', 0, 31536000);
        $cache->put('auth:user_tenant_version:77:202', 0, 31536000);
        $cache->put('auth:user_version:77', 0, 31536000);

        (new AuthorizationService($cache))->clearUserTenantCache($user, 101);

        $this->assertSame(1, $cache->get('auth:user_tenant_version:77:101'));
        $this->assertSame(0, $cache->get('auth:user_tenant_version:77:202'));
        $this->assertSame(0, $cache->get('auth:user_version:77'));
    }

    public function test_tenant_invalidation_initializes_a_generation_counter_when_absent(): void
    {
        $user = new User();
        $user->id = 78;

        $cache = Cache::store('array');
        (new AuthorizationService($cache))->clearUserTenantCache($user, 101);

        $this->assertSame(1, $cache->get('auth:user_tenant_version:78:101'));
    }

    public function test_application_invalidation_advances_only_the_requested_application_and_tenant(): void
    {
        $user = new User();
        $user->id = 79;

        $cache = Cache::store('array');
        $cache->put('auth:user_application_version:79:4:101', 0, 31536000);
        $cache->put('auth:user_application_version:79:4:202', 0, 31536000);
        $cache->put('auth:user_application_version:79:5:101', 0, 31536000);

        (new AuthorizationService($cache))->clearUserApplicationCache($user, 4, 101);

        $this->assertSame(1, $cache->get('auth:user_application_version:79:4:101'));
        $this->assertSame(0, $cache->get('auth:user_application_version:79:4:202'));
        $this->assertSame(0, $cache->get('auth:user_application_version:79:5:101'));
    }

    public function test_application_wildcard_invalidation_advances_all_tenant_contexts_for_that_application(): void
    {
        $user = new User();
        $user->id = 80;

        $cache = Cache::store('array');
        (new AuthorizationService($cache))->clearUserApplicationCache($user, 4);

        $this->assertSame(1, $cache->get('auth:user_application_version:80:4:*'));
    }


}
