<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;

final class PlatformApplicationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'in:all,active,inactive'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $status = $validated['status'] ?? 'all';
        $perPage = (int) ($validated['per_page'] ?? 50);

        $applications = PlatformApplication::query()
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void {
                $inner->where('slug', 'like', '%' . $search . '%')
                    ->orWhere('domain', 'like', '%' . $search . '%')
                    ->orWhere('name_en', 'like', '%' . $search . '%')
                    ->orWhere('name_am', 'like', '%' . $search . '%');
            }))
            ->orderBy('display_order')
            ->orderBy('slug')
            ->paginate($perPage);

        $applications->getCollection()->transform(static fn (PlatformApplication $application): array => [
            'id' => $application->getKey(),
            'slug' => $application->slug,
            'name' => $application->display_name,
            'domain' => $application->domain,
            'category' => $application->category,
            'is_active' => (bool) $application->is_active,
            'frontend_ready' => (bool) $application->is_frontend_ready,
            'display_order' => (int) $application->display_order,
        ]);

        return response()->json(['success' => true, 'data' => $applications]);
    }
}
