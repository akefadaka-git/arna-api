<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class PlatformDomainRegistryController extends Controller
{
    /**
     * Return the domains currently referenced by ARNA's authoritative
     * application and tenant registries. This is an inventory projection,
     * not proof of registrar ownership, DNS resolution, TLS validity, or
     * hosting availability.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:253'],
            'source' => ['sometimes', 'in:application,tenant'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $source = $validated['source'] ?? null;
        $perPage = (int) ($validated['per_page'] ?? 25);

        $applications = collect();
        if ($source === null || $source === 'application') {
            $applications = PlatformApplication::query()
                ->whereNotNull('domain')
                ->where('domain', '<>', '')
                ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void {
                    $inner->where('domain', 'like', '%' . $search . '%')
                        ->orWhere('slug', 'like', '%' . $search . '%')
                        ->orWhere('name_en', 'like', '%' . $search . '%')
                        ->orWhere('name_am', 'like', '%' . $search . '%');
                }))
                ->get()
                ->map(static fn (PlatformApplication $application): array => [
                    'id' => 'application:' . $application->getKey(),
                    'fqdn' => strtolower((string) $application->domain),
                    'source' => 'application',
                    'source_id' => $application->getKey(),
                    'source_slug' => $application->slug,
                    'display_name' => $application->display_name,
                    'is_active' => (bool) $application->is_active,
                    'frontend_ready' => (bool) $application->is_frontend_ready,
                    'registry_state' => 'catalogued',
                    'ownership_state' => 'not_verified',
                    'dns_state' => 'not_observed',
                    'tls_state' => 'not_observed',
                    'hosting_state' => 'not_observed',
                    'last_observed_at' => null,
                ]);
        }

        $tenants = collect();
        if ($source === null || $source === 'tenant') {
            $tenants = Tenant::query()
                ->whereNotNull('domain')
                ->where('domain', '<>', '')
                ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void {
                    $inner->where('domain', 'like', '%' . $search . '%')
                        ->orWhere('slug', 'like', '%' . $search . '%')
                        ->orWhere('name_en', 'like', '%' . $search . '%')
                        ->orWhere('name_am', 'like', '%' . $search . '%');
                }))
                ->get()
                ->map(static fn (Tenant $tenant): array => [
                    'id' => 'tenant:' . $tenant->getKey(),
                    'fqdn' => strtolower((string) $tenant->domain),
                    'source' => 'tenant',
                    'source_id' => $tenant->getKey(),
                    'source_slug' => $tenant->slug,
                    'display_name' => $tenant->display_name,
                    'is_active' => (bool) $tenant->is_active,
                    'frontend_ready' => null,
                    'registry_state' => 'catalogued',
                    'ownership_state' => 'not_verified',
                    'dns_state' => 'not_observed',
                    'tls_state' => 'not_observed',
                    'hosting_state' => 'not_observed',
                    'last_observed_at' => null,
                ]);
        }

        $items = $applications->concat($tenants)
            ->sortBy('fqdn', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $total = $items->count();
        $page = max(1, $request->integer('page', 1));
        $data = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $data,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / $perPage)),
                ],
                'capabilities' => [
                    'registry_inventory' => true,
                    'ownership_verification' => false,
                    'dns_provider_connected' => false,
                    'tls_provider_connected' => false,
                    'hosting_provider_connected' => false,
                    'live_mutations_enabled' => false,
                ],
            ],
        ]);
    }
}
