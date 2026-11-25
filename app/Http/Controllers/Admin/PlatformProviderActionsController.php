<?php

declare(strict_types=1);

namespace App\\Http\\Controllers\\Admin;

use App\\Http\\Controllers\\Controller;
use App\\Services\\Admin\\Providers\\ProviderActionLifecycleService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use RuntimeException;

final class PlatformProviderActionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending_approval,approved,rejected,expired,cancelled'],
            'environment' => ['sometimes', 'in:sandbox,staging,production'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $items = DB::table('platform_provider_action_requests')
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(isset($validated['environment']), fn ($query) => $query->where('environment', $validated['environment']))
            ->orderByDesc('id')->paginate((int) ($validated['per_page'] ?? 25));

        $items->getCollection()->transform(static fn ($row): array => [
            'public_id' => $row->public_id,
            'provider_key' => $row->provider_key,
            'adapter_version' => $row->adapter_version,
            'environment' => $row->environment,
            'operation' => $row->operation_key,
            'resource_type' => $row->resource_type,
            'resource_identifier' => $row->resource_identifier,
            'payload_sha256' => $row->payload_sha256,
            'risk_tier' => $row->risk_tier,
            'policy_version' => $row->policy_version,
            'requester_id' => (int) $row->requester_id,
            'status' => $row->status,
            'required_approvals' => (int) $row->required_approvals,
            'owner_signature_required' => (bool) $row->owner_signature_required,
            'owner_signature_verified' => $row->owner_signature_verified_at !== null,
            'expires_at' => $row->expires_at,
            'approved_at' => $row->approved_at,
            'rejected_at' => $row->rejected_at,
            'created_at' => $row->created_at,
        ]);

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function history(string $publicId): JsonResponse
    {
        $request = DB::table('platform_provider_action_requests')->where('public_id', $publicId)->first();
        abort_if($request === null, 404, 'Provider action request was not found.');

        $approvals = DB::table('platform_provider_action_approvals')
            ->where('request_id', $request->id)->orderBy('id')->get()
            ->map(static fn ($row): array => [
                'approver_id' => (int) $row->approver_id,
                'decision' => $row->decision,
                'payload_sha256' => $row->payload_sha256,
                'policy_version' => $row->policy_version,
                'note' => $row->note,
                'created_at' => $row->created_at,
            ])->values();

        $events = DB::table('platform_provider_action_audit_events')
            ->where('request_id', $request->id)->orderBy('id')->get()
            ->map(static fn ($row): array => [
                'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                'event_type' => $row->event_type,
                'payload_sha256' => $row->payload_sha256,
                'metadata' => $row->metadata === null ? null : json_decode($row->metadata, true),
                'created_at' => $row->created_at,
            ])->values();

        return response()->json(['success' => true, 'data' => [
            'public_id' => $request->public_id,
            'status' => $request->status,
            'payload_sha256' => $request->payload_sha256,
            'policy_version' => $request->policy_version,
            'approvals' => $approvals,
            'audit_events' => $events,
        ]]);
    }

    public function store(Request $request, ProviderActionLifecycleService $service): JsonResponse
    {
        $data = $request->validate([
            'provider_key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_-]{1,99}$/'],
            'environment' => ['required', 'in:sandbox,staging,production'],
            'operation' => ['required', 'string', 'max:100'],
            'resource_type' => ['required', 'string', 'max:80'],
            'resource_identifier' => ['required', 'string', 'max:255'],
            'payload' => ['sometimes', 'array'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $result = $service->request((int) $request->user()->getAuthIdentifier(), $data);
            return response()->json(['success' => true, 'data' => $result], 202);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'error' => ['code' => 'PROVIDER_ACTION_REJECTED', 'message' => $exception->getMessage()]], 422);
        }
    }

    public function approve(Request $request, string $publicId, ProviderActionLifecycleService $service): JsonResponse
    {
        return $this->decide($request, $publicId, 'approved', $service);
    }

    public function reject(Request $request, string $publicId, ProviderActionLifecycleService $service): JsonResponse
    {
        return $this->decide($request, $publicId, 'rejected', $service);
    }

    public function ownerAuthorize(Request $request, string $publicId, ProviderActionLifecycleService $service): JsonResponse
    {
        $data = $request->validate([
            'key_id' => ['required', 'string', 'max:120'],
            'signature' => ['required', 'string', 'max:512'],
        ]);

        try {
            $result = $service->authorizeOwner(
                $publicId,
                (int) $request->user()->getAuthIdentifier(),
                $data['key_id'],
                $data['signature'],
            );
            return response()->json(['success' => true, 'data' => $result]);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'error' => ['code' => 'OWNER_AUTHORIZATION_REJECTED', 'message' => $exception->getMessage()]], 422);
        }
    }

    private function decide(Request $request, string $publicId, string $decision, ProviderActionLifecycleService $service): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);

        try {
            $result = $service->decide(
                $publicId,
                (int) $request->user()->getAuthIdentifier(),
                $decision,
                $data['note'],
            );
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\\Illuminate\\Database\\QueryException) {
            return response()->json(['success' => false, 'error' => ['code' => 'PROVIDER_ACTION_DUPLICATE_APPROVER', 'message' => 'This approver has already recorded a decision for this request.']], 409);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'error' => ['code' => 'PROVIDER_ACTION_DECISION_REJECTED', 'message' => $exception->getMessage()]], 422);
        }
    }
}
