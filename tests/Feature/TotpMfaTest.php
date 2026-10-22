<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\TotpMfaService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TotpMfaTest extends TestCase
{
    use RefreshDatabase;

    private TotpMfaService $mfa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mfa = new TotpMfaService();
    }

    #[Test]
    public function it_enrolls_totp_and_returns_recovery_codes_once(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $enrollment = $this->mfa->beginEnrollment($user);
        $this->assertStringStartsWith('otpauth://totp/', $enrollment['otpauth_url']);

        $stored = $user->fresh()->totp_pending_secret;
        $this->assertNotSame($enrollment['secret'], $stored);
        $this->assertSame($enrollment['secret'], Crypt::decryptString($stored));

        $codes = $this->mfa->confirmEnrollment($user->fresh(), $this->currentTotp($enrollment['secret']));
        $this->assertIsArray($codes);
        $this->assertCount(10, $codes);
        $this->assertTrue($this->mfa->isEnabled($user->fresh()));
        $this->assertDatabaseCount('mfa_recovery_codes', 10);
        $this->assertNotSame($codes[0], DB::table('mfa_recovery_codes')->first()->code_hash);
    }

    #[Test]
    public function login_challenge_requires_second_factor_and_is_single_use(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);

        $token = $this->mfa->createLoginChallenge($user, (int) $tenant->id, false);
        $this->assertFalse($this->mfa->completeLoginChallenge($user, (int) $tenant->id, $token, '000000' === $this->currentTotp($secret) ? '000001' : '000000'));
        $this->assertTrue($this->mfa->completeLoginChallenge($user, (int) $tenant->id, $token, $this->currentTotp($secret, 1)));
        $this->assertFalse($this->mfa->completeLoginChallenge($user, (int) $tenant->id, $token, $this->currentTotp($secret)));
    }

    #[Test]
    public function recovery_code_is_consumed_after_successful_use(): void
    {
        [$user, $secret, $codes] = $this->enrolledUser();
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $token = $this->mfa->createLoginChallenge($user, (int) $tenant->id, false);

        $this->assertTrue($this->mfa->completeLoginChallenge($user, (int) $tenant->id, $token, $codes[0]));
        $nextToken = $this->mfa->createLoginChallenge($user, (int) $tenant->id, false);
        $this->assertFalse($this->mfa->completeLoginChallenge($user, (int) $tenant->id, $nextToken, $codes[0]));
    }

    #[Test]
    public function login_does_not_issue_a_token_until_totp_is_verified(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);

        $first = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mfa_required', true);

        $challenge = $first->json('data.challenge_token');
        $this->assertIsString($challenge);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->id,
            'mfa_challenge_token' => $challenge,
            'mfa_code' => $this->currentTotp($secret, 1),
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
    }

    #[Test]
    public function expired_login_challenges_fail_closed(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $token = $this->mfa->createLoginChallenge($user, (int) $tenant->id, false);
        DB::table('login_mfa_challenges')->where('user_id', $user->id)->update(['expires_at' => now()->subSecond()]);

        $this->assertFalse($this->mfa->completeLoginChallenge($user, (int) $tenant->id, $token, $this->currentTotp($secret)));
    }

    private function enrolledUser(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $enrollment = $this->mfa->beginEnrollment($user);
        $codes = $this->mfa->confirmEnrollment($user, $this->currentTotp($enrollment['secret']));

        return [$user->fresh(), $enrollment['secret'], $codes];
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

        $counter = intdiv(time(), 30) + $stepOffset;
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
