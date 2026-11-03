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
        if ($url === '' || !str_starts_with($url, 'https://')) {
            throw new RuntimeException('A trusted HTTPS media scanner is not configured.');
        }
        if (!Storage::disk('local')->exists($path)) {
            throw new RuntimeException('The quarantined media file is missing.');
        }

        $response = Http::timeout(45)
            ->withToken((string) config('services.corporate_media_scanner.token'))
            ->attach('file', Storage::disk('local')->get($path), basename($path))
            ->post($url, ['reference' => $reference]);

        if (!$response->successful()) {
            throw new RuntimeException('The media scanner did not return a successful response.');
        }

        $status = $response->json('status');
        if (!in_array($status, ['clean', 'infected'], true)) {
            throw new RuntimeException('The media scanner returned an invalid verdict.');
        }

        return ['status' => $status, 'signature' => substr((string) $response->json('signature', ''), 0, 120)];
    }
}
