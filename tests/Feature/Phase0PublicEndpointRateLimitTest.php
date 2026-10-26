<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Phase0PublicEndpointRateLimitTest extends TestCase
{
    /**
     * Public account endpoints must have explicit route-level limits.
     *
     * The runtime route-list audit independently verifies Laravel's resolved
     * middleware. This test protects the route declarations and HTTP behavior.
     */
    #[Test]
    public function all_four_public_account_endpoints_declare_explicit_throttles(): void
    {
        $expected = [
            'api/register' => 'throttle:5,1',
            'api/password/email' => 'throttle:3,1',
            'api/password/reset' => 'throttle:5,1',
            'api/verify-email/resend' => 'throttle:3,1',
        ];

        foreach ($expected as $uri => $throttle) {
            $route = collect(Route::getRoutes())->first(
                static fn (RoutingRoute $candidate): bool =>
                    trim($candidate->uri(), '/') === $uri
                    && in_array('POST', $candidate->methods(), true)
            );

            $this->assertNotNull($route, "Expected POST route {$uri} to be registered.");
            $this->assertContains(
                $throttle,
                $route->gatherMiddleware(),
                "POST {$uri} must declare {$throttle}."
            );
        }
    }

    /**
     * These are intentionally malformed payloads: validation/controller work
     * should not succeed, while the route middleware must still return 429
     * once the endpoint-specific limit is exhausted.
     */
    #[Test]
    public function repeated_http_requests_are_rejected_with_429_after_each_limit(): void
    {
        $cases = [
            ['/api/register', [], 5],
            ['/api/password/email', ['email' => 'not-an-email'], 3],
            ['/api/password/reset', [], 5],
            ['/api/verify-email/resend', [], 3],
        ];

        foreach ($cases as $index => [$uri, $payload, $limit]) {
            $ip = '198.51.100.' . ($index + 10);

            for ($attempt = 1; $attempt <= $limit; $attempt++) {
                $this->withServerVariables(['REMOTE_ADDR' => $ip])
                    ->postJson($uri, $payload);
            }

            $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson($uri, $payload);

            $response->assertStatus(429);
        }
    }
}
