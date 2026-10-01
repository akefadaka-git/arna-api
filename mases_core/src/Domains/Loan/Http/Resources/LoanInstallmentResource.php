<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-L2
 * =============================================================================
 *
 * File:            mases_core/src/Domains/Loan/Http/Resources/LoanInstallmentResource.php
 * Unit:            CR-L2 — Ethiopian calendar date rendering (Loan resources)
 * Delivery type:   Amendment — one import, one trait use, three
 *                  `_display` fields, docblock amendments.
 * Net lines:       +2 imports/use; +3 field lines; docblock revision.
 *
 * Before:
 *   Every date field the resource emitted was ISO 8601 only:
 *
 *     due_date, created_at, updated_at   (3 date fields)
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
 *   `_display` sibling for each of its three date fields. The ISO
 *   fields are preserved byte-for-byte; the `_display` fields are
 *   additive.
 *
 *   Each `_display` field appears immediately after its ISO parent:
 *
 *     due_date_display, created_at_display, updated_at_display
 *
 *   The `due_date` column is a DATE (not a timestamp) per the
 *   migration A.10; its `_display` sibling renders the same calendar
 *   day in the Amharic-first medium form. The two `Carbon` timestamps
 *   carry time-of-day in the ISO form; the `_display` form renders the
 *   calendar day only (LocalizationService::formatEthiopianDateMedium()
 *   emits the date, not the time), which matches the P3 · Unit 2
 *   convention.
 *
 * Public surface delta:
 *   · No new methods. The trait contributes one `protected localizedDate()`
 *     method, which is not part of the resource's external contract.
 *   · The serialised payload gains three keys. No key is removed,
 *     renamed, or re-valued.
 *
 * Dependency note:
 *   · The trait is imported from the Shareholder domain's Concerns
 *     namespace, matching the cross-domain import on LoanResource.php
 *     (CR-L2 file 1). Reading 1 was confirmed: the trait stays where
 *     it is for CR-L2; a future CR may relocate it.
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
 * MASES Phase 2C — Loan Installment Resource
 * =============================================================================
 *
 * File:      mases_core/src/Domains/Loan/Http/Resources/LoanInstallmentResource.php
 * Version:   1.1.0
 *
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;

