<?php

declare(strict_types=1);

/* Emitted after commit when a Governance appointment is registered. */
namespace MaaleArna\Arna\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceAppointment;

/**
 * GovernanceAppointmentRegistered
 *
 * Domain event dispatched when a governance appointment is
 * registered.
 *
 * See the file-level change-verification block for the full
 * rationale on when the event fires, what it carries, what it
 * deliberately omits, and why it is dispatched synchronously rather
 * than queued.
 *
 * @package MaaleArna\Arna\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernanceAppointmentRegistered
{
    use Dispatchable;

    /**
     * The registered governance appointment.
     *
     * Hydrated and persisted at the time the event is constructed.
     * Every attribute reflects the row as committed — with `status`
     * forced to `'draft'` by the service, regardless of what the
     * caller supplied.
     *
     * Read-only. Listeners MUST treat the model as immutable — any
     * modification is visible to subsequent listeners and to the
     * caller. If a listener needs a modified copy, it should clone.
     *
     * @var GovernanceAppointment
     */
    public readonly GovernanceAppointment $appointment;

    /**
     * Create a new event instance.
     *
     * The constructor takes the persisted `GovernanceAppointment`
     * instance. It performs no validation and no side effects; the
     * service has already established every invariant that governs
     * registration before reaching this point.
     *
     * @param GovernanceAppointment $appointment  The newly-created,
     *                                            committed row.
     */
    public function __construct(GovernanceAppointment $appointment)
    {
        $this->appointment = $appointment;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the registered governance appointment.
     *
     * Convenience accessor for listeners that need only the
     * identifier. Equivalent to `$event->appointment->id`, but
     * expresses intent at the call site and tolerates a caller that
     * has not yet typed the property.
     *
     * Used by the audit listener (G2c file 29 of 31) to populate the
     * `resource_id` column of the audit row.
     *
     * @return int
     */
    public function appointmentId(): int
    {
        return (int) $this->appointment->getKey();
    }

    /**
     * The tenant id under which the governance appointment was
     * registered.
     *
     * Resolved directly from the model's `tenant_id` attribute — a
     * non-nullable integer column on `governance_appointments`. The
     * nullable return type reflects the theoretical case where a
     * caller constructs an event manually with an unsaved model
     * whose `tenant_id` has not been set.
     *
     * Used by the audit listener (G2c file 29 of 31) to populate the
     * `tenant_id` column of the audit row.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $tenantId = $this->appointment->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}