<?php

declare(strict_types=1);

namespace App\Services\Admin\Providers;

use RuntimeException;

final class ProviderOwnerSignatureVerifier
{
    public const PURPOSE = 'ARNA_PROVIDER_ACTION_OWNER_APPROVAL_V1';

    /**
     * Verifies a detached Ed25519 Owner authorization using a provider-action-only trust anchor.
     * The trust anchor is deliberately separate from tenant RBAC and settings approval keys.
     *
     * @param array<string, mixed> $request
     */
    public function verify(array $request, string $keyId, string $signatureBase64): string
    {
        $configuredKeyId = (string) config('services.arna_provider_owner.key_id', '');
        $encodedPublicKey = (string) config('services.arna_provider_owner.public_key', '');

        if ($configuredKeyId === '' || $keyId !== $configuredKeyId || $encodedPublicKey === '') {
            throw new RuntimeException('The dedicated ARNA provider Owner trust anchor is not configured or does not match.');
        }

        $publicKey = base64_decode($encodedPublicKey, true);
        $signature = base64_decode($signatureBase64, true);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new RuntimeException('Owner Ed25519 signature or public-key encoding is invalid.');
        }

        $message = self::canonicalMessage($request);
        if (! sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new RuntimeException('Owner signature verification failed for this provider action digest.');
        }

        return hash('sha256', $signature);
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function canonicalMessage(array $request): string
    {
        $binding = [
            'purpose' => self::PURPOSE,
            'request_id' => (string) $request['public_id'],
            'provider_key' => (string) $request['provider_key'],
            'adapter_version' => (string) $request['adapter_version'],
            'environment' => (string) $request['environment'],
            'operation' => (string) $request['operation_key'],
            'resource_type' => (string) $request['resource_type'],
            'resource_identifier' => (string) $request['resource_identifier'],
            'payload_sha256' => (string) $request['payload_sha256'],
            'risk_tier' => (string) $request['risk_tier'],
            'policy_version' => (string) $request['policy_version'],
            'expires_at' => (string) $request['expires_at'],
        ];

        ksort($binding);

        return self::PURPOSE . "\n" . json_encode(
            $binding,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
