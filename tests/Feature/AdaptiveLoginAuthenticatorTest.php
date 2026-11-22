<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\PhoneVerificationDelivery;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdaptiveLoginAuthenticatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function returning_user_can_login_with_otp_on_a_recognized_android_installation(): void
    {
        $user = User::factory()->create(['is_active' => true, 'phone' => '+251911123456']);
        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_phone_hash' => hash('sha256', '+251911123456'),
        ])->save();
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->id,
        ])->assertOk();

        $sentCode = null;
        $this->mock(PhoneVerificationDelivery::class, function ($mock) use (&$sentCode): void {
            $mock->shouldReceive('send')->once()->andReturnUsing(function ($phone, $code) use (&$sentCode): void {
                $sentCode = $code;
            });
        });

        $installation = 'android-installation-identifier-12345';
        $challenge = $this->postJson('/api/login/otp/challenge', [
            'identifier' => $user->email,
            'tenant_id' => $tenant->id,
            'channel' => 'sms',
            'client_type' => 'android',
            'device_installation_id' => $installation,
        ])->assertOk()->assertJsonPath('data.challenge_required', true);

        $this->assertNotNull($sentCode);
        $login = $this->postJson('/api/login/authenticator', [
            'identifier' => $user->email,
            'tenant_id' => $tenant->id,
            'authenticator' => 'otp',
            'code' => $sentCode,
            'challenge_token' => $challenge->json('data.challenge_token'),
            'client_type' => 'android',
            'device_installation_id' => $installation,
            'device_label' => 'ARNA Android',
            'app_version' => '1.0.0',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertIsString($login->json('data.token'));
        $this->assertIsString($login->json('data.session_id'));
        $this->assertDatabaseHas('auth_device_sessions', [
            'public_id' => $login->json('data.session_id'),
            'user_id' => $user->id,
            'platform' => 'android',
        ]);
    }

    #[Test]
    public function first_login_cannot_use_otp_as_a_substitute_for_the_password(): void
    {
        $user = User::factory()->create(['is_active' => true, 'phone' => '+251911123456']);
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);

        $challenge = $this->postJson('/api/login/otp/challenge', [
            'identifier' => $user->email,
            'tenant_id' => $tenant->id,
            'channel' => 'sms',
        ])->assertOk();

        $this->postJson('/api/login/authenticator', [
            'identifier' => $user->email,
            'tenant_id' => $tenant->id,
            'authenticator' => 'otp',
            'code' => '123456',
            'challenge_token' => $challenge->json('data.challenge_token'),
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTH_INVALID_CREDENTIALS');

        $this->assertDatabaseCount('auth_device_sessions', 0);
    }
}
