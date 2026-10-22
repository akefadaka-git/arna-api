<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Auth\\Services;

use Illuminate\\Support\\Facades\\Http;
use RuntimeException;

final class PhoneVerificationDelivery
{
    /**
     * Send a verification code through the configured HTTPS SMS gateway.
     *
     * Gateway contract: POST JSON {to, message, sender?}, with an optional
     * bearer token. The response must be a 2xx status. The code is never logged.
     */
    public function send(string $phone, string $code): void
    {
        $url = trim((string) config('phone-verification.gateway_url', ''));
        $token = trim((string) config('phone-verification.gateway_token', ''));
        $sender = trim((string) config('phone-verification.sender', ''));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('Phone verification delivery is not configured.');
        }

        $message = sprintf(
            'Your ARNA verification code is %s. It expires in %d minutes. Do not share this code.',
            $code,
            max(1, (int) config('phone-verification.expiry_minutes', 10)),
        );

        $request = Http::acceptJson()->asJson()->timeout(5);
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $payload = ['to' => $phone, 'message' => $message];
        if ($sender !== '') {
            $payload['sender'] = $sender;
        }

        $response = $request->post($url, $payload);
        if (! $response->successful()) {
            throw new RuntimeException('Phone verification delivery failed.');
        }
    }
}
