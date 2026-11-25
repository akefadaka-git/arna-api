<?php

declare(strict_types=1);

/* Emitted after commit when a Governance membership changes. */

/*
 * =============================================================================
 * A3 CANDIDATE — NOT APPLIED — NOT TESTED
 * =============================================================================
 *
 * RP-01 — audit before/after integrity (event side).
 *
 * Relative to the G2c v1.0.0 file above, this A3 candidate makes exactly
 * three additions and one version bump:
 *
 *   1. A new public readonly property `$beforeSnapshot` of type
 *      `?array` (default null), carrying the persisted pre-change
 *      attribute map captured by the dispatching service.
 *
 *   2. A new optional constructor parameter `?array $beforeSnapshot = null`
 *      that is assigned to the property.
 *
 *   3. A pointer to this A3 block in the class docblock, plus the
 *      `@version` bump to `1.1.0-a3-candidate`.
 *
 * Everything else — the `use Dispatchable;` trait, the `$membership`
 * property, both convenience accessors (`membershipId()`,
 * `tenantId()`), the class docblock body, the G2c change-verification
 * block above — is preserved byte-for-byte.
 *
 * WHY THIS IS REQUIRED
 * -----------------------------------------------------------------------------
 * In the pre-A3 behaviour, the service mutated and saved the model,
 * dispatched this event, and then called `$membership->refresh()`.
 * Laravel's `finishSave()` (called by `save()`) and `refresh()` both
 * call `syncOriginal()`, which synchronises the model's original
 * attributes to the reloaded persisted state. By the time the
 * after-commit listener read `$membership->getOriginal()`, the
 * originals already reflected the POST-change state. The listener's
 * `old_values` therefore equalled its `new_values` on every changed
 * key.
 *
 * The fix captures the pre-change attribute map with `getOriginal()`
 * in the service, immediately before the mutation, and conveys it
 * through this event as `$beforeSnapshot`. The listener prefers the
 * event's snapshot over the model's originals.
 *
 * This defect is identical to the one on the appointment side (see
 * `Events/GovernanceAppointmentUpdated.php`'s A3 CANDIDATE block for
 * the full trace), and the fix is identical. The two are a matched
 * pair; the A3 verification plan tests both under the same
 * assertions.
 *
 * WHY THE PARAMETER IS OPTIONAL
 * -----------------------------------------------------------------------------
 * The `?array $beforeSnapshot = null` default is deliberate:
 *
 *   · It preserves backward compatibility for any pre-A3 caller
 *     (tests, diagnostic scripts, queued jobs) that constructs the
 *     event with a single argument.
 *
 *   · It allows the listener to handle both the new (snapshot
 *     present) and legacy (snapshot absent) shapes without a
 *     conditional in the caller. The listener's
 *     `property_exists($event, 'beforeSnapshot') ? $event->beforeSnapshot : null`
 *     guard is the sole place the distinction is resolved.
 *
 *   · It keeps the event contract honest: an event dispatched by a
 *     code path that cannot capture a snapshot is still a valid
 *     event, and the listener degrades to the pre-A3 behaviour
 *     rather than throwing.
 *
 * A future CR that removes the `= null` default and makes the
 * argument required would force every caller to supply the snapshot.
 * That CR is not in A3 scope.
 *
 * WHY THE SNAPSHOT IS A PLAIN ARRAY, NOT A MODEL
 * -----------------------------------------------------------------------------
 * The value is the output of `$membership->getOriginal()` at the
 * moment the service is about to mutate the row — an
 * `array<string, mixed>` of raw column values with casts applied.
 * That is exactly the shape the audit listener needs in order to
 * derive `old_values` by filtering to the keys present in
 * `getChanges()`.
 *
 * Passing the model itself would not work: `getOriginal()` on the
 * model returns the POST-change state after Laravel's `finishSave()`
 * synchronises it. Passing a raw array freezes the pre-change state
 * independently of the model's lifecycle.
 *
 * COMPANION FILES IN THIS A3 BATCH
 * -----------------------------------------------------------------------------
 *   · Events/GovernanceAppointmentUpdated.php       (delivered)
 *   · Services/GovernanceAppointmentService.php     (delivered)
 *   · Events/GovernanceMembershipUpdated.php        (this file)
 *   · Services/GovernanceMembershipService.php      (delivered)
 *   · Listeners/AuditGovernanceEvent.php            (delivered)
 *
 * With this file, RP-01's event-side is complete for both entities.
 * The prior "source-shape caveat" on the fabricated membership event
 * candidate is closed: this candidate is built against the actual
 * pre-A3 source.
 *
 * VERIFICATION
 * -----------------------------------------------------------------------------
 * A3 does not permit test execution. The applicable test plan is
 * recorded in the A3 response's §5.3 and §5.4. No test was run.
 *
 * ROLLBACK
 * -----------------------------------------------------------------------------
 * Revert this file, the companion service, and the listener. No
 * schema change, no migration, no data rewrite.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.1.0-a3-candidate
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;

/**
 * GovernanceMembershipUpdated
 *
 * Domain event dispatched when a governance membership is modified
 * — whether by a field-level update, an end, a suspension, a
 * resume, a resignation, a removal, or an expiry.
 *
 * See the file-level change-verification block for the full
 * rationale on the seven-source dispatch, the derivable change-kind
 * design, the model's role as the diff carrier, and the
 * synchronous-dispatch contract.
 *
 * See the A3 CANDIDATE block above the namespace for the RP-01
 * pre-change snapshot addition introduced in this revision.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.1.0-a3-candidate
 */
