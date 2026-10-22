<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MaaleArna\MASES\Core\Auth\Models\User;
use RuntimeException;

final class PhoneVerificationService
{
    /**
     * Create a short-lived challenge for the user's currently stored phone.
     *
     * The returned code is intended only for a trusted delivery adapter. It
     * must never be included in an API response, log, or persisted in plaintext.
     */
    public function issue(User $user): string
    {
        if (! $user->is_active || $user->trashed()) {
            throw new RuntimeException('Inactive users cannot verify a phone number.');
        }

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(max(1, (int) config('phone-verification.expiry_minutes', 10)));

        DB::transaction(function () use ($user, $code, $expiresAt): void {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedUser === null || ! $lockedUser->is_active || $lockedUser->trashed()) {
                throw new RuntimeException('Inactive users cannot verify a phone number.');
            }

            $phone = trim((string) $lockedUser->phone);
            if ($phone === '') {
                throw new RuntimeException('A phone number is required before verification.');
            }

            DB::table('phone_verification_challenges')
                ->where('user_id', $lockedUser->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            DB::table('phone_verification_challenges')->insert([
                'user_id' => $lockedUser->getKey(),
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'expires_at' => $expiresAt,
                'attempts' => 0,
                'consumed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $code;
    }

    /**
     * Verify the code against the latest unconsumed challenge for the current
     * phone value. A code is single-use and bounded by expiry and attempt count.
     */
    public function verify(User $user, string $code): bool
    {
        if (! preg_match('/^\d{6}$/', $code) || ! $user->is_active || $user->trashed()) {
            return false;
        }

        return DB::transaction(function () use ($user, $code): bool {
            $phone = trim((string) $user->phone);
            if ($phone === '') {
                return false;
            }

            $challenge = DB::table('phone_verification_challenges')
                ->where('user_id', $user->getKey())
                ->where('phone', $phone)
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                return false;
            }

            $maxAttempts = max(1, min(10, (int) config('phone-verification.max_attempts', 5)));
            if ((int) $challenge->attempts >= $maxAttempts || now()->greaterThanOrEqualTo($challenge->expires_at)) {
                DB::table('phone_verification_challenges')
                    ->where('id', $challenge->id)
                    ->update(['consumed_at' => now(), 'updated_at' => now()]);

                return false;
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $attempts = (int) $challenge->attempts + 1;
                DB::table('phone_verification_challenges')
                    ->where('id', $challenge->id)
                    ->update([
                        'attempts' => $attempts,
                        'consumed_at' => $attempts >= $maxAttempts ? now() : null,
                        'updated_at' => now(),
                    ]);

                return false;
            }

            DB::table('phone_verification_challenges')
                ->where('id', $challenge->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            $user->forceFill([
                'phone_verified_at' => now(),
                'phone_verified_phone_hash' => hash('sha256', $phone),
            ])->save();

            return true;
        });
    }
}
