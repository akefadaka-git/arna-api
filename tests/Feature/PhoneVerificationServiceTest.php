<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\PhoneVerificationService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class PhoneVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PhoneVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PhoneVerificationService();
        config([
            'phone-verification.expiry_minutes' => 10,
            'phone-verification.max_attempts' => 5,
        ]);
    }

    #[Test]
    public function it_stores_only_a_hash_and_verifies_a_single_use_code(): void
    {
        $user = User::factory()->create(['phone' => '+251911000001', 'is_active' => true]);

        $code = $this->service->issue($user);
        $challenge = DB::table('phone_verification_challenges')->where('user_id', $user->id)->first();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertTrue(Hash::check($code, $challenge->code_hash));
        $this->assertNull($challenge->consumed_at);
        $this->assertNull($user->fresh()->phone_verified_at);

        $this->assertTrue($this->service->verify($user, $code));
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertTrue($user->fresh()->hasVerifiedPhone());
        $this->assertFalse($this->service->verify($user, $code));
    }

    #[Test]
    public function it_invalidates_previous_challenges_when_issuing_a_new_one(): void
    {
        $user = User::factory()->create(['phone' => '+251911000002', 'is_active' => true]);

        $first = $this->service->issue($user);
        $second = $this->service->issue($user);

        $this->assertNotSame($first, $second);
        $this->assertFalse($this->service->verify($user, $first));
        $this->assertTrue($this->service->verify($user, $second));
    }

    #[Test]
    public function it_locks_a_challenge_after_the_configured_number_of_wrong_attempts(): void
    {
        $user = User::factory()->create(['phone' => '+251911000003', 'is_active' => true]);
        $code = $this->service->issue($user);
        config(['phone-verification.max_attempts' => 2]);

        $this->assertFalse($this->service->verify($user, '000000' === $code ? '000001' : '000000'));
        $this->assertFalse($this->service->verify($user, '000000' === $code ? '000002' : '000000'));
        $this->assertFalse($this->service->verify($user, $code));
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    #[Test]
    public function it_rejects_expired_codes(): void
    {
        $user = User::factory()->create(['phone' => '+251911000004', 'is_active' => true]);
        $code = $this->service->issue($user);

        DB::table('phone_verification_challenges')
            ->where('user_id', $user->id)
            ->update(['expires_at' => now()->subSecond()]);

        $this->assertFalse($this->service->verify($user, $code));
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    #[Test]
    public function a_challenge_cannot_verify_a_phone_that_changed_after_issue(): void
    {
        $user = User::factory()->create(['phone' => '+251911000005', 'is_active' => true]);
        $code = $this->service->issue($user);
        $user->phone = '+251911000006';
        $user->save();

        $this->assertFalse($this->service->verify($user, $code));
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    #[Test]
    public function a_verified_phone_status_does_not_follow_a_later_phone_change(): void
    {
        $user = User::factory()->create(['phone' => '+251911000008', 'is_active' => true]);
        $code = $this->service->issue($user);

        $this->assertTrue($this->service->verify($user, $code));
        $this->assertTrue($user->fresh()->hasVerifiedPhone());

        $user->phone = '+251911000009';
        $user->save();

        $this->assertFalse($user->fresh()->hasVerifiedPhone());
    }

    #[Test]
    public function it_rejects_missing_phone_numbers(): void
    {
        $withoutPhone = User::factory()->create(['phone' => null, 'is_active' => true]);

        $this->expectException(RuntimeException::class);
        $this->service->issue($withoutPhone);
    }

    #[Test]
    public function it_rejects_inactive_users(): void
    {
        $inactive = User::factory()->create(['phone' => '+251911000007', 'is_active' => false]);

        $this->expectException(RuntimeException::class);
        $this->service->issue($inactive);
    }
}
