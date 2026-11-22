<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Security;

use RuntimeException;

/**
 * Resolves public Ed25519 keys from a versioned, deployment-controlled bundle.
 * Private keys are never accepted by this class or stored in ARNA.
 */
final class AgeReceiptTrustBundle
{
    /**
     * @return array{bundle_version:string,key_id:string,public_key:string,status:string,valid_from:string,valid_until:?string,revoked_at:?string}
     */
    public static function resolve(string $keyId, string $purpose, ?string $signedAt = null): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $keyId)) {
            throw new RuntimeException('AGE receipt key identifier is invalid.');
        }

        $bundle = (array) config('age.receipts.trust_bundle', []);
        $version = (string) ($bundle['version'] ?? '');
        if ($version === '' || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)) {
            throw new RuntimeException('AGE receipt trust bundle version is missing or invalid.');
        }

        $key = $bundle['keys'][$keyId] ?? null;
        if (!is_array($key) || ($key['purpose'] ?? null) !== $purpose) {
            throw new RuntimeException('AGE receipt signing key is not present in the required trust domain.');
        }

        $status = (string) ($key['status'] ?? '');
        if (!in_array($status, ['active', 'retired', 'revoked'], true) || $status === 'revoked') {
            throw new RuntimeException('AGE receipt signing key is inactive, revoked, or has an invalid status.');
        }

        $validFrom = (string) ($key['valid_from'] ?? '');
        $validUntil = isset($key['valid_until']) ? (string) $key['valid_until'] : null;
        $revokedAt = isset($key['revoked_at']) ? (string) $key['revoked_at'] : null;
        $effectiveAt = $signedAt ?? now()->toIso8601String();

        if ($validFrom === '' || strtotime($effectiveAt) === false || strtotime($validFrom) === false
            || strtotime($effectiveAt) < strtotime($validFrom)) {
            throw new RuntimeException('AGE receipt signature falls outside the key validity interval.');
        }
        if ($validUntil !== null && (strtotime($validUntil) === false || strtotime($effectiveAt) > strtotime($validUntil))) {
            throw new RuntimeException('AGE receipt signing key is outside its validity interval.');
        }
        if ($revokedAt !== null && (strtotime($revokedAt) === false || strtotime($effectiveAt) >= strtotime($revokedAt))) {
            throw new RuntimeException('AGE receipt signing key was revoked at the signature time.');
        }

        $encoded = $key['public_key_base64'] ?? null;
        $publicKey = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('AGE receipt public key must be a base64-encoded Ed25519 public key.');
        }

        return [
            'bundle_version' => $version,
            'key_id' => $keyId,
            'public_key' => $publicKey,
            'status' => $status,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'revoked_at' => $revokedAt,
        ];
    }
}
