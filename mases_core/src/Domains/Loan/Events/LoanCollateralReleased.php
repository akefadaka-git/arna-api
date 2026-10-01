<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanCollateral;

/**
 * LoanCollateralReleased
 *
 * Domain event dispatched when a previously-registered collateral is
 * released by an authorized Loan-domain workflow — the moment the security
 * interest the company held over an external asset or shareholding pledge
 * is discharged and the collateral transitions to its terminal `released`
 * state.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `LoanCollateralService::release()` (A.37)
 * AFTER the transaction that transitioned the collateral's `status` from
 * `CollateralStatus::Registered` to `CollateralStatus::Released`, wrote
 * the collateral's release metadata (`released_at`, `release_reason`,
 * `released_by`), and — if applicable — performed any ancillary Wallet
 * or Shareholder-domain writes (none in Phase 2C; see §"Cross-domain
 * scope") has committed.
 *
 * Because the release is NOT tied to a state transition of the LOAN
 * itself — the loan's status is orthogonal to its collateral population's
 * lifecycle — the event is NOT routed through `LoanStateMachine::transition()`
 * (A.26). Instead, it is registered by the service directly via
 * `DB::afterCommit()`, gated on `config('loan.audit.emit_events')` (A.1,
 * default `true`). This mirrors the pattern used by
 * `LoanRepaymentService::registerAfterCommitEvent()` (A.31) for its
 * non-transition-tied events.
 *
 * Consequence: the event fires once, and only once, per successful
 * release. If the surrounding transaction rolls back — for any reason —
 * the event is never dispatched, because `DB::afterCommit()` fires only
 * after the outermost transaction commits.
 *
 * Multiple collateral releases per loan are permitted: a loan may have
 * several collateral rows registered against it over its life, and each
 * is released independently. Each successful release dispatches one
 * `LoanCollateralReleased` event.
 *
 * =============================================================================
 * The state transition — `registered → released` is the only path
 * =============================================================================
 * The frozen collateral-state enum (`CollateralStatus`, A.3) declares
 * exactly two states:
 *
 *     registered → released
 *
 * `released` is terminal. No transition from `released` exists. The
 * structural check is enforced by
 * `CollateralStatus::canTransitionTo()`, and
 * `LoanCollateralService::release()` (A.37) composes that check with its
 * permission gate, its loan-state guard (the release must not target a
 * collateral on a loan that has already been written off or is otherwise
 * terminal, unless the release itself is part of a write-off workflow),
 * and the after-commit event dispatch.
 *
 * Because the release is terminal, the event is a once-per-collateral
 * milestone: a listener registered for this event will receive exactly
 * one invocation per `LoanCollateral` row across the row's lifetime.
 *
 * =============================================================================
 * What the event carries, and the state of the models at dispatch time
 * =============================================================================
 * Four properties:
 *
 *   $loan        — the loan the released collateral was registered
 *                  against.
 *
 *   $collateral  — the `LoanCollateral` row that was released, reflecting
 *                  its post-release state.
 *
 *   $reason      — the operator's stated reason for the release.
 *
 *   $actorUserId — the user who performed the release, or null for a
 *                  system-initiated release.
 *
 * Under the frozen A.37 flow (assuming the pattern established by A.35's
 * write-off and A.46's restructure), by the time this event is
 * constructed:
 *
 *   - The collateral row's `status` has been UPDATED to
 *     `CollateralStatus::Released`.
 *
 *   - The collateral row's `released_at`, `release_reason`, and
 *     `released_by` columns have been written.
 *
 *   - Any ancillary effects (none in Phase 2C) have been performed.
 *
 * The carried `$collateral` therefore reflects the post-release state:
 * `status = Released`, `released_at` non-null, `release_reason` matching
 * the event's `$reason` parameter, `released_by` matching the event's
 * `$actorUserId` parameter (assuming A.37 passes the actor through to
 * both the model and the event).
 *
 * The carried `$loan` reflects the loan as the service found it: the
 * release operation does not modify the loan row, so no projection update
 * or status transition appears on the carried instance. The loan's
 * `status` is whatever the service found it in (typically `Active`,
 * `InArrears`, `Settled`, or `WrittenOff` — the loan's state is
 * orthogonal to its collateral's lifecycle).
 *
 * =============================================================================
 * Why the event carries the reason as a separate scalar
 * =============================================================================
 * The `$reason` string is exposed as a scalar parameter even though the
 * same value is available on `$collateral->release_reason`. The
 * duplication is deliberate:
 *
 *   1. **Symmetry with the sibling events.** `LoanRejected` (A.40) carries
 *      `$reason` separately from the loan model; `LoanRestructured`
 *      (A.46) exposes `reason()` as a delegating accessor; `LoanWrittenOff`
 *      (A.47) exposes `reason()` similarly. Making the release reason a
 *      first-class parameter on this event keeps the family consistent.
 *
 *   2. **Listener ergonomics.** A listener that only needs the reason
 *      (for a summary audit row) reads `$event->reason` without
 *      property-chain traversal.
 *
 *   3. **Immutability contract on the collateral model.** The collateral
 *      model's release metadata is mutable by the service but declared
 *      immutable at the listener's perspective. Carrying the reason
 *      separately documents that the value is authoritative at
 *      construction time, independent of any later state of the
 *      collateral instance.
 *
 * =============================================================================
 * Why the event carries the actor
 * =============================================================================
 * The reasoning follows the established pattern of `LoanRejected` (A.40),
 * `LoanInstallmentSettled` (A.43), `LoanEnteredArrears` (A.44),
 * `LoanPenaltyRecorded` (A.45), `LoanRestructured` (A.46), and
 * `LoanWrittenOff` (A.47):
 *
 *   - The loan row's actor columns (`approved_by`, `disbursed_by`,
 *     `created_by`) do not describe the release actor.
 *
 *   - The `LoanCollateral` model DOES carry a `released_by` column and a
 *     `releasedBy()` relation (per A.20). The actor is therefore
 *     recoverable from the carried `$collateral` model.
 *
 *   - There is NO state-machine history row for a collateral release.
 *     The `loan_status_histories` table (A.14) records LOAN-status
 *     transitions only; collateral-state transitions are recorded solely
 *     on the collateral row's own `released_at` / `released_by` /
 *     `release_reason` columns.
 *
 *   - The event carries `$actorUserId` EXPLICITLY so that listeners do
 *     not have to reach through the collateral model. Uniformity across
 *     the event family is worth the small duplication.
 *
 * The property is nullable, mirroring the domain-wide convention. The
 * common case is a human operator performing the release; the null case
 * would arise from a future CR that introduces an automated release
 * workflow (e.g. an automatic release after the loan is settled).
 *
 * =============================================================================
 * Cross-domain scope — no Wallet or Shareholder mutation in Phase 2C
 * =============================================================================
 * A collateral release in Phase 2C is a LOAN-DOMAIN-ONLY operation. It
 * writes the collateral row's release metadata and transitions the
 * collateral's status. It does NOT:
 *
 *   - Write to the Wallet. A collateral is NOT Wallet money (per A10),
 *     and the release does not produce or discharge any Wallet
 *     obligation, payment, or entry.
 *
 *   - Mutate the Shareholder domain. A shareholding-typed collateral
 *     references a `pledged_shareholding_id` (per A.4, B4, C3), but the
 *     Loan domain MUST NOT modify `share_holdings` or
 *     `share_holding_entries` directly. The dedicated pledge
 *     relationship is deferred to CR-2C-COLLATERAL-PLEDGE-FK-001; the
 *     Phase 2C release records the Loan-side release fact but does not
 *     touch the Shareholder ledger.
 *
 *   - Dispatch any Wallet-side or Shareholder-side events. The event is
 *     exclusively a Loan-domain audit event.
 *
 * A future Change Request that introduces a shareholding-pledge release
 * workflow through the Shareholder domain will dispatch its own
 * Shareholder-side events; this event's scope remains unchanged.
 *
 * =============================================================================
 * Why the event carries the Loan model
 * =============================================================================
 * The LOAN is the primary model carried by this event, following the
 * domain-wide convention. A listener that needs the loan's identity
 * (tenant, borrower, product) reads it from the carried instance without
 * a re-query.
 *
 * =============================================================================
 * Why the event carries the LoanCollateral model
 * =============================================================================
 * The `LoanCollateral` instance is exposed as a full model so that a
 * listener can read the complete release record without re-querying. The
 * attributes a listener is likely to need are:
 *
 *   - `collateral_type` — the classifier of the released collateral
 *     (external or shareholding);
 *   - `description`, `external_reference` — the collateral's identity;
 *   - `pledged_shareholding_id`, `pledged_quantity` — the pledge
 *     linkage, for a shareholding-typed collateral;
 *   - `valuation_amount`, `valuation_currency`, `valuation_date` — the
 *     valuation triple, for a valuated collateral;
 *   - `status` — `CollateralStatus::Released` at dispatch time;
 *   - `registered_at`, `registered_by` — the collateral's registration
 *     provenance;
 *   - `released_at`, `release_reason`, `released_by` — the collateral's
 *     release provenance.
 *
 * All are available on the model without additional I/O. The model also
 * exposes read-only helpers (`isExternal()`, `isShareholding()`,
 * `isPledgeBacked()`, `hasPledge()`, `hasValuation()`,
 * `hasReleaseMetadata()`, `valuationConsistency()`,
 * `pledgeConsistency()`) that a listener can use to classify or validate
 * the released collateral.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * All four properties are `public readonly`. A listener MUST NOT attempt
 * to mutate any of the carried models; mutations belong to the service
 * layer, and any write from a listener would run outside the service's
 * transaction boundary. The `readonly` modifier enforces this at the
 * language level.
 *
 * The model instances themselves remain mutable by their own API
 * (fill/save), because PHP's `readonly` applies to the property binding,
 * not to the object's internal state. Listeners are expected to observe,
 * not to mutate; if a future requirement demands a post-release side
 * effect that writes, the correct pattern is for the listener to call a
 * service, not to edit the event's models.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2C
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued.
 *
 * If a future Change Request makes the audit listener queued (or any
 * other listener queued), the two carried models must be serialized. The
 * convention for that scenario is to use `SerializesModels` so that
 * Eloquent's model serialization survives the queue boundary. Phase 2C
 * does not adopt that trait; a future CR would add it.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `LoanServiceProvider::registerEventListeners()` (A.85)
 * against the single `AuditLoanEvent` listener (A.51):
 *
 *     Event::listen(
 *         LoanCollateralReleased::class,
 *         AuditLoanEvent::class,
 *     );
 *
 * The listener branches on the event class via `match (true)` and writes
 * an audit row through the Core `AuditService`. A listener MUST NOT
 * propagate exceptions to the caller; per the convention established by
 * `AuditShareholderEvent` (2A.35) and `AuditWalletEvent` (2B.47), the
 * listener catches `Throwable`, logs, and returns.
 *
 * The event's audit event-type string, frozen by the manifest, is
 * `'loan.collateral.released'`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A10 (collateral rules),
 *                                                  B4 (collateral
 *                                                  integration)
 *   Phase 2C Clarification Response, 2026-09-12  — C2 (release endpoint),
 *                                                  C3 (Loan-side pledge
 *                                                  sequencing)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §3
 *   Phase 2C A.3                                 — `CollateralStatus`
 *   Phase 2C A.4                                 — `CollateralType`
 *   Phase 2C A.11                                — `loan_collaterals`
 *                                                  migration
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.20                                — `LoanCollateral` (the
 *                                                  model)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *                                                  (referenced only to
 *                                                  document that the
 *                                                  release does NOT use
 *                                                  it)
 *   Phase 2C A.37                                — `LoanCollateralService`
 *                                                  (the sole constructor
 *                                                  caller)
 *   Phase 2C A.46                                — `LoanRestructured`
 *                                                  (the structurally
 *                                                  analogous event)
 *   Phase 2C A.47                                — `LoanWrittenOff`
 *                                                  (the structurally
 *                                                  analogous event)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   CR-2C-COLLATERAL-PLEDGE-FK-001               — deferred FK
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.50                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanCollateralReleased
{
    use Dispatchable;

    /**
     * The loan the released collateral was registered against.
     *
     * The instance was constructed by `LoanCollateralService::release()`
     * (A.37) with the loan the service loaded at the start of the
     * release operation. The release does not modify the loan row, so
     * the carried instance's attributes (including `status`) are
     * whatever the service found when it loaded the loan. See the class
     * docblock §"What the event carries" for the rationale.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The `LoanCollateral` row that was released.
     *
     * The instance was constructed by `LoanCollateralService::release()`
     * (A.37) AFTER the collateral's `status` was transitioned to
     * `CollateralStatus::Released` and its release metadata
     * (`released_at`, `release_reason`, `released_by`) was written. Its
     * `status` property is therefore `CollateralStatus::Released` at
     * dispatch time; `hasReleaseMetadata()` returns `true`.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var LoanCollateral
     */
    public readonly LoanCollateral $collateral;

    /**
     * The operator's stated reason for the release.
     *
     * This is the same value that `LoanCollateralService::release()`
     * (A.37) wrote to the collateral row's `release_reason` column. The
     * value is non-empty by construction: the release operation requires
     * a reason (see the release endpoint's request validator,
     * `ReleaseCollateralRequest`, A.68), and the service enforces the
     * requirement before persisting.
     *
     * Read-only.
     *
     * @var string
     */
    public readonly string $reason;

    /**
     * The user who performed the release, or null for a system-initiated
     * release.
     *
     * The actor is also preserved on the `collateral.released_by` column;
     * the event carries it explicitly so that listeners do not have to
     * reach through the collateral model. See the class docblock §"Why
     * the event carries the actor".
     *
     * Read-only.
     *
     * @var int|null
     */
    public readonly ?int $actorUserId;

    /**
     * Create a new event instance.
     *
     * @param Loan           $loan         The loan the released
     *                                     collateral was registered
     *                                     against.
     * @param LoanCollateral $collateral   The collateral row that was
     *                                     released.
     * @param string         $reason       The operator's stated reason
     *                                     for the release. Non-empty.
     * @param int|null       $actorUserId  The user who performed the
     *                                     release, or null for a system
     *                                     release.
     */
    public function __construct(
        Loan $loan,
        LoanCollateral $collateral,
        string $reason,
        ?int $actorUserId = null,
    ) {
        $this->loan        = $loan;
        $this->collateral  = $collateral;
        $this->reason      = $reason;
        $this->actorUserId = $actorUserId;
    }

    // =========================================================================
    // Convenience accessors — loan
    // =========================================================================

    /**
     * The primary key of the loan the released collateral belongs to.
     *
     * @return int
     */
    public function loanId(): int
    {
        return (int) $this->loan->getKey();
    }

    /**
     * The tenant id under which the loan and collateral belong.
     *
     * Read directly from the loan's `tenant_id` column.
     *
     * @return int
     */
    public function tenantId(): int
    {
        return (int) $this->loan->tenant_id;
    }

    /**
     * The id of the borrower (shareholder) whose loan holds the released
     * collateral.
     *
     * Read directly from the loan's `borrower_shareholder_id` column.
     *
     * @return int
     */
    public function borrowerId(): int
    {
        return (int) $this->loan->borrower_shareholder_id;
    }

    /**
     * The id of the loan product under which the loan was originated.
     *
     * Read directly from the loan's `loan_product_id` column.
     *
     * @return int
     */
    public function loanProductId(): int
    {
        return (int) $this->loan->loan_product_id;
    }

    // =========================================================================
    // Convenience accessors — collateral
    // =========================================================================

    /**
     * The primary key of the released collateral.
     *
     * @return int
     */
    public function collateralId(): int
    {
        return (int) $this->collateral->getKey();
    }

    /**
     * The released collateral's type as a string value.
     *
     * Returns `'external'` or `'shareholding'` (the backing values of the
     * `CollateralType` enum, A.4). Provided as a scalar accessor so that
     * a listener that needs only the type identifier does not have to
     * unwrap the enum case.
     *
     * @return string
     */
    public function collateralType(): string
    {
        return $this->collateral->collateral_type->value;
    }

    /**
     * The released collateral's description.
     *
     * Read directly from the collateral's `description` column, which the
     * migration A.11 declares non-null.
     *
     * @return string
     */
    public function description(): string
    {
        return (string) $this->collateral->description;
    }

    /**
     * Whether the released collateral was an external asset.
     *
     * Delegates to `LoanCollateral::isExternal()` (A.20).
     *
     * @return bool
     */
    public function isExternal(): bool
    {
        return $this->collateral->isExternal();
    }

    /**
     * Whether the released collateral was a shareholding pledge.
     *
     * Delegates to `LoanCollateral::isShareholding()` (A.20).
     *
     * @return bool
     */
    public function isShareholding(): bool
    {
        return $this->collateral->isShareholding();
    }

    /**
     * The moment the release was recorded, in ISO 8601 form, or null if
     * the `released_at` timestamp is unset.
     *
     * Read directly from the collateral's `released_at` column. The value
     * is guaranteed to be non-null once the release has been persisted;
     * the null branch is defensive.
     *
     * @return string|null
     */
    public function releasedAt(): ?string
    {
        return $this->collateral->released_at?->toIso8601String();
    }

    // =========================================================================
    // Convenience accessors — reason and actor
    // =========================================================================

    /**
     * The operator's stated reason for the release.
     *
     * Mirrors the public readonly `$reason` property; provided so that
     * listeners that prefer a method call over a property read have a
     * consistent entry point.
     *
     * @return string
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The user who performed the release, or null for a system release.
     *
     * Mirrors the public readonly `$actorUserId` property; provided so
     * that listeners that prefer a method call over a property read have
     * a consistent entry point.
     *
     * @return int|null
     */
    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }
}