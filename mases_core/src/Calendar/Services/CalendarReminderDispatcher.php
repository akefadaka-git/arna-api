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
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Throwable;

/**
 * Delivers due Calendar reminders through the canonical tenant notification
 * service. The outbox status transition and notification insert share one
 * database transaction; the notification dedupe key makes retries safe.
 */
final class CalendarReminderDispatcher
{
    private const MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly CalendarService $calendar,
        private readonly TenantContextService $tenantContext,
    ) {
    }

    /**
     * Materialize the next 90 days of recurring reminders from the canonical
     * Calendar recurrence expander. The series' first occurrence remains the
     * existing outbox template; subsequent instances receive stable
     * (event, recipient, reminder type, occurrence index) idempotency keys.
     */
    private function materializeRecurringReminders(): void
    {
        $events = DB::table('calendar_events as e')
            ->join('calendar_calendars as c', 'c.id', '=', 'e.calendar_id')
            ->whereColumn('c.tenant_id', 'e.tenant_id')
            ->where('e.status', 'scheduled')
            ->whereNotNull('e.recurrence_rule')
            ->orderBy('e.tenant_id')
            ->orderBy('e.id')
            ->limit(200)
            ->get(['e.id', 'e.tenant_id', 'e.all_day', 'e.timezone', 'e.created_by', 'c.owner_user_id']);

        $from = now()->toDateTimeImmutable();
        $to = $from->modify('+90 days');

        foreach ($events as $event) {
            try {
                $tenant = $this->tenantContext->setTenantById($event->tenant_id);
                if (!$tenant) {
                    continue;
                }

                $actorId = $event->owner_user_id ?: $event->created_by;
                $actor = User::query()->find($actorId);
                if (!$actor) {
                    continue;
                }

                $occurrences = $this->calendar->recurringOccurrences(
                    $actor,
                    (string) $event->id,
                    $from->format(DATE_ATOM),
                    $to->format(DATE_ATOM),
                );

                $validIndexes = collect($occurrences)
                    ->pluck('occurrence')
                    ->map(fn ($index): int => (int) $index)
                    ->filter(fn (int $index): bool => $index > 0)
                    ->values()
                    ->all();
                $staleRows = DB::table('calendar_reminder_outbox')
                    ->where('tenant_id', $event->tenant_id)
                    ->where('event_id', $event->id)
                    ->where('occurrence_index', '>', 0)
                    ->where('status', 'pending');
                if ($validIndexes !== []) {
                    $staleRows->whereNotIn('occurrence_index', $validIndexes);
                }
                $staleRows->update(['status' => 'skipped', 'updated_at' => now()]);

                $templates = DB::table('calendar_reminder_outbox')
                    ->where('tenant_id', $event->tenant_id)
                    ->where('event_id', $event->id)
                    ->where(function ($query): void {
                        $query->whereNull('occurrence_index')->orWhere('occurrence_index', 0);
                    })
                    ->get();

                if ($templates->isEmpty()) {
                    continue;
                }

                $attendees = DB::table('calendar_attendees')
                    ->where('tenant_id', $event->tenant_id)
                    ->where('event_id', $event->id)
                    ->pluck('user_id');

                foreach ($occurrences as $occurrence) {
                    $index = (int) ($occurrence['occurrence'] ?? 0);

                    $zone = new DateTimeZone($event->timezone ?: 'Africa/Addis_Ababa');
                    if ((bool) $event->all_day) {
                        if (empty($occurrence['starts_on'])) {
                            continue;
                        }
                        $actualStart = new DateTimeImmutable($occurrence['starts_on'], $zone);
                    } else {
                        if (empty($occurrence['starts_at'])) {
                            continue;
                        }
                        $actualStart = new DateTimeImmutable($occurrence['starts_at']);
                    }

                    $actualStartUtc = $actualStart->setTimezone(new DateTimeZone('UTC'));
                    if ($actualStartUtc <= now()->toDateTimeImmutable()) {
                        continue;
                    }

                    $occurrenceKey = (new DateTimeImmutable(
                        (string) ($occurrence['occurrence_key'] ?? ''),
                        new DateTimeZone('UTC'),
                    ))->format('Y-m-d H:i:s');

                    foreach ($templates as $template) {
                        if (!in_array($template->recipient_user_id, $attendees->all(), false)) {
                            continue;
                        }

                        if (!preg_match('/^event_start_([0-9]+)m$/', (string) $template->reminder_type, $matches)) {
                            continue;
                        }

                        $recipientId = (int) $template->recipient_user_id;
                        $reminderType = (string) $template->reminder_type;
                        $deliverAt = $actualStartUtc->modify('-'.(int) $matches[1].' minutes');
                        if ($deliverAt <= now()->toDateTimeImmutable()) {
                            $deliverAt = now()->toDateTimeImmutable();
                        }

                        $idempotencyKey = (string) $event->id.':'.$recipientId.':'.$reminderType
                            .($index === 0 ? '' : ':'.$index);
                        if ($index > 0) {
                            DB::table('calendar_reminder_outbox')->insertOrIgnore([
                                'id' => (string) Str::uuid(),
                                'tenant_id' => $event->tenant_id,
                                'event_id' => $event->id,
                                'recipient_user_id' => $recipientId,
                                'reminder_type' => $reminderType,
                                'occurrence_index' => $index,
                                'occurrence_starts_at' => $actualStartUtc->format('Y-m-d H:i:s'),
                                'occurrence_key' => $occurrenceKey,
                                'deliver_at' => $deliverAt->format('Y-m-d H:i:s'),
                                'status' => 'pending',
                                'attempts' => 0,
                                'idempotency_key' => $idempotencyKey,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        DB::table('calendar_reminder_outbox')
                            ->where('tenant_id', $event->tenant_id)
                            ->where('event_id', $event->id)
                            ->where('idempotency_key', $idempotencyKey)
                            ->where(function ($query): void {
                                $query->where('status', 'pending')
                                    ->orWhere(fn ($skipped) => $skipped->where('status', 'skipped')->whereNull('last_error'));
                            })
                            ->update([
                                'status' => 'pending',
                                'occurrence_index' => $index,
                                'occurrence_starts_at' => $actualStartUtc->format('Y-m-d H:i:s'),
                                'occurrence_key' => $occurrenceKey,
                                'deliver_at' => $deliverAt->format('Y-m-d H:i:s'),
                                'updated_at' => now(),
                            ]);
                    }
                }
            } catch (Throwable $error) {
                report($error);
            } finally {
                $this->tenantContext->clearTenant();
            }
        }
    }

    /** @return array{delivered:int,skipped:int,retried:int,failed:int} */
    public function dispatch(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $this->materializeRecurringReminders();
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

                    $eventStart = null;
                    if ($event && $event->status === 'scheduled') {
                        if ($row->occurrence_starts_at !== null) {
                            $eventStart = new DateTimeImmutable($row->occurrence_starts_at, new DateTimeZone('UTC'));
                        } elseif ((bool) $event->all_day && $event->starts_on !== null && $event->timezone !== null) {
                            $eventStart = new DateTimeImmutable($event->starts_on, new DateTimeZone($event->timezone));
                        } elseif (!(bool) $event->all_day && $event->starts_at !== null) {
                            $eventStart = new DateTimeImmutable($event->starts_at, new DateTimeZone('UTC'));
                        }
                    }

                    $cancelledOccurrence = $row->occurrence_key !== null
                        && DB::table('calendar_recurrence_exceptions')
                            ->where('tenant_id', $row->tenant_id)
                            ->where('event_id', $row->event_id)
                            ->where('original_starts_at', $row->occurrence_key)
                            ->where('status', 'cancelled')
                            ->exists();

                    if (!$event || $event->status !== 'scheduled' || $eventStart === null
                        || $eventStart <= now()->toDateTimeImmutable() || $cancelledOccurrence) {
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

                    $activeMember = TenantMembership::query()
                        ->where('tenant_id', $tenant->getKey())
                        ->where('user_id', $recipient->getKey())
                        ->where('status', TenantMembership::STATUS_ACTIVE)
                        ->exists();
                    if (!$activeMember) {
                        DB::table('calendar_reminder_outbox')->where('id', $row->id)->update([
                            'status' => 'skipped', 'last_error' => 'Recipient is no longer an active tenant member.',
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
