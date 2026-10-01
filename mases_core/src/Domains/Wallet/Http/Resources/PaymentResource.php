<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PaymentResource
 *
 * Serializes a `Payment` model into the API's payment payload.
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
 *   1. Identity          — id, wallet_id
 *   2. Economic fact     — currency, amount, unapplied_amount
 *   3. Business time     — received_at
 *   4. Reference         — reference_type, reference_id, description
 *   5. Actor             — created_by
 *   6. Derived flags     — is_unapplied, is_partially_applied,
 *                          is_fully_applied
 *   7. Conditional rels  — wallet, created_by_user, allocations
 *   8. Timestamps        — created_at, updated_at
 *
 * The order is presentation-oriented. JSON objects are unordered in the
 * specification, but keeping a stable order in the source makes the
 * payload easy to review and diff.
 *
 * =============================================================================
 * Contrast with ObligationResource (2B.32)
 * =============================================================================
 * This resource differs from 2B.32 in four ways, each driven by a
 * substantive difference between the two models (2B.6 vs. 2B.7):
 *
 *   1. No `component` field. Payments are NOT component-tagged
 *      (Architecture §3.4, D109). The economic meaning of a payment is
 *      established later by the allocations that settle specific
 *      obligations; it is not a property of the payment row itself.
 *
 *   2. No `due_at` field. Payments are not owed; they are received. The
 *      `wallet_payments` table has no `due_at` column (2B.51).
 *
 *   3. `received_at` replaces `occurred_at`. The business timestamp of a
 *      payment is when it was received, not when some fact about it
 *      "occurred". The model (2B.7) names the column accordingly.
 *
 *   4. Projection state differs. Instead of the obligation's three
 *      settlement flags (`is_open`, `is_partially_settled`, `is_settled`)
 *      derived from `original_amount` and `outstanding_amount`, this
 *      resource emits three application flags (`is_unapplied`,
 *      `is_partially_applied`, `is_fully_applied`) derived from `amount`
 *      and `unapplied_amount` (Architecture §6.2). The two sets of flags
 *      describe different projections of the same wallet ledger.
 *
 * =============================================================================
 * Derived flags
 * =============================================================================
 * Three boolean fields are emitted as read-only conveniences, derived
 * from the two amount columns via the model's helpers (2B.7):
 *
 *   - `is_unapplied`         — true when unapplied == amount
 *                              (nothing has been allocated)
 *   - `is_partially_applied` — true when 0 < unapplied < amount
 *   - `is_fully_applied`     — true when unapplied == 0
 *
 * The three are mutually exclusive and exhaustive for a well-formed row.
 * Emitting them lets consumers branch on a single boolean rather than
 * recomputing the comparison against the two amount fields — and lets
 * them rely on the model's bcmath-at-scale-4 comparison, which avoids
 * float rounding.
 *
 * The three flags are computed by CALLING the model's methods
 * (`isUnapplied()`, `isPartiallyApplied()`, `isFullyApplied()`). Reading
 * them as attributes would return null — the model has no such columns.
 * See the class docblock §"Correction history (v1.0.1)".
 *
 * The amounts themselves remain present in the payload; the flags are
 * additions, not replacements.
 *
 * =============================================================================
 * Overpayment / unapplied amount (D110)
 * =============================================================================
 * A payment may exceed the total outstanding obligations on the wallet.
 * That fact is expressed in this payload by `unapplied_amount > 0`. D110
 * requires the unapplied remainder to be recorded explicitly — and it is:
 * as the payment's `unapplied_amount` column, exposed here as a
 * first-class field. No "hold" or "reserve" state exists in 2B.
 *
 * The `is_unapplied` flag covers the case where nothing has been
 * allocated; `is_partially_applied` covers any positive remainder below
 * the full amount. A caller that wants to know "how much of this payment
 * is still available?" reads `unapplied_amount` directly.
 *
 * =============================================================================
 * Conditional relations (Step 7 v1.1.0 §A.4, F-2B-LIST-1)
 * =============================================================================
 * The resource embeds three relations ONLY when the caller has
 * eager-loaded them, via `whenLoaded()`:
 *
 *   - `wallet`           — the owning wallet.
 *   - `created_by_user`  — the recording user (nullable relation).
 *   - `allocations`      — the allocations recorded against this payment.
 *
 * Each key is OMITTED when the relation is not loaded. A caller that
 * receives a payload without the `allocations` key knows the relation
 * was not loaded; a caller that receives `allocations: []` knows the
 * relation was loaded and is empty. See 2B.31 §"Conditional relations"
 * for the extended rationale on the omitted-vs-empty distinction.
 *
 * =============================================================================
 * Actor serialization
 * =============================================================================
 * The `created_by_user` key is emitted via `whenLoaded('createdBy')` and
 * returns a MINIMAL inline payload — `{ id, email, name }` — rather than
 * a nested Resource class.
 *
 * Rationale:
 *
 *   - The `Payment::createdBy()` relation (2B.7) returns a `User` from
 *     `MaaleArna\MASES\Core\Auth\Models\User`. That model is a Core
 *     domain object, and the 2B manifest (Step 5) does not include a
 *     `UserResource` in the Wallet domain.
 *
 *   - Wrapping the User in a foreign domain's resource (e.g. a
 *     ShareholderResource) would be a category error: the actor on a
 *     payment is a User, not a Shareholder.
 *
 *   - The three fields emitted (`id`, `email`, `name`) are the actor's
 *     identity surface: enough for a consumer to display "Recorded by
 *     <name>" and to link to the user by id, without committing to a
 *     full user serialization that a future Phase 2 CR may revise.
 *
 *   - The `name` attribute is a computed accessor on the User model
 *     (Phase 1 Amendment A4: `getNameAttribute()` returns the user's
 *     composed display name). The inline payload reads it directly.
 *
 * The inline payload includes a null guard: when the relation is loaded
 * but the FK is null (a payment with no recorded actor), the closure
 * returns null rather than attempting to read properties of a null
 * value. This mirrors the fix applied to `ObligationResource` in v1.0.1.
 *
 * A future CR that introduces a `UserResource` in Core can replace the
 * inline payload with a resource reference in a single line, without
 * changing the payload's shape.
 *
 * =============================================================================
 * Correction history (v1.0.1)
 * =============================================================================
 * The three projection flags (`is_unapplied`, `is_partially_applied`,
 * `is_fully_applied`) were read as attributes. The `Payment` model has
 * no such columns; it exposes `isUnapplied()`, `isPartiallyApplied()`,
 * and `isFullyApplied()` as methods. Reading as attributes returned null
 * in every payload. The corrected version calls the methods.
 *
 * This is the same defect class as `WalletResource` v1.0.1's `is_closed`
 * field and `ObligationResource` v1.0.2's three settlement flags.
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * Every value emitted by this resource comes from a property or a
 * relation already present on the model. There are no `Model::find(...)`
 * calls, no `->load(...)` calls, and no lazy-loaded relation accesses.
 * The `createdBy` relation, when read, is read through the `whenLoaded()`
 * guard — a lazily-evaluated closure that only fires when the relation
 * is already present.
 *
 * =============================================================================
 * Formatting choices
 * =============================================================================
 *   - `currency` is emitted as its stored string (`'ETB'`, `'USD'`, …).
 *
 *   - `amount` and `unapplied_amount` are emitted as the strings from the
 *     model's `decimal:4` cast. No numeric formatting is applied;
 *     consumer presentation is a UI concern.
 *
 *   - `received_at`, `created_at`, and `updated_at` are emitted as ISO
 *     8601 strings via `->toIso8601String()`. This matches the
 *     convention established by `WalletResource` (2B.31) and by the
 *     statement service (2B.19 §"generate").
 *
 *   - `reference_type` and `reference_id` are emitted as their stored
 *     strings or null. The pair is opaque to the Wallet domain; the
 *     consuming domain owns its vocabulary.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does not embed the parent wallet's full payload. The `wallet`
 *     relation is exposed as a nested `WalletResource` (2B.31) WHEN
 *     loaded, not by default. Recursive embedding of the wallet inside
 *     the payment inside the wallet (which embeds payments) would create
 *     a nesting cycle for a caller that loads both directions.
 *
 *   - It does not embed policy payloads. The payment carries no policy
 *     reference (2B.7 §"What this model deliberately does NOT do").
 *
 *   - It does not emit a component label or a `component` field at all.
 *     Payments have no component (see the class docblock §"Contrast with
 *     ObligationResource").
 *
 *   - It does not emit bilingual name fields. The payment has none (Q7
 *     forbids them in 2B transactional tables).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.4    — Payment entity
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.4    — Overpayment and unapplied amounts
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §19     — Q8 resolution (currency)
 *   Phase 1 Amendment A4        — User::getNameAttribute() (composed name)
 *   Step 5 (manifest) 2B.33     — This file
 *   Step 7 v1.1.0 §A.4          — F-2B-LIST-1 resolution (whenLoaded)
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure
 *   §2.7 (conventions)          — Resource conventions
 *   §2.14 (conventions)         — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Resources
 * @version 1.0.1
 */
final class PaymentResource extends JsonResource
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
     * on the payment payload. It is retained for interface compliance
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
            // -----------------------------------------------------------------
            'id'        => (int) $this->id,
            'wallet_id' => (int) $this->wallet_id,

            // -----------------------------------------------------------------
            // Economic fact (Q8).
            //
            // No `component` field: payments are not component-tagged
            // (Architecture §3.4, D109). See the class docblock
            // §"Contrast with ObligationResource".
            // -----------------------------------------------------------------
            'currency'         => (string) $this->currency,
            'amount'           => (string) $this->amount,
            'unapplied_amount' => (string) $this->unapplied_amount,

            // -----------------------------------------------------------------
            // Business time.
            // -----------------------------------------------------------------
            'received_at' => $this->received_at?->toIso8601String(),

            // -----------------------------------------------------------------
            // Reference pair (D109, opaque to this domain) and description.
            // -----------------------------------------------------------------
            'reference_type' => $this->reference_type,
            'reference_id'   => $this->reference_id,
            'description'    => $this->description,

            // -----------------------------------------------------------------
            // Actor.
            // -----------------------------------------------------------------
            'created_by' => $this->created_by !== null
                ? (int) $this->created_by
                : null,

            // -----------------------------------------------------------------
            // Derived projection flags — computed by CALLING the model's
            // methods (2B.7 §"Domain helpers"). Reading them as attributes
            // returns null because the model has no such columns. See the
            // class docblock §"Correction history (v1.0.1)".
            //
            // The three are mutually exclusive and exhaustive for a
            // well-formed row.
            // -----------------------------------------------------------------
            'is_unapplied'         => $this->isUnapplied(),
            'is_partially_applied' => $this->isPartiallyApplied(),
            'is_fully_applied'     => $this->isFullyApplied(),

            // -----------------------------------------------------------------
            // Conditional relations.
            //
            // Each key is present only when the relation was eager-loaded.
            // When absent, the key is OMITTED from the payload.
            //
            // The `created_by_user` key name is deliberately distinct from
            // the `created_by` integer field above: one is the actor's id,
            // the other is the actor's full nested payload. A caller that
            // needs only the id reads `created_by`; a caller that needs
            // the display name loads and reads `created_by_user`.
            //
            // The actor is a `User` (Core), not a `Shareholder`. The
            // inline payload emits the three identity fields a consumer
            // needs, with a null guard for the case where the relation is
            // loaded but the FK is null. See the class docblock
            // §"Actor serialization".
            // -----------------------------------------------------------------
            'wallet' => $this->whenLoaded(
                'wallet',
                fn () => new WalletResource($this->wallet),
            ),

            'created_by_user' => $this->whenLoaded(
                'createdBy',
                fn () => $this->createdBy === null ? null : [
                    'id'    => (int) $this->createdBy->id,
                    'email' => (string) $this->createdBy->email,
                    'name'  => (string) $this->createdBy->name,
                ],
            ),

            'allocations' => $this->whenLoaded(
                'allocations',
                fn () => PaymentAllocationResource::collection($this->allocations),
            ),

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            // -----------------------------------------------------------------
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}