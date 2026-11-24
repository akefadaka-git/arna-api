<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CommunicationConversationService
{
    private function assertParticipant(int $tenantId, string $conversationId, int $userId): object
    {
        $row = DB::table('communication_conversation_participants as p')
            ->join('communication_conversations as c', 'c.id', '=', 'p.conversation_id')
            ->where('c.id', $conversationId)->where('c.tenant_id', $tenantId)
            ->where('c.status', 'active')->where('p.tenant_id', $tenantId)
            ->where('p.user_id', $userId)->where('p.status', 'active')->first(['c.id']);
        if ($row === null) {
            throw new NotFoundHttpException('Conversation not found.');
        }
        return $row;
    }

    public function index(int $tenantId, int $userId): array
    {
        return DB::table('communication_conversations as c')
            ->join('communication_conversation_participants as p', 'p.conversation_id', '=', 'c.id')
            ->where('c.tenant_id', $tenantId)->where('c.status', 'active')
            ->where('p.tenant_id', $tenantId)->where('p.user_id', $userId)->where('p.status', 'active')
            ->orderByDesc('c.updated_at')
            ->get(['c.id', 'c.type', 'c.title', 'c.created_by', 'c.created_at', 'c.updated_at', 'p.last_read_at'])
            ->map(fn ($row) => (array) $row)->all();
    }

    public function create(int $tenantId, int $actorId, string $type, ?string $title, array $participantIds): array
    {
        $participantIds = array_values(array_unique(array_map('intval', [...$participantIds, $actorId])));
        sort($participantIds);
        if ($type === 'direct' && count($participantIds) !== 2) {
            abort(422, 'A direct conversation requires exactly two active tenant members.');
        }
        if ($type === 'group' && count($participantIds) < 2) {
            abort(422, 'A group conversation requires at least two participants.');
        }
        $active = DB::table('tenant_memberships')->where('tenant_id', $tenantId)
            ->where('status', 'active')->whereIn('user_id', $participantIds)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        sort($active);
        if ($active !== $participantIds) {
            abort(422, 'Every participant must be an active member of the current tenant.');
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $tenantId, $actorId, $type, $title, $participantIds): void {
            DB::table('communication_conversations')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'type' => $type, 'title' => $title,
                'created_by' => $actorId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($participantIds as $userId) {
                DB::table('communication_conversation_participants')->insert([
                    'conversation_id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId,
                    'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        return ['id' => $id, 'type' => $type, 'title' => $title, 'participant_ids' => $participantIds];
    }

    public function messages(int $tenantId, string $conversationId, int $userId, int $limit = 50): array
    {
        $this->assertParticipant($tenantId, $conversationId, $userId);
        return DB::table('communication_messages')->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)->where('status', 'sent')
            ->orderByDesc('created_at')->limit($limit)
            ->get(['id', 'sender_id', 'body', 'created_at', 'edited_at'])
            ->reverse()->values()->map(fn ($row) => (array) $row)->all();
    }

    public function send(int $tenantId, string $conversationId, int $userId, string $body, string $key): array
    {
        $this->assertParticipant($tenantId, $conversationId, $userId);
        return DB::transaction(function () use ($tenantId, $conversationId, $userId, $body, $key): array {
            $existing = DB::table('communication_messages')->where('tenant_id', $tenantId)
                ->where('sender_id', $userId)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->conversation_id !== $conversationId || $existing->body !== $body) {
                    abort(409, 'Idempotency key was already used for a different message.');
                }
                return (array) $existing;
            }
            $id = (string) Str::uuid();
            DB::table('communication_messages')->insert([
                'id' => $id, 'conversation_id' => $conversationId, 'tenant_id' => $tenantId,
                'sender_id' => $userId, 'idempotency_key' => $key, 'body' => $body,
                'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('communication_conversations')->where('id', $conversationId)
                ->where('tenant_id', $tenantId)->update(['updated_at' => now()]);
            return (array) DB::table('communication_messages')->where('id', $id)->first();
        });
    }

    public function markRead(int $tenantId, string $conversationId, int $userId): int
    {
        $this->assertParticipant($tenantId, $conversationId, $userId);
        $messages = DB::table('communication_messages')->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)->where('sender_id', '<>', $userId)
            ->where('status', 'sent')->pluck('id');
        $now = now();
        foreach ($messages as $messageId) {
            DB::table('communication_message_reads')->insertOrIgnore([
                'message_id' => $messageId, 'conversation_id' => $conversationId,
                'tenant_id' => $tenantId, 'user_id' => $userId, 'read_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('communication_conversation_participants')->where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->update(['last_read_at' => $now, 'updated_at' => $now]);
        return $messages->count();
    }
}
