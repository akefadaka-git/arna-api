<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Calendar\Models\Calendar;
use MaaleArna\MASES\Core\Calendar\Models\CalendarAttendee;
use MaaleArna\MASES\Core\Calendar\Models\CalendarEvent;
use MaaleArna\MASES\Core\Calendar\Models\CalendarEventLink;
use MaaleArna\MASES\Core\Localization\Calendar\Contracts\CalendarInstant;
use MaaleArna\MASES\Core\Localization\Calendar\Contracts\LocalDate;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class CalendarService
{
    public function __construct(private readonly TenantContextService $tenantContext)
    {
    }

    public function calendars(User $actor): mixed
    {
        return Calendar::query()->where('tenant_id', $this->tenantId())
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('visibility', 'tenant')->orWhere('owner_user_id', $actor->getKey()))
            ->orderBy('name')->paginate(50);
    }

    public function createCalendar(User $actor, array $data): Calendar
    {
        return DB::transaction(function () use ($actor, $data): Calendar {
            $tenantId = $this->tenantId();
            $id = (string) Str::uuid();
            $calendar = new Calendar();
            $calendar->forceFill([
                'id' => $id, 'tenant_id' => $tenantId, 'owner_user_id' => $actor->getKey(),
                'name' => $data['name'], 'visibility' => $data['visibility'] ?? 'tenant',
                'timezone' => $data['timezone'] ?? 'Africa/Addis_Ababa',
                'is_default' => false, 'is_active' => true, 'version' => 1,
            ]);
            $calendar->save();
            $this->audit($actor, 'calendar', $id, 'created', ['name' => $calendar->name]);
            return $calendar;
        });
    }

    public function events(User $actor, array $filters): mixed
    {
        $visibleCalendarIds = Calendar::query()->where('tenant_id', $this->tenantId())
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('visibility', 'tenant')->orWhere('owner_user_id', $actor->getKey()))
            ->select('id');

        return CalendarEvent::query()->where('tenant_id', $this->tenantId())
            ->whereIn('calendar_id', $visibleCalendarIds)
            ->when(isset($filters['from']), fn ($q) => $q->where(function ($w) use ($filters) {
                $w->where('starts_at', '>=', $filters['from'])->orWhere('starts_on', '>=', $filters['from']);
            }))
            ->when(isset($filters['to']), fn ($q) => $q->where(function ($w) use ($filters) {
                $w->where('starts_at', '<=', $filters['to'])->orWhere('starts_on', '<=', $filters['to']);
            }))
            ->with(['attendees', 'links'])->orderByRaw('COALESCE(starts_at, starts_on)')->paginate(100);
    }

    public function createEvent(User $actor, array $data): CalendarEvent
    {
        return DB::transaction(function () use ($actor, $data): CalendarEvent {
            $tenantId = $this->tenantId();
            $calendar = Calendar::query()->where('tenant_id', $tenantId)
                ->whereKey($data['calendar_id'])->lockForUpdate()->first();
            if (!$calendar || ($calendar->visibility === 'private' && (string) $calendar->owner_user_id !== (string) $actor->getKey())) {
                throw new NotFoundHttpException('Calendar not found in the active tenant.');
            }

            $allDay = (bool) ($data['all_day'] ?? false);
            $eventTimezone = $data['timezone'] ?? $calendar->timezone;
            if ($allDay) {
                if (empty($data['starts_on']) || empty($data['ends_on']) || isset($data['starts_at']) || isset($data['ends_at'])) {
                    throw new BadRequestHttpException('All-day events require date-only starts_on and ends_on; instants are not accepted.');
                }
                if ($data['ends_on'] < $data['starts_on']) {
                    throw new BadRequestHttpException('Event end date must not precede its start date.');
                }
            } else {
                if (empty($data['starts_at']) || empty($data['ends_at']) || strtotime($data['ends_at']) <= strtotime($data['starts_at'])) {
                    throw new BadRequestHttpException('Timed events require ordered ISO-8601 instants with explicit UTC offsets.');
                }
                if (!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $data['starts_at']) || !preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $data['ends_at'])) {
                    throw new BadRequestHttpException('Timed event instants must include explicit UTC offsets.');
                }
                $startInstant = CalendarInstant::fromString($data['starts_at'], $eventTimezone);
                $endInstant = CalendarInstant::fromString($data['ends_at'], $eventTimezone);
            }

            $id = (string) Str::uuid();
            $event = new CalendarEvent();
            $event->forceFill([
                'id' => $id, 'calendar_id' => $calendar->id, 'tenant_id' => $tenantId,
                'created_by' => $actor->getKey(), 'title' => $data['title'],
                'description' => $data['description'] ?? null, 'event_type' => $data['event_type'] ?? 'event',
                'all_day' => $allDay, 'starts_on' => $allDay ? $data['starts_on'] : null,
                'ends_on' => $allDay ? $data['ends_on'] : null,
                'starts_at' => $allDay ? null : $startInstant->utc()->format('Y-m-d H:i:s'), 'ends_at' => $allDay ? null : $endInstant->utc()->format('Y-m-d H:i:s'),
                'timezone' => $eventTimezone,
                'status' => 'scheduled', 'recurrence_rule' => $data['recurrence_rule'] ?? null, 'version' => 1,
            ]);
            $event->save();

            foreach (array_unique($data['attendee_user_ids'] ?? []) as $userId) {
                $active = DB::table('tenant_memberships')->where('tenant_id', $tenantId)
                    ->where('user_id', $userId)->where('status', 'active')->exists();
                if (!$active) {
                    throw new BadRequestHttpException('Every attendee must be an active member of this tenant.');
                }
                CalendarAttendee::query()->create([
                    'event_id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId,
                    'response' => 'pending',
                ]);
                if (!$allDay) {
                    $reminderOffsets = array_values(array_unique(array_map('intval', $data['reminders'] ?? [15])));
                    foreach ($reminderOffsets as $minutesBefore) {
                        if ($minutesBefore < 1 || $minutesBefore > 10080) {
                            throw new BadRequestHttpException('Reminder offsets must be between 1 minute and 7 days.');
                        }
                        $reminderType = 'event_start_'.$minutesBefore.'m';
                        $deliverAt = $startInstant->utc()->modify("-{$minutesBefore} minutes");
                        $outboxId = (string) Str::uuid();
                        DB::table('calendar_reminder_outbox')->insert([
                            'id' => $outboxId, 'tenant_id' => $tenantId, 'event_id' => $id,
                            'recipient_user_id' => $userId, 'reminder_type' => $reminderType,
                            'deliver_at' => $deliverAt->format('Y-m-d H:i:s'), 'status' => 'pending',
                            'attempts' => 0, 'idempotency_key' => $id.':'.$userId.':'.$reminderType,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }

            foreach ($data['links'] ?? [] as $link) {
                $domain = (string) ($link['domain'] ?? '');
                $resourceType = (string) ($link['resource_type'] ?? '');
                $resourceId = (string) ($link['resource_id'] ?? '');
                $relationship = (string) ($link['relationship'] ?? 'related');
                if (!in_array($domain, ['people', 'organization', 'governance', 'finance', 'shareholder', 'wallet', 'loan'], true)
                    || !preg_match('/^[a-z][a-z0-9_-]{0,99}$/', $resourceType)
                    || $resourceId === '' || strlen($resourceId) > 100
                    || !in_array($relationship, ['related', 'agenda', 'attachment', 'context'], true)) {
                    throw new BadRequestHttpException('Calendar domain links must be valid opaque references in a supported domain.');
                }

                CalendarEventLink::query()->create([
                    'event_id' => $id, 'tenant_id' => $tenantId,
                    'domain' => $domain, 'resource_type' => $resourceType,
                    'resource_id' => $resourceId, 'relationship' => $relationship,
                ]);
            }

            $this->audit($actor, 'event', $id, 'created', ['calendar_id' => $calendar->id, 'title' => $event->title]);
            return $event->load(['attendees', 'links']);
        });
    }

    public function updateEvent(User $actor, string $id, array $data): CalendarEvent
    {
        return DB::transaction(function () use ($actor, $id, $data): CalendarEvent {
            $event = CalendarEvent::query()->where('tenant_id', $this->tenantId())->whereKey($id)->lockForUpdate()->first();
            if (!$event) throw new NotFoundHttpException('Calendar event not found.');
            $calendar = Calendar::query()->where('tenant_id', $this->tenantId())->whereKey($event->calendar_id)->first();
            if (!$calendar || ($calendar->visibility === 'private' && (string) $calendar->owner_user_id !== (string) $actor->getKey())) {
                throw new NotFoundHttpException('Calendar event not found.');
            }
            if ((int) ($data['version'] ?? 0) !== (int) $event->version) {
                throw new ConflictHttpException('Calendar event version conflict; reload before retrying.');
            }
            $allowed = ['title', 'description', 'status'];
            foreach ($allowed as $key) if (array_key_exists($key, $data)) $event->{$key} = $data[$key];
            $event->version++;
            $event->save();

            if (isset($data['status']) && $data['status'] !== 'scheduled') {
                DB::table('calendar_reminder_outbox')
                    ->where('tenant_id', $this->tenantId())
                    ->where('event_id', $id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'skipped',
                        'last_error' => 'Event is no longer scheduled.',
                        'updated_at' => now(),
                    ]);
            }

            $this->audit($actor, 'event', $id, 'updated', ['version' => $event->version, 'status' => $event->status]);
            return $event->refresh()->load(['attendees', 'links']);
        });
    }

    public function reserveResource(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $tenantId = $this->tenantId();
            $resource = DB::table('calendar_resources')->where('tenant_id', $tenantId)
                ->where('id', $data['resource_id'])->where('is_active', true)->lockForUpdate()->first();
            if (!$resource) throw new NotFoundHttpException('Resource not found in the active tenant.');
            $event = CalendarEvent::query()->where('tenant_id', $tenantId)->whereKey($data['event_id'])->first();
            if (!$event) throw new NotFoundHttpException('Calendar event not found in the active tenant.');
            $calendar = Calendar::query()->where('tenant_id', $tenantId)->whereKey($event->calendar_id)->first();
            if (!$calendar || ($calendar->visibility === 'private' && (string) $calendar->owner_user_id !== (string) $actor->getKey())) throw new NotFoundHttpException('Calendar event not found.');
            foreach (['starts_at', 'ends_at'] as $field) {
                if (!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $data[$field])) {
                    throw new BadRequestHttpException('Reservation instants must include Z or an explicit UTC offset.');
                }
            }
            if ($event->status !== 'scheduled' || $event->all_day || $event->starts_at === null || $event->ends_at === null) {
                throw new BadRequestHttpException('Reservations require a scheduled timed event.');
            }
            $start = CalendarInstant::fromString($data['starts_at'], $event->timezone)->utc();
            $end = CalendarInstant::fromString($data['ends_at'], $event->timezone)->utc();
            if ($end <= $start) throw new BadRequestHttpException('Reservation end must be after start.');
            $eventStart = CalendarInstant::fromDateTime($event->starts_at, $event->timezone)->utc();
            $eventEnd = CalendarInstant::fromDateTime($event->ends_at, $event->timezone)->utc();
            if ($start < $eventStart || $end > $eventEnd) {
                throw new BadRequestHttpException('Reservation interval must be contained within a scheduled timed event.');
            }
            $startUtc = $start->format('Y-m-d H:i:s');
            $endUtc = $end->format('Y-m-d H:i:s');
            $overlap = DB::table('calendar_reservations')->where('tenant_id', $tenantId)
                ->where('resource_id', $resource->id)->where('status', 'confirmed')
                ->where('starts_at', '<', $endUtc)->where('ends_at', '>', $startUtc)
                ->lockForUpdate()->exists();
            if ($overlap) throw new ConflictHttpException('Resource is already reserved for an overlapping interval.');
            $reservationId = (string) Str::uuid();
            DB::table('calendar_reservations')->insert([
                'id' => $reservationId, 'tenant_id' => $tenantId, 'resource_id' => $resource->id,
                'event_id' => $event->id, 'starts_at' => $startUtc, 'ends_at' => $endUtc,
                'status' => 'confirmed', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, 'reservation', $reservationId, 'reserved', ['resource_id' => $resource->id, 'event_id' => $event->id]);
            return (array) DB::table('calendar_reservations')->where('id', $reservationId)->first();
        }, 3);
    }

    public function respondToInvitation(User $actor, string $eventId, string $response): array
    {
        if (!in_array($response, ['accepted', 'declined', 'tentative'], true)) throw new BadRequestHttpException('Invalid attendee response.');
        return DB::transaction(function () use ($actor, $eventId, $response): array {
            $attendee = CalendarAttendee::query()->where('tenant_id', $this->tenantId())
                ->where('event_id', $eventId)->where('user_id', $actor->getKey())->lockForUpdate()->first();
            if (!$attendee) throw new NotFoundHttpException('Invitation not found for authenticated user.');
            $attendee->forceFill(['response' => $response, 'responded_at' => now()]);
            $attendee->save();
            $this->audit($actor, 'attendee', (string) $attendee->getKey(), 'responded', ['event_id' => $eventId, 'response' => $response]);
            return $attendee->fresh()->toArray();
        });
    }

    public function resources(): mixed
    {
        return DB::table('calendar_resources')->where('tenant_id', $this->tenantId())
            ->where('is_active', true)->orderBy('name')->paginate(50);
    }

    public function createResource(User $actor, array $data): object
    {
        return DB::transaction(function () use ($actor, $data): object {
            $id = (string) Str::uuid();
            DB::table('calendar_resources')->insert(['id' => $id, 'tenant_id' => $this->tenantId(),
                'name' => $data['name'], 'resource_type' => $data['resource_type'], 'capacity' => $data['capacity'] ?? null,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, 'resource', $id, 'created', ['name' => $data['name']]);
            return DB::table('calendar_resources')->where('tenant_id', $this->tenantId())->where('id', $id)->first();
        });
    }

    public function updateResource(User $actor, string $id, array $data): object
    {
        return DB::transaction(function () use ($actor, $id, $data): object {
            $query = DB::table('calendar_resources')->where('tenant_id', $this->tenantId())->where('id', $id);
            if (!$query->lockForUpdate()->first()) throw new NotFoundHttpException('Resource not found.');
            $changes = array_intersect_key($data, array_flip(['name', 'resource_type', 'capacity', 'is_active']));
            $changes['updated_at'] = now();
            $query->update($changes);
            $this->audit($actor, 'resource', $id, 'updated', $changes);
            return DB::table('calendar_resources')->where('tenant_id', $this->tenantId())->where('id', $id)->first();
        });
    }

    public function recurringOccurrences(User $actor, string $eventId, string $from, string $to): array
    {
        $event = CalendarEvent::query()->where('tenant_id', $this->tenantId())->whereKey($eventId)->first();
        if (!$event) throw new NotFoundHttpException('Calendar event not found.');
        $calendar = Calendar::query()->where('tenant_id', $this->tenantId())->whereKey($event->calendar_id)->first();
        if (!$calendar || ($calendar->visibility === 'private' && (string) $calendar->owner_user_id !== (string) $actor->getKey())) throw new NotFoundHttpException('Calendar event not found.');
        if (!$event->recurrence_rule) return [$event->toArray()];
        $zone = new \DateTimeZone($event->timezone);
        $fromDate = (new \DateTimeImmutable($from, $zone))->setTimezone($zone);
        $toDate = (new \DateTimeImmutable($to, $zone))->setTimezone($zone);
        if ($toDate > $fromDate->modify('+90 days')) throw new BadRequestHttpException('Expansion window cannot exceed 90 days.');

        $rule = $event->recurrence_rule;
        $frequency = $rule['frequency'] ?? '';
        $step = max(1, min(365, (int) ($rule['interval'] ?? 1)));
        $limit = max(1, min(500, (int) ($rule['count'] ?? 100)));
        $exceptions = DB::table('calendar_recurrence_exceptions')
            ->where('tenant_id', $this->tenantId())->where('event_id', $eventId)
            ->get()->keyBy('original_starts_at');
        $items = [];

        if ($event->all_day) {
            $start = new \DateTimeImmutable($event->starts_on->format('Y-m-d'), $zone);
            $end = new \DateTimeImmutable($event->ends_on->format('Y-m-d'), $zone);
            $durationDays = (int) $start->diff($end)->format('%a');

            for ($index = 0; $index < $limit; $index++) {
                $cursor = $this->recurrenceOccurrenceAt($start, $frequency, $step, $index);
                if ($cursor > $toDate) break;
                $occurrenceKey = $cursor->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $exception = $exceptions->get($occurrenceKey);
                if ($cursor >= $fromDate && (!$exception || $exception->status !== 'cancelled')) {
                    $occurrenceStart = $exception?->override_starts_at
                        ? (new \DateTimeImmutable($exception->override_starts_at, new \DateTimeZone('UTC')))->setTimezone($zone)
                        : $cursor;
                    $occurrenceEnd = $exception?->override_ends_at
                        ? (new \DateTimeImmutable($exception->override_ends_at, new \DateTimeZone('UTC')))->setTimezone($zone)
                        : $occurrenceStart->modify("+{$durationDays} days");
                    $items[] = [
                        'event_id' => $event->id,
                        'occurrence' => $index,
                        'all_day' => true,
                        'starts_on' => $occurrenceStart->format('Y-m-d'),
                        'ends_on' => $occurrenceEnd->format('Y-m-d'),
                        'timezone' => $event->timezone,
                    ];
                }
            }

            return $items;
        }

        $start = \DateTimeImmutable::createFromInterface($event->starts_at)->setTimezone($zone);
        $end = \DateTimeImmutable::createFromInterface($event->ends_at)->setTimezone($zone);
        $duration = $end->getTimestamp() - $start->getTimestamp();
        for ($index = 0; $index < $limit; $index++) {
            $cursor = $this->recurrenceOccurrenceAt($start, $frequency, $step, $index);
            if ($cursor > $toDate) break;
            $occurrenceKey = $cursor->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $exception = $exceptions->get($occurrenceKey);
            if ($cursor >= $fromDate && (!$exception || $exception->status !== 'cancelled')) {
                $occurrenceStart = $exception?->override_starts_at ? new \DateTimeImmutable($exception->override_starts_at, new \DateTimeZone('UTC')) : $cursor;
                $occurrenceEnd = $exception?->override_ends_at ? new \DateTimeImmutable($exception->override_ends_at, new \DateTimeZone('UTC')) : $occurrenceStart->modify("+{$duration} seconds");
                $items[] = ['event_id' => $event->id, 'occurrence' => $index,
                    'starts_at' => $occurrenceStart->setTimezone($zone)->format(DATE_ATOM), 'ends_at' => $occurrenceEnd->setTimezone($zone)->format(DATE_ATOM), 'timezone' => $event->timezone];
            }
        }

        return $items;
    }

    private function recurrenceOccurrenceAt(\DateTimeImmutable $anchor, string $frequency, int $interval, int $occurrence): \DateTimeImmutable
    {
        $offset = $interval * $occurrence;
        if ($frequency === 'daily') return $anchor->modify("+{$offset} days");
        if ($frequency === 'weekly') return $anchor->modify("+{$offset} weeks");
        if (!in_array($frequency, ['monthly', 'yearly'], true)) {
            throw new BadRequestHttpException('Unsupported recurrence frequency.');
        }

        $year = (int) $anchor->format('Y');
        $month = (int) $anchor->format('n');
        if ($frequency === 'monthly') {
            $monthIndex = ($month - 1) + $offset;
            $year += intdiv($monthIndex, 12);
            $month = ($monthIndex % 12) + 1;
        } else {
            $year += $offset;
        }

        $firstOfMonth = $anchor->setDate($year, $month, 1);
        $lastDay = (int) $firstOfMonth->modify('last day of this month')->format('j');
        $day = min((int) $anchor->format('j'), $lastDay);

        return $firstOfMonth->setDate($year, $month, $day);
    }

    public function moveOccurrence(User $actor, string $eventId, string $originalStartsAt, string $newStartsAt, string $newEndsAt): void
    {
        DB::transaction(function () use ($actor, $eventId, $originalStartsAt, $newStartsAt, $newEndsAt): void {
            $event = CalendarEvent::query()->where('tenant_id', $this->tenantId())->whereKey($eventId)->lockForUpdate()->first();
            if (!$event || !$event->recurrence_rule) throw new NotFoundHttpException('Recurring event not found.');
            $calendar = Calendar::query()->where('tenant_id', $this->tenantId())->whereKey($event->calendar_id)->first();
            if (!$calendar || ($calendar->visibility === 'private' && (string) $calendar->owner_user_id !== (string) $actor->getKey())) {
                throw new NotFoundHttpException('Recurring event not found.');
            }

            $zone = new \DateTimeZone($event->timezone);
            if ($event->all_day) {
                $original = LocalDate::fromString($originalStartsAt)->atStartOfDay($zone);
                $newStart = LocalDate::fromString($newStartsAt)->atStartOfDay($zone);
                $newEnd = LocalDate::fromString($newEndsAt)->atStartOfDay($zone);
                if ($newEnd < $newStart) throw new BadRequestHttpException('All-day override end date must not precede its start date.');
            } else {
                foreach ([$originalStartsAt, $newStartsAt, $newEndsAt] as $instant) {
                    if (!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $instant)) {
                        throw new BadRequestHttpException('Recurrence override instants require explicit UTC offsets.');
                    }
                }
                $original = CalendarInstant::fromString($originalStartsAt, $event->timezone)->utc();
                $newStart = CalendarInstant::fromString($newStartsAt, $event->timezone)->utc();
                $newEnd = CalendarInstant::fromString($newEndsAt, $event->timezone)->utc();
                if ($newEnd <= $newStart) throw new BadRequestHttpException('Override end must be after its start.');
            }

            $utc = new \DateTimeZone('UTC');
            $originalUtc = $original->setTimezone($utc)->format('Y-m-d H:i:s');
            $overrideStartUtc = $newStart->setTimezone($utc)->format('Y-m-d H:i:s');
            $overrideEndUtc = $newEnd->setTimezone($utc)->format('Y-m-d H:i:s');

            DB::table('calendar_recurrence_exceptions')->updateOrInsert(
                ['event_id' => $eventId, 'original_starts_at' => $originalUtc],
                [
                    'tenant_id' => $this->tenantId(), 'status' => 'overridden',
                    'override_starts_at' => $overrideStartUtc,
                    'override_ends_at' => $overrideEndUtc,
                    'created_by' => $actor->getKey(), 'updated_at' => now(), 'created_at' => now(),
                ]
            );
            $this->audit($actor, 'recurrence_exception', $eventId.':'.$originalUtc, 'overridden', [
                'original_starts_at' => $originalUtc,
                'override_starts_at' => $overrideStartUtc,
                'override_ends_at' => $overrideEndUtc,
                'all_day' => $event->all_day,
            ]);
        });
    }

    public function cancelOccurrence(User $actor, string $eventId, string $originalStartsAt): void
    {
        DB::transaction(function () use ($actor, $eventId, $originalStartsAt): void {
            $event = CalendarEvent::query()->where('tenant_id', $this->tenantId())->whereKey($eventId)->lockForUpdate()->first();
            if (!$event || !$event->recurrence_rule) throw new NotFoundHttpException('Recurring event not found.');
            $calendar = Calendar::query()->where('tenant_id', $this->tenantId())->whereKey($event->calendar_id)->first();
            if (!$calendar || ($calendar->visibility === 'private' && (string) $calendar->owner_user_id !== (string) $actor->getKey())) {
                throw new NotFoundHttpException('Recurring event not found.');
            }

            if ($event->all_day) {
                $original = LocalDate::fromString($originalStartsAt)->atStartOfDay(new \DateTimeZone($event->timezone));
            } else {
                if (!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $originalStartsAt)) {
                    throw new BadRequestHttpException('Occurrence instant requires an explicit UTC offset.');
                }
                $original = CalendarInstant::fromString($originalStartsAt, $event->timezone)->utc();
            }

            $utc = $original->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            DB::table('calendar_recurrence_exceptions')->updateOrInsert(
                ['event_id' => $eventId, 'original_starts_at' => $utc],
                ['tenant_id' => $this->tenantId(), 'status' => 'cancelled', 'created_by' => $actor->getKey(), 'updated_at' => now(), 'created_at' => now()]
            );
            $this->audit($actor, 'recurrence_exception', $eventId.':'.$utc, 'cancelled', [
                'original_starts_at' => $utc,
                'all_day' => $event->all_day,
            ]);
        });
    }

    private function tenantId(): int|string
    {
        $id = $this->tenantContext->getTenantId();
        if ($id === null) abort(400, 'TENANT_REQUIRED');
        return $id;
    }

    private function audit(User $actor, string $type, string $id, string $action, array $changes): void
    {
        DB::table('calendar_audit_entries')->insert([
            'tenant_id' => $this->tenantId(), 'actor_user_id' => $actor->getKey(),
            'aggregate_type' => $type, 'aggregate_id' => $id, 'action' => $action,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'request_id' => request()->header('X-Request-ID'), 'created_at' => now(),
        ]);
    }
}
