<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\AdaptiveAuthenticationPolicy;
use MaaleArna\MASES\Core\Auth\Services\AdaptiveStepUpService;
use MaaleArna\MASES\Core\Auth\Services\DeviceSessionService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdaptiveStepUpPinFactorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function recognized_android_pin_can_satisfy_the_knowledge_factor_for_critical_actions(): void
    {
        $phone = '+251911123456';
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'is_active' => true,
            'phone' => $phone,
            'last_login_at' => now(),
            'default_tenant_id' => $tenant->id,
        ]);
        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verified_phone_hash' => hash('sha256', $phone),
        ])->save();
        $user->tenants()->attach($tenant->id, ['joined_at' => now(), 'status' => 'active']);

        $devices = app(DeviceSessionService::class);
        $initialToken = $user->createToken('auth_token', ['mobile:api'])->plainTextToken;
        $device = $devices->establish($user, $initialToken, [
            'device_installation_id' => 'android-recognized-installation-12345',
            'device_label' => 'ARNA Android',
            'app_version' => '1.0.0',
        ], $tenant->id);
        $devices->enrollPin($user, $device['session_id'], '482951');

        $activeToken = PersonalAccessToken::findToken($device['token']);
        $this->assertInstanceOf(PersonalAccessToken::class, $activeToken);
        $user->withAccessToken($activeToken);

        Config::set('phone-verification.gateway_url', 'https://sms.example.test/send');
        $sentCode = null;
        Http::fake(function ($request) use (&$sentCode) {
            preg_match('/code is (\\d{6})/', (string) ($request->data()['message'] ?? ''), $matches);
            $sentCode = $matches[1] ?? null;
            return Http::response(['accepted' => true], 200);
        });

        $context = ['wallet_id' => '42', 'amount' => '1000.0000'];
        $stepUp = app(AdaptiveStepUpService::class);
        $challenge = $stepUp->issueOtp($user, $tenant->id, 'critical_financial_transaction', 'sms', $context);
        $grant = $stepUp->verify(
            $user,
            $tenant->id,
            'critical_financial_transaction',
            $context,
            AdaptiveAuthenticationPolicy::OTP,
            (string) $sentCode,
            $challenge['challenge_token'],
            AdaptiveAuthenticationPolicy::PIN,
            '482951',
        );

        $this->assertIsString($grant);
        $stored = \Illuminate\Support\Facades\DB::table('auth_step_up_grants')
            ->where('grant_hash', hash('sha256', $grant))
            ->first();
        $this->assertNotNull($stored);
        $this->assertSame(['knowledge', 'possession'], json_decode($stored->verified_factor_groups, true));
    }
}
