<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CommunicationConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommunicationConversationController extends Controller
{
    private function tenantId(): int
    {
        $id = mases_tenant_id();
        abort_if($id === null, 400, 'A validated tenant context is required.');
        return (int) $id;
    }

    public function index(Request $request, CommunicationConversationService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->index($this->tenantId(), (int) $request->user()->getKey())]);
    }

    public function store(Request $request, CommunicationConversationService $service): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:direct,group,department'],
            'title' => ['nullable', 'string', 'max:180'],
            'participant_ids' => ['required', 'array', 'min:1', 'max:200'],
            'participant_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $result = $service->create($this->tenantId(), (int) $request->user()->getKey(), $data['type'], $data['title'] ?? null, $data['participant_ids']);
        return response()->json(['success' => true, 'data' => $result], 201);
    }

    public function messages(Request $request, string $conversationId, CommunicationConversationService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->messages(
            $this->tenantId(), $conversationId, (int) $request->user()->getKey(),
            min(max((int) $request->integer('per_page', 50), 1), 100)
        )]);
    }

    public function send(Request $request, string $conversationId, CommunicationConversationService $service): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);
        $message = $service->send($this->tenantId(), $conversationId, (int) $request->user()->getKey(), $data['body'], $data['idempotency_key']);
        return response()->json(['success' => true, 'data' => $message], 201);
    }

    public function markRead(Request $request, string $conversationId, CommunicationConversationService $service): JsonResponse
    {
        $count = $service->markRead($this->tenantId(), $conversationId, (int) $request->user()->getKey());
        return response()->json(['success' => true, 'data' => ['read_receipts_recorded' => $count]]);
    }
}
