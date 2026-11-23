<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\TotpMfaService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PasswordRecoveryOtpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function verified_otp_resets_password_and_revokes_all_existing_tokens(): void
    {
        $phone = '+251911123456';
        $user = User::factory()->create(['is_active' => true, 'phone' => $phone]);
        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_phone_hash' => hash('sha256', $phone),
        ])->save();

        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user->forceFill(['default_tenant_id' => $tenant->id])->save();
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);

        $user->createToken('existing-browser-token', ['*']);
        Config::set('phone-verification.gateway_url', 'https://sms.example.test/send');

        $sentCode = null;
        Http::fake(function ($request) use (&$sentCode) {
            preg_match('/code is (\\d{6})/', (string) ($request->data()['message'] ?? ''), $matches);
            $sentCode = $matches[1] ?? null;
            return Http::response(['accepted' => true], 200);
        });

        $challenge = $this->postJson('/api/password/email', [
            'identifier' => $phone,
            'channel' => 'sms',
        ])->assertOk()->assertJsonPath('data.challenge_required', true);

        $this->assertNotNull($sentCode);
        $this->assertDatabaseHas('auth_step_up_challenges', [
            'user_id' => $user->id,
            'action' => 'password_recovery',
            'challenge_hash' => hash('sha256', $challenge->json('data.challenge_token')),
        ]);

        $this->postJson('/api/password/reset', [
            'identifier' => $phone,
            'authenticator' => 'otp',
            'code' => $sentCode,
            'challenge_token' => $challenge->json('data.challenge_token'),
            'password' => 'NewPassword!234',
            'password_confirmation' => 'NewPassword!234',
        ])->assertOk()->assertJsonPath('data.password_reset', true);

        $this->assertTrue(Hash::check('NewPassword!234', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->id,
            'name' => 'existing-browser-token',
        ]);
    }

    #[Test]
    public function unverified_recovery_channels_receive_no_otp_challenge(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => null]);

        $response = $this->postJson('/api/password/email', [
            'email' => $user->email,
            'channel' => 'email',
        ])->assertOk();

        $this->assertIsString($response->json('data.challenge_token'));
        $this->assertDatabaseCount('auth_step_up_challenges', 0);
    }
    #[Test]
    public function google_authenticator_can_also_authorize_password_recovery(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email' => 'totp-recovery@example.com']);
        $totp = app(TotpMfaService::class);
        $enrollment = $totp->beginEnrollment($user);
        $totp->confirmEnrollment($user, $this->currentTotp($enrollment['secret']));

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'authenticator' => 'google_authenticator',
            'code' => $this->currentTotp($enrollment['secret'], 1),
            'password' => 'RecoveredPassword456!',
            'password_confirmation' => 'RecoveredPassword456!',
        ])->assertOk()->assertJsonPath('data.password_reset', true);

        $this->assertTrue(Hash::check('RecoveredPassword456!', $user->fresh()->password));
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
