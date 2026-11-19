<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RotateDemoSeederCredentialsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_rotates_seeded_accounts_revokes_tokens_and_preserves_unrelated_accounts(): void
    {
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->create([
            'email' => 'superadmin@maalearna.com.et',
            'password' => Hash::make('legacy-shared-test-secret'),
            'default_tenant_id' => $tenant->id,
        ]);
        $shareholder = User::factory()->create([
            'password' => Hash::make('legacy-shared-test-secret'),
            'default_tenant_id' => $tenant->id,
        ]);
        $unrelated = User::factory()->create([
            'password' => Hash::make('unrelated-test-secret'),
        ]);

        DB::table('shareholders')->insert([
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'user_id' => $shareholder->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin->createToken('rotation-test');
        $shareholder->createToken('rotation-test');
        $unrelated->createToken('rotation-test');

        DB::table('password_reset_tokens')->insert([
            ['email' => $admin->email, 'token' => 'admin-reset-hash', 'created_at' => now()],
            ['email' => $shareholder->email, 'token' => 'shareholder-reset-hash', 'created_at' => now()],
        ]);

        $exitCode = Artisan::call('auth:rotate-demo-seeder-credentials', ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Rotated 2 seeded account(s)', $output);
        $this->assertStringNotContainsString('legacy-shared-test-secret', $output);

        $this->assertFalse(Hash::check('legacy-shared-test-secret', $admin->fresh()->password));
        $this->assertFalse(Hash::check('legacy-shared-test-secret', $shareholder->fresh()->password));
        $this->assertTrue(Hash::check('unrelated-test-secret', $unrelated->fresh()->password));

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => $unrelated->getMorphClass(),
            'tokenable_id' => $unrelated->id,
        ]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $admin->email]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $shareholder->email]);
    }
}
