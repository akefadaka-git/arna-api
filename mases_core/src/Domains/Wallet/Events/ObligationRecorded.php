<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;

/**
 * ObligationRecorded
 *
 * Domain event dispatched when an obligation is recorded against a
 * wallet.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * `ObligationService::record()` (2B.16) dispatches this event after the
 * transaction that created the obligation has committed, via
 * `DB::afterCommit()`. The single call site is the `store()` action of
 * `ObligationController` (2B.27), which handles route #5 of Step 7
 * v1.1.0 §C.1 (`POST /wallets/{wallet}/obligations`).
 *
 * The event is gated on `config('wallet.audit.emit_events')` (2B.1). When
 * the config is false, the obligation and its ledger entry are still
 * written; only the event dispatch is suppressed. See the class docblock
 * of `ObligationService` §"Transaction and event discipline".
 *
 * =============================================================================
 * What the event carries
 * =============================================================================
 * One property:
 *
 *     $obligation  — the newly-created Obligation model.
 *
 * The obligation is hydrated and persisted at the time the event is
 * constructed. `ObligationService::record()` calls
 * `$obligation->refresh()` before instantiating this event, so every
 * attribute reflects the row as committed — including the
 * database-assigned `id`, the `outstanding_amount` initialized to equal
 * `original_amount`, and the `occurred_at` timestamp written by the
 * service.
 *
 * =============================================================================
 * The event is about the obligation, not the ledger entry
 * =============================================================================
 * Recording an obligation produces TWO rows inside one transaction:
 *
 *   1. An `Obligation` row (2B.6) — the scoped business aggregate.
 *   2. A `WalletEntry` row (2B.10) of type `obligation_recorded`,
 *      written by `WalletService::recordEntry()` (2B.15) via the
 *      service's pre-flight call.
 *
 * This event carries the OBLIGATION, not the ledger entry. The
 * distinction is deliberate:
 *
 *   - The obligation is the business fact the caller asked for; the
 *     ledger entry is the wallet-domain's internal record of the monetary
 *     movement. A listener that audits "what obligations were recorded?"
 *     wants the obligation.
 *
 *   - The ledger entry is reachable from the obligation through the
 *     `wallet_entries.reference_type = 'Obligation'` + `reference_id =
 *     obligation.id` pair (Architecture §5.3). A listener that needs the
 *     ledger entry queries for it by reference. This keeps the event
 *     payload the smallest unit that satisfies the audit intent.
 *
 *   - If a future CR requires a dedicated event for the ledger movement
 *     (e.g. an `EntryPosted` event dispatched by `recordEntry()` itself),
 *     it is added without changing this event's shape.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `Obligation` model as a public readonly
 * property. A listener that needs only the id reads
 * `$event->obligation->id`; a listener that needs the component, the
 * amount, or any other attribute reads the corresponding property. The
 * alternative — carrying only scalar primitives — would force every
 * listener to re-query the row, defeating the purpose of dispatching the
 * event after commit.
 *
 * Per the Event convention (§2.9), constructor parameters are models or
 * scalar primitives, never arrays of arrays; the model is the natural
 * unit of interest here.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$obligation` is `public readonly`. A listener MUST NOT attempt to
 * mutate the obligation through this reference; mutations belong to the
 * service layer, and the model's `booted()` guard (2B.6
 * §"Immutability") rejects mutations of the five immutable facts
 * (component, currency, original_amount, occurred_at, wallet_id)
 * anyway. The `readonly` modifier enforces the binding-level constraint
 * at the language level.
 *
 * A listener that needs to record a correction writes a NEW obligation
 * plus a reversal of the ledger entry — it does not edit this one.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2B
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued. If a future CR makes the audit
 * listener queued, the trait's `SerializesModels` behaviour applies
 * automatically to the `$obligation` property.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `WalletServiceProvider` (2B.2):
 *
 *     Event::listen(
 *         ObligationRecorded::class,
 *         AuditWalletEvent::class,
 *     );
 *
 * The listener, `AuditWalletEvent` (2B.47), branches on the event class
 * via `match (true)` and writes an audit row through the Core
 * `AuditService`. A listener MUST NOT propagate exceptions to the
 * caller; the listener catches `Throwable` and logs, per the Listener
 * convention (§2.10).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D109                         — Obligation types
 *   Architecture v1.2.0 §3.3    — Obligation entity
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §14.1   — Event inventory (ObligationRecorded)
 *   Step 5 (manifest) 2B.41     — This file
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #5)
 *   §2.9 (conventions)          — Event conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Events
 * @version 1.0.0
 */
