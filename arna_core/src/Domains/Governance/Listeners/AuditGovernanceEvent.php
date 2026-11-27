<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Governance\Listeners;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use MaaleArna\Arna\Core\Audit\Services\AuditService;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernanceAppointmentRegistered;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernanceAppointmentUpdated;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernanceBodyRegistered;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernanceBodyUpdated;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernanceMembershipRegistered;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernanceMembershipUpdated;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernancePositionRegistered;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernancePositionUpdated;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernanceTermRegistered;
use MaaleArna\Arna\Core\Domains\Governance\Events\GovernanceTermUpdated;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceTerm;
use Throwable;


/**
 * Writes audit snapshots for Governance body, position, term, appointment
 * and membership events, including registration and update lifecycle changes.
 */
final class AuditGovernanceEvent
{
    /**
     * Construct the listener with its audit dependency.
     *
     * `AuditService` is a Phase 1 singleton registered by
     * `CoreServiceProvider`. The container resolves it when the
     * listener is instantiated by the event dispatcher.
     *
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly AuditService $auditService,
    ) {
    }

    // =========================================================================
    // Entry point
    // =========================================================================

    /**
     * Handle a dispatched domain event.
     *
     * Branches on the concrete event class. Unknown event types are
     * silently ignored — Laravel will not dispatch a class that was
     * not registered, so the `default` arm is purely defensive.
     *
     * Every call is wrapped in `try`/`catch (Throwable)`. See the
     * class docblock §"Fail-open semantics" for the reasoning.
     *
     * @param  object $event
     * @return void
     */
    public function handle(object $event): void
    {
        try {
            match (true) {
                // G2a — body events.
                $event instanceof GovernanceBodyRegistered        => $this->handleGovernanceBodyRegistered($event),
                $event instanceof GovernanceBodyUpdated           => $this->handleGovernanceBodyUpdated($event),

                // G2b — position events.
                $event instanceof GovernancePositionRegistered    => $this->handleGovernancePositionRegistered($event),
                $event instanceof GovernancePositionUpdated       => $this->handleGovernancePositionUpdated($event),

                // G2b — term events.
                $event instanceof GovernanceTermRegistered        => $this->handleGovernanceTermRegistered($event),
                $event instanceof GovernanceTermUpdated           => $this->handleGovernanceTermUpdated($event),

                // G2c — appointment events.
                $event instanceof GovernanceAppointmentRegistered => $this->handleGovernanceAppointmentRegistered($event),
                $event instanceof GovernanceAppointmentUpdated    => $this->handleGovernanceAppointmentUpdated($event),

                // G2c — membership events.
                $event instanceof GovernanceMembershipRegistered  => $this->handleGovernanceMembershipRegistered($event),
                $event instanceof GovernanceMembershipUpdated     => $this->handleGovernanceMembershipUpdated($event),

                default                                            => null,
            };
        } catch (Throwable $e) {
            // Fail-open. The domain transaction has already committed.
            // Record the failure in the application log; do not re-throw.
            Log::error('AuditGovernanceEvent listener failed', [
                'event_class' => $event::class,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Body event handlers (G2a — preserved byte-for-byte)
    // =========================================================================

    /**
     * Record a governance body registration.
     *
     * @param  GovernanceBodyRegistered $event
     * @return void
     */
    private function handleGovernanceBodyRegistered(GovernanceBodyRegistered $event): void
    {
        $body = $event->body;

        $this->auditService->log(
            action: 'governance.body.registered',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceBodyRegistered::class,
            ],
            user: Auth::id(),
            tenantId: (int) $body->tenant_id,
            resourceType: 'GovernanceBody',
            resourceId: (string) $body->id,
            oldValues: null,
            newValues: $this->bodySnapshot($body),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a governance body update.
     *
     * @param  GovernanceBodyUpdated $event
     * @return void
     */
    private function handleGovernanceBodyUpdated(GovernanceBodyUpdated $event): void
    {
        $body = $event->body;

        $changes  = $event->changes;
        $original = $event->before;

        $oldValues = [];
        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $original)) {
                $oldValues[$key] = $original[$key];
            }
        }

        $this->auditService->log(
            action: 'governance.body.updated',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceBodyUpdated::class,
            ],
            user: Auth::id(),
            tenantId: (int) $body->tenant_id,
            resourceType: 'GovernanceBody',
            resourceId: (string) $body->id,
            oldValues: $oldValues,
            newValues: $changes,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    // =========================================================================
    // Position event handlers (G2b — preserved byte-for-byte)
    // =========================================================================

    /**
     * Record a governance position registration.
     *
     * @param  GovernancePositionRegistered $event
     * @return void
     */
    private function handleGovernancePositionRegistered(GovernancePositionRegistered $event): void
    {
        $position = $event->position;

        $this->auditService->log(
            action: 'governance.position.registered',
            data: [
                'source' => 'event-listener',
                'event'  => GovernancePositionRegistered::class,
            ],
            user: Auth::id(),
            tenantId: (int) $position->tenant_id,
            resourceType: 'GovernancePosition',
            resourceId: (string) $position->id,
            oldValues: null,
            newValues: $this->positionSnapshot($position),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a governance position update.
     *
     * @param  GovernancePositionUpdated $event
     * @return void
     */
    private function handleGovernancePositionUpdated(GovernancePositionUpdated $event): void
    {
        $position = $event->position;

        $changes  = $event->changes;
        $original = $event->before;

        $oldValues = [];
        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $original)) {
                $oldValues[$key] = $original[$key];
            }
        }

        $this->auditService->log(
            action: 'governance.position.updated',
            data: [
                'source' => 'event-listener',
                'event'  => GovernancePositionUpdated::class,
            ],
            user: Auth::id(),
            tenantId: (int) $position->tenant_id,
            resourceType: 'GovernancePosition',
            resourceId: (string) $position->id,
            oldValues: $oldValues,
            newValues: $changes,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    // =========================================================================
    // Term event handlers (G2b — preserved byte-for-byte)
    // =========================================================================

    /**
     * Record a governance term registration.
     *
     * @param  GovernanceTermRegistered $event
     * @return void
     */
    private function handleGovernanceTermRegistered(GovernanceTermRegistered $event): void
    {
        $term = $event->term;

        $this->auditService->log(
            action: 'governance.term.registered',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceTermRegistered::class,
            ],
            user: Auth::id(),
            tenantId: (int) $term->tenant_id,
            resourceType: 'GovernanceTerm',
            resourceId: (string) $term->id,
            oldValues: null,
            newValues: $this->termSnapshot($term),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a governance term update.
     *
     * @param  GovernanceTermUpdated $event
     * @return void
     */
    private function handleGovernanceTermUpdated(GovernanceTermUpdated $event): void
    {
        $term = $event->term;

        $changes  = $event->changes;
        $original = $event->before;

        $oldValues = [];
        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $original)) {
                $oldValues[$key] = $original[$key];
            }
        }

        $this->auditService->log(
            action: 'governance.term.updated',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceTermUpdated::class,
            ],
            user: Auth::id(),
            tenantId: (int) $term->tenant_id,
            resourceType: 'GovernanceTerm',
            resourceId: (string) $term->id,
            oldValues: $oldValues,
            newValues: $changes,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    // =========================================================================
    // Appointment event handlers (G2c — new)
    // =========================================================================

    /**
     * Record a governance appointment registration.
     *
     * @param  GovernanceAppointmentRegistered $event
     * @return void
     */
    private function handleGovernanceAppointmentRegistered(GovernanceAppointmentRegistered $event): void
    {
        $appointment = $event->appointment;

        $this->auditService->log(
            action: 'governance.appointment.registered',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceAppointmentRegistered::class,
            ],
            user: Auth::id(),
            tenantId: (int) $appointment->tenant_id,
            resourceType: 'GovernanceAppointment',
            resourceId: (string) $appointment->id,
            oldValues: null,
            newValues: $this->appointmentSnapshot($appointment),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a governance appointment update.
     *
     * The event is dispatched by the service from four methods —
     * `update()`, `activate()`, `revoke()`, and `supersede()` — all
     * under the single `governance.appointment.updated` action slug.
     * The distinguishing information is carried by the change set's
     * `status` key when the update was a lifecycle transition.
     *
     * A3 / RP-01: `old_values` is now sourced from the event's
     * `$beforeSnapshot` when present, and from the model's
     * `getOriginal()` only as a fallback for backward compatibility.
     * See the A3 CANDIDATE block above the namespace.
     *
     * @param  GovernanceAppointmentUpdated $event
     * @return void
     */
    private function handleGovernanceAppointmentUpdated(GovernanceAppointmentUpdated $event): void
    {
        $appointment = $event->appointment;

        $changes = $appointment->getChanges();

        // A3 / RP-01 — prefer the pre-change snapshot captured by the
        // dispatching service before the mutation. Fall back to the
        // model's originals for legacy events constructed without a
        // snapshot (defensive; no current call site does so).
        $original = $event->beforeSnapshot ?? $appointment->getOriginal();

        $oldValues = [];
        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $original)) {
                $oldValues[$key] = $original[$key];
            }
        }

        $this->auditService->log(
            action: 'governance.appointment.updated',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceAppointmentUpdated::class,
            ],
            user: Auth::id(),
            tenantId: (int) $appointment->tenant_id,
            resourceType: 'GovernanceAppointment',
            resourceId: (string) $appointment->id,
            oldValues: $oldValues,
            newValues: $changes,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    // =========================================================================
    // Membership event handlers (G2c — new)
    // =========================================================================

    /**
     * Record a governance membership registration.
     *
     * @param  GovernanceMembershipRegistered $event
     * @return void
     */
    private function handleGovernanceMembershipRegistered(GovernanceMembershipRegistered $event): void
    {
        $membership = $event->membership;

        $this->auditService->log(
            action: 'governance.membership.registered',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceMembershipRegistered::class,
            ],
            user: Auth::id(),
            tenantId: (int) $membership->tenant_id,
            resourceType: 'GovernanceMembership',
            resourceId: (string) $membership->id,
            oldValues: null,
            newValues: $this->membershipSnapshot($membership),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a governance membership update.
     *
     * The event is dispatched by the service from seven methods —
     * `update()`, `end()`, `suspend()`, `resume()`, `resign()`,
     * `remove()`, and `expire()` — all under the single
     * `governance.membership.updated` action slug. The distinguishing
     * information is carried by the change set's `status` key when
     * the update was a lifecycle transition. See the file-level
     * change-verification block §"The four lifecycle transitions of
     * the membership service — why they emit the `updated` slug".
     *
     * A3 / RP-01: `old_values` is now sourced from the event's
     * `$beforeSnapshot` when present, and from the model's
     * `getOriginal()` only as a fallback for backward compatibility.
     * See the A3 CANDIDATE block above the namespace.
     *
     * @param  GovernanceMembershipUpdated $event
     * @return void
     */
    private function handleGovernanceMembershipUpdated(GovernanceMembershipUpdated $event): void
    {
        $membership = $event->membership;

        $changes = $membership->getChanges();

        // A3 / RP-01 — prefer the pre-change snapshot captured by the
        // dispatching service before the mutation. Fall back to the
        // model's originals for legacy events constructed without a
        // snapshot (defensive; no current call site does so).
        $original = $event->beforeSnapshot ?? $membership->getOriginal();

        $oldValues = [];
        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $original)) {
                $oldValues[$key] = $original[$key];
            }
        }

        $this->auditService->log(
            action: 'governance.membership.updated',
            data: [
                'source' => 'event-listener',
                'event'  => GovernanceMembershipUpdated::class,
            ],
            user: Auth::id(),
            tenantId: (int) $membership->tenant_id,
            resourceType: 'GovernanceMembership',
            resourceId: (string) $membership->id,
            oldValues: $oldValues,
            newValues: $changes,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    // =========================================================================
    // Snapshot helpers — G2a + G2b (preserved byte-for-byte)
    // =========================================================================

    /**
     * Build a snapshot of a governance body's full attribute set for
     * auditing.
     *
     * @param  GovernanceBody $body
     * @return array<string, mixed>
     */
    private function bodySnapshot(GovernanceBody $body): array
    {
        return [
            'tenant_id'         => $body->tenant_id,
            'code'              => $body->code,
            'body_type'         => $body->body_type,
            'name_am'           => $body->name_am,
            'name_en'           => $body->name_en,
            'parent_body_id'    => $body->parent_body_id,
            'purpose'           => $body->purpose,
            'mandate'           => $body->mandate,
            'quorum_rule_json'  => $body->quorum_rule_json,
            'meeting_frequency' => $body->meeting_frequency,
            'reporting_body_id' => $body->reporting_body_id,
            'effective_from'    => $body->effective_from?->toDateString(),
            'effective_until'   => $body->effective_until?->toDateString(),
            'is_active'         => $body->is_active,
        ];
    }

    /**
     * Build a snapshot of a governance position's full attribute set
     * for auditing.
     *
     * @param  GovernancePosition $position
     * @return array<string, mixed>
     */
    private function positionSnapshot(GovernancePosition $position): array
    {
        return [
            'tenant_id'          => $position->tenant_id,
            'governance_body_id' => $position->governance_body_id,
            'code'               => $position->code,
            'name_am'            => $position->name_am,
            'name_en'            => $position->name_en,
            'description_am'     => $position->description_am,
            'description_en'     => $position->description_en,
            'is_voting'          => $position->is_voting,
            'is_officer'         => $position->is_officer,
            'sort_order'         => $position->sort_order,
            'is_active'          => $position->is_active,
        ];
    }

    /**
     * Build a snapshot of a governance term's full attribute set for
     * auditing.
     *
     * @param  GovernanceTerm $term
     * @return array<string, mixed>
     */
    private function termSnapshot(GovernanceTerm $term): array
    {
        return [
            'tenant_id'          => $term->tenant_id,
            'governance_body_id' => $term->governance_body_id,
            'label_am'           => $term->label_am,
            'label_en'           => $term->label_en,
            'effective_from'     => $term->effective_from?->toDateString(),
            'effective_until'    => $term->effective_until?->toDateString(),
            'notes'              => $term->notes,
            'is_active'          => $term->is_active,
        ];
    }

    // =========================================================================
    // Snapshot helpers — G2c (new)
    // =========================================================================

    /**
     * Build a snapshot of a governance appointment's full attribute
     * set for auditing.
     *
     * The snapshot excludes `id` (already the audit row's
     * `resource_id`), the timestamps, and `deleted_at`. The two date
     * columns are emitted as `YYYY-MM-DD` strings via
     * `?->toDateString()`, matching the convention the three
     * existing snapshot helpers use for their own date columns.
     *
     * @param  GovernanceAppointment $appointment
     * @return array<string, mixed>
     */
    private function appointmentSnapshot(GovernanceAppointment $appointment): array
    {
        return [
            'tenant_id'              => $appointment->tenant_id,
            'governance_body_id'     => $appointment->governance_body_id,
            'governance_position_id' => $appointment->governance_position_id,
            'governance_term_id'     => $appointment->governance_term_id,
            'instrument_type'        => $appointment->instrument_type,
            'instrument_reference'   => $appointment->instrument_reference,
            'instrument_date'        => $appointment->instrument_date?->toDateString(),
            'effective_date'         => $appointment->effective_date?->toDateString(),
            'appointment_mode'       => $appointment->appointment_mode,
            'status'                 => $appointment->status,
            'title_am'               => $appointment->title_am,
            'title_en'               => $appointment->title_en,
            'notes_am'               => $appointment->notes_am,
            'notes_en'               => $appointment->notes_en,
        ];
    }

    /**
     * Build a snapshot of a governance membership's full attribute
     * set for auditing.
     *
     * The snapshot excludes `id` (already the audit row's
     * `resource_id`), the timestamps, and `deleted_at`. The two date
     * columns are emitted as `YYYY-MM-DD` strings via
     * `?->toDateString()`.
     *
     * The snapshot records all four member-identity columns
     * (`user_id`, `external_reference`, `member_name_am`,
     * `member_name_en`) as separate keys, rather than the composite
     * string the model's `memberKey()` helper produces. See the
     * file-level change-verification block §"The two new snapshot
     * helpers" for the reasoning.
     *
     * @param  GovernanceMembership $membership
     * @return array<string, mixed>
     */
    private function membershipSnapshot(GovernanceMembership $membership): array
    {
        return [
            'tenant_id'                => $membership->tenant_id,
            'governance_body_id'       => $membership->governance_body_id,
            'governance_position_id'   => $membership->governance_position_id,
            'governance_term_id'       => $membership->governance_term_id,
            'governance_appointment_id'=> $membership->governance_appointment_id,
            'user_id'                  => $membership->user_id,
            'member_name_am'           => $membership->member_name_am,
            'member_name_en'           => $membership->member_name_en,
            'external_reference'       => $membership->external_reference,
            'start_date'               => $membership->start_date?->toDateString(),
            'end_date'                 => $membership->end_date?->toDateString(),
            'status'                   => $membership->status,
            'is_ex_officio'            => $membership->is_ex_officio,
            'ended_reason'             => $membership->ended_reason,
            'notes_am'                 => $membership->notes_am,
            'notes_en'                 => $membership->notes_en,
        ];
    }

    // =========================================================================
    // Request-id resolution (G2a — preserved byte-for-byte)
    // =========================================================================

    /**
     * Resolve the request id for the current request, if available.
     *
     * Priority:
     *   1. `X-Request-ID` header (set by upstream proxies / clients).
     *   2. `X-Correlation-ID` header (alternative common name).
     *   3. A freshly generated UUID v4.
     *
     * Mirrors the resolution order used by the canonical error
     * renderer in `bootstrap/app.php` and by the platform's other
     * audit listeners.
     *
     * @return string
     */
    private function resolveRequestId(): string
    {
        $request = request();

        foreach (['X-Request-ID', 'X-Correlation-ID'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}