<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\AuthenticationService;
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
    public function phone_login_normalizes_common_input_separators_without_guessing_country_code(): void
    {
        $user = User::factory()->create([
            'email' => 'phone-format@example.test',
            'phone' => '+251911000001',
            'is_active' => true,
        ]);
        $user->tenants()->attach($this->tenant->id);

        foreach (['+251 911 000 001', '+251 (911) 000-001', '+251.911.000.001'] as $identifier) {
            $resolved = $this->resolver->resolve($identifier, $this->tenant->id);

            $this->assertNotNull($resolved, $identifier);
            $this->assertSame($user->id, $resolved['user']->id);
        }

        $nationalFormatUser = User::factory()->create([
            'email' => 'phone-national@example.test',
            'phone' => '0911000001',
            'is_active' => true,
        ]);
        $nationalFormatUser->tenants()->attach($this->tenant->id);

        $this->assertNull($this->resolver->resolve('+251911000001', $this->tenant->id));
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

    #[Test]
    public function eligible_direct_identifier_after_twenty_ineligible_candidates_is_not_hidden(): void
    {
        for ($index = 0; $index < 20; $index++) {
            User::factory()->create([
                'email' => sprintf('ineligible-%02d@example.test', $index),
                'phone' => '+251911000099',
                'is_active' => true,
            ]);
        }

        $eligible = User::factory()->create([
            'email' => 'eligible-after-limit@example.test',
            'phone' => '+251911000099',
            'is_active' => true,
        ]);
        $eligible->tenants()->attach($this->tenant->id);

        $resolved = $this->resolver->resolve('+251911000099', $this->tenant->id);

        $this->assertNotNull($resolved);
        $this->assertSame($eligible->id, $resolved['user']->id);
    }

    #[Test]
    public function optional_domain_resolution_fails_closed_for_partial_schemas(): void
    {
        Schema::dropIfExists('employees');
        Schema::create('employees', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('employee_number');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::dropIfExists('wallets');
        Schema::create('wallets', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('wallet_number');
            $table->unsignedBigInteger('shareholder_id');
        });

        $this->assertNull($this->resolver->resolve('EMP-000001', $this->tenant->id));
        $this->assertNull($this->resolver->resolve('WAL-000001', $this->tenant->id));

        // Restore the migrated employee schema so later tests exercise the
        // production-shaped table, not this deliberately incomplete fixture.
        Schema::dropIfExists('employees');
        Schema::create('employees', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('employee_number', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'employee_number'], 'employees_tenant_number_unique');
        });
    }

    #[Test]
    public function login_does_not_persist_the_selected_tenant_as_the_default(): void
    {
        $user = User::factory()->create([
            'email' => 'tenant-choice@example.test',
            'password' => Hash::make('correct-password'),
            'default_tenant_id' => null,
            'is_active' => true,
        ]);
        $user->tenants()->attach($this->tenant->id);

        $secondTenant = Tenant::factory()->create(['is_active' => true]);
        $user->tenants()->attach($secondTenant->id);

        $result = app(AuthenticationService::class)->login(
            $user->email,
            'correct-password',
            false,
            $secondTenant->id,
        );

        $this->assertNotEmpty($result['token']);
        $this->assertSame($user->id, $result['user']->id);
        $this->assertNull($user->fresh()->default_tenant_id);
    }
    #[Test]
    public function active_employee_number_resolves_to_its_linked_user_within_the_requested_tenant(): void
    {
        $user = User::factory()->create([
            'email' => 'employee-login@example.test',
            'password' => Hash::make('correct-password'),
            'is_active' => true,
        ]);
        $user->tenants()->attach($this->tenant->id);

        DB::table('employees')->insert([
            'tenant_id' => $this->tenant->id,
            'employee_number' => 'EMP-000001',
            'user_id' => $user->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['EMP-000001', 'emp-000001'] as $identifier) {
            $resolved = $this->resolver->resolve($identifier, $this->tenant->id);

            $this->assertNotNull($resolved, $identifier);
            $this->assertSame($user->id, $resolved['user']->id);
            $this->assertSame($this->tenant->id, $resolved['tenant_id']);
        }

        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $this->assertNull($this->resolver->resolve('EMP-000001', $otherTenant->id));
    }

    #[Test]
    public function inactive_deleted_or_unlinked_employee_cannot_resolve_for_login(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->tenants()->attach($this->tenant->id);

        $rows = [
            ['employee_number' => 'EMP-INACTIVE', 'user_id' => $user->id, 'is_active' => false, 'deleted_at' => null],
            ['employee_number' => 'EMP-DELETED', 'user_id' => $user->id, 'is_active' => true, 'deleted_at' => now()],
            ['employee_number' => 'EMP-UNLINKED', 'user_id' => null, 'is_active' => true, 'deleted_at' => null],
        ];

        foreach ($rows as $row) {
            DB::table('employees')->insert([
                'tenant_id' => $this->tenant->id,
                'employee_number' => $row['employee_number'],
                'user_id' => $row['user_id'],
                'is_active' => $row['is_active'],
                'deleted_at' => $row['deleted_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertNull($this->resolver->resolve($row['employee_number'], $this->tenant->id));
        }
    }

    #[Test]
    public function employee_number_does_not_resolve_for_an_inactive_user_or_non_member(): void
    {
        $inactiveUser = User::factory()->create(['is_active' => false]);
        $nonMember = User::factory()->create(['is_active' => true]);

        foreach ([
            ['number' => 'EMP-INACTIVE-USER', 'user_id' => $inactiveUser->id],
            ['number' => 'EMP-NON-MEMBER', 'user_id' => $nonMember->id],
        ] as $row) {
            DB::table('employees')->insert([
                'tenant_id' => $this->tenant->id,
                'employee_number' => $row['number'],
                'user_id' => $row['user_id'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertNull($this->resolver->resolve($row['number'], $this->tenant->id));
        }
    }

}
