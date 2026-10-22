<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\PhoneVerificationDelivery;
use MaaleArna\MASES\Core\Auth\Services\PhoneVerificationService;

final class PhoneVerificationController extends Controller
{
    public function __construct(
        private readonly PhoneVerificationService $verification,
        private readonly PhoneVerificationDelivery $delivery,
    ) {
    }

    public function requestCode(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->is_active || $user->trashed()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'AUTH_UNAUTHENTICATED', 'message' => 'Authentication required.'],
            ], 401);
        }

        if (trim((string) $user->phone) === '') {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'AUTH_PHONE_REQUIRED', 'message' => 'Add a phone number before verifying it.'],
            ], 422);
        }

        if ($user->hasVerifiedPhone()) {
            return response()->json([
                'success' => true,
                'data' => ['verified' => true, 'message' => 'The current phone number is already verified.'],
            ]);
        }

        $code = $this->verification->issue($user);
        try {
            $this->delivery->send(trim((string) $user->phone), $code);
        } catch (\Throwable $exception) {
            $this->verification->invalidatePending($user);
            Log::warning('Phone verification delivery unavailable', [
                'user_id' => $user->getKey(),
                'exception_class' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AUTH_PHONE_DELIVERY_UNAVAILABLE',
                    'message' => 'Phone verification is temporarily unavailable. Try again later.',
                ],
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'sent' => true,
                'expires_in_seconds' => max(1, (int) config('phone-verification.expiry_minutes', 10)) * 60,
            ],
        ], 202);
    }

    public function confirmCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $user = $request->user();
        if (! $user instanceof User || ! $user->is_active || $user->trashed()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'AUTH_UNAUTHENTICATED', 'message' => 'Authentication required.'],
            ], 401);
        }

        if (! $this->verification->verify($user, $validated['code'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AUTH_PHONE_VERIFICATION_INVALID',
                    'message' => 'The verification code is invalid, expired, or has already been used.',
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['verified' => true],
        ]);
    }
}
