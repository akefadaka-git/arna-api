<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PlatformOperationsController extends Controller
{
    public function integrations(): JsonResponse
    {
        $catalogue = [
            ['provider_key' => 'dns', 'provider_type' => 'dns', 'display_name' => 'DNS Provider', 'category' => 'domain'],
            ['provider_key' => 'registrar', 'provider_type' => 'registrar', 'display_name' => 'Domain Registrar', 'category' => 'domain'],
            ['provider_key' => 'certificates', 'provider_type' => 'certificate', 'display_name' => 'SSL/TLS Certificate Provider', 'category' => 'domain'],
            ['provider_key' => 'hosting', 'provider_type' => 'hosting', 'display_name' => 'Hosting / Plesk', 'category' => 'infrastructure'],
            ['provider_key' => 'routing', 'provider_type' => 'routing', 'display_name' => 'Edge Routing / Reverse Proxy', 'category' => 'infrastructure'],
            ['provider_key' => 'monitoring', 'provider_type' => 'monitoring', 'display_name' => 'Monitoring Provider', 'category' => 'operations'],
            ['provider_key' => 'email', 'provider_type' => 'messaging', 'display_name' => 'Email Delivery Provider', 'category' => 'communication'],
        ];
        $registered = DB::table('platform_integrations')->get()->keyBy('provider_key');
        $items = collect($catalogue)->map(static function (array $provider) use ($registered): array {
            $record = $registered->get($provider['provider_key']);
            return [
                ...$provider,
                'registered' => $record !== null,
                'endpoint_url' => $record?->endpoint_url,
                'auth_mode' => $record?->auth_mode ?? 'none',
                'credentials_configured' => $record !== null && $record->credential_ciphertext !== null,
                'connection_state' => $record?->connection_state ?? 'not_connected',
                'last_observed_at' => null,
                'live_mutations_enabled' => false,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'capabilities' => [
                    'provider_registry' => true,
                    'credential_values_exposed' => false,
                    'provider_health_probes_enabled' => false,
                    'provider_mutations_enabled' => false,
                ],
            ],
        ]);
    }

    public function health(): JsonResponse
    {
        $databaseStatus = 'unknown';
        $databaseSummary = 'Database reachability has not been observed.';
        $databaseDuration = null;
        $startedAt = hrtime(true);
        try {
            DB::selectOne('SELECT 1 AS health_check');
            $databaseStatus = 'healthy';
            $databaseSummary = 'The application database accepted a read-only health query.';
            $databaseDuration = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        } catch (Throwable) {
            $databaseStatus = 'unhealthy';
            $databaseSummary = 'The application database health query failed.';
        }

        try {
            $observed = DB::table('platform_health_observations')
                ->orderByDesc('checked_at')->get()
                ->unique('component_key')->keyBy('component_key');
        } catch (Throwable) {
            $observed = collect();
        }

        $components = [
            ['key' => 'admin-api', 'type' => 'application', 'name' => 'Admin API', 'status' => 'healthy', 'summary' => 'This health endpoint is responding.'],
            ['key' => 'database', 'type' => 'database', 'name' => 'Primary Database', 'status' => $databaseStatus, 'summary' => $databaseSummary, 'duration_ms' => $databaseDuration],
            ['key' => 'queue', 'type' => 'queue', 'name' => 'Queue Workers', 'status' => 'not_observed', 'summary' => 'No queue health probe is configured.'],
            ['key' => 'cache', 'type' => 'cache', 'name' => 'Cache Store', 'status' => 'not_observed', 'summary' => 'No cache health probe is configured.'],
            ['key' => 'object-storage', 'type' => 'storage', 'name' => 'Object Storage', 'status' => 'not_observed', 'summary' => 'No storage health probe is configured.'],
            ['key' => 'dns-provider', 'type' => 'provider', 'name' => 'DNS Provider', 'status' => 'not_observed', 'summary' => 'No provider health probe is configured.'],
            ['key' => 'tls-provider', 'type' => 'provider', 'name' => 'SSL/TLS Provider', 'status' => 'not_observed', 'summary' => 'No provider health probe is configured.'],
            ['key' => 'hosting-provider', 'type' => 'provider', 'name' => 'Hosting Provider', 'status' => 'not_observed', 'summary' => 'No provider health probe is configured.'],
        ];

        $items = collect($components)->map(static function (array $component) use ($observed): array {
            $latest = $observed->get($component['key']);
            if ($latest !== null) {
                $component['status'] = $latest->status;
                $component['summary'] = $latest->summary;
                $component['checked_at'] = $latest->checked_at;
                $component['duration_ms'] = $latest->duration_ms === null ? null : (int) $latest->duration_ms;
                $component['source'] = $latest->source;
            }
            return $component;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'generated_at' => now()->toIso8601String(),
                'capabilities' => ['active_provider_probes' => false, 'automatic_remediation_enabled' => false],
            ],
        ]);
    }

    public function observations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'component_type' => ['sometimes', 'string', 'max:40'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $observations = DB::table('platform_health_observations')
            ->when(isset($validated['component_type']), fn ($query) => $query->where('component_type', $validated['component_type']))
            ->orderByDesc('checked_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json(['success' => true, 'data' => $observations]);
    }
}
