<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\LoginIdentifierResolver;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoginIdentifierResolverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private LoginIdentifierResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['is_active' => true]);
        $this->resolver = new LoginIdentifierResolver();
    }

    #[Test]
    public function email_username_and_phone_resolve_only_for_tenant_members(): void
    {
        $user = User::factory()->create([
            'email' => 'identity@example.test',
            'username' => 'identity-user',
            'phone' => '+251911000001',
            'is_active' => true,
        ]);
        $user->tenants()->attach($this->tenant->id);

        foreach (['IDENTITY@example.test', 'identity-user', '+251911000001'] as $identifier) {
            $resolved = $this->resolver->resolve($identifier, $this->tenant->id);

            $this->assertNotNull($resolved, $identifier);
            $this->assertSame($user->id, $resolved['user']->id);
            $this->assertSame($this->tenant->id, $resolved['tenant_id']);
        }

        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $this->assertNull($this->resolver->resolve('identity-user', $otherTenant->id));
    }

    #[Test]
    public function ambiguous_phone_match_fails_closed(): void
    {
        $first = User::factory()->create([
            'email' => 'phone-one@example.test',
            'phone' => '+251911000002',
            'is_active' => true,
        ]);
        $second = User::factory()->create([
            'email' => 'phone-two@example.test',
            'phone' => '+251911000002',
            'is_active' => true,
        ]);

        $first->tenants()->attach($this->tenant->id);
        $second->tenants()->attach($this->tenant->id);

        $this->assertNull($this->resolver->resolve('+251911000002', $this->tenant->id));
    }

    #[Test]
    public function identifier_collision_across_email_and_username_fails_closed(): void
    {
        $emailUser = User::factory()->create([
            'email' => 'collision@example.test',
            'is_active' => true,
        ]);
        $usernameUser = User::factory()->create([
            'email' => 'different@example.test',
            'username' => 'collision@example.test',
            'is_active' => true,
        ]);

        $emailUser->tenants()->attach($this->tenant->id);
        $usernameUser->tenants()->attach($this->tenant->id);

        $this->assertNull($this->resolver->resolve('collision@example.test', $this->tenant->id));
    }

    #[Test]
    public function omitted_tenant_fails_closed_when_user_has_multiple_memberships_and_no_default(): void
    {
        $user = User::factory()->create([
            'email' => 'multi-tenant@example.test',
            'default_tenant_id' => null,
            'is_active' => true,
        ]);
        $user->tenants()->attach($this->tenant->id);

        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $user->tenants()->attach($otherTenant->id);

        $this->assertNull($this->resolver->resolve('multi-tenant@example.test'));
    }

    #[Test]
    public function shareholder_number_and_legacy_alias_resolve_through_linked_user(): void
    {
        $user = User::factory()->create([
            'email' => 'shareholder-login@example.test',
            'is_active' => true,
        ]);
        $user->tenants()->attach($this->tenant->id);

        $shareholder = Shareholder::create([
            'tenant_id' => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Shareholder Login',
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $shareholder->forceFill(['shareholder_number' => 'SH-000001'])->save();

        DB::table('shareholder_legacy_aliases')->insert([
            'tenant_id' => $this->tenant->id,
            'legacy_number' => 'SH-0001',
            'legacy_number_key' => 'SH-0001',
            'source_system' => 'test-fixture',
            'source_record_key' => (string) $shareholder->id,
            'shareholder_id' => $shareholder->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['SH-000001', 'SH-0001'] as $identifier) {
            $resolved = $this->resolver->resolve($identifier, $this->tenant->id);

            $this->assertNotNull($resolved, $identifier);
            $this->assertSame($user->id, $resolved['user']->id);
        }
    }

    #[Test]
    public function domain_identifiers_fail_closed_when_the_schema_or_link_is_missing(): void
    {
        $this->assertNull($this->resolver->resolve('EMP-000001', $this->tenant->id));
        $this->assertNull($this->resolver->resolve('WAL-000001', $this->tenant->id));
    }
}
