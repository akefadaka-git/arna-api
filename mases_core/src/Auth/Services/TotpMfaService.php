<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MaaleArna\MASES\Core\Auth\Models\User;
use RuntimeException;

final class TotpMfaService
{
    public function isEnabled(User $user): bool
    {
        return $user->totp_enabled_at !== null
            && is_string($user->totp_secret)
            && $user->totp_secret !== '';
    }

    /**
     * Begin enrollment. The returned secret is shown once to the authenticated
     * user for entry into an authenticator app and is never logged.
     *
     * @return array{secret:string, otpauth_url:string}
     */
    public function beginEnrollment(User $user, ?string $currentCode = null): array
    {
        if (! $user->is_active || $user->trashed()) {
            throw new RuntimeException('Inactive users cannot enroll in MFA.');
        }

        $secret = $this->base32Encode(random_bytes(20));
        return DB::transaction(function () use ($user, $currentCode, $secret): array {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($locked === null || ! $locked->is_active || $locked->trashed()) {
                throw new RuntimeException('Inactive users cannot enroll in MFA.');
            }

            // Replacing an enabled authenticator requires current MFA proof
            // in addition to the password reauthentication enforced by the API.
            if ($this->isEnabled($locked)) {
                if ($currentCode === null || ! $this->verifyCurrentFactorLocked($locked, $currentCode)) {
                    throw new RuntimeException('Current MFA proof is required to replace an enabled authenticator.');
                }
            }

            $locked->forceFill([
                'totp_pending_secret' => Crypt::encryptString($secret),
            ])->save();

            $issuer = (string) config('totp-mfa.issuer', 'ARNA');
            $label = rawurlencode($issuer.':'.$locked->email);
            $query = http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => 6,
                'period' => 30,
            ], '', '&', PHP_QUERY_RFC3986);

            return [
                'secret' => $secret,
                'otpauth_url' => 'otpauth://totp/'.$label.'?'.$query,
            ];
        });
    }

    /**
     * Confirm enrollment and return one-time recovery codes. Plain recovery
     * codes are returned only here; only password hashes are persisted.
     *
     * @return list<string>|null
     */
    public function confirmEnrollment(User $user, string $code): ?array
    {
        if (! $user->is_active || $user->trashed() || ! is_string($user->totp_pending_secret) || $user->totp_pending_secret === '') {
            return null;
        }

        // Capture the exact state validated outside the lock. A stale request
        // must not activate a secret another enrollment has replaced.
        $expectedPendingCiphertext = $user->totp_pending_secret;
        $expectedActiveCiphertext = $user->totp_secret;
        $expectedEnabledAt = $user->totp_enabled_at?->format('Y-m-d H:i:s');
        $wasEnabled = $this->isEnabled($user);

        try {
            $secret = Crypt::decryptString($expectedPendingCiphertext);
        } catch (\Throwable) {
            return null;
        }

        $usedStep = $this->matchingCounter($secret, $code);
        if ($usedStep === null) {
            return null;
        }

        $codes = [];
        $committed = DB::transaction(function () use (
            $user,
            $secret,
            $usedStep,
            $expectedPendingCiphertext,
            $expectedActiveCiphertext,
            $expectedEnabledAt,
            $wasEnabled,
            &$codes
        ): bool {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($locked === null || ! $locked->is_active || $locked->trashed()) {
                return false;
            }

            $lockedEnabledAt = $locked->totp_enabled_at?->format('Y-m-d H:i:s');
            if (
                ! is_string($locked->totp_pending_secret)
                || ! hash_equals($expectedPendingCiphertext, $locked->totp_pending_secret)
                || $locked->totp_secret !== $expectedActiveCiphertext
                || $lockedEnabledAt !== $expectedEnabledAt
                || $this->isEnabled($locked) !== $wasEnabled
            ) {
                return false;
            }

            $locked->forceFill([
                'totp_secret' => Crypt::encryptString($secret),
                'totp_pending_secret' => null,
                'totp_enabled_at' => now(),
                'totp_last_used_step' => $usedStep,
            ])->save();

            DB::table('mfa_recovery_codes')->where('user_id', $locked->getKey())->delete();

            $count = max(5, min(12, (int) config('totp-mfa.recovery_code_count', 10)));
            for ($i = 0; $i < $count; $i++) {
                $recoveryCode = strtoupper(bin2hex(random_bytes(6)));
                $codes[] = $recoveryCode;
                DB::table('mfa_recovery_codes')->insert([
                    'user_id' => $locked->getKey(),
                    'code_hash' => Hash::make($recoveryCode),
                    'used_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return true;
        });

        return $committed ? $codes : null;
    }

    /**
     * Create a short-lived, one-use second-factor challenge after the password
     * and tenant membership have already been validated.
     */
    public function createLoginChallenge(User $user, int $tenantId, bool $remember): string
    {
        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($user, $tenantId, $remember, $token): void {
            DB::table('login_mfa_challenges')
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            DB::table('login_mfa_challenges')->insert([
                'user_id' => $user->getKey(),
                'token_hash' => hash('sha256', $token),
                'tenant_id' => $tenantId,
                'remember' => $remember,
                'expires_at' => now()->addMinutes(max(1, (int) config('totp-mfa.login_challenge_minutes', 5))),
                'attempts' => 0,
                'consumed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $token;
    }

    /**
     * Validate and consume a challenge using either a TOTP code or an unused
     * recovery code. The caller must re-check password and tenant membership
     * before calling this method.
     */
    public function completeLoginChallenge(User $user, int $tenantId, string $token, string $code): bool
    {
        if (! preg_match('/^[A-Za-z0-9]{6,64}$/', $code) || ! $this->isEnabled($user)) {
            return false;
        }

        return DB::transaction(function () use ($user, $tenantId, $token, $code): bool {
            $challenge = DB::table('login_mfa_challenges')
                ->where('token_hash', hash('sha256', $token))
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                return false;
            }

            $maxAttempts = max(1, min(10, (int) config('totp-mfa.max_login_attempts', 5)));
            if ((int) $challenge->tenant_id !== $tenantId
                || (int) $challenge->attempts >= $maxAttempts
                || now()->greaterThanOrEqualTo($challenge->expires_at)
                || ! $user->is_active
                || $user->trashed()) {
                DB::table('login_mfa_challenges')->where('id', $challenge->id)
                    ->update(['consumed_at' => now(), 'updated_at' => now()]);
                return false;
            }

            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($lockedUser === null || ! $lockedUser->is_active || $lockedUser->trashed() || ! $this->isEnabled($lockedUser)) {
                DB::table('login_mfa_challenges')->where('id', $challenge->id)
                    ->update(['consumed_at' => now(), 'updated_at' => now()]);
                return false;
            }

            $valid = false;
            $usedStep = null;
            try {
                $secret = Crypt::decryptString((string) $lockedUser->totp_secret);
                $usedStep = $this->matchingCounter($secret, $code);
                $lastUsedStep = $lockedUser->totp_last_used_step;
                if ($usedStep !== null && $lastUsedStep !== null && $usedStep <= (int) $lastUsedStep) {
                    $usedStep = null;
                }
                $valid = $usedStep !== null;
            } catch (\Throwable) {
                // Fail closed if the encrypted TOTP secret cannot be read.
            }

            if (! $valid) {
                $recovery = DB::table('mfa_recovery_codes')
                    ->where('user_id', $user->getKey())
                    ->whereNull('used_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($recovery as $recoveryCode) {
                    if (Hash::check(strtoupper($code), $recoveryCode->code_hash)) {
                        DB::table('mfa_recovery_codes')->where('id', $recoveryCode->id)
                            ->whereNull('used_at')
                            ->update(['used_at' => now(), 'updated_at' => now()]);
                        $valid = true;
                        break;
                    }
                }
            }

            if (! $valid) {
                $attempts = (int) $challenge->attempts + 1;
                DB::table('login_mfa_challenges')->where('id', $challenge->id)->update([
                    'attempts' => $attempts,
                    'consumed_at' => $attempts >= $maxAttempts ? now() : null,
                    'updated_at' => now(),
                ]);
                return false;
            }

            if ($usedStep !== null) {
                $lockedUser->forceFill(['totp_last_used_step' => $usedStep])->save();
            }

            DB::table('login_mfa_challenges')->where('id', $challenge->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            return true;
        });
    }

    public function disable(User $user, string $code): bool
    {
        if (! $this->isEnabled($user)) {
            return false;
        }

        try {
            $secret = Crypt::decryptString((string) $user->totp_secret);
        } catch (\Throwable) {
            return false;
        }

        $usedStep = $this->matchingCounter($secret, $code);
        if ($usedStep === null || ($user->totp_last_used_step !== null && $usedStep <= (int) $user->totp_last_used_step)) {
            return false;
        }

        DB::transaction(function () use ($user, $usedStep): void {
            $user->forceFill([
                'totp_secret' => null,
                'totp_pending_secret' => null,
                'totp_enabled_at' => null,
                'totp_last_used_step' => $usedStep,
            ])->save();

            DB::table('mfa_recovery_codes')->where('user_id', $user->getKey())->delete();
            DB::table('login_mfa_challenges')->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
        });

        return true;
    }

    /**
     * Validate an existing factor while the user row is locked inside a
     * transaction. TOTP steps are replay-protected; recovery codes are consumed.
     */
    private function verifyCurrentFactorLocked(User $user, string $code): bool
    {
        try {
            $secret = Crypt::decryptString((string) $user->totp_secret);
            $usedStep = $this->matchingCounter($secret, $code);
            $lastUsedStep = $user->totp_last_used_step;
            if ($usedStep !== null && ($lastUsedStep === null || $usedStep > (int) $lastUsedStep)) {
                $user->forceFill(['totp_last_used_step' => $usedStep])->save();

                return true;
            }
        } catch (\Throwable) {
            // Fall through to recovery-code verification; fail closed otherwise.
        }

        $recoveryCodes = DB::table('mfa_recovery_codes')
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->lockForUpdate()
            ->get();

        foreach ($recoveryCodes as $recoveryCode) {
            if (Hash::check($code, $recoveryCode->code_hash)) {
                return DB::table('mfa_recovery_codes')
                    ->where('id', $recoveryCode->id)
                    ->whereNull('used_at')
                    ->update(['used_at' => now(), 'updated_at' => now()]) === 1;
            }
        }

        return false;
    }

    private function matchingCounter(string $secret, string $code): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $key = $this->base32Decode($secret);
        if ($key === '') {
            return null;
        }

        $timeStep = max(1, (int) config('totp-mfa.period_seconds', 30));
        $window = max(0, min(2, (int) config('totp-mfa.allowed_clock_drift_steps', 1)));
        $counter = intdiv(now()->timestamp, $timeStep);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $counter + $offset;
            if ($step < 0) {
                continue;
            }

            $counterBytes = pack('N2', 0, $step);
            $hash = hash_hmac('sha1', $counterBytes, $key, true);
            $position = ord($hash[19]) & 0x0f;
            $binary = ((ord($hash[$position]) & 0x7f) << 24)
                | ((ord($hash[$position + 1]) & 0xff) << 16)
                | ((ord($hash[$position + 2]) & 0xff) << 8)
                | (ord($hash[$position + 3]) & 0xff);
            $candidate = str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
            if (hash_equals($candidate, $code)) {
                return $step;
            }
        }

        return null;
    }

    private function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $output = '';

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $output .= $alphabet[($buffer >> $bits) & 31];
                $buffer &= (1 << $bits) - 1;
            }
        }

        if ($bits > 0) {
            $output .= $alphabet[($buffer << (5 - $bits)) & 31];
        }

        return $output;
    }

    private function base32Decode(string $encoded): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $output = '';

        foreach (str_split(strtoupper(rtrim($encoded, '='))) as $char) {
            $value = strpos($alphabet, $char);
            if ($value === false) {
                return '';
            }

            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xff);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $output;
    }
}
