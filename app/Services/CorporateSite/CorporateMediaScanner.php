<?php

declare(strict_types=1);

namespace App\Services\CorporateSite;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class CorporateMediaScanner
{
    /**
     * Fail closed: an absent or unreachable scanner never marks an asset clean.
     *
     * Scanner contract: POST multipart field "file" plus "reference"; JSON
     * response { "status": "clean" | "infected" | "error", "signature": "..." }.
     */
    public function scan(string $path, string $reference): array
    {
        $url = (string) config('services.corporate_media_scanner.url');
        $token = trim((string) config('services.corporate_media_scanner.token'));
        if ($url === '' || !str_starts_with($url, 'https://') || $token === '') {
            throw new RuntimeException('A trusted HTTPS media scanner and authentication token are not configured.');
        }
        if (!Storage::disk('local')->exists($path)) {
            throw new RuntimeException('The quarantined media file is missing.');
        }

        $response = Http::timeout(45)
            ->withToken($token)
            ->attach('file', Storage::disk('local')->get($path), basename($path))
            ->post($url, ['reference' => $reference]);

        if (!$response->successful()) {
            throw new RuntimeException('The media scanner did not return a successful response.');
        }

        $status = $response->json('status');
        if (!in_array($status, ['clean', 'infected'], true)) {
            throw new RuntimeException('The media scanner returned an invalid verdict.');
        }

        $signature = trim((string) $response->json('signature', ''));
        if ($signature === '') {
            throw new RuntimeException('The media scanner did not provide a verifiable scan signature.');
        }

        return ['status' => $status, 'signature' => substr($signature, 0, 120)];
    }
}
