<?php

declare(strict_types=1);

namespace App\\Http\\Controllers\\Auth;

use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;

final class PhoneVerificationController extends Controller
{
    /**
     * SMS delivery is intentionally disabled until the provider integration is
     * reviewed and explicitly enabled. Do not generate or persist OTPs here.
     */
    public function requestCode(Request $request): JsonResponse
    {
        return $this->comingSoon($request);
    }

    /**
     * Confirmation remains unavailable while SMS delivery is not integrated.
     */
    public function confirmCode(Request $request): JsonResponse
    {
        return $this->comingSoon($request);
    }

    private function comingSoon(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->is_active || $user->trashed()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AUTH_UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                ],
            ], 401);
        }

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'AUTH_SMS_INTEGRATION_COMING_SOON',
                'message' => 'SMS phone verification is coming soon. / የSMS ስልክ ማረጋገጫ አገልግሎት በቅርቡ ይጀምራል።',
            ],
        ], 503);
    }
}