final class ObligationRecorded
{
    use Dispatchable;

    /**
     * The obligation that was recorded.
     *
     * Hydrated and persisted at the time the event is constructed — the
     * service has already run `$obligation->refresh()` before this event
     * is instantiated, so every attribute reflects the row as committed.
     *
     * @var Obligation
     */
    public readonly Obligation $obligation;

    /**
     * Construct the event.
     *
     * The constructor takes the persisted `Obligation` instance. It
     * performs no validation and no side effects; the service has
     * already established every invariant that governs recording before
     * reaching this point.
     *
     * @param Obligation $obligation  The newly-recorded obligation.
     */
    public function __construct(Obligation $obligation)
    {
        $this->obligation = $obligation;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the recorded obligation.
     *
     * Convenience accessor for listeners that need only the identifier.
     * Equivalent to `$event->obligation->id`, but expresses intent at
     * the call site.
     *
     * @return int
     */
    public function obligationId(): int
    {
        return (int) $this->obligation->getKey();
    }

    /**
     * The id of the wallet the obligation belongs to.
     *
     * Read directly from the obligation's `wallet_id` column, which is
     * an immutable fact of the row (2B.6 §"Immutability"). No query is
     * required.
     *
     * @return int
     */
    public function walletId(): int
    {
        return (int) $this->obligation->wallet_id;
    }

    /**
     * The tenant id under which the obligation was recorded.
     *
     * Resolved transitively through the obligation's parent wallet:
     * `obligation.wallet_id → wallet.tenant_id`. The `Obligation` model
     * does not carry a `tenant_id` column of its own (2B.6 §"What this
     * model deliberately does NOT do" — tenant scope is inherited through
     * the wallet).
     *
     * Returns `null` if the wallet relation is not loaded and cannot be
     * resolved. In every legitimate Phase 2B dispatch, the relation is
     * reachable: `ObligationService::record()` holds the `Wallet`
     * instance in hand when it constructs this event, and the
     * obligation's `wallet_id` points at it. A listener that needs a
     * non-null tenant should load the relation first
     * (`$event->obligation->loadMissing('wallet')`) or handle the null
     * case, mirroring the pattern on the 2A `CertificateIssued` event
     * (whose `tenantId()` is likewise nullable by transitive
     * resolution).
     *
     * Contrast with `WalletOpened::tenantId()` (2B.40), which returns
     * `int` unconditionally because the `Wallet` model carries
     * `tenant_id` directly. The nullability here reflects the
     * obligation's transitive scope, not a data anomaly.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $wallet = $this->obligation->wallet;

        if ($wallet === null) {
            return null;
        }

        return (int) $wallet->tenant_id;
    }

    /**
     * The economic component of the recorded obligation.
     *
     * Read directly from the obligation's `component` column, which the
     * model casts to the `ObligationComponent` enum (2B.6 §"Enum cast").
     * The component is an immutable fact of the row and is set at
     * creation time (2B.16 §"record").
     *
     * Provided as a convenience for listeners that audit obligations by
     * category without inspecting the model. Equivalent to
     * `$event->obligation->component`.
     *
     * @return ObligationComponent
     */
    public function component(): ObligationComponent
    {
        return $this->obligation->component;
    }
}