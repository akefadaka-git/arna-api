<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Notifications\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Notifications\Models\PlatformNotification;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use InvalidArgumentException;

final class NotificationService
{
    public function __construct(private readonly TenantContextService $tenantContext)
    {
    }

    /**
     * Publish to explicit recipients only. The recipient must have an active
     * membership in the supplied tenant; callers cannot use this API to
     * broadcast to an arbitrary tenant population.
     *
     * @param list<User> $recipients
     * @param array{event_key:string,category:string,priority?:string,title:array{am?:string,en?:string},body?:array{am?:string,en?:string}|null,action_route_name?:string|null,resource_ref?:array<string,mixed>|null,dedupe_key?:string|null} $payload
     * @return list<PlatformNotification>
     */
    public function publish(Tenant $tenant, array $recipients, array $payload): array
    {
        $this->validatePayload($payload);
        $created = [];

        return DB::transaction(function () use ($tenant, $recipients, $payload, &$created): array {
            foreach ($recipients as $recipient) {
                if (!$recipient instanceof User || !$recipient->exists) {
                    throw new InvalidArgumentException('Recipients must be persisted canonical User models.');
                }

                $isMember = TenantMembership::query()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('user_id', $recipient->getKey())
                    ->where('status', TenantMembership::STATUS_ACTIVE)
                    ->exists();

                if (!$isMember) {
                    throw new InvalidArgumentException('Every notification recipient must be an active member of the target tenant.');
                }

                $attributes = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->getKey(),
                    'user_id' => $recipient->getKey(),
                    'event_key' => $payload['event_key'],
                    'category' => $payload['category'],
                    'priority' => $payload['priority'] ?? 'normal',
                    'title' => $payload['title'],
                    'body' => $payload['body'] ?? null,
                    'action_route_name' => $payload['action_route_name'] ?? null,
                    'resource_ref' => $payload['resource_ref'] ?? null,
                    'dedupe_key' => $payload['dedupe_key'] ?? null,
                ];

                if (!empty($attributes['dedupe_key'])) {
                    $notification = PlatformNotification::query()->firstOrCreate(
                        [
                            'tenant_id' => $attributes['tenant_id'],
                            'user_id' => $attributes['user_id'],
                            'dedupe_key' => $attributes['dedupe_key'],
                        ],
                        $attributes,
                    );
                } else {
                    $notification = PlatformNotification::query()->create($attributes);
                }

                $created[] = $notification;
            }

            return $created;
        });
    }

    public function inbox(User $user, int $perPage = 20, bool $unreadOnly = false): LengthAwarePaginator
    {
        $tenantId = $this->requiredTenantId();

        return PlatformNotification::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->when($unreadOnly, static fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 50));
    }

    public function unreadCount(User $user): int
    {
        return PlatformNotification::query()
            ->where('tenant_id', $this->requiredTenantId())
            ->where('user_id', $user->getKey())
            ->whereNull('read_at')
            ->count();
    }

    public function markRead(User $user, string $notificationId): PlatformNotification
    {
        $notification = $this->ownedNotification($user, $notificationId);
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification->refresh();
    }

    public function markAllRead(User $user): int
    {
        return PlatformNotification::query()
            ->where('tenant_id', $this->requiredTenantId())
            ->where('user_id', $user->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function ownedNotification(User $user, string $id): PlatformNotification
    {
        return PlatformNotification::query()
            ->where('tenant_id', $this->requiredTenantId())
            ->where('user_id', $user->getKey())
            ->whereKey($id)
            ->firstOrFail();
    }

    private function requiredTenantId(): int|string
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            abort(400, 'A tenant context is required.');
        }

        return $tenantId;
    }

    private function validatePayload(array $payload): void
    {
        if (empty($payload['event_key']) || empty($payload['category']) || empty($payload['title'])) {
            throw new InvalidArgumentException('Notification event_key, category, and localized title are required.');
        }

        if (!in_array($payload['priority'] ?? 'normal', ['low', 'normal', 'high'], true)) {
            throw new InvalidArgumentException('Notification priority must be low, normal, or high.');
        }

        if (!is_array($payload['title']) || (!isset($payload['title']['am']) && !isset($payload['title']['en']))) {
            throw new InvalidArgumentException('Notification title must contain an Amharic or English translation.');
        }

        if (isset($payload['body']) && $payload['body'] !== null && !is_array($payload['body'])) {
            throw new InvalidArgumentException('Notification body must be a localized object.');
        }
    }
}
