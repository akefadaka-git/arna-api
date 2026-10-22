<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use MaaleArna\MASES\Core\Auth\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PhoneVerificationEndpointsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_phone_verification_endpoints_report_coming_soon_without_sending_sms_or_creating_codes(): void
    {
        Http::fake();

        $user = User::factory()->create([
            'phone' => '+251911000011',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/phone/verification/request')
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'AUTH_SMS_INTEGRATION_COMING_SOON')
            ->assertJsonPath('error.message', 'SMS phone verification is coming soon. / የSMS ስልክ ማረጋገጫ አገልግሎት በቅርቡ ይጀምራል።');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/phone/verification/confirm', ['code' => '123456'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'AUTH_SMS_INTEGRATION_COMING_SOON');

        $this->assertDatabaseCount('phone_verification_challenges', 0);
        Http::assertNothingSent();
    }

    #[Test]
    public function phone_verification_requires_authentication(): void
    {
        $this->postJson('/api/phone/verification/request')->assertUnauthorized();
        $this->postJson('/api/phone/verification/confirm', ['code' => '123456'])->assertUnauthorized();
    }
}
