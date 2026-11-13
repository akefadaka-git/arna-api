<?php

declare(strict_types=1);

namespace App\Services\CorporateSite;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class CorporateMediaScanner
{
    /**
     * Fail closed unless the scanner response is authenticated by the pinned
     * Ed25519 public key. The bearer token authenticates the request; it is
     * deliberately not reused as a response-signing key.
     *
     * Signed message (UTF-8, LF separators, no trailing LF):
     * ARNA-CORPORATE-MEDIA-SCAN-V1\n{status}\n{reference}\n{sha256}
     */
    public function verifyVerdict(string $status, string $reference, string $sha256, string $encodedSignature): bool
    {
        $encodedPublicKey = trim((string) config('services.corporate_media_scanner.public_key'));
        if (
            !function_exists('sodium_crypto_sign_verify_detached') ||
            !in_array($status, ['clean', 'infected'], true) ||
            $reference === '' ||
            str_contains($reference, "\n") ||
            str_contains($reference, "\r") ||
            preg_match('/\\A[a-f0-9]{64}\\z/D', $sha256) !== 1
        ) {
            return false;
        }

        $publicKey = base64_decode($encodedPublicKey, true);
        $signature = base64_decode($encodedSignature, true);
        if (
            $publicKey === false ||
            base64_encode($publicKey) !== $encodedPublicKey ||
            strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
            $signature === false ||
            base64_encode($signature) !== $encodedSignature ||
            strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        ) {
            return false;
        }

        $signedMessage = implode("\n", [
            'ARNA-CORPORATE-MEDIA-SCAN-V1',
            $status,
            $reference,
            $sha256,
        ]);

        return sodium_crypto_sign_verify_detached($signature, $signedMessage, $publicKey);
    }

    public function scan(string $path, string $reference): array
    {
        $url = trim((string) config('services.corporate_media_scanner.url'));
        $token = trim((string) config('services.corporate_media_scanner.token'));
        $encodedPublicKey = trim((string) config('services.corporate_media_scanner.public_key'));

        $urlParts = $url !== '' ? parse_url($url) : false;
        if (
            $urlParts === false ||
            strtolower((string) ($urlParts['scheme'] ?? '')) !== 'https' ||
            !filled($urlParts['host'] ?? null) ||
            $token === '' ||
            $encodedPublicKey === ''
        ) {
            throw new RuntimeException('A trusted HTTPS media scanner, authentication token, and signing public key are required.');
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('Ed25519 signature verification is unavailable; media remains quarantined.');
        }

        $publicKey = base64_decode($encodedPublicKey, true);
        if (
            $publicKey === false ||
            base64_encode($publicKey) !== $encodedPublicKey ||
            strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        ) {
            throw new RuntimeException('The configured media scanner signing public key is invalid.');
        }

        if (!Storage::disk('local')->exists($path)) {
            throw new RuntimeException('The quarantined media file is missing.');
        }

        $fileContents = Storage::disk('local')->get($path);
        $fileDigest = hash('sha256', $fileContents);
        if ($reference === '' || str_contains($reference, "\n") || str_contains($reference, "\r")) {
            throw new RuntimeException('The media scan reference is invalid.');
        }

        $response = Http::timeout(45)
            ->withToken($token)
            ->attach('file', $fileContents, basename($path))
            ->post($url, ['reference' => $reference]);

        if (!$response->successful()) {
            throw new RuntimeException('The media scanner did not return a successful response.');
        }

        $status = $response->json('status');
        if (!in_array($status, ['clean', 'infected'], true)) {
            throw new RuntimeException('The media scanner returned an invalid verdict.');
        }

        $encodedSignature = trim((string) $response->json('signature', ''));
        $signature = $encodedSignature !== '' ? base64_decode($encodedSignature, true) : false;
        if (
            $signature === false ||
            base64_encode($signature) !== $encodedSignature ||
            strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        ) {
            throw new RuntimeException('The media scanner did not provide a valid Ed25519 signature.');
        }

        $signedMessage = implode("\n", [
            'ARNA-CORPORATE-MEDIA-SCAN-V1',
            $status,
            $reference,
            $fileDigest,
        ]);

        if (!$this->verifyVerdict($status, $reference, $fileDigest, $encodedSignature)) {
            throw new RuntimeException('The media scanner signature could not be verified; the asset remains quarantined.');
        }

        return ['status' => $status, 'signature' => $encodedSignature, 'sha256' => $fileDigest];
    }
}
