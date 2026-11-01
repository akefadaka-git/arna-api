<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Notifications\Services\NotificationService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Throwable;

/**
 * Delivers due Calendar reminders through the canonical tenant notification
 * service. The outbox status transition and notification insert share one
 * database transaction; the notification dedupe key makes retries safe.
 */
final class CalendarReminderDispatcher
{
    private const MAX_ATTEMPTS = 8;

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /** @return array{delivered:int,skipped:int,retried:int,failed:int} */
    public function dispatch(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $result = ['delivered' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0];

        for ($processed = 0; $processed < $limit; $processed++) {
            $candidate = DB::table('calendar_reminder_outbox')
                ->where('status', 'pending')
                ->where('deliver_at', '<=', now())
                ->orderBy('deliver_at')
                ->orderBy('id')
                ->value('id');

            if ($candidate === null) {
                break;
            }

            try {
                $outcome = DB::transaction(function () use ($candidate): string {
                    $row = DB::table('calendar_reminder_outbox')->where('id', $candidate)->lockForUpdate()->first();
                    if (!$row || $row->status !== 'pending' || $row->deliver_at > now()->toDateTimeString()) {
                        return 'skipped';
                    }

                    $event = DB::table('calendar_events')
                        ->where('tenant_id', $row->tenant_id)
                        ->where('id', $row->event_id)
                        ->first();

                    if (!$event || $event->status !== 'scheduled' || $event->starts_at === null
                        || new DateTimeImmutable($event->starts_at, new DateTimeZone('UTC')) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                        DB::table('calendar_reminder_outbox')->where('id', $row->id)->update([
                            'status' => 'skipped', 'updated_at' => now(),
                        ]);
                        return 'skipped';
                    }

                    $tenant = Tenant::query()->find($row->tenant_id);
                    $recipient = User::query()->find($row->recipient_user_id);
                    if (!$tenant || !$recipient) {
                        DB::table('calendar_reminder_outbox')->where('id', $row->id)->update([
                            'status' => 'skipped', 'last_error' => 'Tenant or recipient no longer exists.',
                            'updated_at' => now(),
                        ]);
                        return 'skipped';
                    }

                    $this->notifications->publish($tenant, [$recipient], [
                        'event_key' => 'calendar.event.reminder',
                        'category' => 'calendar',
                        'priority' => 'normal',
                        'title' => ['am' => 'የቀጠሮ ማሳሰቢያ', 'en' => 'Calendar reminder'],
                        'body' => [
                            'am' => 'በቅርቡ የታቀደ ቀጠሮ አለዎት።',
                            'en' => 'You have a scheduled calendar event coming up.',
                        ],
                        'action_route_name' => 'calendar',
                        'resource_ref' => ['type' => 'calendar_event', 'id' => (string) $event->id],
                        'dedupe_key' => 'calendar-reminder:'.(string) $row->id,
                    ]);

                    DB::table('calendar_reminder_outbox')->where('id', $row->id)->update([
                        'status' => 'delivered', 'delivered_at' => now(),
                        'attempts' => (int) $row->attempts + 1, 'last_error' => null,
                        'updated_at' => now(),
                    ]);

                    return 'delivered';
                }, 3);

                $result[$outcome]++;
            } catch (Throwable $error) {
                $attempt = DB::table('calendar_reminder_outbox')->where('id', $candidate)->value('attempts');
                $attempt = (int) $attempt + 1;
                $terminal = $attempt >= self::MAX_ATTEMPTS;
                $delayMinutes = min(360, 2 ** min($attempt, 8));

                DB::table('calendar_reminder_outbox')->where('id', $candidate)->where('status', 'pending')->update([
                    'attempts' => $attempt,
                    'status' => $terminal ? 'failed' : 'pending',
                    'deliver_at' => $terminal ? now() : now()->addMinutes($delayMinutes),
                    'last_error' => Str::limit($error->getMessage(), 2000),
                    'updated_at' => now(),
                ]);

                $result[$terminal ? 'failed' : 'retried']++;
                report($error);
            }
        }

        return $result;
    }
}
