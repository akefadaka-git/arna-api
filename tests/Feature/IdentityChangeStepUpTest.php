<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IdentityChangeStepUpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function identity_change_requires_a_single_use_grant_bound_to_the_proposed_value(): void
    {
        $phone = '+251911123456';
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'is_active' => true,
            'email' => 'old@example.com',
            'email_verified_at' => now(),
            'phone' => $phone,
            'default_tenant_id' => $tenant->id,
        ]);
        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_phone_hash' => hash('sha256', $phone),
        ])->save();
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);
        $token = $user->createToken('identity-change-test', ['*'])->plainTextToken;

        Config::set('phone-verification.gateway_url', 'https://sms.example.test/send');
        $sentCode = null;
        Http::fake(function ($request) use (&$sentCode) {
            preg_match('/code is (\\d{6})/', (string) ($request->data()['message'] ?? ''), $matches);
            $sentCode = $matches[1] ?? null;
            return Http::response(['accepted' => true], 200);
        });
        Notification::fake();

        $challenge = $this->withToken($token)->postJson('/api/auth/identity-change/challenge', [
            'field' => 'email',
            'value' => 'new@example.com',
            'channel' => 'sms',
        ])->assertOk();

        $this->assertNotNull($sentCode);
        $grant = $this->withToken($token)->postJson('/api/auth/identity-change/verify', [
            'field' => 'email',
            'value' => 'new@example.com',
            'authenticator' => 'otp',
            'code' => $sentCode,
            'challenge_token' => $challenge->json('data.challenge_token'),
        ])->assertOk()->json('data.step_up_token');

        $this->assertIsString($grant);

        $this->withToken($token)->withHeader('X-ARNA-Step-Up-Token', $grant)
            ->patchJson('/api/auth/identity', [
                'field' => 'email',
                'value' => 'other@example.com',
            ])->assertForbidden();

        $this->withToken($token)->withHeader('X-ARNA-Step-Up-Token', $grant)
            ->patchJson('/api/auth/identity', [
                'field' => 'email',
                'value' => 'new@example.com',
            ])->assertOk()
                ->assertJsonPath('data.identity_updated', true)
                ->assertJsonPath('data.login_required', true);

        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->id,
            'name' => 'identity-change-test',
        ]);
    }
}
