<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Focused regression coverage for the public login rate limit.
 *
 * D-01: the login endpoint must apply an explicit route-level throttle.
 * Five attempts from one client are allowed per minute; the next attempt
 * is rejected with HTTP 429 by Laravel's throttle middleware.
 */
final class LoginThrottlingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function login_rejects_attempts_above_the_route_limit(): void
    {
        $payload = [
            'email' => 'missing-account@example.test',
            'password' => 'NotThePassword123!',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/login', $payload)->assertStatus(401);
        }

        $response = $this->postJson('/api/login', $payload);

        $response->assertStatus(429);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }
}
