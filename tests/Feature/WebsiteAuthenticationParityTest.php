<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Auth\Services\TotpMfaService;
use MaaleArna\Arna\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WebsiteAuthenticationParityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('identifierCases')]
    public function website_login_endpoint_accepts_each_supported_identifier_type(string $type): void
    {
        $fixture = $this->makeIdentifierFixture();
        $identifier = $fixture['identifiers'][$type];
        $tenant = $fixture['tenant'];
        $user = $fixture['user'];

        $response = $this->postJson(route('api.auth.login'), [
            // The website intentionally retains the legacy email wire key.
            'email' => $identifier,
            'password' => 'Password123!',
            'tenant_id' => $tenant->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertNotEmpty($response->json('data.token'), $type);
    }

    public static function identifierCases(): array
    {
        return [
            'email' => ['email'],
            'username' => ['username'],
            'mobile number' => ['mobile number'],
            'employee ID' => ['employee ID'],
            'shareholder number' => ['shareholder number'],
            'wallet number' => ['wallet number'],
        ];
    }

    /**
     * @return array{tenant:Tenant,user:User,identifiers:array<string,string>}
     */
    private function makeIdentifierFixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'email' => 'website-parity@example.test',
            'username' => 'website-parity-user',
            'phone' => '+251911000041',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);

        $shareholder = Shareholder::create([
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Website Parity',
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $shareholder->forceFill(['shareholder_number' => 'SH-WEB-000041'])->save();

        DB::table('employees')->insert([
            'tenant_id' => $tenant->id,
            'employee_number' => 'EMP-WEB-000041',
            'user_id' => $user->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $walletId = (int) DB::table('wallets')->insertGetId([
            'tenant_id' => $tenant->id,
            'shareholder_id' => $shareholder->id,
            'currency' => 'ETB',
            'current_balance' => '0.0000',
            'is_active' => true,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('wallet_account_identifiers')->insert([
            'tenant_id' => $tenant->id,
            'wallet_id' => $walletId,
            'account_number' => 'ACC-WEB-000041',
            'is_active' => true,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'tenant' => $tenant,
            'user' => $user,
            'identifiers' => [
                'email' => $user->email,
                'username' => 'website-parity-user',
                'mobile number' => '+251911000041',
                'employee ID' => 'EMP-WEB-000041',
                'shareholder number' => 'SH-WEB-000041',
                'wallet number' => 'ACC-WEB-000041',
            ],
        ];
    }

    #[Test]
    public function website_password_login_completes_a_totp_challenge(): void
    {
        [$user, $tenant, $secret] = $this->makeEnrolledUser('website-totp@example.test');
        $challengeToken = app(TotpMfaService::class)->createLoginChallenge($user, (int) $tenant->id, false);

        $response = $this->postJson(route('api.auth.login'), [
            'email' => $user->email,
            'password' => 'Password123!',
            'tenant_id' => $tenant->id,
            'mfa_challenge_token' => $challengeToken,
            'mfa_code' => $this->currentTotp($secret, 1),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertNotEmpty($response->json('data.token'));
    }

    #[Test]
    public function website_password_login_completes_and_consumes_a_recovery_code(): void
    {
        [$user, $tenant, , $recoveryCodes] = $this->makeEnrolledUser('website-recovery@example.test');
        $challengeToken = app(TotpMfaService::class)->createLoginChallenge($user, (int) $tenant->id, false);
        $recoveryCode = $recoveryCodes[0];

        $response = $this->postJson(route('api.auth.login'), [
            'email' => $user->email,
            'password' => 'Password123!',
            'tenant_id' => $tenant->id,
            'mfa_challenge_token' => $challengeToken,
            'mfa_code' => $recoveryCode,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertTrue(
            DB::table('mfa_recovery_codes')
                ->where('user_id', $user->id)
                ->whereNotNull('used_at')
                ->exists(),
            'The successful website recovery-code login must consume the one-time code.',
        );
    }

    /**
     * @return array{0:User,1:Tenant,2:string,3:list<string>}
     */
    private function makeEnrolledUser(string $email): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);

        $mfa = app(TotpMfaService::class);
        $enrollment = $mfa->beginEnrollment($user);
        $recoveryCodes = $mfa->confirmEnrollment(
            $user->fresh(),
            $this->currentTotp($enrollment['secret']),
        );

        $this->assertIsArray($recoveryCodes);

        return [$user->fresh(), $tenant, $enrollment['secret'], $recoveryCodes];
    }

    private function currentTotp(string $secret, int $stepOffset = 0): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $key = '';
        foreach (str_split($secret) as $char) {
            $buffer = ($buffer << 5) | strpos($alphabet, $char);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $key .= chr(($buffer >> $bits) & 0xff);
            }
        }

        $counter = intdiv(now()->timestamp, 30) + $stepOffset;
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
