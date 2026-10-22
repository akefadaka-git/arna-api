<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Http\\Client\\Request as HttpRequest;
use Illuminate\\Support\\Facades\\Http;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use PHPUnit\\Framework\\Attributes\\Test;
use Tests\\TestCase;

final class PhoneVerificationEndpointsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_user_can_request_and_confirm_a_phone_code_without_exposing_it_in_the_response(): void
    {
        $code = null;
        config([
            'phone-verification.gateway_url' => 'https://sms-gateway.example.test/messages',
            'phone-verification.gateway_token' => 'test-token',
            'phone-verification.sender' => 'ARNA',
        ]);

        Http::fake(function (HttpRequest $request) use (&$code) {
            preg_match('/code is (\\d{6})/', (string) ($request->data()['message'] ?? ''), $matches);
            $code = $matches[1] ?? null;

            return Http::response(['accepted' => true], 202);
        });

        $user = User::factory()->create([
            'phone' => '+251911000011',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/phone/verification/request');

        $response->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sent', true)
            ->assertJsonMissingPath('data.code');

        $this->assertNotNull($code);
        Http::assertSent(fn (HttpRequest $request): bool =>
            $request->url() === 'https://sms-gateway.example.test/messages'
            && ($request->data()['to'] ?? null) === '+251911000011'
            && str_contains((string) ($request->data()['message'] ?? ''), 'ARNA verification code')
        );

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/phone/verification/confirm', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.verified', true);

        $this->assertTrue($user->fresh()->hasVerifiedPhone());
    }

    #[Test]
    public function request_fails_closed_when_the_sms_gateway_is_not_configured(): void
    {
        config(['phone-verification.gateway_url' => null]);
        Http::fake();

        $user = User::factory()->create([
            'phone' => '+251911000012',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/phone/verification/request')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'AUTH_PHONE_DELIVERY_UNAVAILABLE');

        Http::assertNothingSent();
    }

    #[Test]
    public function phone_verification_requires_authentication(): void
    {
        $this->postJson('/api/phone/verification/request')->assertUnauthorized();
        $this->postJson('/api/phone/verification/confirm', ['code' => '123456'])->assertUnauthorized();
    }
}
