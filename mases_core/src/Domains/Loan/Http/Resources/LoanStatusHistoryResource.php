<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-L2
 * =============================================================================
 *
 * File:            mases_core/src/Domains/Loan/Http/Resources/LoanStatusHistoryResource.php
 * Unit:            CR-L2 — Ethiopian calendar date rendering (Loan resources)
 * Delivery type:   Amendment — one import, one trait use, two
 *                  `_display` fields, docblock amendments.
 * Net lines:       +2 imports/use; +2 field lines; docblock revision.
 *
 * Before:
 *   Every date field the resource emitted was ISO 8601 only:
 *
 *     occurred_at, created_at   (2 date fields)
 *
 *   A consumer that wanted the Amharic-first medium form had to slice
 *   the ISO string client-side, breaking the convention established by
 *   P3 · Unit 2 across the Shareholder resources, extended to
 *   WalletResource by CR-W1, and being extended to the six Loan
 *   resources by CR-L2.
 *
 * After:
 *   The resource mixes in RendersLocalizedDates (Reading 1:
 *   cross-domain import from the Shareholder domain, matching
 *   WalletResource's and the sibling Loan resources' precedent) and
 *   emits a `_display` sibling for each of its two date fields. The
 *   ISO fields are preserved byte-for-byte; the `_display` fields are
 *   additive.
 *
 *   Each `_display` field appears immediately after its ISO parent:
 *
 *     occurred_at_display, created_at_display
 *
 *   Both ISO dates are written by the state machine from the same
 *   `Carbon::now()` value per Phase 2C; both `_display` siblings
 *   therefore render the same localized calendar day. The two are kept
 *   as separate fields in the payload so that a future CR which
 *   introduces back-dated transitions (imports of historical records)
 *   will not require a schema or resource change.
 *
 * Public surface delta:
 *   · No new methods. The trait contributes one `protected localizedDate()`
 *     method, which is not part of the resource's external contract.
 *   · The serialised payload gains two keys. No key is removed,
 *     renamed, or re-valued. In particular, the three computed
 *     predicates (`was_automatic`, `is_initial_creation`,
 *     `is_transition`) are unchanged.
 *
 * Dependency note:
 *   · The trait is imported from the Shareholder domain's Concerns
 *     namespace, matching the cross-domain import on LoanResource.php
 *     (CR-L2 file 1). Reading 1 was confirmed: the trait stays where
 *     it is for CR-L2.
 *
 *   · The trait and LocalizationService::formatEthiopianDateMedium()
 *     are both deployed (Track A closed).
 *
 *   · No new class, no config change, no route change. No Composer
 *     refresh is required for this file alone.
 *
 * =============================================================================
 */