/**
 * LoanInstallmentResource
 *
 * The HTTP representation of a `LoanInstallment` (A.19). Serializes one
 * row of a loan's contractual schedule: its identity within the schedule,
 * its due date, its expected triple, its settled triple, its lifecycle
 * status, and the three computed `remaining_*` helpers.
 *
 * =============================================================================
 * Shape
 * =============================================================================
 * The resource emits six groups of fields:
 *
 *   1. IDENTITY — the installment's primary key, its parent loan id, and
 *      its monotonic `installment_number` (per the migration A.10
 *      numbering discipline, unique across all schedules of the same
 *      loan).
 *
 *   2. DUE DATE — the installment's `due_date`, emitted as an ISO 8601
 *      date string AND as a localized display string.
 *
 *   3. EXPECTED TRIPLE — the immutable monetary triple set at schedule
 *      generation: `expected_principal`, `expected_interest`,
 *      `expected_total` (A.19 §"What this model represents").
 *
 *   4. SETTLED TRIPLE — the mutable monetary triple updated by payments:
 *      `settled_principal`, `settled_interest`, `settled_amount`.
 *
 *   5. REMAINING TRIPLE (computed) — the three `remaining_*` helpers
 *      declared on the model (A.19): `remaining_principal`,
 *      `remaining_interest`, `remaining_total`. Each is computed by
 *      bcmath at scale 4 and floored at zero.
 *
 *   6. STATUS AND TIMESTAMPS — the installment's `status` (an
 *      `InstallmentStatus` case, emitted as its snake_case backing
 *      string) and the model's `created_at` / `updated_at` timestamps,
 *      each ISO 8601 with a localized `_display` sibling.
 *
 * =============================================================================
 * Every monetary field is a decimal string
 * =============================================================================
 * All nine monetary fields — the three expected, the three settled, and
 * the three remaining — are emitted as the strings the model's
 * `decimal:4` casts produce (A.19's `$casts` array). The three
 * `remaining_*` helpers delegate to the model's bcmath-based
 * computations (A.19); no arithmetic is performed inside this resource.
 *
 * The resource NEVER casts a monetary value to float. The platform-wide
 * no-floats discipline (handoff §3) applies to the resource layer as
 * strictly as it applies to the service layer.
 *
 * =============================================================================
 * `remaining_*` are computed, not stored
 * =============================================================================
 * The three `remaining_*` fields are NOT columns on the
 * `loan_installments` table. They are computed on demand by three
 * helpers declared on the model (A.19). Each computes
 * `expected_X − settled_X` at scale 4 via `bcsub()`, floors the result
 * at zero (an overpaid installment reports `"0.0000"`, not a negative
 * value), and returns a decimal string.
 *
 * Emitting them on the resource gives an API consumer the immediate
 * answer to "how much is still owed on this installment?" without
 * requiring the consumer to recompute the subtraction on the client side.
 * The consumer can also reconstruct them from the raw triples; the two
 * paths produce identical values.
 *
 * The three helpers are PURE reads. Calling them does not trigger a
 * query, does not mutate state, and does not dispatch events.
 *
 * =============================================================================
 * The `status` field
 * =============================================================================
 * The `status` column is cast to `InstallmentStatus` on the model (A.19);
 * `->value` yields the snake_case backing string. The five possible
 * values are, per the frozen A.5 vocabulary:
 *
 *   'pending'     — no settlement yet.
 *   'partial'     — some settlement, not yet full.
 *   'settled'     — fully settled.
 *   'overdue'     — past due, not yet settled.
 *   'superseded'  — belongs to a prior schedule, replaced by a
 *                   restructuring.
 *
 * The resource emits the string directly; a client that needs a label
 * resolves it through its own presentation layer.
 *
 * =============================================================================
 * Dates — ISO 8601 and localized display siblings  (CR-L2)
 * =============================================================================
 * Three date fields are emitted. Each is accompanied by a `_display`
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
 * `due_date` is a DATE column per the migration A.10; the model's
 * `date` cast produces a Carbon instance at midnight. Its ISO form
 * carries `T00:00:00`; its `_display` form carries the calendar day
 * only (the trait's method, following the P3 · Unit 2 convention,
 * emits the date portion without a time component).
 *
 * A future CR that changes the display format changes one method in
 * LocalizationService, and the change propagates to every resource that
 * uses this trait without a resource-level edit.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does NOT trigger lazy-loads. The resource accesses only the
 *     installment's own columns and the three `remaining_*` helpers,
 *     all of which are resolved without additional I/O.
 *
 *   - It does NOT emit the installment's `loan` relation. The parent
 *     loan's id is emitted as `loan_id`; a caller that needs the loan
 *     entity resolves it through its own read path.
 *
 *   - It does NOT emit the installment's `interestAccrual` or
 *     `penaltyAccrual` relations. Those are bookkeeping records for the
 *     domain's internal reconciliation; the reconciliation report
 *     (A.75) is their consumer. Emitting them inline on every
 *     installment would bloat the schedule payload.
 *
 *   - It does NOT expose the `accrual_period_key` or any other
 *     accrual-layer identifier. The installment's own identity is
 *     sufficient; the accrual bookkeeping is a separate concern.
 *
 *   - It does NOT perform arithmetic beyond the delegated model
 *     helpers. The three `remaining_*` fields are computed by the model
 *     (A.19) with bcmath at scale 4.
 *
 *   - It does NOT cast a monetary value to float. See §"Every monetary
 *     field is a decimal string".
 *
 *   - It does NOT emit a `status` label. The raw string is emitted; a
 *     localized label is a presentation-layer concern.
 *
 *   - It does NOT compute a `days_until_due` or `days_overdue` field. A
 *     date-difference computation depends on the request's current
 *     moment and the tenant's timezone, both of which vary per request.
 *     A client that needs "how many days until this installment is due?"
 *     computes it from `due_date` and its own clock.
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
 *           · Three `_display` sibling fields are added — one for
 *             `due_date`, one for `created_at`, one for `updated_at`.
 *             Each `_display` field appears immediately after its ISO
 *             sibling.
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
 *   Phase 2C Decision Register, 2026-09-12       — A9, D132, D135
 *   Phase 2C Clarification Response, 2026-09-12  — C4, C5
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.10                                — `loan_installments`
 *                                                  migration
 *   Phase 2C A.19                                — `LoanInstallment` (the
 *                                                  model serialized by
 *                                                  this resource, whose
 *                                                  `remaining_*` helpers
 *                                                  this resource
 *                                                  delegates to)
 *   Phase 2C A.69                                — `LoanResource` (the
 *                                                  parent resource that
 *                                                  embeds a collection
 *                                                  of these via
 *                                                  `whenLoaded`)
 *   Phase 2C A.73                                — `LoanScheduleResource`
 *                                                  (the schedule-view
 *                                                  projection that
 *                                                  aggregates these)
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
 *   Step 5 (manifest) A.71                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Resources
 * @version 1.1.0
 */