final class GovernanceMembershipUpdated
{
    use Dispatchable;

    /**
     * The updated governance membership.
     *
     * The same instance the service modified and persisted. It
     * carries the post-update attribute values and — critically —
     * retains its pre-update values in `getOriginal()` and the diff
     * in `getChanges()` until the model is refreshed or reloaded.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var GovernanceMembership
     */
    public readonly GovernanceMembership $membership;

    /**
     * A3 / RP-01 — the persisted pre-change attribute map.
     *
     * Captured by the dispatching service with
     * `$membership->getOriginal()` immediately BEFORE the mutation.
     * This is the last point at which `getOriginal()` reflects the
     * pre-change state; Laravel's `finishSave()` (called by `save()`)
     * and `refresh()` both call `syncOriginal()`, which synchronises
     * the originals to the reloaded persisted state.
     *
     * The audit listener derives `old_values` from this map when it
     * is present. When null (legacy callers, non-service construction
     * paths), the listener falls back to
     * `$membership->getOriginal()` — the pre-A3 behaviour — which
     * reproduces the RP-01 defect for that call site but preserves
     * backward compatibility.
     *
     * Read-only. Listeners MUST treat the array as immutable.
     *
     * @var array<string, mixed>|null
     */
    public readonly ?array $beforeSnapshot;

    /**
     * Create a new event instance.
     *
     * @param GovernanceMembership $membership
     *        The updated, committed row.
     *
     * @param array<string, mixed>|null $beforeSnapshot
     *        The persisted pre-change attribute map captured by the
     *        dispatching service BEFORE the mutation. Null when the
     *        caller did not capture one (legacy or non-service
     *        construction paths). See the A3 CANDIDATE block above
     *        the namespace for the full rationale.
     */
    public function __construct(
        GovernanceMembership $membership,
        ?array $beforeSnapshot = null,
    ) {
        $this->membership     = $membership;
        $this->beforeSnapshot = $beforeSnapshot;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the updated governance membership.
     *
     * Convenience accessor for listeners that need only the
     * identifier. Used by the audit listener (G2c file 29 of 31) to
     * populate the `resource_id` column of the audit row.
     *
     * @return int
     */
    public function membershipId(): int
    {
        return (int) $this->membership->getKey();
    }

    /**
     * The tenant id under which the governance membership was
     * updated.
     *
     * Resolved directly from the model's `tenant_id` attribute.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $tenantId = $this->membership->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}