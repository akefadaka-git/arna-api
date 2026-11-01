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
            if (!$calendar) {
                throw new NotFoundHttpException('Calendar not found in the active tenant.');
            }

            $allDay = (bool) ($data['all_day'] ?? false);
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
            }

            $id = (string) Str::uuid();
            $event = new CalendarEvent();
            $event->forceFill([
                'id' => $id, 'calendar_id' => $calendar->id, 'tenant_id' => $tenantId,
                'created_by' => $actor->getKey(), 'title' => $data['title'],
                'description' => $data['description'] ?? null, 'event_type' => $data['event_type'] ?? 'event',
                'all_day' => $allDay, 'starts_on' => $allDay ? $data['starts_on'] : null,
                'ends_on' => $allDay ? $data['ends_on'] : null,
                'starts_at' => $allDay ? null : $data['starts_at'], 'ends_at' => $allDay ? null : $data['ends_at'],
                'timezone' => $data['timezone'] ?? $calendar->timezone,
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
            }

            if (!empty($data['links'])) { throw new BadRequestHttpException('Calendar domain links are disabled until an owning-domain authorization adapter is registered.'); }

            foreach ($data['links'] ?? [] as $link) {
                CalendarEventLink::query()->create([
                    'event_id' => $id, 'tenant_id' => $tenantId,
                    'domain' => $link['domain'], 'resource_type' => $link['resource_type'],
                    'resource_id' => (string) $link['resource_id'], 'relationship' => $link['relationship'] ?? 'related',
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
            if ((int) ($data['version'] ?? 0) !== (int) $event->version) {
                throw new ConflictHttpException('Calendar event version conflict; reload before retrying.');
            }
            $allowed = ['title', 'description', 'status'];
            foreach ($allowed as $key) if (array_key_exists($key, $data)) $event->{$key} = $data[$key];
            $event->version++;
            $event->save();
            $this->audit($actor, 'event', $id, 'updated', ['version' => $event->version]);
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
            foreach (['starts_at', 'ends_at'] as $field) {
                if (!preg_match('/(?:Z|[+-]\\d{2}:?\\d{2})$/i', $data[$field])) {
                    throw new BadRequestHttpException('Reservation instants must include Z or an explicit UTC offset.');
                }
            }
            $start = new \\DateTimeImmutable($data['starts_at']);
            $end = new \\DateTimeImmutable($data['ends_at']);
            if ($end <= $start) throw new BadRequestHttpException('Reservation end must be after start.');
            if ($event->status !== 'scheduled' || $event->all_day
                || $start < $event->starts_at || $end > $event->ends_at) {
                throw new BadRequestHttpException('Reservation interval must be contained within a scheduled timed event.');
            }
            $overlap = DB::table('calendar_reservations')->where('tenant_id', $tenantId)
                ->where('resource_id', $resource->id)->where('status', 'confirmed')
                ->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])
                ->lockForUpdate()->exists();
            if ($overlap) throw new ConflictHttpException('Resource is already reserved for an overlapping interval.');
            $reservationId = (string) Str::uuid();
            DB::table('calendar_reservations')->insert([
                'id' => $reservationId, 'tenant_id' => $tenantId, 'resource_id' => $resource->id,
                'event_id' => $event->id, 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'],
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

    public function recurringOccurrences(string $eventId, string $from, string $to): array
    {
        $event = CalendarEvent::query()->where('tenant_id', $this->tenantId())->whereKey($eventId)->first();
        if (!$event) throw new NotFoundHttpException('Calendar event not found.');
        if (!$event->recurrence_rule || $event->all_day) return [$event->toArray()];
        $zone = new \\DateTimeZone($event->timezone);
        $start = new \\DateTimeImmutable($event->starts_at);
        $end = new \\DateTimeImmutable($event->ends_at);
        $fromDate = new \\DateTimeImmutable($from, $zone);
        $toDate = new \\DateTimeImmutable($to, $zone);
        if ($toDate > $fromDate->modify('+90 days')) throw new BadRequestHttpException('Expansion window cannot exceed 90 days.');
        $rule = $event->recurrence_rule;
        $frequency = $rule['frequency'] ?? '';
        $step = max(1, min(365, (int) ($rule['interval'] ?? 1)));
        $limit = max(1, min(500, (int) ($rule['count'] ?? 100)));
        $duration = $end->getTimestamp() - $start->getTimestamp();
        $cursor = $start;
        $items = [];
        for ($index = 0; $index < $limit && $cursor <= $toDate; $index++) {
            if ($cursor >= $fromDate) $items[] = ['event_id' => $event->id, 'occurrence' => $index,
                'starts_at' => $cursor->format(DATE_ATOM), 'ends_at' => $cursor->modify("+{$duration} seconds")->format(DATE_ATOM), 'timezone' => $event->timezone];
            $cursor = match ($frequency) {
                'daily' => $cursor->modify("+{$step} days"), 'weekly' => $cursor->modify("+{$step} weeks"),
                'monthly' => $cursor->modify("+{$step} months"), 'yearly' => $cursor->modify("+{$step} years"),
                default => throw new BadRequestHttpException('Unsupported recurrence frequency.'),
            };
        }
        return $items;
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
