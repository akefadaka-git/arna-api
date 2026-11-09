<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Auth\Services\TotpMfaService;
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
        $this->withToken($deviceB['token'])->getJson('/api/profile')->assertOk();

        // Revoking B from A must not invalidate A's newly rotated token.
        $this->withToken($newTokenA)->deleteJson('/api/auth/sessions/'.$deviceB['session_id'])
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->withToken($deviceB['token'])->getJson('/api/profile')->assertUnauthorized();
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
        $this->withToken($deviceB['token'])->getJson('/api/profile')->assertOk();
    }


    #[Test]
    public function mfa_login_challenge_is_bound_to_the_original_android_installation(): void
    {
        [$user, $tenant] = $this->userWithTenant();
        $mfa = new TotpMfaService();
        $enrollment = $mfa->beginEnrollment($user);
        $mfa->confirmEnrollment($user, $this->currentTotp($enrollment['secret']));

        $first = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->id,
            'client_type' => 'android',
            'device_installation_id' => 'installation-identifier-device-A',
        ])->assertOk()->assertJsonPath('data.mfa_required', true);
        $challenge = $first->json('data.challenge_token');
        $code = $this->currentTotp($enrollment['secret'], 1);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->id,
            'client_type' => 'android',
            'device_installation_id' => 'installation-identifier-device-B',
            'mfa_challenge_token' => $challenge,
            'mfa_code' => $code,
        ])->assertUnauthorized();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'tenant_id' => $tenant->id,
            'client_type' => 'android',
            'device_installation_id' => 'installation-identifier-device-A',
            'mfa_challenge_token' => $challenge,
            'mfa_code' => $code,
        ])->assertOk()->assertJsonPath('success', true);
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
