<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Security\Services\SecurityEventService;
use RuntimeException;

final class AdaptiveStepUpService
{
    public function __construct(
        private readonly AdaptiveAuthenticationPolicy $policy,
        private readonly AdaptiveStepUpContext $context,
        private readonly PhoneVerificationDelivery $phoneDelivery,
        private readonly TotpMfaService $totp,
        private readonly DeviceSessionService $devices,
    ) {
    }

    /**
     * Issue a short-lived OTP challenge to a previously verified contact.
     *
     * @return array{challenge_token:string, channel:string, destination:string, expires_at:string}
     */
    public function issueOtp(User $user, int $tenantId, string $action, string $channel, array $context): array
    {
        $this->assertSupportedAction($action);
        if (! $user->is_active || $user->trashed()) {
            throw new RuntimeException('Inactive users cannot complete step-up authentication.');
        }

        $requirements = $this->policy->requirementsFor($action);
        if (! $requirements['step_up_required'] && $action !== 'login') {
            throw new RuntimeException('The requested action does not require step-up authentication.');
        }

        [$destination, $masked] = $this->verifiedDestination($user, $channel);
        $challengeToken = bin2hex(random_bytes(32));
        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(max(1, min(10, (int) config('auth-step-up.otp_expiry_minutes', 5))));
        $contextHash = $this->context->fingerprint($action, $context);

        DB::transaction(function () use ($user, $tenantId, $action, $channel, $challengeToken, $code, $expiresAt, $contextHash): void {
            DB::table('auth_step_up_challenges')
                ->where('user_id', $user->getKey())
                ->where('action', $action)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            DB::table('auth_step_up_challenges')->insert([
                'user_id' => $user->getKey(),
                'tenant_id' => $tenantId,
                'action' => $action,
                'channel' => $channel,
                'challenge_hash' => hash('sha256', $challengeToken),
                'context_hash' => $contextHash,
                'code_hash' => Hash::make($code),
                'expires_at' => $expiresAt,
                'attempts' => 0,
                'verified_at' => null,
                'consumed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            if ($channel === 'sms') {
                $this->phoneDelivery->send($destination, $code);
            } else {
                $body = sprintf(
                    "Your ARNA security verification code is %s. It expires in %d minutes. Do not share this code.",
                    $code,
                    max(1, (int) config('auth-step-up.otp_expiry_minutes', 5)),
                );
                Mail::raw($body, static function ($message) use ($destination): void {
                    $message->to($destination)->subject('ARNA security verification code');
                });
            }
        } catch (\Throwable $exception) {
            DB::table('auth_step_up_challenges')
                ->where('challenge_hash', hash('sha256', $challengeToken))
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
            Log::warning('ARNA step-up OTP delivery failed', [
                'user_id' => $user->getKey(),
                'action' => $action,
                'channel' => $channel,
                'exception_class' => $exception::class,
            ]);
            throw new RuntimeException('The verification code could not be delivered. Try another verified channel.');
        }

        $this->audit('auth.step_up.otp_issued', $user, $tenantId, $action, $channel);

        return [
            'challenge_token' => $challengeToken,
            'channel' => $channel,
            'destination' => $masked,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Verify one possession factor and, for critical actions, one knowledge
     * factor. A successful proof produces a single-use, context-bound grant.
     */
    public function verify(
        User $user,
        int $tenantId,
        string $action,
        array $context,
        string $authenticator,
        string $code,
        ?string $challengeToken = null,
        ?string $knowledgeFactorType = null,
        ?string $knowledgeProof = null,
    ): ?string {
        $this->assertSupportedAction($action);
        $requirements = $this->policy->requirementsFor($action);
        $contextHash = $this->context->fingerprint($action, $context);

        if (! $user->is_active || $user->trashed()) {
            return null;
        }

        $possessionVerified = false;
        if ($authenticator === AdaptiveAuthenticationPolicy::OTP) {
            $possessionVerified = $this->verifyOtp($user, $tenantId, $action, $contextHash, $challengeToken, $code);
        } elseif ($authenticator === AdaptiveAuthenticationPolicy::GOOGLE_AUTHENTICATOR) {
            $possessionVerified = $this->totp->verifyForStepUp($user, $code);
        }

        if (! $possessionVerified) {
            $this->audit('auth.step_up.failed', $user, $tenantId, $action, $authenticator);
            return null;
        }

        $verifiedGroups = ['possession'];
        if ($requirements['required_factor_count'] >= 2) {
            if (! $this->verifyKnowledgeFactor($user, $knowledgeFactorType, $knowledgeProof)) {
                $this->audit('auth.step_up.knowledge_factor_failed', $user, $tenantId, $action, $knowledgeFactorType ?? 'missing');
                return null;
            }
            array_unshift($verifiedGroups, 'knowledge');
        }

        $grant = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes(max(1, min(10, (int) config('auth-step-up.grant_expiry_minutes', 5))));
        DB::table('auth_step_up_grants')->insert([
            'user_id' => $user->getKey(),
            'tenant_id' => $tenantId,
            'action' => $action,
            'grant_hash' => hash('sha256', $grant),
            'context_hash' => $contextHash,
            'verified_factor_groups' => json_encode($verifiedGroups, JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit('auth.step_up.grant_issued', $user, $tenantId, $action, implode('+', $verifiedGroups));

        return $grant;
    }

    public function consumeGrant(User $user, int $tenantId, string $action, array $context, string $grant): bool
    {
        if ($grant === '' || strlen($grant) !== 64) {
            return false;
        }

        $contextHash = $this->context->fingerprint($action, $context);

        return DB::transaction(function () use ($user, $tenantId, $action, $contextHash, $grant): bool {
            $row = DB::table('auth_step_up_grants')
                ->where('grant_hash', hash('sha256', $grant))
                ->where('user_id', $user->getKey())
                ->where('tenant_id', $tenantId)
                ->where('action', $action)
                ->where('context_hash', $contextHash)
                ->whereNull('consumed_at')
                ->lockForUpdate()
                ->first();

            if ($row === null || now()->greaterThanOrEqualTo($row->expires_at)) {
                return false;
            }

            DB::table('auth_step_up_grants')->where('id', $row->id)->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    private function verifyOtp(
        User $user,
        int $tenantId,
        string $action,
        string $contextHash,
        ?string $challengeToken,
        string $code,
    ): bool {
        if (! is_string($challengeToken) || ! preg_match('/^[a-f0-9]{64}$/', $challengeToken)
            || ! preg_match('/^\\d{6}$/', $code)) {
            return false;
        }

        return DB::transaction(function () use ($user, $tenantId, $action, $contextHash, $challengeToken, $code): bool {
            $challenge = DB::table('auth_step_up_challenges')
                ->where('challenge_hash', hash('sha256', $challengeToken))
                ->where('user_id', $user->getKey())
                ->where('tenant_id', $tenantId)
                ->where('action', $action)
                ->where('context_hash', $contextHash)
                ->whereNull('consumed_at')
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                return false;
            }

            $maxAttempts = max(1, min(10, (int) config('auth-step-up.max_attempts', 5)));
            if ((int) $challenge->attempts >= $maxAttempts || now()->greaterThanOrEqualTo($challenge->expires_at)) {
                DB::table('auth_step_up_challenges')->where('id', $challenge->id)
                    ->update(['consumed_at' => now(), 'updated_at' => now()]);
                return false;
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $attempts = (int) $challenge->attempts + 1;
                DB::table('auth_step_up_challenges')->where('id', $challenge->id)->update([
                    'attempts' => $attempts,
                    'consumed_at' => $attempts >= $maxAttempts ? now() : null,
                    'updated_at' => now(),
                ]);
                return false;
            }

            DB::table('auth_step_up_challenges')->where('id', $challenge->id)
                ->update(['verified_at' => now(), 'consumed_at' => now(), 'updated_at' => now()]);

            return true;
        }, 3);
    }

    private function verifyKnowledgeFactor(User $user, ?string $type, ?string $proof): bool
    {
        if (! is_string($proof) || $proof === '') {
            return false;
        }

        if ($type === AdaptiveAuthenticationPolicy::PASSWORD) {
            return Hash::check($proof, (string) $user->password);
        }

        if ($type === AdaptiveAuthenticationPolicy::PIN) {
            $token = $user->currentAccessToken();
            return $token instanceof \Laravel\Sanctum\PersonalAccessToken
                && $this->devices->isMobileToken($token)
                && $this->devices->verifyPinForToken($user, $token, $proof);
        }

        return false;
    }

    private function verifiedDestination(User $user, string $channel): array
    {
        if ($channel === 'email') {
            if ($user->email_verified_at === null || trim((string) $user->email) === '') {
                throw new RuntimeException('A previously verified email address is required.');
            }

            $email = trim((string) $user->email);
            [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
            $masked = strlen($local) <= 2
                ? str_repeat('*', max(1, strlen($local))).'@'.$domain
                : substr($local, 0, 2).str_repeat('*', max(1, strlen($local) - 2)).'@'.$domain;

            return [$email, $masked];
        }

        if ($channel === 'sms') {
            $phone = trim((string) $user->phone);
            $verifiedHash = (string) $user->phone_verified_phone_hash;
            if ($phone === '' || $user->phone_verified_at === null
                || $verifiedHash === '' || ! hash_equals($verifiedHash, hash('sha256', $phone))) {
                throw new RuntimeException('A previously verified phone number is required.');
            }

            return [$phone, '••••'.substr(preg_replace('/\\D+/', '', $phone) ?: '', -4)];
        }

        throw new RuntimeException('Unsupported OTP delivery channel.');
    }

    private function assertSupportedAction(string $action): void
    {
        if (! $this->context->supportsAction($action)) {
            throw new RuntimeException('The requested action is not registered for adaptive authentication.');
        }
    }

    private function audit(string $event, User $user, int $tenantId, string $action, string $factor): void
    {
        try {
            app(SecurityEventService::class)->log($event, [
                'user_id' => $user->getKey(),
                'tenant_id' => $tenantId,
                'action' => $action,
                'factor' => $factor,
                'ip' => request()->ip(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('ARNA adaptive authentication audit write failed', [
                'event' => $event,
                'user_id' => $user->getKey(),
                'exception_class' => $exception::class,
            ]);
        }
    }
}
