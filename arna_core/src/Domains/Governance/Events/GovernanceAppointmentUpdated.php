<?php

declare(strict_types=1);

/* Emitted after commit when a Governance appointment is updated or its state changes. */
namespace MaaleArna\Arna\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceAppointment;

/**
 * GovernanceAppointmentUpdated
 *
 * Domain event dispatched when a governance appointment is modified
 * — whether by a field-level update, an activation, a revocation, or
 * a supersession.
 *
 * See the file-level change-verification block for the full
 * rationale on the four-source dispatch, the derivable change-kind
 * design, the model's role as the diff carrier, and the
 * synchronous-dispatch contract.
 *
 * @package MaaleArna\Arna\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernanceAppointmentUpdated
{
    use Dispatchable;

    /**
     * The updated governance appointment.
     *
     * The same instance the service modified and persisted. It
     * carries the post-update attribute values and — critically —
     * retains its pre-update values in `getOriginal()` and the diff
     * in `getChanges()` until the model is refreshed or reloaded.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var GovernanceAppointment
     */
    public readonly GovernanceAppointment $appointment;
    /**
     * Persisted attributes captured before the appointment mutation.
     * Null for legacy callers that do not supply a snapshot.
     *
     * @var array<string, mixed>|null
     */
    public readonly ?array $beforeSnapshot;

    /**
     * Create a new event instance.
     *
     * @param GovernanceAppointment $appointment  The updated,
     *                                            committed row.
     */
    public function __construct(
        GovernanceAppointment $appointment,
        ?array $beforeSnapshot = null,
    ) {
        $this->appointment = $appointment;
        $this->beforeSnapshot = $beforeSnapshot;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the updated governance appointment.
     *
     * Convenience accessor for listeners that need only the
     * identifier. Used by the audit listener (G2c file 29 of 31) to
     * populate the `resource_id` column of the audit row.
     *
     * @return int
     */
    public function appointmentId(): int
    {
        return (int) $this->appointment->getKey();
    }

    /**
     * The tenant id under which the governance appointment was
     * updated.
     *
     * Resolved directly from the model's `tenant_id` attribute.
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