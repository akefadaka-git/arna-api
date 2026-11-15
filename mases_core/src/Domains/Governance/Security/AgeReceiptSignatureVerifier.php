<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Security;

use InvalidArgumentException;
use RuntimeException;
use SodiumException;

/**
 * Verifies signatures from separate receipt-authority and witness trust domains.
 * Payload signatures cover every supplied field except the signature itself.
 */
final class AgeReceiptSignatureVerifier
{
    private const RECEIPT_DOMAIN = "arna:age:external-receipt:receipt:v1\0";
    private const WITNESS_DOMAIN = "arna:age:external-receipt:witness:v1\0";

    /** @param array<string,mixed> $receipt */
    public function verifyReceipt(array $receipt): bool
    {
        return $this->verify($receipt, 'receipt', self::RECEIPT_DOMAIN);
    }

    /** @param array<string,mixed> $checkpoint */
    public function verifyWitnessCheckpoint(array $checkpoint): bool
    {
        return $this->verify($checkpoint, 'witness', self::WITNESS_DOMAIN);
    }

    /** @param array<string,mixed> $head */
    public function verifyWitnessHead(array $head): bool
    {
        return $this->verify($head, 'witness', self::WITNESS_DOMAIN);
    }

    /** @param array<string,mixed> $signed */
    private function verify(array $signed, string $purpose, string $domain): bool
    {
        $keyId = $signed['key_id'] ?? $signed['receipt_key_id'] ?? $signed['witness_key_id'] ?? null;
        $signatureEncoded = $signed['signature'] ?? $signed['receipt_signature'] ?? $signed['witness_signature'] ?? null;
        $signedAt = $signed['signed_at'] ?? $signed['witnessed_at'] ?? $signed['anchored_at'] ?? null;
        if (!is_string($keyId) || !is_string($signatureEncoded) || !is_string($signedAt)) {
            return false;
        }

        try {
            $key = AgeReceiptTrustBundle::resolve($keyId, $purpose, $signedAt);
            $signature = base64_decode($signatureEncoded, true);
            if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }

            unset($signed['signature'], $signed['receipt_signature'], $signed['witness_signature']);
            $message = $domain . AgeReceiptCanonicalizer::encode($signed);

            return sodium_crypto_sign_verify_detached($signature, $message, $key['public_key']);
        } catch (InvalidArgumentException|RuntimeException|SodiumException) {
            return false;
        }
    }
}
