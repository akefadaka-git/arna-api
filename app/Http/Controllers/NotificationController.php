<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Notifications\Services\NotificationService;

final class NotificationController
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'unread_only' => ['sometimes', 'boolean'],
        ]);

        $items = $this->notifications->inbox(
            $user,
            (int) ($validated['per_page'] ?? 20),
            (bool) ($validated['unread_only'] ?? false),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items->getCollection()->map(fn ($item): array => $this->present($item))->values(),
                'pagination' => [
                    'page' => $items->currentPage(),
                    'per_page' => $items->perPage(),
                    'total' => $items->total(),
                ],
                'unread_count' => $this->notifications->unreadCount($user),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => ['unread_count' => $this->notifications->unreadCount($user)],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $notification = $this->notifications->markRead($user, $id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $notification->id,
                'is_read' => true,
                'read_at' => $notification->read_at?->toISOString(),
                'unread_count' => $this->notifications->unreadCount($user),
            ],
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $this->notifications->markAllRead($user);

        return response()->json([
            'success' => true,
            'data' => [
                'updated' => $updated,
                'unread_count' => $this->notifications->unreadCount($user),
            ],
        ]);
    }

    private function present(object $notification): array
    {
        return [
            'id' => (string) $notification->id,
            'title' => $notification->title,
            'body' => $notification->body,
            'category' => $notification->category,
            'priority' => $notification->priority,
            'is_read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toISOString(),
            'action' => $notification->action_route_name === null ? null : [
                'route_name' => $notification->action_route_name,
                'resource_ref' => $notification->resource_ref,
            ],
        ];
    }
}
