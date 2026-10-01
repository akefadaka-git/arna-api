<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHoldingEntry;

/**
 * ShareHoldingChanged
 *
 * Domain event dispatched after a share-holding entry has been posted and
 * the holding's current-quantity projection has been updated.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by ShareHoldingService::persistEntry() (2A.14)
 * inside a `DB::afterCommit(...)` callback, after the entry row has been
 * inserted and the holding's `current_quantity` has been updated within
 * the same transaction.
 *
 * The event therefore fires:
 *
 *   - For every balance-changing operation, including subscriptions,
 *     transfers, allocations, adjustments, and reversals.
 *
 *   - Only after the transaction commits. If any invariant would be
 *     violated — a negative resulting balance (D16-R5), a fractional
 *     quantity (D6), or an unexpected entry type (D16-R8) — the service
 *     throws, the transaction rolls back, and the event never fires.
 *
 *   - Before the HTTP response is sent. The dispatch is synchronous: the
 *     registered listener (AuditShareholderEvent, 2A.35) runs in the same
 *     request and writes its audit row before the controller returns.
 *
 * If `config('shareholder.audit.emit_events')` is false, the service
 * skips the dispatch entirely.
 *
 * =============================================================================
 * What this event carries
 * =============================================================================
 * Three readonly values:
 *
 *   entry          — the persisted ShareHoldingEntry row. Carries the
 *                    signed quantity, the entry type, the reference
 *                    metadata (reference_type, reference_id, description),
 *                    the occurred_at timestamp, the actor (created_by),
 *                    and, for a reversal, the reversed_entry_id.
 *
 *   balanceBefore  — the holding's `current_quantity` immediately before
 *                    the entry was applied, as a DECIMAL(20,4) string
 *                    (e.g. "0.0000").
 *
 *   balanceAfter   — the holding's `current_quantity` immediately after
 *                    the entry was applied, as a DECIMAL(20,4) string.
 *
 * The two balance values are also present on the entry itself
 * (`ShareHoldingEntry::balance_before` and `::balance_after`), but they
 * are duplicated on the event because they are the two operands of the
 * ledger's central invariant:
 *
 *     balance_before + quantity = balance_after
 *
 * A listener that audits the transition reads them directly from the
 * event rather than dereferencing the entry. The duplication is
 * deliberate: it keeps the event self-contained for the common listener
 * use case.
 *
 * =============================================================================
 * Why the balances are strings, not numbers
 * =============================================================================
 * The balance columns are DECIMAL(20,4). PHP's float type cannot
 * represent every decimal fraction exactly at that scale, and casting to
 * float during an audit trail would silently lose precision. The event
 * therefore exposes the balances as the raw strings that came from the
 * database. A listener that performs arithmetic on them must use bcmath
 * (`bcadd`, `bccomp`, `bcsub`) or an equivalent decimal library — never
 * PHP's `+`, `-`, `==`, or `<`.
 *
 * This policy is consistent with the model layer (2A.8, 2A.9), which
 * casts the same columns as `decimal:4` (yielding strings), and with the
 * resource layer (2A.27), which emits them as strings in JSON.
 *
 * =============================================================================
 * Named-argument contract
 * =============================================================================
 * The service dispatches this event with NAMED arguments:
 *
 *     Event::dispatch(new ShareHoldingChanged(
 *         entry:         $entry,
 *         balanceBefore: $balanceBefore,
 *         balanceAfter:  $balanceAfter,
 *     ));
 *
 * PHP 8 resolves named arguments by parameter NAME, not position.
 * Therefore the constructor parameter names below MUST remain
 * `$entry`, `$balanceBefore`, `$balanceAfter` — reordering, renaming, or
 * re-typing any of them will break the dispatch at runtime with a
 * "Unknown named parameter" or "ArgumentCountError" error. Any change to
 * this signature is a Change Request.
 *
 * =============================================================================
 * Reversals
 * =============================================================================
 * A reversal is dispatched through the same event. The listener
 * distinguishes a reversal from a normal entry by inspecting the entry:
 *
 *     $event->entry->entry_type === 'reversal'
 *     $event->entry->reversed_entry_id !== null
 *
 * There is no separate `ShareHoldingReversed` event; the ledger treats a
 * reversal as one more row in the same table, and the event mirrors that
 * treatment. See 2A.14 for the reversal semantics.
 *
 * =============================================================================
 * Consumer
 * =============================================================================
 * The only registered listener in Phase 2A is `AuditShareholderEvent`
 * (2A.35), which writes a row to the platform `audit_logs` table via the
 * Core `AuditService`. The listener records:
 *
 *   - `action`      = `share_holding.changed`
 *   - `resource_type` = `ShareHoldingEntry`
 *   - `resource_id` = `$entry->id`
 *   - `new_values`  = the entry's fields plus the before/after balances
 *
 * Additional listeners can be added by a future CR.
 *
 * =============================================================================
 * No queue interface
 * =============================================================================
 * Same rationale as ShareholderRegistered (2A.31). Audit writes are
 * synchronous; the event is not `ShouldQueue`.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Events
 */
final class ShareHoldingChanged
{
    use Dispatchable;

    /**
     * The persisted ledger entry.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var ShareHoldingEntry
     */
    public readonly ShareHoldingEntry $entry;

    /**
     * The holding's current quantity immediately before this entry.
     *
     * Decimal string with four fractional digits, e.g. `"145.0000"`.
     * Listeners performing arithmetic MUST use bcmath on this value.
     *
     * @var string
     */
    public readonly string $balanceBefore;

    /**
     * The holding's current quantity immediately after this entry.
     *
     * Decimal string with four fractional digits, e.g. `"245.0000"`.
     * Listeners performing arithmetic MUST use bcmath on this value.
     *
     * @var string
     */
    public readonly string $balanceAfter;

    /**
     * Create a new event instance.
     *
     * The parameter names are part of the event's public contract — the
     * service dispatches this event with named arguments. Do not rename
     * or reorder. See the class docblock section "Named-argument contract".
     *
     * @param ShareHoldingEntry $entry          The persisted ledger entry.
     * @param string            $balanceBefore  Decimal string, four fractional digits.
     * @param string            $balanceAfter   Decimal string, four fractional digits.
     */
    public function __construct(
        ShareHoldingEntry $entry,
        string $balanceBefore,
        string $balanceAfter,
    ) {
        $this->entry         = $entry;
        $this->balanceBefore = $balanceBefore;
        $this->balanceAfter  = $balanceAfter;
    }
}