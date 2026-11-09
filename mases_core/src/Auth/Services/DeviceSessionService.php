<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use MaaleArna\MASES\Core\Auth\Models\DeviceSession;
use MaaleArna\MASES\Core\Auth\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeviceSessionService
{
    public const MOBILE_ABILITY = 'mobile:api';

    public function installationHash(string $installationId): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        if ($key === '') {
            throw new \RuntimeException('Application encryption key is unavailable.');
        }

        return hash_hmac('sha256', $installationId, $key);
    }

    /**
     * Replace the just-issued legacy login token with a device-bound token.
     * The legacy token has not yet been returned to the caller.
     */
    public function establish(User $user, string $legacyPlainToken, array $device, ?int $tenantId): array
    {
        $legacy = PersonalAccessToken::findToken($legacyPlainToken);
        if (!$legacy || $legacy->tokenable_type !== $user->getMorphClass() || (string) $legacy->tokenable_id !== (string) $user->getKey()) {
            throw new \RuntimeException('Unable to bind authentication token to device session.');
        }

        return DB::transaction(function () use ($user, $legacy, $device, $tenantId): array {
            $legacy->delete();
            $expiresAt = now()->addMinutes(max(1, (int) config('sanctum.expiration', 10080)));
            $newToken = $user->createToken('arna-android', [self::MOBILE_ABILITY], $expiresAt);

            $session = new DeviceSession();
            $session->public_id = (string) Str::uuid();
            $session->tenant_id = $tenantId;
            $session->installation_hash = $this->installationHash($device['device_installation_id']);
            $session->platform = 'android';
            $session->app_version = $device['app_version'] ?? null;
            $session->device_label = $device['device_label'] ?? null;
            $session->last_seen_at = now();
            $session->expires_at = $expiresAt;
            $session->user()->associate($user);
            $session->save();
            $session->personal_access_token_id = $newToken->accessToken->getKey();
            $session->save();

            return [
                'token' => $newToken->plainTextToken,
                'session_id' => $session->public_id,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        }, 3);
    }

    public function listFor(User $user, int|string|null $currentTokenId): array
    {
        return DeviceSession::query()
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(static fn (DeviceSession $session): array => [
                'session_id' => $session->public_id,
                'platform' => $session->platform,
                'device_label' => $session->device_label,
                'app_version' => $session->app_version,
                'created_at' => $session->created_at?->toIso8601String(),
                'last_seen_at' => $session->last_seen_at?->toIso8601String(),
                'expires_at' => $session->expires_at?->toIso8601String(),
                'revoked_at' => $session->revoked_at?->toIso8601String(),
                'is_current' => (string) $session->personal_access_token_id === (string) $currentTokenId,
            ])->all();
    }

    public function revoke(User $user, string $publicId): void
    {
        DB::transaction(function () use ($user, $publicId): void {
            $session = DeviceSession::query()
                ->where('public_id', $publicId)
                ->where('user_type', $user->getMorphClass())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if (!$session) {
                throw new NotFoundHttpException('Session not found.');
            }
            if ($session->revoked_at !== null) {
                return;
            }

            if ($session->personal_access_token_id !== null) {
                PersonalAccessToken::query()->whereKey($session->personal_access_token_id)->delete();
            }
            $session->forceFill([
                'revoked_at' => now(),
                'revocation_reason' => 'user_revoked',
                'personal_access_token_id' => null,
            ])->save();
        }, 3);
    }

    public function refresh(User $user, PersonalAccessToken $currentToken): array
    {
        return DB::transaction(function () use ($user, $currentToken): array {
            $session = DeviceSession::query()
                ->where('personal_access_token_id', $currentToken->getKey())
                ->where('user_type', $user->getMorphClass())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if (!$session || !$session->isActive()) {
                throw new AccessDeniedHttpException('Session is not available.');
            }

            $expiresAt = now()->addMinutes(max(1, (int) config('sanctum.expiration', 10080)));
            $replacement = $user->createToken('arna-android', [self::MOBILE_ABILITY], $expiresAt);
            PersonalAccessToken::query()->whereKey($currentToken->getKey())->delete();

            $session->forceFill([
                'personal_access_token_id' => $replacement->accessToken->getKey(),
                'last_seen_at' => now(),
                'expires_at' => $expiresAt,
            ])->save();

            return [
                'token' => $replacement->plainTextToken,
                'token_type' => 'Bearer',
                'session_id' => $session->public_id,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        }, 3);
    }

    public function logoutCurrent(User $user, PersonalAccessToken $currentToken): void
    {
        DB::transaction(function () use ($user, $currentToken): void {
            $session = DeviceSession::query()
                ->where('personal_access_token_id', $currentToken->getKey())
                ->where('user_type', $user->getMorphClass())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($session) {
                $session->forceFill([
                    'revoked_at' => now(),
                    'revocation_reason' => 'logout',
                    'personal_access_token_id' => null,
                ])->save();
            }
            PersonalAccessToken::query()->whereKey($currentToken->getKey())->delete();
        }, 3);
    }

    public function validateToken(User $user, PersonalAccessToken $token): ?DeviceSession
    {
        if (!$token->can(self::MOBILE_ABILITY)) {
            return null;
        }

        return DeviceSession::query()
            ->where('personal_access_token_id', $token->getKey())
            ->where('user_type', $user->getMorphClass())
            ->where('user_id', $user->getKey())
            ->active()
            ->first();
    }
}
