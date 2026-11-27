<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Calendar\Models\Calendar;
use MaaleArna\MASES\Core\Calendar\Models\CalendarAppointment;
use MaaleArna\MASES\Core\Calendar\Models\CalendarAttendee;
use MaaleArna\MASES\Core\Calendar\Models\CalendarEvent;
use MaaleArna\MASES\Core\Calendar\Models\CalendarEventLink;
use MaaleArna\MASES\Core\Localization\Calendar\Contracts\CalendarInstant;
use MaaleArna\MASES\Core\Localization\Calendar\Contracts\LocalDate;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianDate;
use MaaleArna\MASES\Core\Notifications\Services\NotificationService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Throwable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class CalendarService
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly CalendarDomainResourceAuthorizer $domainResourceAuthorizer,
        private readonly NotificationService $notifications,
    ) {
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

    public function updateCalendar(User $actor, string $id, array $data): Calendar
    {
        return DB::transaction(function () use ($actor, $id, $data): Calendar {
            $calendar = Calendar::query()->where('tenant_id', $this->tenantId())
                ->whereKey($id)->lockForUpdate()->first();
            if (!$calendar || (string) $calendar->owner_user_id !== (string) $actor->getKey()) {
                throw new NotFoundHttpException('Calendar not found.');
            }
            if ((int) ($data['version'] ?? 0) !== (int) $calendar->version) {
                throw new ConflictHttpException('Calendar version conflict; reload before retrying.');
            }

            $changes = array_intersect_key($data, array_flip(['name', 'visibility', 'timezone']));
            foreach ($changes as $key => $value) {
                $calendar->{$key} = $value;
            }
            $calendar->version++;
            $calendar->save();
            $this->audit($actor, 'calendar', (string) $calendar->id, 'updated', [
                'version' => $calendar->version,
                'changes' => $changes,
            ]);

            return $calendar->refresh();
        });
    }

    public function events(User $actor, array $filters): mixed
    {
        $visibleCalendarIds = Calendar::query()->where('tenant_id', $this->tenantId())
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('visibility', 'tenant')->orWhere('owner_user_id', $actor->getKey()))
            ->select('id');

        $tenantId = $this->tenantId();
        $invitedEventIds = DB::table('calendar_attendees as ca')
            ->join('calendar_events as ce', 'ce.id', '=', 'ca.event_id')
            ->join('calendar_calendars as c', 'c.id', '=', 'ce.calendar_id')
            ->where('ca.tenant_id', $tenantId)
            ->where('ca.user_id', $actor->getKey())
            ->where('ce.tenant_id', $tenantId)
            ->where('c.tenant_id', $tenantId)
            ->where('c.is_active', true)
            ->select('ca.event_id');

        $events = CalendarEvent::query()->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereIn('calendar_id', $visibleCalendarIds)->orWhereIn('id', $invitedEventIds))
            ->when(isset($filters['from']), fn ($q) => $q->where(function ($w) use ($filters) {
                $w->where('starts_at', '>=', $filters['from'])->orWhere('starts_on', '>=', $filters['from']);
            }))
            ->when(isset($filters['to']), fn ($q) => $q->where(function ($w) use ($filters) {
                $w->where('starts_at', '<=', $filters['to'])->orWhere('starts_on', '<=', $filters['to']);
            }))
            ->with(['attendees', 'links'])->orderByRaw('COALESCE(starts_at, starts_on)')->paginate(100);

        $events->getCollection()->each(function (CalendarEvent $event) use ($actor, $tenantId): void {
            $visibleLinks = $event->links->filter(fn (CalendarEventLink $link): bool =>
                $this->domainResourceAuthorizer->canView(
                    $actor, $tenantId, $link->domain, $link->resource_type, $link->resource_id,
                )
            );
            $event->setRelation('links', $visibleLinks->values());
        });

        return $events;
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
                $reminderBaseInstant = $startInstant->utc();
            }

            if ($allDay) {
                $reminderBaseInstant = LocalDate::fromString($data['starts_on'])
                    ->atStartOfDay(new \DateTimeZone($eventTimezone))
                    ->setTimezone(new \DateTimeZone('UTC'));
            }

            $recurrenceRule = $data['recurrence_rule'] ?? null;
            if (is_array($recurrenceRule) && isset($recurrenceRule['until'])) {
                $anchorDate = $allDay
                    ? $data['starts_on']
                    : $startInstant->inEventTimezone()->format('Y-m-d');
                if ($recurrenceRule['until'] < $anchorDate) {
                    throw new BadRequestHttpException('Recurrence end date must not precede the first occurrence.');
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
                $reminderOffsets = array_values(array_unique(array_map('intval', $data['reminders'] ?? [15])));
                foreach ($reminderOffsets as $minutesBefore) {
                    if ($minutesBefore < 1 || $minutesBefore > 10080) {
                        throw new BadRequestHttpException('Reminder offsets must be between 1 minute and 7 days.');
                    }
                    $reminderType = 'event_start_'.$minutesBefore.'m';
                    $deliverAt = $reminderBaseInstant->modify("-{$minutesBefore} minutes");
                    $outboxId = (string) Str::uuid();
                    DB::table('calendar_reminder_outbox')->insert([
                        'id' => $outboxId, 'tenant_id' => $tenantId, 'event_id' => $id,
                        'recipient_user_id' => $userId, 'reminder_type' => $reminderType,
                        'occurrence_index' => 0,
                        'occurrence_starts_at' => $reminderBaseInstant->format('Y-m-d H:i:s'),
                        'occurrence_key' => $reminderBaseInstant->format('Y-m-d H:i:s'),
                        'deliver_at' => $deliverAt->format('Y-m-d H:i:s'), 'status' => 'pending',
                        'attempts' => 0, 'idempotency_key' => $id.':'.$userId.':'.$reminderType,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            foreach ($data['links'] ?? [] as $link) {
                $domain = (string) ($link['domain'] ?? '');
                $resourceType = (string) ($link['resource_type'] ?? '');
                $resourceId = (string) ($link['resource_id'] ?? '');
                $relationship = (string) ($link['relationship'] ?? 'related');
                if (!in_array($domain, ['people', 'organization', 'governance', 'finance', 'shareholder', 'wallet', 'loan', 'task'], true)
                    || !preg_match('/^[a-z][a-z0-9_-]{0,99}$/', $resourceType)
                    || $resourceId === '' || strlen($resourceId) > 100
                    || !in_array($relationship, ['related', 'agenda', 'attachment', 'context'], true)) {
                    throw new BadRequestHttpException('Calendar domain links must be valid opaque references in a supported domain.');
                }

                $this->domainResourceAuthorizer->resolveAndAuthorize(
                    $actor, $tenantId, $domain, $resourceType, $resourceId,
                );

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

    /**
     * Check a shared personal or corporate appointment slot without
     * disclosing other members' event details.
     */
    public function appointmentAvailability(User $actor, array $data): array
    {
        $tenantId = $this->tenantId();
        $calendar = $this->appointmentCalendar($actor, $tenantId, $data);
        [$start, $end] = $this->appointmentInstants($data, $calendar->timezone);
        $participantIds = $this->appointmentParticipantIds($actor, $data);
        $this->assertActiveAppointmentParticipants($tenantId, $participantIds);

        $resourceIds = array_values(array_unique(array_map('strval', $data['resource_ids'] ?? [])));
        $this->assertActiveAppointmentResources($tenantId, $resourceIds, false);

        $participantConflicts = $this->appointmentParticipantConflicts(
            $tenantId,
            $participantIds,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        );
        $resourceConflicts = $this->appointmentResourceConflicts(
            $tenantId,
            $resourceIds,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        );

        return [
            'available' => $participantConflicts === [] && $resourceConflicts === [],
            'participant_conflict_count' => count($participantConflicts),
            'resource_conflict_count' => count($resourceConflicts),
            'timezone' => $calendar->timezone,
        ];
    }

    /**
     * Create a booking request using the Calendar event, invitation,
     * reminder and resource-reservation infrastructure as its schedule
     * source. The appointment aggregate owns request/confirmation/
     * decline/cancellation state; Governance appointments remain separate.
     */
    public function bookAppointment(User $actor, array $data): CalendarAppointment
    {
        return DB::transaction(function () use ($actor, $data): CalendarAppointment {
            $tenantId = $this->tenantId();
            $calendar = $this->appointmentCalendar($actor, $tenantId, $data);
            [$start, $end] = $this->appointmentInstants($data, $calendar->timezone);
            $participantIds = $this->appointmentParticipantIds($actor, $data);
            $this->lockAppointmentParticipants($participantIds);
            $this->assertActiveAppointmentParticipants($tenantId, $participantIds);

            $resourceIds = array_values(array_unique(array_map('strval', $data['resource_ids'] ?? [])));
            $this->assertActiveAppointmentResources($tenantId, $resourceIds, true);

            $availability = $this->appointmentAvailability($actor, $data);
            if (!$availability['available']) {
                throw new ConflictHttpException('The selected appointment slot is no longer available.');
            }

            $invitees = array_values(array_unique(array_map('intval', [
                $data['host_user_id'],
                ...($data['attendee_user_ids'] ?? []),
            ])));
            $event = $this->createEvent($actor, [
                'calendar_id' => $calendar->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'event_type' => 'appointment',
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'timezone' => $data['timezone'] ?? $calendar->timezone,
                'attendee_user_ids' => $invitees,
                'reminders' => $data['reminders'] ?? [15],
            ]);

            $appointment = new CalendarAppointment();
            $appointment->forceFill([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'event_id' => $event->id,
                'requested_by_user_id' => $actor->getKey(),
                'host_user_id' => $data['host_user_id'],
                'appointment_scope' => $data['appointment_scope'] ?? 'personal',
                'status' => 'requested',
                'version' => 1,
            ]);
            $appointment->save();

            foreach ($resourceIds as $resourceId) {
                $this->reserveResource($actor, [
                    'resource_id' => $resourceId,
                    'event_id' => $event->id,
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'],
                ]);
            }

            $this->audit($actor, 'appointment', (string) $appointment->id, 'requested', [
                'event_id' => $event->id,
                'appointment_scope' => $appointment->appointment_scope,
                'host_user_id' => $appointment->host_user_id,
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end->format('Y-m-d H:i:s'),
            ]);

            return $appointment->fresh()->load(['event.attendees', 'event.links']);
        }, 3);
    }

    public function appointments(User $actor): mixed
    {
        $tenantId = $this->tenantId();

        return CalendarAppointment::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($actor): void {
                $query->where('requested_by_user_id', $actor->getKey())
                    ->orWhere('host_user_id', $actor->getKey())
                    ->orWhereHas('event.attendees', fn ($attendees) => $attendees->where('user_id', $actor->getKey()));
            })
            ->with(['event.attendees', 'event.links'])
            ->orderByDesc('created_at')
            ->paginate(50);
    }

    public function confirmAppointment(User $actor, string $id): CalendarAppointment
    {
        return DB::transaction(function () use ($actor, $id): CalendarAppointment {
            $appointment = CalendarAppointment::query()->where('tenant_id', $this->tenantId())
                ->whereKey($id)->lockForUpdate()->first();
            if (!$appointment || (string) $appointment->host_user_id !== (string) $actor->getKey()) {
                throw new NotFoundHttpException('Appointment not found.');
            }
            if ($appointment->status !== 'requested') {
                throw new BadRequestHttpException('Only requested appointments can be confirmed.');
            }

            $event = CalendarEvent::query()->where('tenant_id', $tenantId)
                ->whereKey($appointment->event_id)->lockForUpdate()->first();
            if (!$event || $event->status !== 'scheduled') {
                throw new NotFoundHttpException('Appointment not found.');
            }

            $appointment->status = 'confirmed';
            $appointment->confirmed_at = now();
            $appointment->version++;
            $appointment->save();

            CalendarAttendee::query()->where('tenant_id', $this->tenantId())
                ->where('event_id', $event->id)->where('user_id', $actor->getKey())
                ->update(['response' => 'accepted', 'responded_at' => now(), 'updated_at' => now()]);

            $this->audit($actor, 'appointment', (string) $appointment->id, 'confirmed', [
                'event_id' => $event->id,
                'version' => $appointment->version,
            ]);

            return $appointment->fresh()->load(['event.attendees', 'event.links']);
        }, 3);
    }

    public function declineAppointment(User $actor, string $id): CalendarAppointment
    {
        return $this->closeAppointment($actor, $id, 'declined');
    }

    public function cancelAppointment(User $actor, string $id): CalendarAppointment
    {
        return $this->closeAppointment($actor, $id, 'cancelled');
    }

    private function closeAppointment(User $actor, string $id, string $status): CalendarAppointment
    {
        return DB::transaction(function () use ($actor, $id, $status): CalendarAppointment {
            $tenantId = $this->tenantId();
            $appointment = CalendarAppointment::query()->where('tenant_id', $tenantId)
                ->whereKey($id)->lockForUpdate()->first();
            if (!$appointment) {
                throw new NotFoundHttpException('Appointment not found.');
            }
            $isHost = (string) $appointment->host_user_id === (string) $actor->getKey();
            $isRequester = (string) $appointment->requested_by_user_id === (string) $actor->getKey();
            if (($status === 'declined' && !$isHost) || ($status === 'cancelled' && !$isHost && !$isRequester)) {
                throw new NotFoundHttpException('Appointment not found.');
            }
            if (!in_array($appointment->status, ['requested', 'confirmed'], true)) {
                throw new BadRequestHttpException('This appointment is already closed.');
            }

            $event = CalendarEvent::query()->where('tenant_id', $this->tenantId())
                ->whereKey($appointment->event_id)->lockForUpdate()->first();
            if (!$event) {
                throw new NotFoundHttpException('Appointment not found.');
            }

            $appointment->status = $status;
            $appointment->version++;
            if ($status === 'declined') {
                $appointment->declined_at = now();
            } else {
                $appointment->cancelled_at = now();
            }
            $appointment->save();

            if ($event->status === 'scheduled') {
                $event->status = 'cancelled';
                $event->version++;
                $event->save();
            }
            DB::table('calendar_reminder_outbox')
                ->where('tenant_id', $this->tenantId())
                ->where('event_id', $event->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'skipped',
                    'last_error' => 'Appointment is no longer active.',
                    'updated_at' => now(),
                ]);
            DB::table('calendar_reservations')->where('tenant_id', $this->tenantId())
                ->where('event_id', $event->id)->where('status', 'confirmed')
                ->update(['status' => 'cancelled', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

            if ($status === 'cancelled') {
                DB::table('calendar_appointment_reschedule_proposals')
                    ->where('tenant_id', $tenantId)->where('appointment_id', $appointment->id)
                    ->where('status', 'pending')->update([
                        'status' => 'withdrawn', 'responded_by_user_id' => $actor->getKey(),
                        'responded_at' => now(), 'updated_at' => now(),
                    ]);
            }

            $this->audit($actor, 'appointment', (string) $appointment->id, $status, [
                'event_id' => $event->id,
                'version' => $appointment->version,
            ]);

            return $appointment->fresh()->load(['event.attendees', 'event.links']);
        }, 3);
    }


    /**
     * Return proposal history to the appointment's requester or host only.
     */
    public function rescheduleProposals(User $actor, string $appointmentId): array
    {
        $tenantId = $this->tenantId();
        $appointment = CalendarAppointment::query()->where('tenant_id', $tenantId)
            ->whereKey($appointmentId)->first();
        if (!$appointment
            || !in_array((string) $actor->getKey(), [(string) $appointment->requested_by_user_id, (string) $appointment->host_user_id], true)) {
            throw new NotFoundHttpException('Appointment not found.');
        }

        return DB::table('calendar_appointment_reschedule_proposals')
            ->where('tenant_id', $tenantId)->where('appointment_id', $appointmentId)
            ->orderByDesc('created_at')->get()->all();
    }

    public function createRescheduleProposal(User $actor, string $appointmentId, array $data): object
    {
        return DB::transaction(function () use ($actor, $appointmentId, $data): object {
            $tenantId = $this->tenantId();
            $appointment = CalendarAppointment::query()->where('tenant_id', $tenantId)
                ->whereKey($appointmentId)->lockForUpdate()->first();
            if (!$appointment
                || !in_array((string) $actor->getKey(), [(string) $appointment->requested_by_user_id, (string) $appointment->host_user_id], true)) {
                throw new NotFoundHttpException('Appointment not found.');
            }
            if ($appointment->status !== 'confirmed') {
                throw new BadRequestHttpException('Only confirmed appointments can be rescheduled.');
            }
            if ((int) $data['expected_appointment_version'] !== (int) $appointment->version) {
                throw new ConflictHttpException('Appointment version conflict; reload before proposing a new time.');
            }

            $event = CalendarEvent::query()->where('tenant_id', $tenantId)
                ->whereKey($appointment->event_id)->lockForUpdate()->first();
            if (!$event || $event->status !== 'scheduled' || $event->all_day || $event->recurrence_rule !== null) {
                throw new BadRequestHttpException('Only standalone timed appointments can be rescheduled.');
            }
            if ((int) $data['expected_event_version'] !== (int) $event->version) {
                throw new ConflictHttpException('Calendar event version conflict; reload before proposing a new time.');
            }
            if (isset($data['timezone']) && $data['timezone'] !== $event->timezone) {
                throw new BadRequestHttpException('Rescheduling cannot change the appointment timezone.');
            }
            $pending = DB::table('calendar_appointment_reschedule_proposals')
                ->where('tenant_id', $tenantId)->where('appointment_id', $appointmentId)
                ->where('status', 'pending')->lockForUpdate()->exists();
            if ($pending) {
                throw new ConflictHttpException('This appointment already has a pending reschedule proposal.');
            }

            foreach (['starts_at', 'ends_at'] as $field) {
                if (!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', (string) $data[$field])) {
                    throw new BadRequestHttpException('Proposed appointment instants require Z or an explicit UTC offset.');
                }
            }
            $start = CalendarInstant::fromString($data['starts_at'], $event->timezone)->utc();
            $end = CalendarInstant::fromString($data['ends_at'], $event->timezone)->utc();
            if ($end <= $start) {
                throw new BadRequestHttpException('Proposed appointment end must be after its start.');
            }
            if ($start <= now()->toDateTimeImmutable()->setTimezone(new \DateTimeZone('UTC'))) {
                throw new BadRequestHttpException('The proposed appointment start must be in the future.');
            }

            $attendeeIds = DB::table('calendar_attendees')->where('tenant_id', $tenantId)
                ->where('event_id', $event->id)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $participantIds = array_values(array_unique(array_map('intval', [
                $appointment->requested_by_user_id, $appointment->host_user_id, ...$attendeeIds,
            ])));
            sort($participantIds);
            $this->lockAppointmentParticipants($participantIds);
            $this->assertActiveAppointmentParticipants($tenantId, $participantIds);

            $reservations = DB::table('calendar_reservations')->where('tenant_id', $tenantId)
                ->where('event_id', $event->id)->where('status', 'confirmed')
                ->orderBy('resource_id')->lockForUpdate()->get();
            $resourceIds = $reservations->pluck('resource_id')->map(fn ($id): string => (string) $id)->unique()->values()->all();
            sort($resourceIds);
            $this->assertActiveAppointmentResources($tenantId, $resourceIds, true);
            $participantConflicts = $this->appointmentParticipantConflicts(
                $tenantId, $participantIds, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), (string) $event->id,
            );
            $resourceConflicts = $this->appointmentResourceConflicts(
                $tenantId, $resourceIds, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), (string) $event->id,
            );
            if ($participantConflicts !== [] || $resourceConflicts !== []) {
                throw new ConflictHttpException('The proposed appointment time is no longer available.');
            }

            $proposerId = (int) $actor->getKey();
            $counterpartyId = $proposerId === (int) $appointment->requested_by_user_id
                ? (int) $appointment->host_user_id : (int) $appointment->requested_by_user_id;
            $proposalId = (string) Str::uuid();
            DB::table('calendar_appointment_reschedule_proposals')->insert([
                'id' => $proposalId,
                'tenant_id' => $tenantId,
                'appointment_id' => $appointmentId,
                'proposer_user_id' => $proposerId,
                'counterparty_user_id' => $counterpartyId,
                'proposed_starts_at' => $start->format('Y-m-d H:i:s'),
                'proposed_ends_at' => $end->format('Y-m-d H:i:s'),
                'timezone' => $event->timezone,
                'reason' => $data['reason'] ?? null,
                'base_appointment_version' => $appointment->version,
                'base_event_version' => $event->version,
                'status' => 'pending',
                'responded_by_user_id' => null,
                'responded_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($actor, 'appointment_reschedule_proposal', $proposalId, 'proposed', [
                'appointment_id' => $appointmentId,
                'event_id' => $event->id,
                'starts_at' => $start->format(DATE_ATOM),
                'ends_at' => $end->format(DATE_ATOM),
                'base_appointment_version' => $appointment->version,
                'base_event_version' => $event->version,
                'reason' => $data['reason'] ?? null,
            ]);
            $this->notifyAppointmentReschedule($tenantId, $appointment, $event, $proposalId, 'proposed', $participantIds);

            return DB::table('calendar_appointment_reschedule_proposals')->where('id', $proposalId)->first();
        }, 3);
    }

    public function acceptRescheduleProposal(User $actor, string $appointmentId, string $proposalId): CalendarAppointment
    {
        return DB::transaction(function () use ($actor, $appointmentId, $proposalId): CalendarAppointment {
            $tenantId = $this->tenantId();
            $appointment = CalendarAppointment::query()->where('tenant_id', $tenantId)
                ->whereKey($appointmentId)->lockForUpdate()->first();
            if (!$appointment || $appointment->status !== 'confirmed') {
                throw new NotFoundHttpException('Appointment not found.');
            }
            $proposal = DB::table('calendar_appointment_reschedule_proposals')
                ->where('tenant_id', $tenantId)->where('appointment_id', $appointmentId)
                ->where('id', $proposalId)->lockForUpdate()->first();
            if (!$proposal || (int) $proposal->counterparty_user_id !== (int) $actor->getKey()) {
                throw new NotFoundHttpException('Reschedule proposal not found.');
            }
            if ($proposal->status !== 'pending') {
                throw new ConflictHttpException('This reschedule proposal is no longer pending.');
            }
            if ((int) $proposal->base_appointment_version !== (int) $appointment->version) {
                throw new ConflictHttpException('Appointment changed after this proposal was created; submit a new proposal.');
            }

            $event = CalendarEvent::query()->where('tenant_id', $tenantId)
                ->whereKey($appointment->event_id)->lockForUpdate()->first();
            if (!$event || $event->status !== 'scheduled' || (int) $proposal->base_event_version !== (int) $event->version) {
                throw new ConflictHttpException('Calendar event changed after this proposal was created; submit a new proposal.');
            }
            if ($event->timezone !== $proposal->timezone || $event->all_day || $event->recurrence_rule !== null) {
                throw new ConflictHttpException('The appointment no longer matches the proposal baseline.');
            }

            $newStart = new \DateTimeImmutable($proposal->proposed_starts_at, new \DateTimeZone('UTC'));
            $newEnd = new \DateTimeImmutable($proposal->proposed_ends_at, new \DateTimeZone('UTC'));
            if ($newStart <= now()->toDateTimeImmutable()->setTimezone(new \DateTimeZone('UTC')) || $newEnd <= $newStart) {
                throw new ConflictHttpException('The proposed time is no longer valid; submit a new proposal.');
            }

            $attendeeIds = DB::table('calendar_attendees')->where('tenant_id', $tenantId)
                ->where('event_id', $event->id)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $participantIds = array_values(array_unique(array_map('intval', [
                $appointment->requested_by_user_id, $appointment->host_user_id, ...$attendeeIds,
            ])));
            sort($participantIds);
            $this->lockAppointmentParticipants($participantIds);
            $this->assertActiveAppointmentParticipants($tenantId, $participantIds);

            $reservations = DB::table('calendar_reservations')->where('tenant_id', $tenantId)
                ->where('event_id', $event->id)->where('status', 'confirmed')
                ->orderBy('resource_id')->lockForUpdate()->get();
            $resourceIds = $reservations->pluck('resource_id')->map(fn ($id): string => (string) $id)->unique()->values()->all();
            sort($resourceIds);
            $this->assertActiveAppointmentResources($tenantId, $resourceIds, true);
            $oldStart = new \DateTimeImmutable($event->starts_at->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
            $oldEnd = new \DateTimeImmutable($event->ends_at->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
            $participantConflicts = $this->appointmentParticipantConflicts(
                $tenantId, $participantIds, $newStart->format('Y-m-d H:i:s'), $newEnd->format('Y-m-d H:i:s'), (string) $event->id,
            );
            $resourceConflicts = $this->appointmentResourceConflicts(
                $tenantId, $resourceIds, $newStart->format('Y-m-d H:i:s'), $newEnd->format('Y-m-d H:i:s'), (string) $event->id,
            );
            if ($participantConflicts !== [] || $resourceConflicts !== []) {
                throw new ConflictHttpException('The proposed appointment time is no longer available; the current booking is unchanged.');
            }

            $event->starts_at = $newStart->format('Y-m-d H:i:s');
            $event->ends_at = $newEnd->format('Y-m-d H:i:s');
            $event->version++;
            $event->save();

            foreach ($reservations as $reservation) {
                $reservationStart = new \DateTimeImmutable($reservation->starts_at, new \DateTimeZone('UTC'));
                $reservationEnd = new \DateTimeImmutable($reservation->ends_at, new \DateTimeZone('UTC'));
                $startOffset = $reservationStart->getTimestamp() - $oldStart->getTimestamp();
                $endOffset = $reservationEnd->getTimestamp() - $oldStart->getTimestamp();
                $shiftedStart = (new \DateTimeImmutable('@'.($newStart->getTimestamp() + $startOffset)))->setTimezone(new \DateTimeZone('UTC'));
                $shiftedEnd = (new \DateTimeImmutable('@'.($newStart->getTimestamp() + $endOffset)))->setTimezone(new \DateTimeZone('UTC'));
                if ($shiftedStart < $newStart || $shiftedEnd > $newEnd || $shiftedEnd <= $shiftedStart) {
                    throw new ConflictHttpException('The proposed interval is too short to preserve an existing resource reservation.');
                }
                DB::table('calendar_reservations')->where('tenant_id', $tenantId)->where('id', $reservation->id)
                    ->update([
                        'starts_at' => $shiftedStart->format('Y-m-d H:i:s'),
                        'ends_at' => $shiftedEnd->format('Y-m-d H:i:s'),
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
            }

            $oldPending = DB::table('calendar_reminder_outbox')->where('tenant_id', $tenantId)
                ->where('event_id', $event->id)->where('status', 'pending');
            $reminderTemplates = DB::table('calendar_reminder_outbox')->where('tenant_id', $tenantId)
                ->where('event_id', $event->id)->get(['recipient_user_id', 'reminder_type']);
            $oldPending->update([
                'status' => 'skipped',
                'last_error' => 'Superseded by an accepted appointment reschedule.',
                'updated_at' => now(),
            ]);
            $reminderStart = $newStart;
            foreach ($reminderTemplates->unique(fn ($row) => $row->recipient_user_id.':'.$row->reminder_type) as $template) {
                if (!preg_match('/^event_start_([0-9]+)m$/', (string) $template->reminder_type, $matches)) {
                    continue;
                }
                $minutes = (int) $matches[1];
                $deliverAt = $reminderStart->modify('-'.$minutes.' minutes');
                if ($deliverAt <= now()->toDateTimeImmutable()) {
                    $deliverAt = now()->toDateTimeImmutable();
                }
                $key = $event->id.':'.$template->recipient_user_id.':'.$template->reminder_type.':reschedule:v'.$event->version;
                DB::table('calendar_reminder_outbox')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'event_id' => $event->id,
                    'recipient_user_id' => $template->recipient_user_id,
                    'reminder_type' => $template->reminder_type,
                    'deliver_at' => $deliverAt->format('Y-m-d H:i:s'),
                    'status' => 'pending',
                    'attempts' => 0,
                    'idempotency_key' => $key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $appointment->version++;
            $appointment->save();
            DB::table('calendar_appointment_reschedule_proposals')->where('id', $proposalId)
                ->where('tenant_id', $tenantId)->update([
                    'status' => 'accepted',
                    'responded_by_user_id' => $actor->getKey(),
                    'responded_at' => now(),
                    'updated_at' => now(),
                ]);
            $this->audit($actor, 'appointment', (string) $appointment->id, 'rescheduled', [
                'event_id' => $event->id,
                'old_starts_at' => $oldStart->format(DATE_ATOM),
                'old_ends_at' => $oldEnd->format(DATE_ATOM),
                'new_starts_at' => $newStart->format(DATE_ATOM),
                'new_ends_at' => $newEnd->format(DATE_ATOM),
                'appointment_version' => $appointment->version,
                'event_version' => $event->version,
                'proposal_id' => $proposalId,
            ]);
            $this->audit($actor, 'appointment_reschedule_proposal', $proposalId, 'accepted', [
                'appointment_id' => $appointmentId,
                'event_id' => $event->id,
            ]);
            $this->notifyAppointmentReschedule($tenantId, $appointment, $event, $proposalId, 'accepted', $participantIds);

            return $appointment->fresh()->load(['event.attendees', 'event.links']);
        }, 3);
    }

    public function declineRescheduleProposal(User $actor, string $appointmentId, string $proposalId): object
    {
        return $this->closeRescheduleProposal($actor, $appointmentId, $proposalId, 'declined');
    }

    public function withdrawRescheduleProposal(User $actor, string $appointmentId, string $proposalId): object
    {
        return $this->closeRescheduleProposal($actor, $appointmentId, $proposalId, 'withdrawn');
    }

    private function closeRescheduleProposal(User $actor, string $appointmentId, string $proposalId, string $status): object
    {
        return DB::transaction(function () use ($actor, $appointmentId, $proposalId, $status): object {
            $tenantId = $this->tenantId();
            $appointment = CalendarAppointment::query()->where('tenant_id', $tenantId)
                ->whereKey($appointmentId)->lockForUpdate()->first();
            if (!$appointment || $appointment->status !== 'confirmed') {
                throw new NotFoundHttpException('Appointment not found.');
            }
            $proposal = DB::table('calendar_appointment_reschedule_proposals')
                ->where('tenant_id', $tenantId)->where('appointment_id', $appointmentId)
                ->where('id', $proposalId)->lockForUpdate()->first();
            if (!$proposal || $proposal->status !== 'pending') {
                throw new NotFoundHttpException('Pending reschedule proposal not found.');
            }
            $allowed = $status === 'declined'
                ? (int) $proposal->counterparty_user_id === (int) $actor->getKey()
                : (int) $proposal->proposer_user_id === (int) $actor->getKey();
            if (!$allowed) {
                throw new NotFoundHttpException('Pending reschedule proposal not found.');
            }

            DB::table('calendar_appointment_reschedule_proposals')->where('id', $proposalId)
                ->where('tenant_id', $tenantId)->update([
                    'status' => $status,
                    'responded_by_user_id' => $actor->getKey(),
                    'responded_at' => now(),
                    'updated_at' => now(),
                ]);
            $event = CalendarEvent::query()->where('tenant_id', $tenantId)->whereKey($appointment->event_id)->first();
            $attendeeIds = DB::table('calendar_attendees')->where('tenant_id', $tenantId)
                ->where('event_id', $appointment->event_id)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $participantIds = array_values(array_unique(array_map('intval', [
                $appointment->requested_by_user_id, $appointment->host_user_id, ...$attendeeIds,
            ])));
            $this->audit($actor, 'appointment_reschedule_proposal', $proposalId, $status, [
                'appointment_id' => $appointmentId,
                'event_id' => $appointment->event_id,
            ]);
            if ($event) {
                $this->notifyAppointmentReschedule($tenantId, $appointment, $event, $proposalId, $status, $participantIds);
            }

            return DB::table('calendar_appointment_reschedule_proposals')->where('id', $proposalId)->first();
        }, 3);
    }

    private function notifyAppointmentReschedule(
        int|string $tenantId,
        CalendarAppointment $appointment,
        CalendarEvent $event,
        string $proposalId,
        string $action,
        array $recipientIds,
    ): void {
        DB::afterCommit(function () use ($tenantId, $appointment, $event, $proposalId, $action, $recipientIds): void {
            try {
                $tenant = Tenant::query()->find($tenantId);
                if (!$tenant) {
                    return;
                }
                $users = User::query()->whereIn('id', array_values(array_unique($recipientIds)))->get();
                if ($users->isEmpty()) {
                    return;
                }
                $titles = [
                    'proposed' => ['am' => 'አዲስ የቀጠሮ ሰዓት ቀርቧል', 'en' => 'Appointment time change proposed'],
                    'accepted' => ['am' => 'የቀጠሮ ሰዓት ተቀይሯል', 'en' => 'Appointment time changed'],
                    'declined' => ['am' => 'የቀጠሮ ለውጥ ተቀባይነት አላገኘም', 'en' => 'Appointment time change declined'],
                    'withdrawn' => ['am' => 'የቀጠሮ ለውጥ ጥያቄ ተመልሷል', 'en' => 'Appointment time change withdrawn'],
                ];
                $title = $titles[$action] ?? $titles['proposed'];
                $this->notifications->publish($tenant, $users->all(), [
                    'event_key' => 'calendar.appointment.reschedule.'.$action,
                    'category' => 'calendar',
                    'priority' => 'normal',
                    'title' => $title,
                    'body' => [
                        'am' => $event->title.' — የቀጠሮ ጊዜ ለውጥ',
                        'en' => $event->title.' — appointment schedule update',
                    ],
                    'action_route_name' => 'appointments',
                    'resource_ref' => ['type' => 'calendar_appointment', 'id' => (string) $appointment->id, 'proposal_id' => $proposalId],
                    'dedupe_key' => 'appointment-reschedule:'.$proposalId.':'.$action,
                ]);
            } catch (Throwable $error) {
                report($error);
            }
        });
    }

    private function appointmentCalendar(User $actor, int|string $tenantId, array $data): Calendar
    {
        $calendar = Calendar::query()->where('tenant_id', $tenantId)
            ->whereKey($data['calendar_id'])->where('is_active', true)->first();
        if (!$calendar || ($calendar->visibility === 'private' && (string) $calendar->owner_user_id !== (string) $actor->getKey())) {
            throw new NotFoundHttpException('Calendar not found in the active tenant.');
        }
        if (($data['appointment_scope'] ?? 'personal') === 'corporate' && $calendar->visibility !== 'tenant') {
            throw new BadRequestHttpException('Corporate appointments require a tenant-visible calendar.');
        }

        return $calendar;
    }

    private function appointmentInstants(array $data, string $timezone): array
    {
        if (!preg_match('/(?:Z|[+-]\\d{2}:?\\d{2})$/i', (string) $data['starts_at'])
            || !preg_match('/(?:Z|[+-]\\d{2}:?\\d{2})$/i', (string) $data['ends_at'])) {
            throw new BadRequestHttpException('Appointment instants must include Z or an explicit UTC offset.');
        }
        $start = CalendarInstant::fromString($data['starts_at'], $data['timezone'] ?? $timezone)->utc();
        $end = CalendarInstant::fromString($data['ends_at'], $data['timezone'] ?? $timezone)->utc();
        if ($end <= $start) {
            throw new BadRequestHttpException('Appointment end must be after its start.');
        }

        return [$start, $end];
    }

    private function appointmentParticipantIds(User $actor, array $data): array
    {
        $hostId = (int) $data['host_user_id'];
        if ($hostId === (int) $actor->getKey()) {
            throw new BadRequestHttpException('The appointment host must be a different tenant member from the requester.');
        }

        return array_values(array_unique(array_map('intval', [
            $actor->getKey(),
            $hostId,
            ...($data['attendee_user_ids'] ?? []),
        ])));
    }

    private function lockAppointmentParticipants(array $participantIds): void
    {
        DB::table('users')->whereIn('id', $participantIds)
            ->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function assertActiveAppointmentParticipants(int|string $tenantId, array $participantIds): void
    {
        $activeIds = DB::table('tenant_memberships')->where('tenant_id', $tenantId)
            ->where('status', 'active')->whereIn('user_id', $participantIds)
            ->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $missing = array_diff($participantIds, $activeIds);
        if ($missing !== []) {
            throw new BadRequestHttpException('Every appointment participant must be an active member of the current tenant.');
        }
    }

    private function assertActiveAppointmentResources(int|string $tenantId, array $resourceIds, bool $lock): void
    {
        if ($resourceIds === []) {
            return;
        }
        $query = DB::table('calendar_resources')->where('tenant_id', $tenantId)
            ->where('is_active', true)->whereIn('id', $resourceIds)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $activeIds = $query->pluck('id')->map(fn ($id): string => (string) $id)->all();
        if (count($activeIds) !== count($resourceIds)) {
            throw new NotFoundHttpException('One or more appointment resources are unavailable in the active tenant.');
        }
    }

    private function appointmentParticipantConflicts(int|string $tenantId, array $participantIds, string $startUtc, string $endUtc, ?string $excludeEventId = null): array
    {
        $participantFilter = function ($query) use ($participantIds): void {
            $query->whereIn('event.created_by', $participantIds)
                ->orWhere(function ($attendeeQuery) use ($participantIds): void {
                    $attendeeQuery->whereIn('attendee.user_id', $participantIds)
                        ->whereIn('attendee.response', ['pending', 'accepted', 'tentative']);
                });
        };

        $joinAttendees = function ($join) use ($tenantId): void {
            $join->on('attendee.event_id', '=', 'event.id')
                ->where('attendee.tenant_id', '=', $tenantId);
        };

        $timedConflicts = DB::table('calendar_events as event')
            ->leftJoin('calendar_attendees as attendee', $joinAttendees)
            ->where('event.tenant_id', $tenantId)
            ->when($excludeEventId !== null, fn ($query) => $query->where('event.id', '!=', $excludeEventId))
            ->where('event.status', 'scheduled')
            ->where('event.all_day', false)
            ->whereNotNull('event.starts_at')
            ->where('event.starts_at', '<', $endUtc)
            ->where('event.ends_at', '>', $startUtc)
            ->where($participantFilter)
            ->distinct()->pluck('event.id')->map(fn ($id): string => (string) $id)->all();

        // All-day holds are civil dates in each event's own timezone.
        // Evaluate those boundaries in PHP so SQLite/MySQL behave identically.
        $allDayEvents = DB::table('calendar_events as event')
            ->leftJoin('calendar_attendees as attendee', $joinAttendees)
            ->where('event.tenant_id', $tenantId)
            ->when($excludeEventId !== null, fn ($query) => $query->where('event.id', '!=', $excludeEventId))
            ->where('event.status', 'scheduled')
            ->where('event.all_day', true)
            ->where($participantFilter)
            ->select(['event.id', 'event.starts_on', 'event.ends_on', 'event.timezone'])
            ->distinct()->get();

        $utcZone = new \DateTimeZone('UTC');
        $startInstant = new \DateTimeImmutable($startUtc, $utcZone);
        $endInstant = new \DateTimeImmutable($endUtc, $utcZone);
        $allDayConflicts = [];
        foreach ($allDayEvents as $event) {
            $eventZone = new \DateTimeZone($event->timezone ?: 'Africa/Addis_Ababa');
            $startDate = $startInstant->setTimezone($eventZone)->format('Y-m-d');
            $endDate = $endInstant->setTimezone($eventZone)->format('Y-m-d');
            if ((string) $event->starts_on <= $endDate && (string) $event->ends_on >= $startDate) {
                $allDayConflicts[] = (string) $event->id;
            }
        }

        return array_values(array_unique([...$timedConflicts, ...$allDayConflicts]));
    }

    private function appointmentResourceConflicts(int|string $tenantId, array $resourceIds, string $startUtc, string $endUtc, ?string $excludeEventId = null): array
    {
        if ($resourceIds === []) {
            return [];
        }

        return DB::table('calendar_reservations')->where('tenant_id', $tenantId)
            ->whereIn('resource_id', $resourceIds)->where('status', 'confirmed')
            ->when($excludeEventId !== null, fn ($query) => $query->where('event_id', '!=', $excludeEventId))
            ->where('starts_at', '<', $endUtc)->where('ends_at', '>', $startUtc)
            ->distinct()->pluck('resource_id')->map(fn ($id): string => (string) $id)->all();
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
        if ($toDate->format('Y-m-d') > $fromDate->modify('+90 days')->format('Y-m-d')) {
            throw new BadRequestHttpException('Expansion window cannot exceed 90 days.');
        }

        $rule = $event->recurrence_rule;
        $frequency = $rule['frequency'] ?? '';
        $step = max(1, min(365, (int) ($rule['interval'] ?? 1)));
        $limit = max(1, min(500, (int) ($rule['count'] ?? 100)));
        $until = isset($rule['until'])
            ? (new \DateTimeImmutable($rule['until'], $zone))->setTime(23, 59, 59, 999999)
            : null;
        $exceptions = DB::table('calendar_recurrence_exceptions')
            ->where('tenant_id', $this->tenantId())->where('event_id', $eventId)
            ->get()->keyBy('original_starts_at');
        $items = [];

        if ($event->all_day) {
            $start = new \DateTimeImmutable($event->starts_on->format('Y-m-d'), $zone);
            $end = new \DateTimeImmutable($event->ends_on->format('Y-m-d'), $zone);
            $durationDays = (int) $start->diff($end)->format('%a');

            $fromCivilDate = $fromDate->format('Y-m-d');
            $toCivilDate = $toDate->format('Y-m-d');
            for ($index = 0; $index < $limit; $index++) {
                $cursor = (($rule['calendar_system'] ?? 'gregorian') === 'ethiopian')
                    ? $this->ethiopianRecurrenceOccurrenceAt($start, $frequency, $step, $index, $zone)
                    : $this->recurrenceOccurrenceAt($start, $frequency, $step, $index);
                $cursorCivilDate = $cursor->format('Y-m-d');
                if ($cursorCivilDate > $toCivilDate || ($until !== null && $cursor > $until)) break;
                $occurrenceKey = $cursor->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $exception = $exceptions->get($occurrenceKey);
                $occurrenceStart = $exception?->override_starts_at
                    ? (new \DateTimeImmutable($exception->override_starts_at, new \DateTimeZone('UTC')))->setTimezone($zone)
                    : $cursor;
                $occurrenceStartCivilDate = $occurrenceStart->format('Y-m-d');
                if ($occurrenceStartCivilDate >= $fromCivilDate && $occurrenceStartCivilDate <= $toCivilDate
                    && (!$exception || $exception->status !== 'cancelled')) {
                    $occurrenceEnd = $exception?->override_ends_at
                        ? (new \DateTimeImmutable($exception->override_ends_at, new \DateTimeZone('UTC')))->setTimezone($zone)
                        : $occurrenceStart->modify("+{$durationDays} days");
                    $items[] = [
                        'event_id' => $event->id,
                        'occurrence' => $index,
                        'occurrence_key' => $occurrenceKey,
                        'all_day' => true,
                        'starts_on' => $occurrenceStart->format('Y-m-d'),
                        'ends_on' => $occurrenceEnd->format('Y-m-d'),
                        'starts_on_ec' => $this->ethiopianDatePayload($occurrenceStart, $zone),
                        'ends_on_ec' => $this->ethiopianDatePayload($occurrenceEnd, $zone),
                        'calendar_system' => $rule['calendar_system'] ?? 'gregorian',
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
            if ($cursor > $toDate || ($until !== null && $cursor > $until)) break;
            $occurrenceKey = $cursor->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $exception = $exceptions->get($occurrenceKey);
            $occurrenceStart = $exception?->override_starts_at
                ? new \DateTimeImmutable($exception->override_starts_at, new \DateTimeZone('UTC'))
                : $cursor;
            if ($occurrenceStart >= $fromDate && $occurrenceStart <= $toDate
                && (!$exception || $exception->status !== 'cancelled')) {
                $occurrenceEnd = $exception?->override_ends_at ? new \DateTimeImmutable($exception->override_ends_at, new \DateTimeZone('UTC')) : $occurrenceStart->modify("+{$duration} seconds");
                $items[] = ['event_id' => $event->id, 'occurrence' => $index, 'occurrence_key' => $occurrenceKey,
                    'starts_at' => $occurrenceStart->setTimezone($zone)->format(DATE_ATOM), 'ends_at' => $occurrenceEnd->setTimezone($zone)->format(DATE_ATOM), 'timezone' => $event->timezone];
            }
        }

        return $items;
    }

    private function ethiopianRecurrenceOccurrenceAt(
        \DateTimeImmutable $anchor,
        string $frequency,
        int $interval,
        int $occurrence,
        \DateTimeZone $timezone,
    ): \DateTimeImmutable {
        if (!in_array($frequency, ['monthly', 'yearly'], true)) {
            return $this->recurrenceOccurrenceAt($anchor, $frequency, $interval, $occurrence);
        }

        $calendar = new EthiopianCalendar($timezone);
        $ethiopianAnchor = EthiopianDate::fromGregorian($anchor, $calendar);
        $offset = $interval * $occurrence;
        $year = $ethiopianAnchor->year;
        $month = $ethiopianAnchor->month;

        if ($frequency === 'monthly') {
            $monthIndex = (($year - 1) * 13) + ($month - 1) + $offset;
            $year = intdiv($monthIndex, 13) + 1;
            $month = ($monthIndex % 13) + 1;
        } else {
            $year += $offset;
        }

        $day = min($ethiopianAnchor->day, $calendar->daysInMonth($year, $month));
        return EthiopianDate::fromComponents($year, $month, $day)->toGregorian($calendar);
    }

    private function ethiopianDatePayload(\DateTimeInterface $date, \DateTimeZone $timezone): array
    {
        $calendar = new EthiopianCalendar($timezone);
        $ethiopian = EthiopianDate::fromGregorian($date, $calendar);

        return [
            'year' => $ethiopian->year,
            'month' => $ethiopian->month,
            'day' => $ethiopian->day,
            'iso' => $ethiopian->toIso(),
            'formatted_am' => $ethiopian->formatFull('am', $calendar),
            'formatted_en' => $ethiopian->formatFull('en', $calendar),
        ];
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
