<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlatformSettingsController extends Controller
{
    public function settings(Request $request): JsonResponse
    {
        return $this->indexCategory($request, 'setting');
    }

    public function configuration(Request $request): JsonResponse
    {
        return $this->indexCategory($request, 'configuration');
    }

    public function settingRequests(Request $request): JsonResponse
    {
        return $this->listRequests($request, 'setting');
    }

    public function configurationRequests(Request $request): JsonResponse
    {
        return $this->listRequests($request, 'configuration');
    }

    public function requestSettingChange(Request $request): JsonResponse
    {
        return $this->createChangeRequest($request, 'setting');
    }

    public function requestConfigurationChange(Request $request): JsonResponse
    {
        return $this->createChangeRequest($request, 'configuration');
    }

    public function approveSettingChange(Request $request, int $changeRequest): JsonResponse
    {
        return $this->approveChange($request, $changeRequest, 'setting');
    }

    public function approveConfigurationChange(Request $request, int $changeRequest): JsonResponse
    {
        return $this->approveChange($request, $changeRequest, 'configuration');
    }

    public function rejectSettingChange(Request $request, int $changeRequest): JsonResponse
    {
        return $this->rejectChange($request, $changeRequest, 'setting');
    }

    public function rejectConfigurationChange(Request $request, int $changeRequest): JsonResponse
    {
        return $this->rejectChange($request, $changeRequest, 'configuration');
    }

    private function indexCategory(Request $request, string $category): JsonResponse
    {
        $validated = $request->validate([
            'environment' => ['sometimes', 'in:production,staging,development,testing'],
            'search' => ['sometimes', 'string', 'max:160'],
        ]);
        $items = DB::table('platform_settings')
            ->where('category', $category)
            ->when(isset($validated['environment']), fn ($query) => $query->where('environment', $validated['environment']))
            ->when(($validated['search'] ?? '') !== '', fn ($query) => $query->where('setting_key', 'like', '%' . $validated['search'] . '%'))
            ->orderBy('setting_key')->get()
            ->map(static function ($row): array {
                return [
                    'key' => $row->setting_key,
                    'category' => $row->category,
                    'value' => $row->is_secret ? null : Crypt::decryptString($row->value_ciphertext),
                    'is_secret' => (bool) $row->is_secret,
                    'is_configured' => true,
                    'description' => $row->description,
                    'environment' => $row->environment,
                    'version' => (int) $row->version,
                    'updated_at' => $row->updated_at,
                ];
            })->values();

        return response()->json(['success' => true, 'data' => ['items' => $items]]);
    }

    private function listRequests(Request $request, string $category): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:pending,approved,rejected'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $requests = DB::table('platform_setting_change_requests')
            ->where('category', $category)
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $requests->getCollection()->transform(static function ($row): array {
            return [
                'id' => (int) $row->id,
                'key' => $row->setting_key,
                'category' => $row->category,
                'proposed_value' => $row->is_secret ? null : Crypt::decryptString($row->proposed_value_ciphertext),
                'secret_value_provided' => (bool) $row->is_secret,
                'description' => $row->description,
                'environment' => $row->environment,
                'requested_by' => (int) $row->requested_by,
                'approved_by' => $row->approved_by === null ? null : (int) $row->approved_by,
                'status' => $row->status,
                'reason' => $row->reason,
                'approval_note' => $row->approval_note,
                'created_at' => $row->created_at,
                'applied_at' => $row->applied_at,
            ];
        });

        return response()->json(['success' => true, 'data' => $requests]);
    }

    private function createChangeRequest(Request $request, string $category): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:160', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,159}$/'],
            'value' => ['required', 'string', 'max:20000'],
            'is_secret' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'environment' => ['sometimes', 'in:production,staging,development,testing'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $actorId = (int) $request->user()->getAuthIdentifier();
        $now = now();
        $id = DB::transaction(function () use ($data, $category, $actorId, $now, $request): int {
            $id = DB::table('platform_setting_change_requests')->insertGetId([
                'setting_key' => $data['key'],
                'category' => $category,
                'proposed_value_ciphertext' => Crypt::encryptString($data['value']),
                'is_secret' => (bool) ($data['is_secret'] ?? false),
                'description' => $data['description'] ?? null,
                'environment' => $data['environment'] ?? 'production',
                'requested_by' => $actorId,
                'status' => 'pending',
                'reason' => $data['reason'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit((int) $id, $actorId, $data['key'], 'change.requested', null, [
                'category' => $category,
                'is_secret' => (bool) ($data['is_secret'] ?? false),
                'environment' => $data['environment'] ?? 'production',
                'status' => 'pending',
            ], $data['reason']);
            return (int) $id;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $id,
                'key' => $data['key'],
                'category' => $category,
                'status' => 'pending',
                'secret_value_provided' => (bool) ($data['is_secret'] ?? false),
                'approval_required' => true,
                'value_returned' => false,
            ],
        ], 202);
    }

    private function approveChange(Request $request, int $changeRequest, string $category): JsonResponse
    {
        $data = $request->validate(['approval_note' => ['required', 'string', 'min:3', 'max:500']]);
        $approverId = (int) $request->user()->getAuthIdentifier();

        DB::transaction(function () use ($changeRequest, $category, $approverId, $data): void {
            $pending = DB::table('platform_setting_change_requests')->where('id', $changeRequest)
                ->where('category', $category)->lockForUpdate()->first();
            abort_if($pending === null, 404, 'Configuration change request not found.');
            abort_unless($pending->status === 'pending', 409, 'Only pending change requests can be approved.');
            abort_unless((int) $pending->requested_by !== $approverId, 403, 'The requester cannot approve their own change.');

            $current = DB::table('platform_settings')->where('category', $category)
                ->where('setting_key', $pending->setting_key)
                ->where('environment', $pending->environment)->first();
            $version = $current === null ? 1 : ((int) $current->version + 1);
            $now = now();
            DB::table('platform_settings')->updateOrInsert(
                ['category' => $category, 'setting_key' => $pending->setting_key, 'environment' => $pending->environment],
                [
                    'category' => $category,
                    'value_ciphertext' => $pending->proposed_value_ciphertext,
                    'is_secret' => (bool) $pending->is_secret,
                    'description' => $pending->description,
                    'version' => $version,
                    'updated_by' => $approverId,
                    'updated_at' => $now,
                    'created_at' => $current?->created_at ?? $now,
                ],
            );
            DB::table('platform_setting_change_requests')->where('id', $changeRequest)->update([
                'status' => 'approved',
                'approved_by' => $approverId,
                'approval_note' => $data['approval_note'],
                'applied_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit($changeRequest, $approverId, $pending->setting_key, 'change.approved',
                $current === null ? null : ['configured' => true, 'is_secret' => (bool) $current->is_secret, 'version' => (int) $current->version],
                ['configured' => true, 'is_secret' => (bool) $pending->is_secret, 'version' => $version],
                $data['approval_note']);
        });

        return response()->json(['success' => true, 'data' => ['id' => $changeRequest, 'status' => 'approved']]);
    }

    private function rejectChange(Request $request, int $changeRequest, string $category): JsonResponse
    {
        $data = $request->validate(['approval_note' => ['required', 'string', 'min:3', 'max:500']]);
        $actorId = (int) $request->user()->getAuthIdentifier();
        DB::transaction(function () use ($changeRequest, $category, $actorId, $data): void {
            $pending = DB::table('platform_setting_change_requests')->where('id', $changeRequest)
                ->where('category', $category)->lockForUpdate()->first();
            abort_if($pending === null, 404, 'Configuration change request not found.');
            abort_unless($pending->status === 'pending', 409, 'Only pending change requests can be rejected.');
            abort_unless((int) $pending->requested_by !== $actorId, 403, 'The requester cannot reject their own change.');
            $now = now();
            DB::table('platform_setting_change_requests')->where('id', $changeRequest)->update([
                'status' => 'rejected', 'decided_by' => $actorId,
                'approval_note' => $data['approval_note'], 'updated_at' => $now,
            ]);
            $this->audit($changeRequest, $actorId, $pending->setting_key, 'change.rejected', null,
                ['status' => 'rejected'], $data['approval_note']);
        });
        return response()->json(['success' => true, 'data' => ['id' => $changeRequest, 'status' => 'rejected']]);
    }

    private function audit(int $changeRequestId, int $actorId, string $key, string $action, ?array $before, ?array $after, ?string $reason): void
    {
        DB::table('platform_setting_audit_events')->insert([
            'change_request_id' => $changeRequestId,
            'actor_id' => $actorId,
            'setting_key' => $key,
            'action' => $action,
            'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
