<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\TotpMfaService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BrowserSessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sanctum.stateful' => ['localhost', 'localhost:5173'],
            'session.driver' => 'array',
            'session.domain' => null,
            'session.secure' => false,
        ]);

        $this->tenant = Tenant::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create([
            'email' => 'browser-sso@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->user->tenants()->attach($this->tenant->id);
    }

    private function fromFirstPartySpa(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
            'Accept' => 'application/json',
        ]);
    }

    #[Test]
    public function browser_login_establishes_a_web_session_without_issuing_a_personal_access_token(): void
    {
        $this->fromFirstPartySpa()
            ->postJson('/api/spa/login', [
                'email' => $this->user->email,
                'password' => 'Password123!',
                'tenant_id' => $this->tenant->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $this->user->id)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_type');

        $this->assertAuthenticatedAs($this->user, 'web');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->fromFirstPartySpa()
            ->getJson('/api/spa/user')
            ->assertOk()
            ->assertJsonPath('data.id', $this->user->id);
    }

    #[Test]
    public function browser_logout_invalidates_the_session_without_revoking_api_tokens(): void
    {
        $this->fromFirstPartySpa()
            ->postJson('/api/spa/login', [
                'email' => $this->user->email,
                'password' => 'Password123!',
                'tenant_id' => $this->tenant->id,
            ])
            ->assertOk();

        $apiToken = $this->user->createToken('independent-api-client')->plainTextToken;

        $this->fromFirstPartySpa()
            ->postJson('/api/spa/logout')
            ->assertOk();

        $this->fromFirstPartySpa()
            ->getJson('/api/user')
            ->assertUnauthorized();

        $this->assertGuest('web');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->getJson('/api/spa/user')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->postJson('/api/spa/logout')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer '.$apiToken)
            ->getJson('/api/user')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    #[Test]
    public function mfa_challenge_does_not_establish_a_browser_session_or_issue_a_token(): void
    {
        $mfa = new TotpMfaService();
        $enrollment = $mfa->beginEnrollment($this->user);
        $mfa->confirmEnrollment($this->user, $this->currentTotp($enrollment['secret']));

        $this->fromFirstPartySpa()
            ->postJson('/api/spa/login', [
                'email' => $this->user->email,
                'password' => 'Password123!',
                'tenant_id' => $this->tenant->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonMissingPath('data.token');

        $this->assertGuest('web');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function mfa_completion_establishes_a_browser_session_without_issuing_a_personal_access_token(): void
    {
        $mfa = new TotpMfaService();
        $enrollment = $mfa->beginEnrollment($this->user);
        $mfa->confirmEnrollment($this->user, $this->currentTotp($enrollment['secret']));

        $challenge = $this->fromFirstPartySpa()
            ->postJson('/api/spa/login', [
                'email' => $this->user->email,
                'password' => 'Password123!',
                'tenant_id' => $this->tenant->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.mfa_required', true);

        $challengeToken = $challenge->json('data.challenge_token');
        $this->assertIsString($challengeToken);

        $this->fromFirstPartySpa()
            ->postJson('/api/spa/login', [
                'email' => $this->user->email,
                'password' => 'Password123!',
                'tenant_id' => $this->tenant->id,
                'mfa_challenge_token' => $challengeToken,
                'mfa_code' => $this->currentTotp($enrollment['secret']),
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_type');

        $this->assertAuthenticatedAs($this->user, 'web');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->fromFirstPartySpa()
            ->getJson('/api/spa/user')
            ->assertOk()
            ->assertJsonPath('data.id', $this->user->id);
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
}
