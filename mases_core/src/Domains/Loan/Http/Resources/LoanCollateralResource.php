<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-L2
 * =============================================================================
 *
 * File:            mases_core/src/Domains/Loan/Http/Resources/LoanCollateralResource.php
 * Unit:            CR-L2 — Ethiopian calendar date rendering (Loan resources)
 * Delivery type:   Amendment — one import, one trait use, five
 *                  `_display` fields, docblock amendments.
 * Net lines:       +2 imports/use; +5 field lines; docblock revision.
 *
 * Before:
 *   Every date field the resource emitted was ISO 8601 only:
 *
 *     valuation_date, registered_at, released_at,
 *     created_at, updated_at   (5 date fields)
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
 *   WalletResource's and LoanResource's precedent) and emits a
 *   `_display` sibling for each of its five date fields. The ISO
 *   fields are preserved byte-for-byte; the `_display` fields are
 *   additive.
 *
 *   Each `_display` field appears immediately after its ISO parent:
 *
 *     valuation_date_display, registered_at_display,
 *     released_at_display, created_at_display, updated_at_display
 *
 *   The nullable ISO dates (`valuation_date` when the product policy
 *   does not require valuation; `released_at` for an unreleased
 *   collateral) have nullable `_display` siblings — the trait returns
 *   null for null input, so the two forms agree on which rows carry a
 *   value.
 *
 *   `valuation_date` is a DATE column per the migration A.11; its ISO
 *   form carries a `T00:00:00` component, while its `_display` sibling
 *   renders the calendar day only (the trait's method, following the
 *   P3 · Unit 2 convention, emits the date portion without a time
 *   component).
 *
 * Public surface delta:
 *   · No new methods. The trait contributes one `protected localizedDate()`
 *     method, which is not part of the resource's external contract.
 *   · The serialised payload gains five keys. No key is removed,
 *     renamed, or re-valued.
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
 * MASES Phase 2C — Loan Collateral Resource
 * =============================================================================
 *
 * File:      mases_core/src/Domains/Loan/Http/Resources/LoanCollateralResource.php
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
 * LoanCollateralResource
 *
 * The HTTP representation of a `LoanCollateral` (A.20). Serializes one
 * collateral record attached to a loan: its type, its identifying and
 * valuation attributes, its pledge linkage where applicable, its
 * lifecycle status, its release metadata where applicable, and — when
 * eager-loaded — the pledged shareholding and the registering/releasing
 * actor summaries.
 *
 * =============================================================================
 * Shape
 * =============================================================================
 * The resource emits seven groups of fields:
 *
 *   1. IDENTITY — the collateral's primary key, its parent loan id, its
 *      type (as the enum's snake_case backing string), and its
 *      free-text description.
 *
 *   2. EXTERNAL REFERENCE — the optional `external_reference` field.
 *
 *   3. PLEDGE LINKAGE — the two columns declared on the
 *      `shareholding`-typed collateral: `pledged_shareholding_id` and
 *      `pledged_quantity`. Both are emitted unconditionally (as null
 *      when absent); a caller distinguishes "not applicable" (the
 *      collateral is external-typed) from "required but missing" (a
 *      data defect) via the type field.
 *
 *   4. VALUATION TRIPLE — `valuation_amount`, `valuation_currency`, and
 *      `valuation_date`. All three are emitted unconditionally (as null
 *      when absent).
 *
 *   5. LIFECYCLE STATUS AND RELEASE TRIPLE — the `status` enum (as its
 *      snake_case backing string) and, when the collateral has been
 *      released, the `released_at`, `release_reason`, and `released_by`
 *      fields.
 *
 *   6. COMPUTED PREDICATES — the five read-only helpers declared on the
 *      model (A.20): `has_pledge`, `has_valuation`, `is_registered`,
 *      `is_released`, and the two type-classification booleans
 *      `is_external` and `is_shareholding`. These give a client the
 *      immediate answers to the questions that a UI would ask without
 *      requiring the client to recompute them.
 *
 *   7. NESTED SUMMARIES (whenLoaded) — the pledged shareholding summary
 *      (`pledged_share_holding`), the registering actor
 *      (`registered_by_user`), and the releasing actor
 *      (`released_by_user`).
 *
 * =============================================================================
 * Monetary and quantity values are decimal strings
 * =============================================================================
 * Two fields carry decimal quantities: `pledged_quantity` and
 * `valuation_amount`. Both are emitted as the strings the model's
 * `decimal:4` casts produce (A.20's `$casts` array).
 *
 * The `pledged_quantity` field is a decimal string, not an integer,
 * because share quantities on the Phase 2A `share_holdings` table are
 * `DECIMAL(20,4)`; the pledge must match. The resource preserves the
 * string representation end-to-end.
 *
 * The resource NEVER casts a monetary or quantity value to float. The
 * platform-wide no-floats discipline (handoff §3) applies to the
 * resource layer as strictly as it applies to the service layer.
 *
 * =============================================================================
 * Both languages — no, only one description field
 * =============================================================================
 * The `loan_collaterals` table declares a single `description` column,
 * not a bilingual pair. The migration A.11 does not carry
 * `description_am` / `description_en`. The resource therefore emits one
 * `description` field, sourced from that column. A future CR that
 * introduces bilingual collateral descriptions would add the pair at
 * the schema level and extend this resource.
 *
 * =============================================================================
 * Dates — ISO 8601 and localized display siblings  (CR-L2)
 * =============================================================================
 * Five date fields are emitted. Each is accompanied by a `_display`
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
 * The nullable dates have nullable `_display` siblings — the trait
 * returns null for null input, so `valuation_date_display` is null
 * exactly when `valuation_date` is null, and `released_at_display` is
 * null exactly when `released_at` is null.
 *
 * `valuation_date` is a `date` column (not a timestamp); the model's
 * `date` cast yields a Carbon instance at midnight, so the emitted ISO
 * 8601 string carries a `T00:00:00` component. Its `_display` sibling
 * renders the calendar day only, following the P3 · Unit 2 convention.
 * If a deployment prefers the plain `Y-m-d` form for the ISO
 * `valuation_date`, that change is localized to this resource's
 * `valuation_date` line (see the corresponding deployment note in the
 * pre-CR-L2 version).
 *
 * `registered_at` is NOT NULL per the migration A.11 (it has a
 * `useCurrent()` default); the null-safe operator is applied uniformly
 * for defensive consistency, and the `_display` sibling handles a
 * hypothetical null the same way the ISO form does.
 *
 * =============================================================================
 * The whenLoaded contract
 * =============================================================================
 * Three relations are exposed via `whenLoaded()`:
 *
 *   'pledgedShareHolding' — the ShareHolding the pledge references, for
 *                           a shareholding-typed collateral.
 *   'registeredBy'        — the User who registered the collateral.
 *   'releasedBy'          — the User who released the collateral (null
 *                           for an unreleased collateral).
 *
 * A resource serialized without eager-loading a given relation omits
 * the corresponding field entirely. Controllers compose the eager-load
 * set at the query boundary via the `$with` parameter on
 * `LoanCollateralRepository` (A.58). The resource NEVER triggers a
 * lazy-load.
 *
 * =============================================================================
 * The `pledged_share_holding` summary
 * =============================================================================
 * No `ShareHoldingResource` exists in the Phase 2C manifest. The
 * summary is built inline by the private `pledgedShareHoldingSummary()`
 * helper. It emits the four identifying columns the Phase 2A
 * `share_holdings` table is known to carry:
 *
 *   id                  — the shareholding's primary key.
 *   shareholder_id      — the owning shareholder's primary key.
 *   share_class_id      — the share class's primary key.
 *   current_quantity    — the shareholding's current quantity, as a
 *                         decimal string at scale 4.
 *
 * A future CR that introduces a `ShareHoldingResource` would replace
 * the helper with a call to that resource; the public shape of
 * `LoanCollateralResource` would not change.
 *
 * The helper returns null when the loaded relation is null (an
 * external-typed collateral has no pledged shareholding; a
 * shareholding-typed collateral with a stale reference also yields
 * null). The reference's integrity is enforced by
 * `LoanCollateralService` (A.37) at registration time in Phase 2C;
 * the deferred FK (CR-2C-COLLATERAL-PLEDGE-FK-001) will enforce it at
 * the database level in a future CR.
 *
 * =============================================================================
 * The actor summaries
 * =============================================================================
 * The two actor relations — `registeredBy` and `releasedBy` — emit the
 * platform-wide `{id, email, name}` inline payload, matching the
 * pattern used by `LoanResource` (A.69) and every other Phase 2A/2B
 * resource. No `UserResource` exists in the Phase 2C manifest.
 *
 * `released_by_user` is emitted as null for an unreleased collateral
 * (the relation is loaded but null) and as the actor summary for a
 * released one.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does NOT trigger lazy-loads. See §"The whenLoaded contract".
 *
 *   - It does NOT emit the parent loan relation. The loan's id is
 *     emitted as `loan_id`; a caller that needs the loan entity
 *     resolves it through its own read path.
 *
 *   - It does NOT emit the pledged shareholding's nested relations
 *     (its own `shareholder`, its own `shareClass`, its
 *     `shareHoldingEntries`). The summary is deliberately shallow; a
 *     caller that needs deeper shareholding detail resolves it through
 *     the Shareholder domain's own read paths.
 *
 *   - It does NOT cast a monetary or quantity value to float. See
 *     §"Monetary and quantity values are decimal strings".
 *
 *   - It does NOT expose the `pledged_shareholding_id` twice. The
 *     linkage column is emitted once as `pledged_shareholding_id`, and
 *     the nested summary as `pledged_share_holding`. A caller that
 *     needs only the id reads the column; a caller that needs the
 *     summary reads the loaded relation.
 *
 *   - It does NOT emit a `status` label. The raw string is emitted; a
 *     localized label is a presentation-layer concern.
 *
 *   - It does NOT emit a computed `days_since_registration` or
 *     `days_since_release`. Date-difference computations are a
 *     caller-side concern.
 *
 *   - It does NOT perform arithmetic. Every field is a direct column
 *     read or a delegated model helper.
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
 *           · Five `_display` sibling fields are added — one for
 *             `valuation_date`, one for `registered_at`, one for
 *             `released_at`, one for `created_at`, one for
 *             `updated_at`. Each `_display` field appears immediately
 *             after its ISO sibling.
 *
 *           · The ISO fields are preserved byte-for-byte. Every existing
 *             key retains its name, its type, and its value; the
 *             `_display` fields are additive.
 *
 *           · No structural change. No new dependency beyond the trait.
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
 *   Phase 2C A.3                                 — `CollateralStatus`
 *   Phase 2C A.4                                 — `CollateralType`
 *   Phase 2C A.11                                — `loan_collaterals`
 *                                                  migration
 *   Phase 2C A.20                                — `LoanCollateral` (the
 *                                                  model serialized by
 *                                                  this resource, whose
 *                                                  consistency and
 *                                                  state helpers this
 *                                                  resource delegates
 *                                                  to)
 *   Phase 2C A.37                                — `LoanCollateralService`
 *   Phase 2C A.58                                — `LoanCollateralRepository`
 *                                                  (the `$with`
 *                                                  composition point)
 *   Phase 2C A.69                                — `LoanResource` (the
 *                                                  parent resource that
 *                                                  embeds a collection
 *                                                  of these via
 *                                                  `whenLoaded`)
 *   Phase 2C A.79                                — `LoanCollateralController`
 *                                                  (the primary HTTP
 *                                                  consumer)
 *   CR-2C-COLLATERAL-PLEDGE-FK-001               — deferred FK
 *   Phase 2A 2A.39                               — `share_holdings`
 *                                                  migration (the source
 *                                                  of the pledged
 *                                                  shareholding's
 *                                                  columns)
 *   Phase 2B 2B.23                               — `WalletResource` (the
 *                                                  sibling resource)
 *   P3 · Unit 2                                  — Ethiopian calendar
 *                                                  date rendering (the
 *                                                  convention this
 *                                                  amendment extends)
 *   CR-W1                                        — the cross-domain trait
 *                                                  import precedent
 *   CR-L2                                        — This amendment
 *   Step 5 (manifest) A.72                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Resources
 * @version 1.1.0
 */
final class LoanCollateralResource extends JsonResource
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
     * Transform the collateral into its array representation.
     *
     * See the class docblock for the field layout and the
     * no-lazy-loads / no-floats / ISO-plus-`_display` / whenLoaded
     * contract.
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
            'id'              => (int) $this->id,
            'loan_id'         => (int) $this->loan_id,
            'collateral_type' => $this->collateral_type->value,
            'description'     => (string) $this->description,

            // -----------------------------------------------------------------
            // External reference.
            // -----------------------------------------------------------------
            'external_reference' => $this->external_reference,

            // -----------------------------------------------------------------
            // Pledge linkage.
            //
            // Both columns are emitted unconditionally; null for an
            // external-typed collateral (which must not carry a pledge
            // linkage, per A.4 and A.20).
            // -----------------------------------------------------------------
            'pledged_shareholding_id' => $this->pledged_shareholding_id,
            'pledged_quantity'        => $this->pledged_quantity,

            // -----------------------------------------------------------------
            // Valuation triple.
            //
            // All three columns are emitted unconditionally; null when
            // absent (for a shareholding-typed collateral whose product
            // policy does not require valuation). `valuation_date` is
            // emitted as ISO 8601 plus a localized `_display` sibling,
            // per CR-L2.
            // -----------------------------------------------------------------
            'valuation_amount'         => $this->valuation_amount,
            'valuation_currency'       => $this->valuation_currency,
            'valuation_date'           => $this->valuation_date?->toIso8601String(),
            'valuation_date_display'   => $this->localizedDate($this->valuation_date),

            // -----------------------------------------------------------------
            // Lifecycle status and registration metadata.
            //
            // `registered_at` is emitted as ISO 8601 plus a localized
            // `_display` sibling, per CR-L2.
            // -----------------------------------------------------------------
            'status'                 => $this->status->value,
            'registered_at'          => $this->registered_at?->toIso8601String(),
            'registered_at_display'  => $this->localizedDate($this->registered_at),
            'registered_by'          => $this->registered_by,

            // -----------------------------------------------------------------
            // Release triple.
            //
            // All three columns are null for a `registered` collateral.
            // The `released_by` FK column is emitted as a raw id; its
            // corresponding user summary is emitted below as
            // `released_by_user` when the relation is eager-loaded.
            // `released_at` is emitted as ISO 8601 plus a localized
            // `_display` sibling, per CR-L2.
            // -----------------------------------------------------------------
            'released_at'          => $this->released_at?->toIso8601String(),
            'released_at_display'  => $this->localizedDate($this->released_at),
            'release_reason'       => $this->release_reason,
            'released_by'          => $this->released_by,

            // -----------------------------------------------------------------
            // Computed predicates.
            //
            // Delegated to the model's read-only helpers (A.20). Every
            // call is a pure in-memory check; no I/O is triggered.
            // -----------------------------------------------------------------
            'is_external'     => $this->isExternal(),
            'is_shareholding' => $this->isShareholding(),
            'is_registered'   => $this->isRegistered(),
            'is_released'     => $this->isReleased(),
            'has_pledge'      => $this->hasPledge(),
            'has_valuation'   => $this->hasValuation(),

            // -----------------------------------------------------------------
            // Pledged shareholding summary (whenLoaded).
            //
            // Emitted only when the `pledgedShareHolding` relation is
            // eager-loaded. Null when the loaded relation is null
            // (external-typed collateral, or a stale reference).
            // -----------------------------------------------------------------
            'pledged_share_holding' => $this->whenLoaded(
                'pledgedShareHolding',
                fn (): ?array => $this->pledgedShareHoldingSummary(),
            ),

            // -----------------------------------------------------------------
            // Registered-by actor summary (whenLoaded).
            // -----------------------------------------------------------------
            'registered_by_user' => $this->whenLoaded(
                'registeredBy',
                fn (): ?array => $this->actorSummary($this->registeredBy),
            ),

            // -----------------------------------------------------------------
            // Released-by actor summary (whenLoaded).
            //
            // Null for an unreleased collateral (the loaded relation is
            // null); the actor summary for a released one.
            // -----------------------------------------------------------------
            'released_by_user' => $this->whenLoaded(
                'releasedBy',
                fn (): ?array => $this->actorSummary($this->releasedBy),
            ),

            // -----------------------------------------------------------------
            // Timestamps — ISO + localized display, paired.  (CR-L2)
            // -----------------------------------------------------------------
            'created_at'         => $this->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($this->created_at),
            'updated_at'         => $this->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($this->updated_at),
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

    /**
     * Build the inline pledged shareholding summary.
     *
     * Returns null when the loaded `pledgedShareHolding` relation is
     * null — the state an external-typed collateral legitimately
     * carries, or the state a shareholding-typed collateral carries
     * when its reference points at a now-deleted shareholding (a state
     * the deferred FK will prevent once
     * CR-2C-COLLATERAL-PLEDGE-FK-001 lands).
     *
     * The emitted fields are the four identifying columns the Phase 2A
     * `share_holdings` table carries. `current_quantity` is emitted as
     * a decimal string at scale 4. A future CR that exposes richer
     * ShareHolding fields extends this payload.
     *
     * @return array{
     *     id: int,
     *     shareholder_id: int,
     *     share_class_id: int,
     *     current_quantity: string
     * }|null
     */
    private function pledgedShareHoldingSummary(): ?array
    {
        $shareHolding = $this->pledgedShareHolding;

        if ($shareHolding === null) {
            return null;
        }

        return [
            'id'               => (int) $shareHolding->getKey(),
            'shareholder_id'   => (int) $shareHolding->shareholder_id,
            'share_class_id'   => (int) $shareHolding->share_class_id,
            'current_quantity' => (string) $shareHolding->current_quantity,
        ];
    }
}