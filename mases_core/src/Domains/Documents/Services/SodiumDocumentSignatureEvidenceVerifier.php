<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Documents\Contracts\DocumentSignatureEvidenceVerifier;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Verifies detached Ed25519 signatures against tenant-scoped, pre-trusted keys.
 * Private keys never enter ARNA. Key onboarding is an owner-controlled operation.
 */
final class SodiumDocumentSignatureEvidenceVerifier implements DocumentSignatureEvidenceVerifier
{
    public static function canonicalPayload(
        int $tenantId,
        string $workflowUuid,
        string $fileSha256,
        int $signerUserId,
        DateTimeImmutable $signedAt,
    ): string {
        return json_encode([
            'contract' => 'ARNA-DOCUMENT-SIGNATURE-V1',
            'tenant_id' => $tenantId,
            'workflow_uuid' => $workflowUuid,
            'file_sha256' => $fileSha256,
            'signer_user_id' => $signerUserId,
            'signed_at' => $signedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function verify(
        User $signer,
        int $tenantId,
        string $workflowUuid,
        string $fileSha256,
        array $evidence,
    ): array {
        if (! extension_loaded('sodium')) {
            throw new \LogicException('The sodium extension is required for document signature verification.');
        }
        if (! $signer->is_active || $tenantId < 1 || trim($workflowUuid) === '') {
            throw new AuthorizationException('An active authenticated signatory and validated tenant workflow are required.');
        }

        $keyId = trim((string) ($evidence['key_id'] ?? ''));
        $signatureEncoded = (string) ($evidence['signature'] ?? '');
        $signedAtRaw = (string) ($evidence['signed_at'] ?? '');

        if ($keyId === '' || $signatureEncoded === ''
            || ! preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/', $signedAtRaw)
            || ! preg_match('/^[a-f0-9]{64}$/', $fileSha256)) {
            throw new UnprocessableEntityHttpException('A trusted signing key, detached signature and signed-at instant are required.');
        }

        try {
            $signedAt = new DateTimeImmutable($signedAtRaw);
        } catch (\Throwable) {
            throw new UnprocessableEntityHttpException('The signature timestamp is invalid.');
        }

        if ($signedAt > new DateTimeImmutable('now')) {
            throw new UnprocessableEntityHttpException('A document signature cannot be dated in the future.');
        }

        $key = DB::table('document_signing_keys')
            ->where('tenant_id', $tenantId)
            ->where('key_id', $keyId)
            ->where('signer_user_id', $signer->getKey())
            ->where('algorithm', 'Ed25519')
            ->where('trusted_at', '<=', $signedAt->format('Y-m-d H:i:s'))
            ->where(function ($query) use ($signedAt): void {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $signedAt->format('Y-m-d H:i:s'));
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('document_signing_key_revocations')
                    ->whereColumn('document_signing_key_revocations.signing_key_id', 'document_signing_keys.id');
            })
            ->first(['id', 'public_key_base64']);

        if ($key === null) {
            throw new AuthorizationException('The signing key is not trusted, is outside its validity interval, or has been revoked.');
        }

        $publicKey = base64_decode((string) $key->public_key_base64, true);
        $signature = base64_decode($signatureEncoded, true);
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new UnprocessableEntityHttpException('The Ed25519 public key or detached signature has an invalid encoding or length.');
        }

        // The canonical challenge is deliberately fixed-order JSON. It binds
        // the tenant, workflow, exact binary digest, signer identity and time.
        $payload = self::canonicalPayload(
            $tenantId,
            $workflowUuid,
            $fileSha256,
            (int) $signer->getKey(),
            $signedAt,
        );

        if (! sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
            throw new AuthorizationException('The detached Ed25519 signature does not verify for this document evidence.');
        }

        return [
            'key_id' => (int) $key->id,
            'payload_sha256' => hash('sha256', $payload),
            'signature' => $signatureEncoded,
            'signed_at' => $signedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }
}
