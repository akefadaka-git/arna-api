<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Governance\\Security;

use RuntimeException;

/**
 * Application-held HMAC attestations for the AGE voter-register lifecycle.
 * Keys are supplied through deployment configuration and are never stored in
 * the database. This detects database-only tampering; it is not protection
 * against compromise of the application process or its secret store.
 */
final class AgeRegisterAttestation
{
    private const DOMAIN = 'maale-arna:age:voter-register:attestation:v1';

    /**
     * @param array<string,mixed> $payload
     * @return array{key_id:string,signature:string}
     */
    public static function signActive(array $payload): array
    {
        $keyId = trim((string) config('age.register_attestation.active_key_id', ''));
        if ($keyId === '') {
            throw new RuntimeException('AGE register attestation signing is not configured; register preparation is blocked.');
        }

        $key = self::key($keyId);
        return [
            'key_id' => $keyId,
            'signature' => hash_hmac('sha256', self::message($payload), $key),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function verify(?string $keyId, ?string $signature, array $payload): bool
    {
        if ($keyId === null || $signature === null || !preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return false;
        }

        try {
            $expected = hash_hmac('sha256', self::message($payload), self::key($keyId));
        } catch (RuntimeException) {
            return false;
        }

        return hash_equals($expected, strtolower($signature));
    }

    private static function key(string $keyId): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId)) {
            throw new RuntimeException('AGE register attestation key identifier is invalid.');
        }

        $encoded = config('age.register_attestation.keys.' . $keyId);
        if (!is_string($encoded) || $encoded === '') {
            throw new RuntimeException('AGE register attestation key is unavailable.');
        }

        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) < 32) {
            throw new RuntimeException('AGE register attestation keys must be base64-encoded secrets of at least 32 bytes.');
        }

        return $key;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function message(array $payload): string
    {
        return self::DOMAIN . "\\0" . json_encode(
            self::canonicalize(['payload' => $payload]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
