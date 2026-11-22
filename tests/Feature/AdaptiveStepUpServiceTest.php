<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\AdaptiveAuthenticationPolicy;
use MaaleArna\MASES\Core\Auth\Services\AdaptiveStepUpService;
use MaaleArna\MASES\Core\Auth\Services\PhoneVerificationDelivery;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdaptiveStepUpServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function otp_grant_is_single_use_and_bound_to_the_transaction_context(): void
    {
        $user = User::factory()->create(['is_active' => true, 'phone' => '+251911123456']);
        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_phone_hash' => hash('sha256', '+251911123456'),
        ])->save();
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);

        $code = null;
        $this->mock(PhoneVerificationDelivery::class, function ($mock) use (&$code): void {
            $mock->shouldReceive('send')->once()->andReturnUsing(function ($phone, $sentCode) use (&$code): void {
                $code = $sentCode;
            });
        });

        $context = ['wallet_id' => '42', 'amount' => '25.5000', 'reference_type' => 'invoice', 'reference_id' => 'INV-42'];
        $service = app(AdaptiveStepUpService::class);
        $challenge = $service->issueOtp($user, $tenant->id, 'wallet.payment.record', 'sms', $context);
        $this->assertNotNull($code);

        $grant = $service->verify($user, $tenant->id, 'wallet.payment.record', $context,
            AdaptiveAuthenticationPolicy::OTP, $code, $challenge['challenge_token']);
        $this->assertIsString($grant);

        $changed = $context;
        $changed['amount'] = '25.5100';
        $this->assertFalse($service->consumeGrant($user, $tenant->id, 'wallet.payment.record', $changed, $grant));
        $this->assertTrue($service->consumeGrant($user, $tenant->id, 'wallet.payment.record', $context, $grant));
        $this->assertFalse($service->consumeGrant($user, $tenant->id, 'wallet.payment.record', $context, $grant));
    }
}
