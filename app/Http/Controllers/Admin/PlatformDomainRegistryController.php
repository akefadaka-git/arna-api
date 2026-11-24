<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class PlatformDomainRegistryController extends Controller
{
    /**
     * Merge persistent domain records with legacy application/tenant projections.
     * Provider observations remain explicitly distinct from ownership verification.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:253'],
            'source' => ['sometimes', 'in:application,tenant,registry'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $source = $validated['source'] ?? null;
        $perPage = (int) ($validated['per_page'] ?? 25);
        $items = collect();

        if ($source === null || $source === 'registry') {
            $items = DB::table('platform_domains')
                ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void {
                    $inner->where('fqdn', 'like', '%' . $search . '%')
                        ->orWhere('display_name', 'like', '%' . $search . '%');
                }))
                ->orderBy('fqdn')
                ->get()
                ->map(static fn ($domain): array => [
                    'id' => 'registry:' . $domain->id,
                    'registry_id' => (int) $domain->id,
                    'fqdn' => $domain->fqdn,
                    'source' => 'registry',
                    'owner_type' => $domain->owner_type,
                    'owner_id' => $domain->owner_id === null ? null : (int) $domain->owner_id,
                    'display_name' => $domain->display_name,
                    'environment' => $domain->environment,
                    'is_active' => $domain->lifecycle_status === 'active',
                    'registry_state' => $domain->lifecycle_status,
                    'ownership_state' => $domain->ownership_verified_at === null ? 'not_verified' : 'verified',
                    'dns_state' => 'not_observed',
                    'tls_state' => 'not_observed',
                    'hosting_state' => 'not_observed',
                    'last_observed_at' => null,
                ]);
        }

        if ($source === null || $source === 'application') {
            $items = $items->concat(PlatformApplication::query()
                ->whereNotNull('domain')->where('domain', '<>', '')
                ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void {
                    $inner->where('domain', 'like', '%' . $search . '%')
                        ->orWhere('slug', 'like', '%' . $search . '%')
                        ->orWhere('name_en', 'like', '%' . $search . '%')
                        ->orWhere('name_am', 'like', '%' . $search . '%');
                }))->get()
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
                ]));
        }

        if ($source === null || $source === 'tenant') {
            $items = $items->concat(Tenant::query()
                ->whereNotNull('domain')->where('domain', '<>', '')
                ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void {
                    $inner->where('domain', 'like', '%' . $search . '%')
                        ->orWhere('slug', 'like', '%' . $search . '%')
                        ->orWhere('name_en', 'like', '%' . $search . '%')
                        ->orWhere('name_am', 'like', '%' . $search . '%');
                }))->get()
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
                ]));
        }

        $items = $items->sortBy('fqdn', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $total = $items->count();
        $page = max(1, $request->integer('page', 1));

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items->slice(($page - 1) * $perPage, $perPage)->values(),
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
                'capabilities' => [
                    'persistent_registry' => true,
                    'ownership_verification' => true,
                    'dns_provider_connected' => false,
                    'tls_provider_connected' => false,
                    'hosting_provider_connected' => false,
                    'live_provider_mutations_enabled' => false,
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fqdn' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'owner_type' => ['sometimes', 'in:platform,application,tenant'],
            'owner_id' => ['nullable', 'integer', 'min:1'],
            'display_name' => ['nullable', 'string', 'max:160'],
            'environment' => ['sometimes', 'in:production,staging,development,testing'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $fqdn = strtolower(rtrim($data['fqdn'], '.'));
        $ownerType = $data['owner_type'] ?? 'platform';
        $ownerId = isset($data['owner_id']) ? (int) $data['owner_id'] : null;
        $this->assertOwnerExists($ownerType, $ownerId);

        if (DB::table('platform_domains')->where('fqdn', $fqdn)->exists()) {
            throw ValidationException::withMessages(['fqdn' => 'This domain is already registered.']);
        }

        $token = Str::random(48);
        $actorId = $request->user()?->getAuthIdentifier();
        $domainId = DB::transaction(function () use ($data, $fqdn, $ownerType, $ownerId, $token, $actorId, $request): int {
            $now = now();
            $id = DB::table('platform_domains')->insertGetId([
                'fqdn' => $fqdn,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'display_name' => $data['display_name'] ?? null,
                'environment' => $data['environment'] ?? 'production',
                'lifecycle_status' => 'pending_verification',
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('platform_domain_verifications')->insert([
                'domain_id' => $id,
                'method' => 'dns_txt',
                'challenge_hash' => hash('sha256', $token),
                'expires_at' => $now->copy()->addHours(48),
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit($id, $actorId, 'domain.registered', null, ['fqdn' => $fqdn, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'lifecycle_status' => 'pending_verification'], $data['reason'], $request);
            return (int) $id;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $domainId,
                'fqdn' => $fqdn,
                'lifecycle_status' => 'pending_verification',
                'verification' => ['method' => 'dns_txt', 'record_name' => '_arna-verification.' . $fqdn, 'record_value' => $token, 'expires_at' => now()->addHours(48)->toIso8601String()],
                'secret_handling' => 'The challenge is stored only as a SHA-256 hash and is returned once.',
            ],
        ], 201);
    }

    public function update(Request $request, int $domain): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'environment' => ['sometimes', 'in:production,staging,development,testing'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $before = DB::table('platform_domains')->where('id', $domain)->first();
        abort_if($before === null, 404, 'Domain registry record not found.');

        $after = [
            'display_name' => $data['display_name'] ?? $before->display_name,
            'environment' => $data['environment'] ?? $before->environment,
            'updated_by' => $request->user()?->getAuthIdentifier(),
            'updated_at' => now(),
        ];
        DB::transaction(function () use ($domain, $before, $after, $data, $request): void {
            DB::table('platform_domains')->where('id', $domain)->update($after);
            $this->audit($domain, $request->user()?->getAuthIdentifier(), 'domain.updated',
                ['display_name' => $before->display_name, 'environment' => $before->environment],
                ['display_name' => $after['display_name'], 'environment' => $after['environment']],
                $data['reason'], $request);
        });

        return response()->json(['success' => true, 'data' => ['id' => $domain, 'display_name' => $after['display_name'], 'environment' => $after['environment']]]);
    }

    public function verify(Request $request, int $domain): JsonResponse
    {
        $data = $request->validate(['record_value' => ['required', 'string', 'min:16', 'max:128']]);
        $verification = DB::table('platform_domain_verifications')->where('domain_id', $domain)
            ->whereNull('verified_at')->where('expires_at', '>', now())->orderByDesc('id')->first();
        abort_if($verification === null, 409, 'No active domain verification challenge exists.');
        abort_unless(hash_equals($verification->challenge_hash, hash('sha256', $data['record_value'])), 422, 'Domain verification evidence did not match.');

        DB::transaction(function () use ($domain, $verification, $request): void {
            $now = now();
            DB::table('platform_domain_verifications')->where('id', $verification->id)->update([
                'verified_at' => $now,
                'evidence' => json_encode(['method' => 'dns_txt', 'verified_at' => $now->toIso8601String()], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
            DB::table('platform_domains')->where('id', $domain)->update([
                'ownership_verified_at' => $now,
                'lifecycle_status' => 'active',
                'updated_by' => $request->user()?->getAuthIdentifier(),
                'updated_at' => $now,
            ]);
            $this->audit($domain, $request->user()?->getAuthIdentifier(), 'domain.ownership_verified',
                ['lifecycle_status' => 'pending_verification'], ['lifecycle_status' => 'active', 'ownership_verified_at' => $now->toIso8601String()],
                'DNS TXT challenge verified', $request);
        });

        return response()->json(['success' => true, 'data' => ['id' => $domain, 'ownership_state' => 'verified', 'lifecycle_status' => 'active']]);
    }

    public function history(Request $request, int $domain): JsonResponse
    {
        abort_unless(DB::table('platform_domains')->where('id', $domain)->exists(), 404, 'Domain registry record not found.');
        $events = DB::table('platform_domain_audit_events')->where('domain_id', $domain)->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 25))));
        return response()->json(['success' => true, 'data' => $events]);
    }

    private function assertOwnerExists(string $ownerType, ?int $ownerId): void
    {
        if ($ownerType === 'platform' && $ownerId !== null) {
            throw ValidationException::withMessages(['owner_id' => 'Platform-owned domains cannot specify an owner_id.']);
        }
        if ($ownerType !== 'platform' && $ownerId === null) {
            throw ValidationException::withMessages(['owner_id' => 'An owner_id is required for application- or tenant-owned domains.']);
        }
        if ($ownerType === 'application' && !DB::table('platform_applications')->where('id', $ownerId)->exists()) {
            throw ValidationException::withMessages(['owner_id' => 'The selected application does not exist.']);
        }
        if ($ownerType === 'tenant' && !DB::table('tenants')->where('id', $ownerId)->exists()) {
            throw ValidationException::withMessages(['owner_id' => 'The selected tenant does not exist.']);
        }
    }

    private function audit(?int $domainId, mixed $actorId, string $action, ?array $before, ?array $after, ?string $reason, Request $request): void
    {
        DB::table('platform_domain_audit_events')->insert([
            'domain_id' => $domainId,
            'actor_id' => $actorId,
            'action' => $action,
            'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
