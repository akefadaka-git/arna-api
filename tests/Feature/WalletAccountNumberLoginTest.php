<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\LoginIdentifierResolver;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WalletAccountNumberLoginTest extends TestCase
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
    public function wallet_account_number_authenticates_through_the_api_login_endpoint(): void
    {
        $user = User::factory()->create([
            'email' => 'wallet-login@example.test',
            'password' => Hash::make('Password123!'),
            'default_tenant_id' => null,
            'is_active' => true,
        ]);
        $user->tenants()->attach($this->tenant->id);

        $walletId = $this->makeWallet($this->tenant, $user, 'ACC-API-000001');
        $this->makeIdentifier($this->tenant, $walletId, 'ACC-API-000001');

        $response = $this->postJson(route('api.auth.login'), [
            'email' => 'acc-api-000001',
            'password' => 'Password123!',
            'tenant_id' => $this->tenant->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'wallet-login@example.test')
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertNotEmpty($response->json('data.token'));
    }

    #[Test]
    public function active_wallet_account_number_resolves_within_its_tenant(): void
    {
        $user = $this->makeTenantUser($this->tenant);
        $walletId = $this->makeWallet($this->tenant, $user, 'ACC-000001');
        $this->makeIdentifier($this->tenant, $walletId, 'ACC-000001');

        foreach (['ACC-000001', 'acc-000001'] as $identifier) {
            $resolved = $this->resolver->resolve($identifier, $this->tenant->id);

            $this->assertNotNull($resolved, $identifier);
            $this->assertSame($user->id, $resolved['user']->id);
            $this->assertSame($this->tenant->id, $resolved['tenant_id']);
        }

        $this->assertNull($this->resolver->resolve('ACC-000001', Tenant::factory()->create(['is_active' => true])->id));
    }

    #[Test]
    public function inactive_deleted_wallet_identifier_and_inactive_wallet_fail_closed(): void
    {
        $activeIdentifierUser = $this->makeTenantUser($this->tenant);
        $activeWalletId = $this->makeWallet($this->tenant, $activeIdentifierUser, 'ACC-INACTIVE-ID');
        $this->makeIdentifier($this->tenant, $activeWalletId, 'ACC-INACTIVE-ID', false);

        $deletedIdentifierUser = $this->makeTenantUser($this->tenant);
        $deletedWalletId = $this->makeWallet($this->tenant, $deletedIdentifierUser, 'ACC-DELETED-ID');
        $this->makeIdentifier($this->tenant, $deletedWalletId, 'ACC-DELETED-ID', true, now());

        $inactiveWalletUser = $this->makeTenantUser($this->tenant);
        $inactiveWalletId = $this->makeWallet($this->tenant, $inactiveWalletUser, 'ACC-INACTIVE-WALLET', false);
        $this->makeIdentifier($this->tenant, $inactiveWalletId, 'ACC-INACTIVE-WALLET');

        foreach (['ACC-INACTIVE-ID', 'ACC-DELETED-ID', 'ACC-INACTIVE-WALLET'] as $number) {
            $this->assertNull($this->resolver->resolve($number, $this->tenant->id), $number);
        }
    }

    #[Test]
    public function account_number_does_not_resolve_for_inactive_shareholder_user_or_non_member(): void
    {
        $inactiveUser = User::factory()->create(['is_active' => false]);
        $nonMember = User::factory()->create(['is_active' => true]);

        $inactiveHolder = Shareholder::create([
            'tenant_id' => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Inactive Holder',
            'user_id' => $inactiveUser->id,
            'is_active' => true,
        ]);
        $inactiveWallet = $this->insertWallet($this->tenant, $inactiveHolder->id);
        $this->makeIdentifier($this->tenant, $inactiveWallet, 'ACC-INACTIVE-USER');

        $nonMemberHolder = Shareholder::create([
            'tenant_id' => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Non Member',
            'user_id' => $nonMember->id,
            'is_active' => true,
        ]);
        $nonMemberWallet = $this->insertWallet($this->tenant, $nonMemberHolder->id);
        $this->makeIdentifier($this->tenant, $nonMemberWallet, 'ACC-NON-MEMBER');

        $inactiveHolderUser = User::factory()->create(['is_active' => true]);
        $inactiveShareholder = Shareholder::create([
            'tenant_id' => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Disabled Holder',
            'user_id' => $inactiveHolderUser->id,
            'is_active' => false,
        ]);
        // The user is not a member; the inactive shareholder check is still
        // fail-closed regardless of which guard rejects the lookup first.
        $inactiveHolderWallet = $this->insertWallet($this->tenant, $inactiveShareholder->id);
        $this->makeIdentifier($this->tenant, $inactiveHolderWallet, 'ACC-INACTIVE-HOLDER');

        foreach (['ACC-INACTIVE-USER', 'ACC-NON-MEMBER', 'ACC-INACTIVE-HOLDER'] as $number) {
            $this->assertNull($this->resolver->resolve($number, $this->tenant->id), $number);
        }
    }

    private function makeTenantUser(Tenant $tenant): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id);

        return $user;
    }

    private function makeWallet(Tenant $tenant, User $user, string $label, bool $active = true): int
    {
        $holder = Shareholder::create([
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Wallet '.$label,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        return $this->insertWallet($tenant, $holder->id, $active);
    }

    private function insertWallet(Tenant $tenant, int $shareholderId, bool $active = true): int
    {
        return (int) DB::table('wallets')->insertGetId([
            'tenant_id' => $tenant->id,
            'shareholder_id' => $shareholderId,
            'currency' => 'ETB',
            'current_balance' => '0.0000',
            'is_active' => $active,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeIdentifier(
        Tenant $tenant,
        int $walletId,
        string $accountNumber,
        bool $active = true,
        mixed $deletedAt = null,
    ): void {
        DB::table('wallet_account_identifiers')->insert([
            'tenant_id' => $tenant->id,
            'wallet_id' => $walletId,
            'account_number' => $accountNumber,
            'is_active' => $active,
            'deleted_at' => $deletedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
