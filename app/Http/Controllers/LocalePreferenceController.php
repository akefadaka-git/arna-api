<?php

declare(strict_types=1);

namespace App\\Http\\Controllers;

use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;

final class LocalePreferenceController
{
    /**
     * Persist the authenticated user's UI language preference.
     *
     * Locale is an account-level preference, not a tenant resource.
     * This route intentionally requires authentication but not an
     * active tenant membership. The caller cannot select another user.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:am,en'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['locale' => $validated['locale']])->save();

        return response()->json([
            'success' => true,
            'data' => [
                'locale' => $user->locale,
            ],
        ]);
    }
}
