<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Notifications\Services\NotificationService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CommunicationConversationController
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
        $this->authorize($user, 'communication.view', $tenantId);

        $items = DB::table('communication_conversations as c')
            ->join('communication_conversation_participants as p', 'p.conversation_id', '=', 'c.id')
            ->where('c.tenant_id', $tenantId)
            ->where('p.tenant_id', $tenantId)
            ->where('p.user_id', $user->getKey())
            ->whereNull('p.left_at')
            ->whereNull('c.archived_at')
            ->select('c.id', 'c.type', 'c.title', 'c.created_by', 'c.updated_at')
            ->orderByDesc('c.updated_at')
            ->distinct()
            ->paginate(30);

        return response()->json(['success' => true, 'data' => [
            'items' => $items->items(),
            'pagination' => ['page' => $items->currentPage(), 'per_page' => $items->perPage(), 'total' => $items->total()],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $this->authorize($user, 'communication.message.send', $tenantId);

        $data = $request->validate([
            'type' => ['required', 'in:direct,group,department'],
            'title' => ['nullable', 'string', 'max:180'],
            'participant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'participant_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        if ($data['type'] === 'direct' && count($data['participant_ids']) !== 1) {
            abort(422, 'A direct conversation must have exactly one other participant.');
        }
        if ($data['type'] !== 'direct' && blank($data['title'])) {
            abort(422, 'Group and department conversations require a title.');
        }

        $participantIds = array_values(array_unique(array_map('intval', $data['participant_ids'])));
        $memberIds = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereIn('user_id', $participantIds)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $activeUserIds = User::query()->whereIn('id', $participantIds)
            ->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        sort($memberIds);
        sort($activeUserIds);
        $expected = $participantIds;
        sort($expected);
        if ($memberIds !== $expected || $activeUserIds !== $expected) {
            abort(422, 'Every participant must be an active user with active membership in the current tenant.');
        }

        $allParticipantIds = array_values(array_unique([...$participantIds, (int) $user->getKey()]));
        $conversationId = (string) Str::uuid();

        DB::transaction(function () use ($conversationId, $tenantId, $user, $data, $allParticipantIds): void {
            DB::table('communication_conversations')->insert([
                'id' => $conversationId,
                'tenant_id' => $tenantId,
                'type' => $data['type'],
                'title' => $data['title'] ?? null,
                'created_by' => $user->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($allParticipantIds as $participantId) {
                DB::table('communication_conversation_participants')->insert([
                    'id' => (string) Str::uuid(),
                    'conversation_id' => $conversationId,
                    'tenant_id' => $tenantId,
                    'user_id' => $participantId,
                    'role' => $participantId === (int) $user->getKey() ? 'owner' : 'member',
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['success' => true, 'data' => [
            'id' => $conversationId,
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
        ]], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $this->authorize($user, 'communication.view', $tenantId);
        $conversation = $this->participantConversation($id, $tenantId, $user);

        $participants = DB::table('communication_conversation_participants as p')
            ->where('p.conversation_id', $id)
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.left_at')
            ->select('p.user_id', 'p.role', 'p.joined_at')
            ->orderBy('p.joined_at')
            ->get();

        return response()->json(['success' => true, 'data' => [
            'conversation' => $conversation,
            'participants' => $participants,
        ]]);
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $this->authorize($user, 'communication.view', $tenantId);
        $this->participantConversation($id, $tenantId, $user);

        $items = DB::table('communication_messages')
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json(['success' => true, 'data' => [
            'items' => array_reverse($items->items()),
            'pagination' => ['page' => $items->currentPage(), 'per_page' => $items->perPage(), 'total' => $items->total()],
        ]]);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $this->authorize($user, 'communication.message.send', $tenantId);
        $this->participantConversation($id, $tenantId, $user);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            'body_locale' => ['sometimes', 'in:am,en'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        $existing = DB::table('communication_messages')
            ->where('tenant_id', $tenantId)
            ->where('sender_id', $user->getKey())
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing !== null) {
            if ($existing->conversation_id !== $id || $existing->body !== $data['body']) {
                abort(409, 'The idempotency key was already used for a different message.');
            }
            return response()->json(['success' => true, 'data' => $existing]);
        }

        $messageId = (string) Str::uuid();
        try {
            DB::transaction(function () use ($messageId, $id, $tenantId, $user, $data): void {
            DB::table('communication_messages')->insert([
                'id' => $messageId,
                'conversation_id' => $id,
                'tenant_id' => $tenantId,
                'sender_id' => $user->getKey(),
                'body' => $data['body'],
                'body_locale' => $data['body_locale'] ?? 'am',
                'idempotency_key' => $data['idempotency_key'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $recipientIds = DB::table('communication_conversation_participants')
                ->join('tenant_memberships', function ($join) use ($tenantId): void {
                    $join->on('tenant_memberships.user_id', '=', 'communication_conversation_participants.user_id')
                        ->where('tenant_memberships.tenant_id', '=', $tenantId)
                        ->where('tenant_memberships.status', '=', TenantMembership::STATUS_ACTIVE);
                })
                ->where('communication_conversation_participants.conversation_id', $id)
                ->where('communication_conversation_participants.tenant_id', $tenantId)
                ->whereNull('communication_conversation_participants.left_at')
                ->where('communication_conversation_participants.user_id', '!=', $user->getKey())
                ->pluck('communication_conversation_participants.user_id')->all();
            $recipients = User::query()->whereIn('id', $recipientIds)->where('is_active', true)->get();

            if ($recipients->isNotEmpty()) {
                $tenant = Tenant::query()->findOrFail($tenantId);
                $this->notifications->publish($tenant, $recipients->all(), [
                    'event_key' => 'communication.message.created',
                    'category' => 'message',
                    'priority' => 'normal',
                    'title' => ['am' => 'አዲስ መልዕክት', 'en' => 'New message'],
                    'body' => ['am' => mb_substr($data['body'], 0, 300), 'en' => mb_substr($data['body'], 0, 300)],
                    'action_route_name' => 'communications',
                    'resource_ref' => ['conversation_id' => $id, 'message_id' => $messageId],
                    'dedupe_key' => 'communication:message:'.$messageId,
                ]);
            }
            });
        } catch (QueryException $exception) {
            // The unique idempotency index is the concurrency authority. If
            // another request won the race, return its result only when the
            // key, conversation and payload are identical.
            $raced = DB::table('communication_messages')
                ->where('tenant_id', $tenantId)
                ->where('sender_id', $user->getKey())
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($raced === null) {
                throw $exception;
            }
            if ($raced->conversation_id !== $id || $raced->body !== $data['body']) {
                abort(409, 'The idempotency key was already used for a different message.');
            }
            return response()->json(['success' => true, 'data' => $raced]);
        }

        $message = DB::table('communication_messages')->where('id', $messageId)->first();
        return response()->json(['success' => true, 'data' => $message], 201);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $this->authorize($user, 'communication.view', $tenantId);
        $this->participantConversation($id, $tenantId, $user);

        $messageIds = DB::table('communication_messages')
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $id)
            ->where('sender_id', '!=', $user->getKey())
            ->whereNull('deleted_at')
            ->pluck('id');

        DB::transaction(function () use ($messageIds, $id, $tenantId, $user): void {
            foreach ($messageIds as $messageId) {
                DB::table('communication_message_receipts')->updateOrInsert(
                    ['message_id' => $messageId, 'user_id' => $user->getKey()],
                    [
                        'id' => (string) Str::uuid(),
                        'conversation_id' => $id,
                        'tenant_id' => $tenantId,
                        'delivered_at' => now(),
                        'read_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        });

        return response()->json(['success' => true, 'data' => ['read_count' => $messageIds->count()]]);
    }

    public function markDelivered(Request $request, string $messageId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $user = $this->user($request);
        $this->authorize($user, 'communication.view', $tenantId);

        $message = DB::table('communication_messages')
            ->where('id', $messageId)->where('tenant_id', $tenantId)->first();
        if ($message === null) abort(404);
        $this->participantConversation($message->conversation_id, $tenantId, $user);
        if ((int) $message->sender_id === (int) $user->getKey()) abort(409, 'A sender cannot mark their own message as delivered.');

        DB::table('communication_message_receipts')->updateOrInsert(
            ['message_id' => $messageId, 'user_id' => $user->getKey()],
            [
                'id' => (string) Str::uuid(),
                'conversation_id' => $message->conversation_id,
                'tenant_id' => $tenantId,
                'delivered_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return response()->json(['success' => true, 'data' => ['delivered' => true]]);
    }

    private function participantConversation(string $id, int|string $tenantId, User $user): object
    {
        $conversation = DB::table('communication_conversations as c')
            ->join('communication_conversation_participants as p', 'p.conversation_id', '=', 'c.id')
            ->where('c.id', $id)
            ->where('c.tenant_id', $tenantId)
            ->where('p.tenant_id', $tenantId)
            ->where('p.user_id', $user->getKey())
            ->whereNull('p.left_at')
            ->whereNull('c.archived_at')
            ->select('c.id', 'c.type', 'c.title', 'c.created_by', 'c.created_at', 'c.updated_at')
            ->first();
        if ($conversation === null) abort(404);
        return $conversation;
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
            throw new AccessDeniedHttpException('You are not authorized to perform this communication action.');
        }
    }
}