/*
 * =============================================================================
 * MASES Phase 2C — Loan Status History Resource
 * =============================================================================
 *
 * File:      mases_core/src/Domains/Loan/Http/Resources/LoanStatusHistoryResource.php
 * Version:   1.1.0
 *
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;

/**
 * LoanStatusHistoryResource
 *
 * The HTTP representation of a single `LoanStatusHistory` row (A.23) —
 * one entry in a loan's append-only lifecycle log. Serializes the
 * transition's endpoints, its optional reason, its optional actor, and
 * its two timestamps.
 *
 * This is the last of the eight Loan-domain HTTP resources (A.69–A.76).
 *
 * =============================================================================
 * Shape
 * =============================================================================
 * The resource emits five groups of fields:
 *
 *   1. IDENTITY — the history row's primary key and its parent loan id.
 *
 *   2. TRANSITION ENDPOINTS — `from_status` (nullable on the
 *      initial-creation row, per A.23 §"The initial-creation row") and
 *      `to_status` (never null). Both are emitted as the snake_case
 *      backing strings of the `LoanStatus` enum (A.2).
 *
 *   3. REASON — the nullable `transition_reason` string. Populated for
 *      the transitions in `LoanStateMachine::REASON_REQUIRED_TRANSITIONS`
 *      (A.26) and for any other transition where the caller supplied a
 *      reason; null otherwise.
 *
 *   4. ACTOR — the raw `actor_user_id` FK column (nullable) plus the
 *      inline `actor_user` summary when the `actor` relation is
 *      eager-loaded. The FK is null for automatic transitions and for a
 *      hard-deleted user (per the migration A.14's `nullOnDelete()`
 *      behavior).
 *
 *   5. TIMESTAMPS AND PREDICATES — `occurred_at` and `created_at` (both
 *      ISO 8601 with localized `_display` siblings) and three computed
 *      predicates delegated to the model's read-only helpers (A.23):
 *      `was_automatic`, `is_initial_creation`, `is_transition`.
 *
 * The resource deliberately emits NO `updated_at` field. The migration
 * A.14 declares no such column, and the model sets `$timestamps = false`
 * (A.23). Emitting a field for a nonexistent column would be a
 * semantic defect.
 *
 * =============================================================================
 * The `from_status` nullability
 * =============================================================================
 * The FIRST history row for a loan — the initial-creation row written by
 * `LoanStateMachine::initialize()` (A.26) — carries
 * `from_status = null` because the loan did not exist before that
 * moment. Every subsequent row carries a non-null `from_status`.
 *
 * The resource emits the null directly. A client that wants the
 * domain-level distinction ("this is the creation row, not a
 * transition") reads the `is_initial_creation` predicate, which is
 * emitted alongside the raw field.
 *
 * =============================================================================
 * The `actor_user_id` nullability
 * =============================================================================
 * The FK is nullable for two distinct reasons (see the migration A.14
 * §"`actor_user_id` is nullable"):
 *
 *   1. Automatic transitions. Several lifecycle transitions are driven
 *      by the domain's own logic without a human actor — the
 *      `disbursed → active` settle-into-working transition (A.30), the
 *      `active → settled` transition (A.31), the
 *      `submitted → under_review` pickup (A.29 in its silent
 *      `ensureUnderReview()` branch), and the initial-creation row
 *      itself (A.26). All produce a row with `actor_user_id = null`.
 *
 *   2. Hard-deleted users. The migration declares
 *      `nullOnDelete()` on the FK: a hard-deleted user detaches from
 *      the history row, which survives with a null actor.
 *
 * The resource emits the FK id as-is (null when either case applies).
 * The inline `actor_user` summary is emitted as null when the loaded
 * relation is null; the two are consistent by construction.
 *
 * A client that wants to distinguish "automatic" from "hard-deleted
 * user" reads the `was_automatic` predicate: it returns true when
 * `actor_user_id` is null. In a well-formed system, the two cases are
 * functionally equivalent from the audit-trail's perspective — the row
 * has no attributable human actor — so the predicate does not attempt a
 * further distinction.
 *
 * =============================================================================
 * The `occurred_at` vs. `created_at` distinction
 * =============================================================================
 * Every row carries two timestamps (A.23 §"The two-column business-time
 * convention"):
 *
 *   `occurred_at` — the moment the transition occurred, per the domain's
 *                   clock. The semantically meaningful time.
 *
 *   `created_at` — the moment the row was inserted. In Phase 2C the two
 *                   are identical by construction (`LoanStateMachine`,
 *                   A.26, writes both from the same `Carbon::now()`
 *                   value).
 *
 * The two are kept separate because a future CR that introduces
 * back-dated transitions (imports of historical records, administrative
 * reconstructions) would legitimately diverge them. Phase 2C does not
 * produce a divergence, but the resource emits both fields so that a
 * future divergence is visible without a schema change.
 *
 * =============================================================================
 * Dates — ISO 8601 and localized display siblings  (CR-L2)
 * =============================================================================
 * Two date fields are emitted. Each is accompanied by a `_display`
 * sibling produced by LocalizationService::formatEthiopianDateMedium()
 * via the RendersLocalizedDates trait (imported cross-domain from the
 * Shareholder domain, matching LoanResource.php's precedent):
 *
 *     am:  መስከረም 5, 2018 ዓ.ም. (September 15, 2025)
 *     en:  Meskerem 5, 2018 E.C. (September 15, 2025)
 *
 * The two forms serve different consumers:
 *
 *   · The ISO field remains the wire's machine-readable source of truth:
 *     parseable, sortable, timezone-explicit. Preserved for clients that
 *     need the exact instant.
 *
 *   · The `_display` field is the localized human-readable form. Amharic
 *     month names for `am`, transliterated Latin for `en`, with the
 *     Gregorian equivalent in parentheses. Rendered by the UI directly.
 *
 * `occurred_at` is NOT NULL per the migration A.14. The `created_at`
 * column is nullable; the null-safe operator is applied uniformly for
 * defensive consistency, and the `_display` sibling handles a
 * hypothetical null the same way the ISO form does. Phase 2C writes
 * both from the same `Carbon::now()` value (A.26), so the two
 * `_display` strings are identical by construction.
 *
 * =============================================================================
 * The `whenLoaded` contract
 * =============================================================================
 * One relation is exposed via `whenLoaded()`:
 *
 *   'actor' — the User who performed the transition, or null.
 *
 * A resource serialized without eager-loading the relation omits the
 * `actor_user` field entirely. Controllers compose the eager-load set at
 * the query boundary via the `$with` parameter on the parent repository
 * (A.55 for the loan-level read, or `LoanStatusHistory`'s own relation
 * when loaded through `$loan->load('statusHistories.actor')`).
 *
 * The resource NEVER triggers a lazy-load.
 *
 * =============================================================================
 * The actor summary
 * =============================================================================
 * The inline actor payload uses the platform-wide `{id, email, name}`
 * shape, matching `LoanResource` (A.69), `LoanCollateralResource` (A.72),
 * and every other Phase 2A/2B resource. No `UserResource` exists in the
 * Phase 2C manifest.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does NOT trigger lazy-loads. See §"The whenLoaded contract".
 *
 *   - It does NOT emit the parent loan entity. The loan's id is emitted
 *     as `loan_id`; a caller that needs the loan resolves it through
 *     `GET /loans/{loan}` (which uses `LoanResource`, A.69).
 *
 *   - It does NOT emit an `updated_at` field. See §"Shape".
 *
 *   - It does NOT compute a `duration_since_previous` interval. The
 *     interval between two history rows is a query-time concern (the
 *     model's class docblock §"What this model deliberately does NOT do"
 *     documents the rationale). A client that wants an interval walks
 *     the ordered `status_histories` collection and computes it.
 *
 *   - It does NOT emit a localized status label (e.g. "Under Review").
 *     The raw snake_case backing value is emitted; localization is a
 *     presentation-layer concern.
 *
 *   - It does NOT filter or transform the `transition_reason` string.
 *     The value is emitted verbatim, including for a transition whose
 *     reason was optional and null.
 *
 *   - It does NOT perform authorization. The controller gates the
 *     endpoint through `Gate::authorize('view', $loan)` (A.52); the
 *     resource is a pure serializer.
 *
 * =============================================================================
 * Correction history
 * =============================================================================
 * v1.0.0  — initial delivery.
 *
 * v1.1.0  — Ethiopian calendar date rendering (CR-L2):
 *
 *           · The `RendersLocalizedDates` trait is imported from the
 *             Shareholder domain (cross-domain, matching the
 *             LoanResource.php precedent from CR-L2 file 1) and mixed
 *             into the class.
 *
 *           · Two `_display` sibling fields are added — one for
 *             `occurred_at`, one for `created_at`. Each `_display`
 *             field appears immediately after its ISO sibling.
 *
 *           · The ISO fields are preserved byte-for-byte. Every existing
 *             key retains its name, its type, and its value; the
 *             `_display` fields are additive. The three computed
 *             predicates (`was_automatic`, `is_initial_creation`,
 *             `is_transition`) are unchanged.
 *
 *           · No structural change. No new dependency beyond the trait.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — C4 (state freeze)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (transition matrix)
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.14                                — `loan_status_histories`
 *                                                  migration
 *   Phase 2C A.23                                — `LoanStatusHistory`
 *                                                  (the model serialized
 *                                                  by this resource,
 *                                                  whose predicates
 *                                                  this resource
 *                                                  delegates to)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *                                                  (the sole writer of
 *                                                  these rows)
 *   Phase 2C A.69                                — `LoanResource` (the
 *                                                  parent resource that
 *                                                  embeds a collection
 *                                                  of these via
 *                                                  `whenLoaded`)
 *   Phase 2C A.76                                — this file
 *   Phase 2A 2A.23                               — `ShareClassResource`
 *                                                  (the shape precedent)
 *   Phase 2B 2B.23                               — `WalletResource` (the
 *                                                  sibling resource)
 *   P3 · Unit 2                                  — Ethiopian calendar
 *                                                  date rendering (the
 *                                                  convention this
 *                                                  amendment extends)
 *   CR-W1                                        — the cross-domain trait
 *                                                  import precedent
 *   CR-L2                                        — This amendment
 *   Step 5 (manifest) A.76                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Resources
 * @version 1.1.0
 */
