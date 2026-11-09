<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
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
