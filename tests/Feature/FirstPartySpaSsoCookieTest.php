<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for the stateless first-party SPA SSO cookie.
 *
 * The shared cookie transports the existing revocable Sanctum PAT; it does
 * not establish a Laravel web-guard session. A request must originate from
 * a host present in both the explicit CORS allow-list and Sanctum's
 * stateful-host allow-list before the middleware may promote the cookie to
 * an Authorization bearer header.
 */
final class FirstPartySpaSsoCookieTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cors.allowed_origins', [
            'https://www.maalearna.com.et',
            'https://app.maalearna.com.et',
            'https://portal.maalearna.com.et',
        ]);
        Config::set('sanctum.stateful', [
            'app.maalearna.com.et',
            'portal.maalearna.com.et',
        ]);
        Config::set('session.domain', '.maalearna.com.et');
        Config::set('session.secure', true);
        Config::set('sanctum.expiration', 10080);

        $this->tenant = Tenant::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create([
            'email' => 'spa-sso@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->user->tenants()->attach($this->tenant->id);
    }

    private function loginFrom(string $origin): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Origin' => $origin,
            'Referer' => $origin.'/',
            'Accept' => 'application/json',
        ])->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => 'Password123!',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    private function ssoCookie(\Illuminate\Testing\TestResponse $response): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'arna_sso_token') {
                return $cookie;
            }
        }

        return null;
    }

    #[Test]
    public function approved_workspace_login_sets_a_secure_parent_domain_http_only_cookie(): void
    {
        $response = $this->loginFrom('https://app.maalearna.com.et');

        $response->assertOk()->assertJsonPath('success', true);
        $cookie = $this->ssoCookie($response);

        self::assertNotNull($cookie, 'Trusted Workspace login must set the shared SSO cookie.');
        self::assertSame('.maalearna.com.et', $cookie->getDomain());
        self::assertSame('/', $cookie->getPath());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        self::assertGreaterThan(time(), $cookie->getExpiresTime());
        self::assertNotEmpty($response->json('data.token'), 'Bearer-token compatibility must remain intact.');
    }

    #[Test]
    public function cors_allowed_public_site_origin_does_not_receive_the_sso_cookie(): void
    {
        $response = $this->loginFrom('https://www.maalearna.com.et');

        $response->assertOk()->assertJsonPath('success', true);
        self::assertNull($this->ssoCookie($response), 'CORS permission alone must not enable SSO.');
    }

    #[Test]
    public function shared_cookie_authenticates_only_from_an_approved_stateful_spa_origin(): void
    {
        $login = $this->loginFrom('https://app.maalearna.com.et');
        $login->assertOk();
        $token = (string) $login->json('data.token');
        self::assertNotSame('', $token);

        $this->withUnencryptedCookie('arna_sso_token', $token)
            ->withHeader('Origin', 'https://portal.maalearna.com.et')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email', $this->user->email);

        $this->withUnencryptedCookie('arna_sso_token', $token)
            ->withHeader('Origin', 'https://www.maalearna.com.et')
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    #[Test]
    public function browser_logout_revokes_the_shared_token_and_expires_the_cookie(): void
    {
        $login = $this->loginFrom('https://app.maalearna.com.et');
        $login->assertOk();
        $token = (string) $login->json('data.token');
        self::assertNotSame('', $token);

        $logout = $this->withUnencryptedCookie('arna_sso_token', $token)
            ->withHeader('Origin', 'https://app.maalearna.com.et')
            ->postJson('/api/logout');

        $logout->assertOk();
        $expiredCookie = $this->ssoCookie($logout);
        self::assertNotNull($expiredCookie, 'Trusted browser logout must expire the shared SSO cookie.');
        self::assertLessThanOrEqual(time(), $expiredCookie->getExpiresTime());

        $this->withUnencryptedCookie('arna_sso_token', $token)
            ->withHeader('Origin', 'https://portal.maalearna.com.et')
            ->getJson('/api/user')
            ->assertUnauthorized();
    }
}