final class LoanInstallmentResource extends JsonResource
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
     * Transform the installment into its array representation.
     *
     * See the class docblock for the field layout and the
     * no-lazy-loads / no-floats / decimal-strings / ISO-plus-`_display`
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
            'id'                 => (int) $this->id,
            'loan_id'            => (int) $this->loan_id,
            'installment_number' => (int) $this->installment_number,

            // -----------------------------------------------------------------
            // Due date — ISO + localized display, paired.  (CR-L2)
            //
            // The `due_date` column is a DATE (not a timestamp) per the
            // migration A.10; the `date` cast on the model produces a
            // Carbon instance at midnight. Emitting it as ISO 8601
            // preserves the date portion; a client that needs the
            // time-of-day reads `"00:00:00"` and interprets accordingly.
            // The `_display` sibling renders the same calendar day in
            // the localized medium Ethiopian form. See the class
            // docblock §"Dates — ISO 8601 and localized display
            // siblings".
            // -----------------------------------------------------------------
            'due_date'         => $this->due_date?->toIso8601String(),
            'due_date_display' => $this->localizedDate($this->due_date),

            // -----------------------------------------------------------------
            // Expected triple (immutable).
            // -----------------------------------------------------------------
            'expected_principal' => (string) $this->expected_principal,
            'expected_interest'  => (string) $this->expected_interest,
            'expected_total'     => (string) $this->expected_total,

            // -----------------------------------------------------------------
            // Settled triple (mutable).
            // -----------------------------------------------------------------
            'settled_principal' => (string) $this->settled_principal,
            'settled_interest'  => (string) $this->settled_interest,
            'settled_amount'    => (string) $this->settled_amount,

            // -----------------------------------------------------------------
            // Remaining triple (computed via the model's bcmath helpers).
            //
            // Each helper computes `expected_X − settled_X` at scale 4
            // and floors the result at zero. The three calls are pure
            // reads with no I/O.
            // -----------------------------------------------------------------
            'remaining_principal' => $this->remainingPrincipal(),
            'remaining_interest'  => $this->remainingInterest(),
            'remaining_total'     => $this->remainingTotal(),

            // -----------------------------------------------------------------
            // Status.
            //
            // The `status` column is enum-cast; `->value` yields the
            // snake_case backing string.
            // -----------------------------------------------------------------
            'status' => $this->status->value,

            // -----------------------------------------------------------------
            // Timestamps — ISO + localized display, paired.  (CR-L2)
            // -----------------------------------------------------------------
            'created_at'         => $this->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($this->created_at),
            'updated_at'         => $this->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($this->updated_at),
        ];
    }
}