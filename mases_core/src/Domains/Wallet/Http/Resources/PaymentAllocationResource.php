<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PaymentAllocationResource
 *
 * Serializes a `PaymentAllocation` model into the API's allocation
 * payload.
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
 *   1. Identity          — id, wallet_id, payment_id, obligation_id
 *   2. Economic fact     — amount
 *   3. Business time     — allocated_at
 *   4. Actor             — allocated_by
 *   5. Policy slot       — allocation_policy_version_id
 *   6. Conditional rels  — wallet, payment, obligation,
 *                          allocated_by_user, allocation_policy,
 *                          reversals
 *   7. Timestamps        — created_at, updated_at
 *
 * The order is presentation-oriented. JSON objects are unordered in the
 * specification, but keeping a stable order in the source makes the
 * payload easy to review and diff.
 *
 * =============================================================================
 * No derived flags (contrast with 2B.32 and 2B.33)
 * =============================================================================
 * Unlike `ObligationResource` (2B.32) and `PaymentResource` (2B.33),
 * this resource emits NO derived projection flags.
 *
 * Rationale: a `PaymentAllocation` has no projection column. It is a
 * fully immutable attribution record (2B.8 §"Immutability" — every
 * attribute is an identity or an economic fact that must never change).
 * There is no `is_open`-style classification to emit because there is no
 * projection state to classify.
 *
 * The allocation's EFFECTIVE allocated amount — `amount` minus the sum
 * of its reversals — is NOT emitted as a derived field either. Computing
 * it would require summing across the `reversals` collection on every
 * request, producing an N+1 pattern for a caller that loads a page of
 * allocations. The projection-cached values on the parent payment
 * (`unapplied_amount`) and parent obligation (`outstanding_amount`)
 * already reflect the effective state; a consumer that needs the
 * effective amount of a specific allocation reads the parent projections
 * or queries the reversals collection explicitly. See 2B.14
 * §"What this repository deliberately does NOT do" for the extended
 * rationale.
 *
 * =============================================================================
 * Allocation policy reference
 * =============================================================================
 * `allocation_policy_version_id` is emitted as an integer or null. It
 * records the specific `VersionedPolicy` version that governed — or
 * would have governed — this allocation. Phase 2B does NOT evaluate the
 * policy; the reference is historical metadata (Architecture §10).
 *
 * The full policy payload is NOT embedded by default. A caller that
 * needs it loads the `allocationPolicy` relation; when loaded, the
 * relation is exposed under the `allocation_policy` key as an inline
 * payload with the policy's identity surface — `{ id, policy_type,
 * version, effective_from, effective_until }` — rather than a nested
 * Resource class, mirroring the actor pattern (2B.33 §"Actor
 * serialization"). There is no `VersionedPolicyResource` in the 2B
 * manifest; the inline payload is the correct expression until a future
 * CR adds one.
 *
 * Type compatibility (that the referenced policy's `policy_type` is
 * `'allocation'`) is NOT verified by the FK; it is a future
 * policy-attachment-service concern (Architecture §8.6). The inline
 * payload emits `policy_type` so that a consumer can verify compatibility
 * if it needs to.
 *
 * =============================================================================
 * Conditional relations (Step 7 v1.1.0 §A.4, F-2B-LIST-1)
 * =============================================================================
 * The resource embeds six relations ONLY when the caller has eager-loaded
 * them, via `whenLoaded()`:
 *
 *   - `wallet`              — the owning wallet.
 *   - `payment`             — the payment being applied.
 *   - `obligation`          — the obligation being settled.
 *   - `allocated_by_user`   — the recording user (nullable relation).
 *   - `allocation_policy`   — the policy version, when one is attached.
 *   - `reversals`           — the reversals recorded against this
 *                             allocation.
 *
 * Each key is OMITTED when the relation is not loaded. A caller that
 * receives a payload without the `reversals` key knows the relation was
 * not loaded; a caller that receives `reversals: []` knows the relation
 * was loaded and is empty. See 2B.31 §"Conditional relations" for the
 * extended rationale on the omitted-vs-empty distinction.
 *
 * The 2B controllers (2B.26–2B.30) do NOT eager-load any of these six
 * relations on the allocation store action. They are present in the
 * resource so that a future CR, a test, or an artisan script can load
 * and expose them without modifying this class.
 *
 * =============================================================================
 * Actor serialization
 * =============================================================================
 * The `allocated_by_user` key is emitted via `whenLoaded('allocatedBy')`
 * and returns a MINIMAL inline payload — `{ id, email, name }` — rather
 * than a nested Resource class.
 *
 * Rationale: identical to the reasoning documented in 2B.33
 * §"Actor serialization". The `PaymentAllocation::allocatedBy()` relation
 * (2B.8) returns a `User` from `MaaleArna\MASES\Core\Auth\Models\User`;
 * the 2B manifest contains no `UserResource`; the three emitted fields
 * are the actor's identity surface; a future CR can replace the inline
 * payload with a `UserResource` in a single line.
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
 *   - `amount` is emitted as the string from the model's `decimal:4`
 *     cast. No numeric formatting is applied; consumer presentation is a
 *     UI concern.
 *
 *   - `allocated_at`, `created_at`, and `updated_at` are emitted as ISO
 *     8601 strings via `->toIso8601String()`. This matches the
 *     convention established by `WalletResource` (2B.31), by
 *     `ObligationResource` (2B.32), by `PaymentResource` (2B.33), and by
 *     the statement service (2B.19 §"generate").
 *
 *   - The resource emits NO `currency` field. A `PaymentAllocation` has
 *     no currency column (2B.8 §"No currency column"); the currency of
 *     an allocation is unambiguously the wallet's currency (Q8), and a
 *     consumer that needs it reads it from the wallet or from either
 *     parent. Duplicating the currency here would create a fourth copy
 *     of a value that the Q8 invariant guarantees is identical to the
 *     other three, with no compensating benefit.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does not embed the parent wallet, payment, or obligation in
 *     full by default. Each is exposed as a nested resource (or inline
 *     payload) WHEN loaded, not by default. Recursive embedding of the
 *     wallet inside the allocation inside the wallet (which embeds
 *     payments, which embed allocations) would create a nesting cycle
 *     for a caller that loads multiple directions.
 *
 *   - It does not embed ledger entries. Allocations do NOT produce ledger
 *     entries (Architecture §5.1); the wallet's ledger is untouched by
 *     the allocation action. There is no ledger row to embed.
 *
 *   - It does not emit bilingual name fields. The allocation has none
 *     (Q7 forbids them in 2B transactional tables).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.5    — PaymentAllocation entity
 *   Architecture v1.2.0 §5.1    — Allocations do not produce ledger entries
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.3    — Allocation invariants
 *   Architecture v1.2.0 §8.6    — Policy reference integrity
 *   Architecture v1.2.0 §10     — Allocation-policy contract boundary
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §19     — Q8 resolution (currency)
 *   Step 5 (manifest) 2B.34     — This file
 *   Step 7 v1.1.0 §A.4          — F-2B-LIST-1 resolution (whenLoaded)
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure
 *   §2.7 (conventions)          — Resource conventions
 *   §2.14 (conventions)         — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Resources
 * @version 1.0.0
 */
final class PaymentAllocationResource extends JsonResource
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
     * on the allocation payload. It is retained for interface compliance
     * and for the day a request-aware field (e.g. a `can` block) is added
     * by a future CR.
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
            // The three foreign keys identify the parent aggregate and the
            // two entities this allocation links. They are always present
            // — they are columns on the row itself, not relations.
            // -----------------------------------------------------------------
            'id'            => (int) $this->id,
            'wallet_id'     => (int) $this->wallet_id,
            'payment_id'    => (int) $this->payment_id,
            'obligation_id' => (int) $this->obligation_id,

            // -----------------------------------------------------------------
            // Economic fact.
            //
            // `amount` is the POSITIVE decimal string recorded by the
            // service. No `currency` field — a `PaymentAllocation` has no
            // currency column; see the class docblock §"Formatting
            // choices".
            // -----------------------------------------------------------------
            'amount' => (string) $this->amount,

            // -----------------------------------------------------------------
            // Business time.
            // -----------------------------------------------------------------
            'allocated_at' => $this->allocated_at?->toIso8601String(),

            // -----------------------------------------------------------------
            // Actor.
            //
            // The integer id, always present. The full nested payload is
            // exposed under `allocated_by_user` when the relation is
            // loaded — see the conditional relations block below.
            // -----------------------------------------------------------------
            'allocated_by' => $this->allocated_by !== null
                ? (int) $this->allocated_by
                : null,

            // -----------------------------------------------------------------
            // Policy slot (D110).
            //
            // Nullable integer id. The full policy payload is NOT embedded
            // by default; see the class docblock §"Allocation policy
            // reference".
            // -----------------------------------------------------------------
            'allocation_policy_version_id' => $this->allocation_policy_version_id !== null
                ? (int) $this->allocation_policy_version_id
                : null,

            // -----------------------------------------------------------------
            // Conditional relations.
            //
            // Each key is present only when the relation was eager-loaded.
            // When absent, the key is OMITTED from the payload.
            //
            // The `allocated_by_user` key name is deliberately distinct
            // from the `allocated_by` integer field above: one is the
            // actor's id, the other is the actor's full nested payload.
            // -----------------------------------------------------------------
            'wallet' => $this->whenLoaded(
                'wallet',
                fn () => new WalletResource($this->wallet),
            ),

            'payment' => $this->whenLoaded(
                'payment',
                fn () => new PaymentResource($this->payment),
            ),

            'obligation' => $this->whenLoaded(
                'obligation',
                fn () => new ObligationResource($this->obligation),
            ),

            'allocated_by_user' => $this->whenLoaded(
                'allocatedBy',
                fn () => [
                    'id'    => (int) $this->allocatedBy->id,
                    'email' => (string) $this->allocatedBy->email,
                    'name'  => (string) $this->allocatedBy->name,
                ],
            ),

            // -----------------------------------------------------------------
            // Policy version, when loaded.
            //
            // Emitted as an inline payload rather than a nested Resource
            // class — there is no `VersionedPolicyResource` in the 2B
            // manifest. The four fields are the policy's identity
            // surface; the payload itself is NOT embedded. See the class
            // docblock §"Allocation policy reference".
            // -----------------------------------------------------------------
            'allocation_policy' => $this->whenLoaded(
                'allocationPolicy',
                fn () => [
                    'id'              => (int) $this->allocationPolicy->id,
                    'policy_type'     => (string) $this->allocationPolicy->policy_type,
                    'version'         => (int) $this->allocationPolicy->version,
                    'effective_from'  => $this->allocationPolicy->effective_from?->toIso8601String(),
                    'effective_until' => $this->allocationPolicy->effective_until?->toIso8601String(),
                ],
            ),

            'reversals' => $this->whenLoaded(
                'reversals',
                fn () => PaymentAllocationReversalResource::collection($this->reversals),
            ),

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            // -----------------------------------------------------------------
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}