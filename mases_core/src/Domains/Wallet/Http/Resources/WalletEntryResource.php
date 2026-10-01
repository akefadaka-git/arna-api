<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WalletEntryResource
 *
 * Serializes a `WalletEntry` model into the API's ledger-entry payload.
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
 *   1. Identity          — id, wallet_id, entry_type, component
 *   2. Ledger triple     — signed_quantity, balance_before, balance_after
 *   3. Business time     — occurred_at
 *   4. Reference         — reference_type, reference_id, description
 *   5. Reversal linkage  — reversed_entry_id
 *   6. Actor             — created_by
 *   7. Derived flags     — is_credit, is_debit, is_reversal
 *   8. Conditional rels  — wallet, created_by_user, reversed_entry,
 *                          reversals
 *   9. Timestamp         — created_at (no updated_at — see below)
 *
 * The order is presentation-oriented. JSON objects are unordered in the
 * specification, but keeping a stable order in the source makes the
 * payload easy to review and diff.
 *
 * =============================================================================
 * No `updated_at` — deliberate omission
 * =============================================================================
 * This is the ONLY resource in the Wallet domain that emits no
 * `updated_at` field. The omission is not an oversight:
 *
 *   - The `wallet_entries` table (2B.54) declares NO `updated_at` column.
 *   - The `WalletEntry` model (2B.10) sets `$timestamps = false` and
 *     disables Eloquent's timestamp management.
 *   - The ledger is append-only by construction (Architecture §3.7); a
 *     posted entry is never modified, so there is no update timestamp to
 *     emit.
 *
 * Emitting an `updated_at` field would require the resource to synthesize
 * a value from `created_at`, which would misrepresent the row's actual
 * schema and invite consumers to expect mutability that the ledger does
 * not permit.
 *
 * =============================================================================
 * Enum serialization
 * =============================================================================
 *   - `entry_type` is emitted as the `WalletEntryType` enum's raw VALUE
 *     string (`'obligation_recorded'`, `'payment_received'`,
 *     `'adjustment'`, `'reversal'`). The model (2B.10) casts the column
 *     to the enum; this resource extracts the string via `->value`.
 *
 *   - `component` is emitted as the `ObligationComponent` enum's raw
 *     VALUE string when non-null, and as null otherwise. It is set only
 *     on `obligation_recorded` entries; every other entry type has a null
 *     component (2B.15 §"assertComponentConsistency").
 *
 * No label fields (`entry_type_label`, `component_label`) are emitted.
 * Display strings belong to the presentation layer, translated through
 * the `__()` mechanism (§2.14); the `wallet` translation files are not
 * yet authored (2B.20 §"Amharic-first error messages"). A future CR may
 * add label fields once the translation namespace is populated; adding
 * them now would emit untranslated keys as values, which is worse than
 * emitting the technical discriminators.
 *
 * =============================================================================
 * Derived flags
 * =============================================================================
 * Three boolean fields are emitted as read-only conveniences, derived
 * from the entry's properties via the model's helpers (2B.10):
 *
 *   - `is_credit`   — true when `signed_quantity > 0`. The organization
 *                     holds credit for the member (Architecture §4.2).
 *   - `is_debit`    — true when `signed_quantity < 0`. The member owes
 *                     the organization.
 *   - `is_reversal` — true when `entry_type === 'reversal'`.
 *
 * `is_credit` and `is_debit` are mutually exclusive and, for a well-formed
 * row, exhaustive: the ledger-write primitive (2B.15
 * §"assertSignedQuantityIsWellFormed") rejects zero-value entries, so no
 * entry is neither credit nor debit. The comparisons use bcmath at scale 4,
 * avoiding float rounding.
 *
 * `is_reversal` overlaps with the `entry_type` field's value; both are
 * emitted because consumers that branch on "is this a reversal?" benefit
 * from a boolean that does not require comparing strings.
 *
 * The three flags are computed by CALLING the model's methods
 * (`isCredit()`, `isDebit()`, `isReversal()`). Reading them as attributes
 * would return null — the model has no such columns. See the class
 * docblock §"Correction history (v1.0.1)".
 *
 * The `satisfies_balance_invariant` diagnostic (2B.10 §"Domain helpers")
 * is deliberately NOT emitted. It is an internal integrity check, useful
 * in tests and in artisan diagnostics, but not part of the API's contract.
 * A consumer that wants to verify the ledger's invariant checks the two
 * balance columns and the signed quantity itself.
 *
 * =============================================================================
 * Reference pair (Architecture §5.3)
 * =============================================================================
 * `reference_type` and `reference_id` are emitted as their stored strings
 * or null. The pair identifies the source object that produced the entry,
 * per the closed mapping frozen in Architecture §5.3:
 *
 *     obligation_recorded  → reference_type = 'Obligation'
 *     payment_received     → reference_type = 'Payment'
 *     adjustment           → reference_type = 'Wallet'
 *     reversal             → reference_type = 'WalletEntry'
 *
 * The resource does NOT verify the mapping — the service (2B.15) enforces
 * it at write time, and the FK on `reversed_entry_id` enforces the
 * reversal linkage at the schema level (2B.54, S6-B). The resource emits
 * the values as stored.
 *
 * =============================================================================
 * Reversal linkage
 * =============================================================================
 * `reversed_entry_id` is emitted as an integer or null. It is set only
 * on `reversal` entries; every other entry type has a null value (2B.15
 * §"assertReversalConsistency").
 *
 * Two conditional relations expose the linkage in both directions:
 *
 *   - `reversed_entry` — the original entry that this entry reverses.
 *                        Non-null only on a reversal entry.
 *   - `reversals`      — the entries that reverse this one. Empty for a
 *                        non-reversed entry; contains at most one element
 *                        because the "no double reversal" rule
 *                        (Architecture §4.3) permits each entry to be
 *                        reversed at most once.
 *
 * Recursion is bounded by the domain's invariants. A reversal entry's
 * `reversals` relation is always empty (a reversal is never itself
 * reversed, Architecture §4.3); a non-reversal entry's `reversed_entry`
 * relation is always null. Any nesting path terminates after at most two
 * hops regardless of how deeply the caller eager-loads.
 *
 * =============================================================================
 * Conditional relations (Step 7 v1.1.0 §A.4, F-2B-LIST-1)
 * =============================================================================
 * The resource embeds four relations ONLY when the caller has
 * eager-loaded them, via `whenLoaded()`:
 *
 *   - `wallet`           — the owning wallet.
 *   - `created_by_user`  — the recording user (nullable relation).
 *   - `reversed_entry`   — the original entry being reversed (nullable).
 *   - `reversals`        — the entries that reverse this one.
 *
 * Each key is OMITTED when the relation is not loaded. A caller that
 * receives a payload without the `reversals` key knows the relation was
 * not loaded; a caller that receives `reversals: []` knows the relation
 * was loaded and is empty. See 2B.31 §"Conditional relations" for the
 * extended rationale on the omitted-vs-empty distinction.
 *
 * =============================================================================
 * Actor serialization
 * =============================================================================
 * The `created_by_user` key is emitted via `whenLoaded('createdBy')` and
 * returns a MINIMAL inline payload — `{ id, email, name }` — rather than
 * a nested Resource class.
 *
 * Rationale: identical to the reasoning documented in 2B.33
 * §"Actor serialization". The `WalletEntry::createdBy()` relation (2B.10)
 * returns a `User` from `MaaleArna\MASES\Core\Auth\Models\User`; the 2B
 * manifest contains no `UserResource`; the three emitted fields are the
 * actor's identity surface; a future CR can replace the inline payload
 * with a `UserResource` in a single line.
 *
 * The inline payload includes a null guard: when the relation is loaded
 * but the FK is null (a system-generated entry with no recorded actor),
 * the closure returns null rather than attempting to read properties of
 * a null value. This mirrors the fix applied to `ObligationResource`
 * v1.0.1.
 *
 * =============================================================================
 * Correction history (v1.0.1)
 * =============================================================================
 * The three projection flags (`is_credit`, `is_debit`, `is_reversal`)
 * were read as attributes. The `WalletEntry` model has no such columns;
 * it exposes `isCredit()`, `isDebit()`, and `isReversal()` as methods.
 * Reading as attributes returned null in every payload. The corrected
 * version calls the methods.
 *
 * This is the same defect class as `WalletResource` v1.0.1's `is_closed`
 * field, `ObligationResource` v1.0.2's three settlement flags, and
 * `PaymentResource` v1.0.1's three application flags.
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
 *   - `signed_quantity`, `balance_before`, and `balance_after` are
 *     emitted as the strings from the model's `decimal:4` cast. No
 *     numeric formatting is applied; consumer presentation is a UI
 *     concern.
 *
 *   - `signed_quantity` is the only signed monetary value in the Wallet
 *     domain's serialized output. Every other monetary field
 *     (`original_amount`, `outstanding_amount`, `amount`,
 *     `unapplied_amount`, `reversed_amount`) is a positive magnitude. The
 *     sign on this field is the ledger's direction indicator
 *     (Architecture §4.2) and is preserved exactly as stored.
 *
 *   - `occurred_at` and `created_at` are emitted as ISO 8601 strings via
 *     `->toIso8601String()`. This matches the convention established by
 *     every other resource in the domain (2B.31–2B.35) and by the
 *     statement service (2B.19 §"generate").
 *
 *   - The resource emits NO `currency` field. A `WalletEntry` has no
 *     currency column (2B.10); the entry is denominated in the wallet's
 *     currency (Q8), and a consumer that needs it reads it from the
 *     wallet. Duplicating the currency here would create a second copy
 *     of a value that the Q8 invariant guarantees is identical, with no
 *     compensating benefit.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does not emit a `direction` field or a `Direction` enum. The
 *     2B architecture does not declare a direction enum; the signed
 *     quantity is self-documenting. The two boolean flags (`is_credit`,
 *     `is_debit`) express the sign convention at the call site without
 *     introducing a new vocabulary (2B.10 §"What this model deliberately
 *     does NOT do").
 *
 *   - It does not emit an `effective_quantity` or similar derived field.
 *     A `WalletEntry` is a snapshot of a single movement; its quantity is
 *     its meaning. Aggregations (net movement, cumulative total) are
 *     statement-level concerns, computed by the statement service
 *     (2B.19), not per-entry concerns.
 *
 *   - It does not embed the source object of the reference pair
 *     (obligation, payment, wallet, or wallet-entry) by default. The
 *     reference is emitted as a type-and-id pair; a consumer that needs
 *     the full source object fetches it through its own endpoint.
 *
 *   - It does not emit bilingual name fields. The ledger entry has none
 *     (Q7 forbids them in 2B transactional tables); the `description`
 *     field is free text and is emitted as-is.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.7    — WalletEntry ledger table
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.3    — Corrections
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §19     — Q8 resolution (currency)
 *   Step 5 (manifest) 2B.36     — This file
 *   Step 7 v1.1.0 §A.4          — F-2B-LIST-1 resolution (whenLoaded)
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure
 *   §2.7 (conventions)          — Resource conventions
 *   §2.14 (conventions)         — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Http\Resources
 * @version 1.0.1
 */
final class WalletEntryResource extends JsonResource
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
     * on the ledger-entry payload. It is retained for interface
     * compliance and for the day a request-aware field (e.g. a `can`
     * block) is added by a future CR.
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
            // `entry_type` is the enum's raw value string; `component` is
            // the enum's raw value string or null. See the class docblock
            // §"Enum serialization".
            // -----------------------------------------------------------------
            'id'         => (int) $this->id,
            'wallet_id'  => (int) $this->wallet_id,
            'entry_type' => (string) $this->entry_type->value,
            'component'  => $this->component !== null
                ? (string) $this->component->value
                : null,

            // -----------------------------------------------------------------
            // Ledger triple (Architecture §4.6).
            //
            // The signed quantity and the two balances are the entry's
            // audit invariant: `balance_before + signed_quantity =
            // balance_after`. All three are emitted as decimal strings
            // from the model's `decimal:4` cast.
            // -----------------------------------------------------------------
            'signed_quantity' => (string) $this->signed_quantity,
            'balance_before'  => (string) $this->balance_before,
            'balance_after'   => (string) $this->balance_after,

            // -----------------------------------------------------------------
            // Business time.
            // -----------------------------------------------------------------
            'occurred_at' => $this->occurred_at?->toIso8601String(),

            // -----------------------------------------------------------------
            // Reference pair (Architecture §5.3, opaque discriminator) and
            // description.
            // -----------------------------------------------------------------
            'reference_type' => $this->reference_type,
            'reference_id'   => $this->reference_id,
            'description'    => $this->description,

            // -----------------------------------------------------------------
            // Reversal linkage.
            //
            // The integer id, always present (nullable). The full nested
            // payloads in both directions are exposed under
            // `reversed_entry` and `reversals` when the respective
            // relations are loaded — see the conditional relations block
            // below.
            // -----------------------------------------------------------------
            'reversed_entry_id' => $this->reversed_entry_id !== null
                ? (int) $this->reversed_entry_id
                : null,

            // -----------------------------------------------------------------
            // Actor.
            //
            // The integer id, always present (nullable). The full nested
            // payload is exposed under `created_by_user` when the relation
            // is loaded — see the conditional relations block below.
            // -----------------------------------------------------------------
            'created_by' => $this->created_by !== null
                ? (int) $this->created_by
                : null,

            // -----------------------------------------------------------------
            // Derived flags — computed by CALLING the model's methods
            // (2B.10 §"Domain helpers"). Reading them as attributes returns
            // null because the model has no such columns. See the class
            // docblock §"Correction history (v1.0.1)".
            //
            // `is_credit` and `is_debit` are mutually exclusive and
            // exhaustive for a well-formed row. `is_reversal` mirrors the
            // `entry_type` field as a boolean convenience.
            // -----------------------------------------------------------------
            'is_credit'   => $this->isCredit(),
            'is_debit'    => $this->isDebit(),
            'is_reversal' => $this->isReversal(),

            // -----------------------------------------------------------------
            // Conditional relations.
            //
            // Each key is present only when the relation was eager-loaded.
            // When absent, the key is OMITTED from the payload.
            //
            // The `created_by_user` key name is deliberately distinct
            // from the `created_by` integer field above: one is the
            // actor's id, the other is the actor's full nested payload.
            //
            // The `reversed_entry` and `reversals` relations are bounded
            // by the domain's invariants: a reversal cannot be reversed,
            // so any nesting path terminates after at most two hops. See
            // the class docblock §"Reversal linkage".
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

            'reversed_entry' => $this->whenLoaded(
                'reversedEntry',
                fn () => new WalletEntryResource($this->reversedEntry),
            ),

            'reversals' => $this->whenLoaded(
                'reversals',
                fn () => WalletEntryResource::collection($this->reversals),
            ),

            // -----------------------------------------------------------------
            // Insert timestamp.
            //
            // There is NO `updated_at` field. The `wallet_entries` table
            // (2B.54) has no such column; the ledger is append-only by
            // construction (Architecture §3.7). See the class docblock
            // §"No updated_at — deliberate omission".
            // -----------------------------------------------------------------
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}