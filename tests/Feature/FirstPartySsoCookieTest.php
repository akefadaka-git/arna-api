<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FirstPartySsoCookieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.domain' => '.maalearna.com.et',
            'session.secure' => true,
            'sanctum.stateful' => [
                'app.maalearna.com.et',
                'portal.maalearna.com.et',
            ],
            'cors.allowed_origins' => [
                'https://app.maalearna.com.et',
                'https://portal.maalearna.com.et',
            ],
        ]);
    }

    #[Test]
    public function trusted_spa_login_issues_an_http_only_parent_domain_cookie(): void
    {
        [$user, $tenant] = $this->createLoginIdentity();

        $response = $this->withServerVariables([
            'HTTP_HOST' => 'api.maalearna.com.et',
            'HTTPS' => 'on',
            'HTTP_ORIGIN' => 'https://app.maalearna.com.et',
        ])->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
            'tenant_id' => $tenant->id,
        ]);

        $response->assertOk()->assertCookie('arna_sso_token');

        $cookie = collect($response->headers->getCookies())
            ->first(static fn ($candidate) => $candidate->getName() === 'arna_sso_token');

        $this->assertNotNull($cookie);
        $this->assertSame('maalearna.com.et', ltrim((string) $cookie->getDomain(), '.'));
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    #[Test]
    public function sibling_spa_can_authenticate_and_logout_revokes_the_shared_token(): void
    {
        $this->withCredentials();
        [$user, $tenant] = $this->createLoginIdentity();

        $login = $this->withServerVariables([
            'HTTP_HOST' => 'api.maalearna.com.et',
            'HTTPS' => 'on',
            'HTTP_ORIGIN' => 'https://app.maalearna.com.et',
        ])->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
            'tenant_id' => $tenant->id,
        ])->assertOk();

        $token = $login->json('data.token');
        $this->assertIsString($token);

        $this->withServerVariables(['HTTP_HOST' => 'api.maalearna.com.et', 'HTTPS' => 'on'])
            ->withCookie('arna_sso_token', $token)
            ->withHeader('Origin', 'https://portal.maalearna.com.et')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->withServerVariables(['HTTP_HOST' => 'api.maalearna.com.et', 'HTTPS' => 'on'])
            ->withCookie('arna_sso_token', $token)
            ->withHeader('Origin', 'https://portal.maalearna.com.et')
            ->postJson('/api/logout')
            ->assertOk()
            ->assertCookieExpired('arna_sso_token');

        $this->withServerVariables(['HTTP_HOST' => 'api.maalearna.com.et', 'HTTPS' => 'on'])
            ->withCookie('arna_sso_token', $token)
            ->withHeader('Origin', 'https://app.maalearna.com.et')
            ->getJson('/api/user')->assertUnauthorized();
    }

    #[Test]
    public function an_unapproved_sibling_origin_cannot_authenticate_with_the_cookie(): void
    {
        $this->withCredentials();
        [$user, $tenant] = $this->createLoginIdentity();

        $login = $this->withServerVariables([
            'HTTP_HOST' => 'api.maalearna.com.et',
            'HTTPS' => 'on',
            'HTTP_ORIGIN' => 'https://app.maalearna.com.et',
        ])->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
            'tenant_id' => $tenant->id,
        ])->assertOk();

        $this->withServerVariables(['HTTP_HOST' => 'api.maalearna.com.et', 'HTTPS' => 'on'])
            ->withCookie('arna_sso_token', $login->json('data.token'))
            ->withHeader('Origin', 'https://untrusted.maalearna.com.et')
            ->getJson('/api/user')->assertUnauthorized();
    }

    #[Test]
    public function shared_cookie_takes_precedence_over_an_origin_local_bearer_token(): void
    {
        $this->withCredentials();
        [$firstUser, $firstTenant] = $this->createLoginIdentity('sso-first@example.com');
        [$secondUser, $secondTenant] = $this->createLoginIdentity('sso-second@example.com');

        $firstLogin = $this->withServerVariables([
            'HTTP_HOST' => 'api.maalearna.com.et',
            'HTTPS' => 'on',
            'HTTP_ORIGIN' => 'https://app.maalearna.com.et',
        ])->postJson('/api/login', [
            'email' => $firstUser->email,
            'password' => 'Password123!',
            'tenant_id' => $firstTenant->id,
        ])->assertOk();

        $secondLogin = $this->withServerVariables([
            'HTTP_HOST' => 'api.maalearna.com.et',
            'HTTPS' => 'on',
            'HTTP_ORIGIN' => 'https://portal.maalearna.com.et',
        ])->postJson('/api/login', [
            'email' => $secondUser->email,
            'password' => 'Password123!',
            'tenant_id' => $secondTenant->id,
        ])->assertOk();

        $this->withServerVariables(['HTTP_HOST' => 'api.maalearna.com.et', 'HTTPS' => 'on'])
            ->withCookie('arna_sso_token', $secondLogin->json('data.token'))
            ->withHeader('Origin', 'https://app.maalearna.com.et')
            ->withHeader('Authorization', 'Bearer '.$firstLogin->json('data.token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email', $secondUser->email);
    }

    private function createLoginIdentity(string $email = 'sso-test@example.com'): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id);

        return [$user, $tenant];
    }
}
