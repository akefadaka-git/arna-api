<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PaymentAllocationReversalResource
 *
 * Serializes a `PaymentAllocationReversal` model into the API's
 * allocation-reversal payload.
 *
 * =============================================================================
 * Envelope responsibility
 * =============================================================================
 * `$wrap = null` — the resource does NOT wrap its output in a `data` key.
 * The controller is the sole envelope provider, producing:
 *
 *     { "success": true, "data": <this resource's array> }
 *
 * The resource itself never emits `success` or `data`.
 *
 * =============================================================================
 * Field order (Architecture conventions §2.7)
 * =============================================================================
 * Fields are emitted in a fixed order:
 *
 *   1. Identity          — id, wallet_id, payment_allocation_id
 *   2. Economic fact     — reversed_amount
 *   3. Reason            — reason
 *   4. Business time     — reversed_at
 *   5. Actor             — reversed_by
 *   6. Conditional rels  — wallet, payment_allocation, reversed_by_user
 *   7. Timestamps        — created_at, updated_at
 *
 * The order is presentation-oriented. JSON objects are unordered in the
 * specification, but keeping a stable order in the source makes the
 * payload easy to review and diff.
 *
 * =============================================================================
 * No derived flags
 * =============================================================================
 * Consistent with `PaymentAllocationResource` (2B.34) and unlike
 * `ObligationResource` (2B.32) or `PaymentResource` (2B.33), this
 * resource emits NO derived projection flags.
 *
 * Rationale: a `PaymentAllocationReversal` has no projection column. It
 * is a fully immutable correction record (2B.9 §"Full immutability" —
 * every attribute is an identity or an economic fact that must never
 * change). There is no state to classify.
 *
 * The reversal's contribution to the parent allocation's effective
 * allocated amount is simply its `reversed_amount`; it is already
 * emitted as a first-class field. The aggregate effective amount of the
 * parent allocation is the sum of its `amount` minus the sum of its
 * reversals, but computing that sum on a per-reversal resource would
 * require an N+1 pattern; the parent projection columns already reflect
 * the effective state (see 2B.34 §"No derived flags").
 *
 * =============================================================================
 * The `reason` field
 * =============================================================================
 * `reason` is REQUIRED by the service (2B.18 §"assertReasonIsProvided")
 * and NOT NULL in the schema (2B.53). It is the metadata that
 * distinguishes a reversal from a mere mutation (Architecture §3.6). It
 * is emitted as a string, unconditionally.
 *
 * The reason is free text; no translation or label substitution is
 * applied. It is consumer-supplied content, not a controlled vocabulary.
 *
 * =============================================================================
 * Conditional relations (Step 7 v1.1.0 §A.4, F-2B-LIST-1)
 * =============================================================================
 * The resource embeds three relations ONLY when the caller has
 * eager-loaded them, via `whenLoaded()`:
 *
 *   - `wallet`                — the owning wallet.
 *   - `payment_allocation`    — the parent allocation being corrected.
 *   - `reversed_by_user`      — the recording user (nullable relation).
 *
 * Each key is OMITTED when the relation is not loaded. A caller that
 * receives a payload without the `payment_allocation` key knows the
 * relation was not loaded; a caller that receives the nested payload
 * knows it was. See 2B.31 §"Conditional relations" for the extended
 * rationale on the omitted-vs-empty distinction.
 *
 * The 2B controllers (2B.26–2B.30) do NOT eager-load any of these three
 * relations on the allocation reverse action. They are present in the
 * resource so that a future CR, a test, or an artisan script can load
 * and expose them without modifying this class.
 *
 * NOTE on nesting depth: the `payment_allocation` relation resolves to
 * a `PaymentAllocationResource` (2B.34), which itself can embed a
 * `reversals` collection containing THIS resource. The recursion is
 * bounded by what the caller eager-loads — a caller that loads
 * `reversal.paymentAllocation.reversals` on a reversal of a reversal
 * would descend one level and stop, because `PaymentAllocationReversal`
 * has no `reversals` relation (a reversal is never itself reversed;
 * Architecture §6.5 invariant 4). The recursion cannot be infinite.
 * Callers that do not need the parent allocation simply do not load it,
 * and the key is omitted.
 *
 * =============================================================================
 * Actor serialization
 * =============================================================================
 * The `reversed_by_user` key is emitted via `whenLoaded('reversedBy')`
 * and returns a MINIMAL inline payload — `{ id, email, name }` — rather
 * than a nested Resource class.
 *
 * Rationale: identical to the reasoning documented in 2B.33
 * §"Actor serialization". The `PaymentAllocationReversal::reversedBy()`
 * relation (2B.9) returns a `User` from
 * `MaaleArna\MASES\Core\Auth\Models\User`; the 2B manifest contains no
 * `UserResource`; the three emitted fields are the actor's identity
 * surface; a future CR can replace the inline payload with a
 * `UserResource` in a single line.
 *
 * The `reversed_by` integer column is nullable at the schema level
 * (2B.9 §"reversedBy"), but the Phase 2B service always requires a user
 * on every reversal call. The nullable path exists for forward
 * compatibility with future automated correction workflows; the resource
 * emits `null` for `reversed_by` and omits `reversed_by_user` if the
 * relation was not loaded, which is the correct posture for both cases.
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * Every value emitted by this resource comes from a property or a
 * relation already present on the model. There are no `Model::find(...)`
 * calls, no `->load(...)` calls, and no lazy-loaded relation accesses.
 * Each relation read is guarded by `whenLoaded()`, which fires its
 * closure only when the relation is already present on the model.
 *
 * =============================================================================
 * Formatting choices
 * =============================================================================
 *   - `reversed_amount` is emitted as the string from the model's
 *     `decimal:4` cast. No numeric formatting is applied; consumer
 *     presentation is a UI concern.
 *
 *   - `reversed_at`, `created_at`, and `updated_at` are emitted as ISO
 *     8601 strings via `->toIso8601String()`. This matches the
 *     convention established by every other resource in the domain
 *     (2B.31–2B.34) and by the statement service (2B.19 §"generate").
 *
 *   - The resource emits NO `currency` field. A
 *     `PaymentAllocationReversal` has no currency column (2B.9 §"No
 *     currency column"); the currency of a reversal is unambiguously the
 *     wallet's currency (Q8), inherited through the reversal's ancestry
 *     (reversal → allocation → payment/obligation → wallet). See 2B.34
 *     §"Formatting choices" for the extended rationale.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does not embed the parent allocation's full payload by default.
 *     The `payment_allocation` relation is exposed as a nested
 *     `PaymentAllocationResource` (2B.34) WHEN loaded, not by default.
 *
 *   - It does not embed the parent wallet's full payload by default.
 *     The `wallet` relation is exposed as a nested `WalletResource`
 *     (2B.31) WHEN loaded, not by default.
 *
 *   - It does not embed ledger entries. Allocation reversals do NOT
 *     produce ledger entries (Architecture §5.1); the wallet's ledger is
 *     untouched by the reversal action. There is no ledger row to
 *     embed.
 *
 *   - It does not emit bilingual name fields. The reversal has none
 *     (Q7 forbids them in 2B transactional tables); the `reason` field
 *     is free text and is emitted as-is.
 *
 *   - It does not emit a `reversed_by` relation by default. The integer
 *     id is emitted unconditionally (as null when absent); the nested
 *     payload is emitted only when the relation is loaded.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.6    — PaymentAllocationReversal entity (A1)
 *   Architecture v1.2.0 §5.1    — Allocations and reversals do not
 *                                 produce ledger entries
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.5    — Allocation-reversal invariants
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §19     — Q8 resolution (currency)
 *   Step 5 (manifest) 2B.35     — This file
 *   Step 7 v1.1.0 §A.4          — F-2B-LIST-1 resolution (whenLoaded)
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure
 *   §2.7 (conventions)          — Resource conventions
 *   §2.14 (conventions)         — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Resources
 * @version 1.0.0
 */
final class PaymentAllocationReversalResource extends JsonResource
{
    /**
     * Disable the resource's default `data` wrapping.
     *
     * The controller provides the envelope (`{ success, data }`). See the
     * class docblock §"Envelope responsibility".
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * The `$request` parameter is required by the `JsonResource` contract
     * but is not used by this resource — no input-dependent fields exist
     * on the reversal payload. It is retained for interface compliance
     * and for the day a request-aware field (e.g. a `can` block) is
     * added by a future CR.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // -----------------------------------------------------------------
            // Identity.
            //
            // The two foreign keys identify the owning wallet and the
            // parent allocation this reversal corrects. They are always
            // present — they are columns on the row itself, not relations.
            // -----------------------------------------------------------------
            'id'                    => (int) $this->id,
            'wallet_id'             => (int) $this->wallet_id,
            'payment_allocation_id' => (int) $this->payment_allocation_id,

            // -----------------------------------------------------------------
            // Economic fact.
            //
            // `reversed_amount` is the POSITIVE decimal string recorded by
            // the service. No `currency` field — a
            // `PaymentAllocationReversal` has no currency column; see the
            // class docblock §"Formatting choices".
            // -----------------------------------------------------------------
            'reversed_amount' => (string) $this->reversed_amount,

            // -----------------------------------------------------------------
            // Reason (required by the service and by the schema).
            //
            // Free text; no translation or label substitution. See the
            // class docblock §"The reason field".
            // -----------------------------------------------------------------
            'reason' => (string) $this->reason,

            // -----------------------------------------------------------------
            // Business time.
            // -----------------------------------------------------------------
            'reversed_at' => $this->reversed_at?->toIso8601String(),

            // -----------------------------------------------------------------
            // Actor.
            //
            // The integer id, always present (nullable). The full nested
            // payload is exposed under `reversed_by_user` when the
            // relation is loaded — see the conditional relations block
            // below.
            // -----------------------------------------------------------------
            'reversed_by' => $this->reversed_by !== null
                ? (int) $this->reversed_by
                : null,

            // -----------------------------------------------------------------
            // Conditional relations.
            //
            // Each key is present only when the relation was eager-loaded.
            // When absent, the key is OMITTED from the payload.
            //
            // The `reversed_by_user` key name is deliberately distinct
            // from the `reversed_by` integer field above: one is the
            // actor's id, the other is the actor's full nested payload.
            // -----------------------------------------------------------------
            'wallet' => $this->whenLoaded(
                'wallet',
                fn () => new WalletResource($this->wallet),
            ),

            'payment_allocation' => $this->whenLoaded(
                'paymentAllocation',
                fn () => new PaymentAllocationResource($this->paymentAllocation),
            ),

            'reversed_by_user' => $this->whenLoaded(
                'reversedBy',
                fn () => [
                    'id'    => (int) $this->reversedBy->id,
                    'email' => (string) $this->reversedBy->email,
                    'name'  => (string) $this->reversedBy->name,
                ],
            ),

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            // -----------------------------------------------------------------
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}