<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $actor = User::factory()->create();

        $this->expectException(NotFoundHttpException::class);
        app(CalendarDomainResourceAuthorizer::class)->resolveAndAuthorize(
            $actor, 1, 'finance', 'arbitrary_model', '1',
        );
    }

    public function test_global_person_reference_requires_active_tenant_access_and_owning_domain_permission(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        app(TenantContextService::class)->setTenantById($tenant->getKey());
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

        try {
            app(CalendarDomainResourceAuthorizer::class)->resolveAndAuthorize(
                $actor, $tenant->getKey(), 'people', 'person', (string) $person->getKey(),
            );
            $this->fail('A person reference must not bypass the owning-domain people.manage permission.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_person_reference_without_tenant_access_is_not_resolvable(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        app(TenantContextService::class)->setTenantById($tenant->getKey());
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

}