final class LoanStatusHistoryResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the automatic `data` wrapping.
     *
     * See the sibling note on `LoanResource` (A.69) §"Deployment Notes"
     * (item 2) for the rationale.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the status history row into its array representation.
     *
     * See the class docblock for the field layout and the
     * no-lazy-loads / ISO-plus-`_display` / whenLoaded contract.
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
            'id'      => (int) $this->id,
            'loan_id' => (int) $this->loan_id,

            // -----------------------------------------------------------------
            // Transition endpoints.
            //
            // Both columns are enum-cast (A.23); `->value` yields the
            // snake_case backing string. `from_status` is null on the
            // initial-creation row; `to_status` is never null.
            // -----------------------------------------------------------------
            'from_status' => $this->from_status?->value,
            'to_status'   => $this->to_status->value,

            // -----------------------------------------------------------------
            // Transition reason.
            //
            // Nullable. Populated for reason-required transitions
            // (A.26) and for any other transition where the caller
            // supplied one.
            // -----------------------------------------------------------------
            'transition_reason' => $this->transition_reason,

            // -----------------------------------------------------------------
            // Actor — raw FK id.
            //
            // Null for automatic transitions and for a hard-deleted
            // user (per the migration A.14's `nullOnDelete()` behavior).
            // The corresponding user summary is emitted below as
            // `actor_user`, only when the relation is eager-loaded.
            // -----------------------------------------------------------------
            'actor_user_id' => $this->actor_user_id,

            // -----------------------------------------------------------------
            // Actor summary (whenLoaded).
            //
            // Emitted only when the `actor` relation is eager-loaded.
            // Null when the loaded relation is null (automatic transition,
            // hard-deleted user, or the initial-creation row).
            // -----------------------------------------------------------------
            'actor_user' => $this->whenLoaded(
                'actor',
                fn (): ?array => $this->actorSummary($this->actor),
            ),

            // -----------------------------------------------------------------
            // Timestamps — ISO + localized display, paired.  (CR-L2)
            //
            // `occurred_at` is NOT NULL per the migration A.14. The
            // `created_at` column is nullable; the null-safe operator
            // is applied uniformly for defensive consistency. Phase 2C
            // writes both from the same `Carbon::now()` value (A.26),
            // so the two are identical by construction and their
            // `_display` siblings render the same localized day. See
            // the class docblock §"Dates — ISO 8601 and localized
            // display siblings".
            //
            // No `updated_at` field is emitted: the migration declares
            // no such column, and the model sets `$timestamps = false`
            // (A.23).
            // -----------------------------------------------------------------
            'occurred_at'         => $this->occurred_at?->toIso8601String(),
            'occurred_at_display' => $this->localizedDate($this->occurred_at),
            'created_at'          => $this->created_at?->toIso8601String(),
            'created_at_display'  => $this->localizedDate($this->created_at),

            // -----------------------------------------------------------------
            // Computed predicates.
            //
            // Delegated to the model's read-only helpers (A.23). Every
            // call is a pure in-memory check; no I/O is triggered.
            // -----------------------------------------------------------------
            'was_automatic'       => $this->wasAutomatic(),
            'is_initial_creation' => $this->isInitialCreation(),
            'is_transition'       => $this->isTransition(),
        ];
    }

    // =========================================================================
    // Private — nested-payload builders
    // =========================================================================

    /**
     * Build the inline actor summary for a user.
     *
     * The `{id, email, name}` shape is the platform-wide actor payload,
     * matching Phase 2B's Wallet resources and Phase 2A's resources. No
     * `UserResource` exists in the Phase 2C manifest.
     *
     * @param User|null $user
     * @return array{id: int, email: string|null, name: string|null}|null
     */
    private function actorSummary(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id'    => (int) $user->getKey(),
            'email' => $user->email,
            'name'  => $user->name,
        ];
    }
}