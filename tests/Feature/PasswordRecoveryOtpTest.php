<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use MaaleArna\MASES\Core\Auth\Models\User;
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
}
