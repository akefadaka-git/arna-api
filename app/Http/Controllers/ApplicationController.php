<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;

/**
 * Returns active, frontend-ready applications accessible to the current user.
 * Tenant membership is enforced by route middleware; access includes direct
 * and role-derived permissions. The response is the launcher API envelope.
 */
final class ApplicationController extends Controller
{
    /** Returns the user's authorized launcher catalog. */
    public function index(Request $request): JsonResponse
    {

        $user = $request->user();
        $user->loadMissing(['permissions', 'roles.permissions']);

        $permissionSlugs = array_flip(
            $user->permissions
                ->pluck('slug')
                ->merge($user->roles->flatMap->permissions->pluck('slug'))
                ->unique()
                ->values()
                ->all()
        );

        $applications = PlatformApplication::query()
            ->where('is_active', true)
            ->where('is_frontend_ready', true)
            ->orderBy('display_order')
            ->get();

        $launchable = $applications
            ->filter(function (PlatformApplication $application) use ($permissionSlugs): bool {
                return isset($permissionSlugs['app.' . $application->slug . '.access']);
            })
            ->values();

        return response()->json([
            'success' => true,
            'data'    => $launchable->map(static fn (PlatformApplication $application): array => [
                'slug'              => $application->slug,
                'name_am'           => $application->name_am,
                'name_en'           => $application->name_en,
                'description_am'    => $application->description_am,
                'description_en'    => $application->description_en,
                'domain'            => $application->domain,
                'is_active'         => $application->is_active,
                'is_frontend_ready' => $application->is_frontend_ready,
                'icon_key'          => $application->icon_key,
                'category'          => $application->category,
                'display_order'     => $application->display_order,
            ])->all(),
        ]);
    }
}