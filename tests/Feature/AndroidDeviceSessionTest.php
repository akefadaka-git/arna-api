<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Auth\Services\TotpMfaService;
use MaaleArna\MASES\Core\Auth\Services\DeviceSessionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AndroidDeviceSessionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function android_login_creates_an_independently_revocable_device_session(): void
    {
        [$user, $tenant] = $this->userWithTenant();

        $deviceA = $this->androidLogin($user, $tenant, 'installation-identifier-device-A');
        $deviceB = $this->androidLogin($user, $tenant, 'installation-identifier-device-B');

        $this->assertSame(2, DB::table('auth_device_sessions')->where('user_id', $user->id)->count());
        $this->assertSame(2, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());

        $refresh = $this->withToken($deviceA['token'])->postJson('/api/token/refresh')
            ->assertOk()
            ->assertJsonPath('success', true);
        $newTokenA = $refresh->json('data.token');
        $this->assertIsString($newTokenA);
        $this->withToken($deviceA['token'])->postJson('/api/token/refresh')->assertUnauthorized();
        $sessionB = DB::table('auth_device_sessions')->where('public_id', $deviceB['session_id'])->first();
        $this->assertNotNull($sessionB?->personal_access_token_id);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $sessionB->personal_access_token_id]);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'arna-android', 'id' => $sessionB->personal_access_token_id]);

        // Refreshing A must leave B's bearer token valid.
        app('auth')->forgetGuards();
        $this->withToken($deviceB['token'])->getJson('/api/profile')->assertOk();

        // Revoking B from A must not invalidate A's newly rotated token.
        $this->withToken($newTokenA)->deleteJson('/api/auth/sessions/'.$deviceB['session_id'])
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        app('auth')->forgetGuards();
        $this->withToken($deviceB['token'])->getJson('/api/profile')->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($newTokenA)->getJson('/api/profile')->assertOk();
    }

    #[Test]
    public function android_logout_revokes_only_the_current_device_session(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $deviceA = $this->androidLogin($user, $tenant, 'installation-identifier-device-A');
        $deviceB = $this->androidLogin($user, $tenant, 'installation-identifier-device-B');

        $this->withToken($deviceA['token'])->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($deviceA['token'])->getJson('/api/profile')->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($deviceB['token'])->getJson('/api/profile')->assertOk();
    }


    #[Test]
    public function legacy_wildcard_web_tokens_bypass_android_device_session_enforcement(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $legacyToken = $user->createToken('legacy-web-session', ['*']);

        $this->withToken($legacyToken->plainTextToken)->getJson('/api/profile')->assertOk();

        $this->assertSame(
            0,
            DB::table('auth_device_sessions')->where('user_id', $user->getKey())->count(),
            'Legacy browser/SSO tokens must not be forced into Android device-session records.',
        );
    }

    #[Test]
    public function password_login_on_android_is_single_authenticator_after_totp_enrollment(): void
    {
        [$user] = $this->enrolledUser();
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->id,
            'client_type' => 'android',
            'device_installation_id' => 'android-installation-original-12345',
            'device_label' => 'ARNA Android',
            'app_version' => '1.0.0',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertIsString($response->json('data.session_id'));
        $this->assertDatabaseHas('auth_device_sessions', [
            'public_id' => $response->json('data.session_id'),
            'user_id' => $user->id,
            'platform' => 'android',
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    #[Test]
    public function a_user_cannot_list_or_revoke_another_users_device_session(): void
    {
        [$userA, $tenantA] = $this->userWithTenant();
        [$userB, $tenantB] = $this->userWithTenant();
        $deviceA = $this->androidLogin($userA, $tenantA, 'installation-identifier-device-A');
        $deviceB = $this->androidLogin($userB, $tenantB, 'installation-identifier-device-B');

        $list = $this->withToken($deviceA['token'])->getJson('/api/auth/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data.sessions');
        $this->assertNotContains($deviceB['session_id'], array_column($list->json('data.sessions'), 'session_id'));

        $this->withToken($deviceA['token'])->deleteJson('/api/auth/sessions/'.$deviceB['session_id'])->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken($deviceB['token'])->getJson('/api/profile')->assertOk();
    }

    #[Test]
    public function expired_device_session_is_rejected_even_if_the_token_row_exists(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $device = $this->androidLogin($user, $tenant, 'installation-identifier-device-A');
        DB::table('auth_device_sessions')->where('public_id', $device['session_id'])
            ->update(['expires_at' => now()->subSecond()]);

        $this->withToken($device['token'])->getJson('/api/profile')->assertUnauthorized();
    }

    #[Test]
    public function a_mobile_token_without_its_session_link_fails_closed(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $device = $this->androidLogin($user, $tenant, 'installation-identifier-device-A');
        DB::table('auth_device_sessions')->where('public_id', $device['session_id'])
            ->update(['personal_access_token_id' => null]);

        $this->withToken($device['token'])->getJson('/api/profile')->assertUnauthorized();
    }

    #[Test]
    public function recognized_device_pin_login_requires_prior_password_login_and_rotates_only_that_device_token(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $device = $this->androidLogin($user, $tenant, 'installation-identifier-device-A');

        app(DeviceSessionService::class)->enrollPin($user, $device['session_id'], '482951');

        $response = $this->postJson('/api/login/pin', [
            'identifier' => $user->email,
            'pin' => '482951',
            'device_installation_id' => 'installation-identifier-device-A',
            'tenant_id' => $tenant->id,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session_id', $device['session_id']);

        $newToken = $response->json('data.token');
        $this->assertIsString($newToken);

        app('auth')->forgetGuards();
        $this->withToken($device['token'])->getJson('/api/profile')->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($newToken)->getJson('/api/profile')->assertOk();
    }

    #[Test]
    public function pin_login_is_rejected_before_first_password_authentication(): void
    {
        [$user, $tenant] = $this->userWithTenant();

        $this->postJson('/api/login/pin', [
            'identifier' => $user->email,
            'pin' => '482951',
            'device_installation_id' => 'installation-identifier-device-A',
            'tenant_id' => $tenant->id,
        ])->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTH_INVALID_CREDENTIALS');

        $this->assertDatabaseCount('auth_device_sessions', 0);
    }

    #[Test]
    public function five_invalid_pin_attempts_temporarily_lock_the_recognized_device(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $device = $this->androidLogin($user, $tenant, 'installation-identifier-device-A');
        $user->refresh();
        $service = app(DeviceSessionService::class);
        $service->enrollPin($user, $device['session_id'], '482951');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertNull($service->resumeWithPin($user, $tenant->id, 'installation-identifier-device-A', '000000'));
        }

        $this->assertNull($service->resumeWithPin($user, $tenant->id, 'installation-identifier-device-A', '482951'));
        $this->assertNotNull(DB::table('auth_device_sessions')->where('public_id', $device['session_id'])->value('pin_locked_until'));
    }

    private function enrolledUser(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $enrollment = app(TotpMfaService::class)->beginEnrollment($user);
        $codes = app(TotpMfaService::class)->confirmEnrollment(
            $user,
            $this->currentTotp($enrollment['secret']),
        );
        $this->assertNotNull($codes);

        return [$user];
    }

    private function currentTotp(string $secret): string
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
        $counter = intdiv(now()->timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function userWithTenant(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);
        return [$user, $tenant];
    }

    private function androidLogin(User $user, Tenant $tenant, string $installationId): array
    {
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->id,
            'client_type' => 'android',
            'device_installation_id' => $installationId,
            'device_label' => 'Android test device',
            'app_version' => '1.0.0-test',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer');

        $token = $response->json('data.token');
        $sessionId = $response->json('data.session_id');
        $this->assertIsString($token);
        $this->assertIsString($sessionId);
        $this->assertDatabaseHas('auth_device_sessions', [
            'public_id' => $sessionId,
            'user_id' => $user->id,
            'platform' => 'android',
        ]);

        return ['token' => $token, 'session_id' => $sessionId];
    }
}
