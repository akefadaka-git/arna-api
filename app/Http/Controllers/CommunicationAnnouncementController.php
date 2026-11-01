<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Notifications\Services\NotificationService;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CommunicationAnnouncementController
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly NotificationService $notifications,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $query = DB::table('communication_announcements')->where('tenant_id', $tenantId);

        if (!$user->hasPermission('announcement.manage', (int) $tenantId)) {
            $query->where('status', 'published')
                ->whereExists(function ($sub) use ($user): void {
                    $sub->selectRaw('1')->from('communication_announcement_recipients as ar')
                        ->whereColumn('ar.announcement_id', 'communication_announcements.id')
                        ->where('ar.user_id', $user->getKey());
                });
        }

        $items = $query->orderByDesc('created_at')->paginate(20);
        return response()->json(['success' => true, 'data' => [
            'items' => $items->items(),
            'pagination' => ['page' => $items->currentPage(), 'per_page' => $items->perPage(), 'total' => $items->total()],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $this->authorize($user, 'announcement.manage', $tenantId);
        $data = $request->validate([
            'title.am' => ['required_without:title.en', 'string', 'max:240'],
            'title.en' => ['required_without:title.am', 'string', 'max:240'],
            'content' => ['required', 'string', 'max:100000'],
            'content_locale' => ['sometimes', 'in:am,en'],
            'priority' => ['sometimes', 'in:normal,important,urgent,critical'],
            'requires_acknowledgement' => ['sometimes', 'boolean'],
            'recipient_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'recipient_ids.*' => ['required', 'integer', 'distinct'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'reference' => ['nullable', 'string', 'max:64'],
        ]);

        $recipientIds = array_values(array_unique(array_map('intval', $data['recipient_ids'])));
        $members = TenantMembership::query()
            ->where('tenant_id', $tenantId)->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereIn('user_id', $recipientIds)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        sort($members);
        $expected = $recipientIds;
        sort($expected);
        if ($members !== $expected) {
            abort(422, 'Every recipient must be an active member of the current tenant.');
        }

        $id = (string) Str::uuid();
        DB::table('communication_announcements')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'created_by' => $user->getKey(),
            'reference' => $data['reference'] ?? null, 'title' => json_encode($data['title'], JSON_THROW_ON_ERROR),
            'content' => $data['content'], 'content_locale' => $data['content_locale'] ?? 'am',
            'priority' => $data['priority'] ?? 'normal', 'status' => 'draft',
            'requires_acknowledgement' => (bool) ($data['requires_acknowledgement'] ?? false),
            'expires_at' => $data['expires_at'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($recipientIds as $recipientId) {
            DB::table('communication_announcement_recipients')->insert([
                'id' => (string) Str::uuid(), 'announcement_id' => $id, 'tenant_id' => $tenantId,
                'user_id' => $recipientId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'data' => ['id' => $id, 'status' => 'draft']], 201);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $this->authorize($user, 'announcement.manage', $tenantId);
        $row = $this->owned($id, $tenantId);
        if ($row->status !== 'draft') abort(409, 'Only draft announcements can be submitted for approval.');
        DB::table('communication_announcements')->where('id', $id)->update([
            'status' => 'pending_approval', 'submitted_by' => $user->getKey(),
            'submitted_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['success' => true, 'data' => ['id' => $id, 'status' => 'pending_approval']]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $this->authorize($user, 'announcement.approve', $tenantId);
        $row = $this->owned($id, $tenantId);
        if ($row->status !== 'pending_approval') abort(409, 'Only pending announcements can be approved.');
        if ((int) $row->submitted_by === (int) $user->getKey()) abort(403, 'The submitter cannot approve their own announcement.');
        DB::table('communication_announcements')->where('id', $id)->update([
            'status' => 'approved', 'approved_by' => $user->getKey(), 'approved_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['success' => true, 'data' => ['id' => $id, 'status' => 'approved']]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $this->authorize($user, 'announcement.publish', $tenantId);
        $row = $this->owned($id, $tenantId);
        if ($row->status !== 'approved') abort(409, 'Only approved announcements can be published.');
        if ($row->expires_at !== null && now()->greaterThanOrEqualTo($row->expires_at)) abort(422, 'Expired announcements cannot be published.');

        $recipients = DB::table('communication_announcement_recipients')
            ->where('announcement_id', $id)->where('tenant_id', $tenantId)->pluck('user_id')->all();
        if ($recipients === []) abort(422, 'An announcement must have at least one recipient.');

        $activeMembershipIds = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereIn('user_id', $recipients)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $users = User::query()->whereIn('id', $recipients)->where('is_active', true)->get();
        if (count($activeMembershipIds) !== count($recipients) || $users->count() !== count($recipients)) {
            abort(409, 'Every recipient must remain an active user with active membership at publication time.');
        }

        DB::transaction(function () use ($id, $tenantId, $user, $row, $users): void {
            DB::table('communication_announcements')->where('id', $id)->where('tenant_id', $tenantId)->update([
                'status' => 'published', 'published_at' => now(), 'published_by' => $user->getKey(), 'updated_at' => now(),
            ]);
            DB::table('communication_announcement_recipients')->where('announcement_id', $id)->update(['delivered_at' => now(), 'updated_at' => now()]);
            $this->notifications->publish(
                app(\MaaleArna\MASES\Core\Tenancy\Models\Tenant::class)->newQuery()->findOrFail($tenantId),
                $users->all(),
                [
                    'event_key' => 'communication.announcement.published', 'category' => 'announcement',
                    'priority' => $row->priority === 'critical' ? 'high' : ($row->priority === 'urgent' ? 'high' : 'normal'),
                    'title' => json_decode($row->title, true, 512, JSON_THROW_ON_ERROR),
                    'body' => ['am' => mb_substr(strip_tags($row->content), 0, 500), 'en' => mb_substr(strip_tags($row->content), 0, 500)],
                    'action_route_name' => 'announcements', 'resource_ref' => ['announcement_id' => $id],
                    'dedupe_key' => 'announcement:'.$id,
                ],
            );
        });

        return response()->json(['success' => true, 'data' => ['id' => $id, 'status' => 'published']]);
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $recipient = DB::table('communication_announcement_recipients')
            ->where('announcement_id', $id)->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->first();
        if ($recipient === null) abort(404);
        $announcement = $this->owned($id, $tenantId);
        if ($announcement->status !== 'published' || !$announcement->requires_acknowledgement) abort(409, 'Acknowledgement is not required.');
        DB::table('communication_announcement_recipients')->where('id', $recipient->id)->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now(), 'read_at' => now(), 'updated_at' => now()]);
        return response()->json(['success' => true, 'data' => ['acknowledged' => true]]);
    }

    private function owned(string $id, int|string $tenantId): object
    {
        return DB::table('communication_announcements')->where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
    }

    private function tenantId(): int|string
    {
        $id = $this->tenantContext->getTenantId();
        if ($id === null) abort(400, 'A tenant context is required.');
        return $id;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (!$user instanceof User) abort(401);
        return $user;
    }

    private function authorize(User $user, string $permission, int|string $tenantId): void
    {
        if (!$user->hasPermission($permission, (int) $tenantId)) {
            throw new AccessDeniedHttpException('You are not authorized to perform this announcement action.');
        }
    }
}
