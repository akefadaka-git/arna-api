<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * ShareholderUpdated
 *
 * Domain event dispatched after a Shareholder row has been modified and
 * the change committed.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by ShareholderService (2A.12) from every mutating
 * method except `register()`:
 *
 *   - `update()`                 — a general field update.
 *   - `deactivate()`             — sets `is_active = false`.
 *   - `reactivate()`             — sets `is_active = true`.
 *   - `assignRepresentative()`   — sets or changes `representative_user_id`.
 *   - `clearRepresentative()`    — nulls `representative_user_id` on an
 *                                  individual shareholder.
 *
 * Each dispatch is wrapped in `DB::afterCommit(...)`, so the event fires
 * only if the transaction commits. If the transaction rolls back — for
 * example, because a validation check fails inside the service — the
 * event never fires.
 *
 * The event fires ONCE per successful mutation. A service call that
 * modifies two columns of the same row dispatches one event, not one per
 * column. A caller that invokes `deactivate()` and then `assignRepresentative()`
 * as separate service calls dispatches two events.
 *
 * =============================================================================
 * What this event carries
 * =============================================================================
 * A single readonly property: the updated Shareholder model.
 *
 * The model is the same instance the service modified and saved. It
 * carries the post-update attribute values. It does NOT carry the
 * pre-update values as a separate payload — those are still accessible
 * via the model's `getOriginal()` method at the time the event fires,
 * because the model retains its original attributes in memory until it is
 * refreshed or re-loaded.
 *
 * For a listener that needs to record the change in the audit trail:
 *
 *   $before = $event->shareholder->getOriginal();
 *   $after  = $event->shareholder->getAttributes();
 *   $changed = $event->shareholder->getChanges();
 *
 * The `getChanges()` array is the minimal set of attributes that differ
 * between the original and current state — the exact diff for this
 * mutation. This is what the audit listener (2A.35) uses to populate the
 * `old_values` and `new_values` columns of the audit row.
 *
 * IMPORTANT: `getOriginal()` and `getChanges()` are only meaningful while
 * the model is in the state the service left it in. If any code between
 * the `save()` and the event dispatch touches the model — for example, an
 * eager-load that refreshes attributes — the original/changed semantics
 * may be lost. The service does not do this; listeners should not either.
 *
 * =============================================================================
 * Why no change-kind discriminator
 * =============================================================================
 * A caller might reasonably want to know "was this a representative
 * change, a status change, or a name change?". The event does not encode
 * that answer, because it is derivable:
 *
 *   - Representative change → `in_array('representative_user_id', $changed)`.
 *   - Status change         → `in_array('is_active', $changed)`.
 *   - Name change           → any of the six name columns in `$changed`.
 *
 * Encoding the change kind as a separate property would create a second
 * source of truth for the same fact and would force the service to
 * compute it (adding coupling). Leaving it derivable keeps the event
 * payload minimal.
 *
 * =============================================================================
 * Consumer
 * =============================================================================
 * The only registered listener in Phase 2A is `AuditShareholderEvent`
 * (2A.35), which writes a row to the platform `audit_logs` table via the
 * Core `AuditService`. It distinguishes this event from the other three
 * domain events by class and reads the model's change set to populate the
 * audit row's `old_values` and `new_values` columns.
 *
 * Additional listeners can be added by a future CR without modifying
 * this file.
 *
 * =============================================================================
 * No queue interface
 * =============================================================================
 * Same rationale as ShareholderRegistered (2A.31): audit writes are
 * synchronous by design. Making the event queueable would require a
 * serialization strategy for the model reference and a decision about
 * behaviour when the row changes between dispatch and processing. If a
 * future phase requires that, it is a CR.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Events
 */
final class ShareholderUpdated
{
    use Dispatchable;

    /**
     * The updated shareholder.
     *
     * Read-only. Listeners MUST treat the model as immutable. The model
     * carries both the current attribute values and the original
     * attribute values (`getOriginal()`) so a listener can compute the
     * diff for this mutation.
     *
     * @var Shareholder
     */
    public readonly Shareholder $shareholder;

    /**
     * Create a new event instance.
     *
     * @param Shareholder $shareholder  The updated, committed row.
     */
    public function __construct(Shareholder $shareholder)
    {
        $this->shareholder = $shareholder;
    }
}