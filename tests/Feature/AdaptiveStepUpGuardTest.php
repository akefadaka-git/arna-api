<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\AdaptiveAuthenticationPolicy;
use MaaleArna\MASES\Core\Auth\Services\AdaptiveStepUpContext;
use MaaleArna\MASES\Core\Auth\Services\AdaptiveStepUpGuard;
use MaaleArna\MASES\Core\Auth\Services\AdaptiveStepUpService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdaptiveStepUpGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function financial_guard_consumes_a_context_bound_grant_once(): void
    {
        $phone = '+251911123456';
        $user = User::factory()->create(['is_active' => true, 'phone' => $phone]);
        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_phone_hash' => hash('sha256', $phone),
        ])->save();
        $tenant = Tenant::factory()->create(['is_active' => true]);

        Config::set('phone-verification.gateway_url', 'https://sms.example.test/send');
        $sentCode = null;
        Http::fake(function ($request) use (&$sentCode) {
            preg_match('/code is (\\d{6})/', (string) ($request->data()['message'] ?? ''), $matches);
            $sentCode = $matches[1] ?? null;
            return Http::response(['accepted' => true], 200);
        });

        $request = Request::create('/api/wallets/42/payments', 'POST', [
            'amount' => '25.5000',
            'received_at' => '2026-11-22T10:00:00Z',
            'reference_type' => 'invoice',
            'reference_id' => 'INV-42',
            'description' => 'Context-bound payment',
        ]);
        $request->setUserResolver(static fn () => $user);
        $request->setRouteResolver(static fn () => new class {
            public function parameter(string $key): ?string
            {
                return $key === 'wallet' ? '42' : null;
            }
        });

        $context = app(AdaptiveStepUpContext::class)->fromRequest('wallet.payment.record', $request);
        $service = app(AdaptiveStepUpService::class);
        $challenge = $service->issueOtp($user, $tenant->id, 'wallet.payment.record', 'sms', $context);
        $grant = $service->verify(
            $user,
            $tenant->id,
            'wallet.payment.record',
            $context,
            AdaptiveAuthenticationPolicy::OTP,
            (string) $sentCode,
            $challenge['challenge_token'],
        );

        $this->assertIsString($grant);
        $request->headers->set('X-ARNA-Step-Up-Token', $grant);

        $changedRequest = clone $request;
        $changedRequest->merge(['amount' => '25.5100']);
        try {
            app(AdaptiveStepUpGuard::class)->assert($changedRequest, 'wallet.payment.record', $tenant->id);
            $this->fail('A grant for a different amount must be rejected.');
        } catch (\MaaleArna\MASES\Core\Exceptions\AuthenticationException $exception) {
            $this->assertSame('AUTH_STEP_UP_REQUIRED', $exception->getErrorCode());
        }

        app(AdaptiveStepUpGuard::class)->assert($request, 'wallet.payment.record', $tenant->id);

        $this->expectException(\MaaleArna\MASES\Core\Exceptions\AuthenticationException::class);
        app(AdaptiveStepUpGuard::class)->assert($request, 'wallet.payment.record', $tenant->id);
    }
}
