<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Calendar\Services\CalendarDomainResourceAuthorizer;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class CalendarDomainResourceAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_domain_resource_types_fail_closed(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        app(TenantContextService::class)->setTenantById($tenant->getKey());
        $actor = User::factory()->create();

        $this->expectException(NotFoundHttpException::class);
        app(CalendarDomainResourceAuthorizer::class)->resolveAndAuthorize(
            $actor, 1, 'finance', 'arbitrary_model', '1',
        );
    }

    public function test_person_reference_uses_canonical_read_policy_without_people_manage_permission(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        app(TenantContextService::class)->setTenantById($tenant->getKey());
        $this->grantTenantMembership($tenant, $actor);

        $person = Person::query()->create([
            'first_name_am' => 'ሙከራ',
            'first_name_en' => 'Test',
        ]);
        PersonTenantAccess::query()->create([
            'person_id' => $person->getKey(),
            'tenant_id' => $tenant->getKey(),
            'created_by' => $actor->getKey(),
            'status' => 'active',
        ]);

        $resolved = app(CalendarDomainResourceAuthorizer::class)->resolveAndAuthorize(
            $actor, $tenant->getKey(), 'people', 'person', (string) $person->getKey(),
        );

        $this->assertSame($person->getKey(), $resolved->getKey());
        $this->assertFalse($actor->permissions()->where('slug', 'people.manage')->exists());
    }

    public function test_person_reference_is_denied_without_active_tenant_membership(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        app(TenantContextService::class)->setTenantById($tenant->getKey());
        $person = Person::query()->create(['first_name_en' => 'Tenant member required']);
        PersonTenantAccess::query()->create([
            'person_id' => $person->getKey(),
            'tenant_id' => $tenant->getKey(),
            'created_by' => $actor->getKey(),
            'status' => 'active',
        ]);

        $this->expectException(AuthorizationException::class);
        app(CalendarDomainResourceAuthorizer::class)->resolveAndAuthorize(
            $actor, $tenant->getKey(), 'people', 'person', (string) $person->getKey(),
        );
    }

    public function test_person_reference_without_tenant_access_is_not_resolvable(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        app(TenantContextService::class)->setTenantById($tenant->getKey());
        $this->grantTenantMembership($tenant, $actor);
        $person = Person::query()->create(['first_name_en' => 'Outside tenant']);

        $this->expectException(NotFoundHttpException::class);
        app(CalendarDomainResourceAuthorizer::class)->resolveAndAuthorize(
            $actor, $tenant->getKey(), 'people', 'person', (string) $person->getKey(),
        );
    }

    public function test_resource_authorization_rejects_a_tenant_context_mismatch(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        app(TenantContextService::class)->setTenantById($otherTenant->getKey());

        $this->expectException(NotFoundHttpException::class);
        app(CalendarDomainResourceAuthorizer::class)->resolveAndAuthorize(
            $actor, $tenant->getKey(), 'finance', 'legal_entity', '1',
        );
    }

    private function grantTenantMembership(Tenant $tenant, User $actor): void
    {
        DB::table('tenant_memberships')->insert([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $actor->getKey(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
