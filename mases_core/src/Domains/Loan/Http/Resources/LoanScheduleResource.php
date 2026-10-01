<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — CR-L2
 * =============================================================================
 *
 * File:            mases_core/src/Domains/Loan/Http/Resources/LoanScheduleResource.php
 * Unit:            CR-L2 — Ethiopian calendar date rendering (Loan resources)
 * Delivery type:   Amendment — one import, one trait use, one
 *                  `_display` field, docblock amendments.
 * Net lines:       +2 imports/use; +1 field line; docblock revision.
 *
 * Before:
 *   The resource emitted one date field of its own — `maturity_date` —
 *   as ISO 8601 only, with no `_display` sibling. Consumers that
 *   wanted the Amharic-first medium form had to slice the ISO string
 *   client-side.
 *
 *   The embedded `installments` collection was already covered: it
 *   serializes through `LoanInstallmentResource`, whose `_display`
 *   fields are delivered by CR-L2 file 2. The `totals` block carries
 *   no dates. The resource therefore contributes one date field to
 *   CR-L2's scope.
 *
 * After:
 *   The resource mixes in RendersLocalizedDates (Reading 1:
 *   cross-domain import from the Shareholder domain, matching
 *   WalletResource's and the sibling Loan resources' precedent) and
 *   emits a `_display` sibling for its one date field. The ISO field
 *   is preserved byte-for-byte; the `_display` field is additive.
 *
 *   The `_display` field appears immediately after its ISO parent:
 *
 *     maturity_date_display
 *
 *   `maturity_date` is nullable — a pre-disbursement loan has no
 *   maturity date set (it is written by LoanScheduleService at
 *   disbursement). The `_display` sibling is therefore nullable too —
 *   the trait returns null for null input, so the two forms agree on
 *   which rows carry a value.
 *
 * Public surface delta:
 *   · No new methods. The trait contributes one `protected localizedDate()`
 *     method, which is not part of the resource's external contract.
 *   · The serialised payload gains one key. No key is removed,
 *     renamed, or re-valued. In particular, the `totals` block and
 *     the embedded `installments` collection are unchanged (the
 *     latter's payload grows internally per file 2, but the field
 *     composition here is identical).
 *
 * Dependency note:
 *   · The trait is imported from the Shareholder domain's Concerns
 *     namespace, matching the cross-domain import on LoanResource.php
 *     (CR-L2 file 1). Reading 1 was confirmed: the trait stays where
 *     it is for CR-L2.
 *
 *   · File 2 of CR-L2 (`LoanInstallmentResource.php`) delivers the
 *     `_display` fields that appear on the embedded `installments`
 *     collection. Both files should be deployed in the same batch;
 *     the schedule endpoint's payload depends on both.
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
 * MASES Phase 2C — Loan Schedule Resource
 * =============================================================================
 *
 * File:      mases_core/src/Domains/Loan/Http/Resources/LoanScheduleResource.php
 * Version:   1.1.0
 *
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Resources;

use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;

/**
 * LoanScheduleResource
 *
 * The HTTP representation of a loan's contractual schedule view — the
 * composite that a schedule endpoint (`GET /loans/{loan}/schedule`,
 * A.81) returns.
 *
 * This resource is a PROJECTION over a `Loan` aggregate: it emits the
 * loan's schedule-relevant identity, the persisted schedule-summary
 * columns, a computed aggregate of the installments, and the installments
 * themselves (as a `LoanInstallmentResource` collection).
 *
 * =============================================================================
 * Why this resource exists separately from LoanResource (A.69)
 * =============================================================================
 * `LoanResource` is the loan's full representation, designed for the
 * show endpoint. It exposes every field the loan row carries, plus every
 * related record the controller chooses to eager-load.
 *
 * `LoanScheduleResource` is a NARROW view: it exposes only the fields
 * that matter to a consumer reading a payment schedule. It omits the
 * loan's free-text fields (`purpose`, `external_reference`), the
 * outstanding-projection triple (those are the loan's state, not its
 * schedule), the entire `whenLoaded` relation set of `LoanResource`
 * (borrower, product, policyVersion, collaterals, restructurings,
 * write_off, status_histories, actor summaries), and the loan's actor
 * FKs.
 *
 * The separation serves three purposes:
 *
 *   1. A schedule endpoint's payload stays small and focused. The
 *      installments array dominates the response; every additional
 *      field is payload bloat.
 *
 *   2. The aggregate TOTALS section — computed from the installments —
 *      is meaningful only in the schedule context. `LoanResource`
 *      does not carry it.
 *
 *   3. The two resources can evolve independently. A future CR that
 *      adds a field to `LoanResource` (e.g., a new lifecycle date)
 *      does not automatically grow the schedule payload.
 *
 * =============================================================================
 * Shape
 * =============================================================================
 * The resource emits four groups of fields:
 *
 *   1. IDENTITY — the loan's primary key, its tenant, its borrower and
 *      product FKs, and its current lifecycle status.
 *
 *   2. TERMS — the loan's currency, its principal, and its term in
 *      months.
 *
 *   3. SCHEDULE SUMMARY (from the loan row) — the three persisted
 *      summary columns written by `LoanScheduleService::generate()`
 *      (A.27) at disbursement or restructure time:
 *      `monthly_installment_amount`, `total_scheduled_interest`,
 *      `total_scheduled_repayment`, plus the `maturity_date` (ISO 8601
 *      with a localized `_display` sibling).
 *
 *   4. INSTALLMENTS AND TOTALS (whenLoaded) — the full installment
 *      collection serialized through `LoanInstallmentResource` (A.71),
 *      and a `totals` object that aggregates the collection. Both
 *      fields are emitted only when the `installments` relation is
 *      eager-loaded.
 *
 * =============================================================================
 * The totals object
 * =============================================================================
 * The `totals` object aggregates the loaded installments into a single
 * summary. All monetary values are decimal strings at scale 4, computed
 * with BCMath; the resource NEVER uses floating-point arithmetic.
 *
 * The totals distinguish ACTIVE installments (those not in the
 * `superseded` state) from the full collection:
 *
 *   - The monetary sums are computed over the ACTIVE installments only.
 *     A superseded installment belonged to a prior schedule that a
 *     restructure has replaced; its expected values are no longer part
 *     of the loan's contractual obligation, and including them would
 *     double-count the restructured debt.
 *
 *   - The counts section reports every status separately, including
 *     `superseded_count`, so a caller that wants the full population's
 *     breakdown has it.
 *
 * The totals object emits:
 *
 *   Counts:
 *     installment_count          — every installment, including superseded.
 *     active_installment_count   — installments not in `superseded`.
 *     pending_count              — installments in `pending`.
 *     partial_count              — installments in `partial`.
 *     settled_count              — installments in `settled`.
 *     overdue_count              — installments in `overdue`.
 *     superseded_count           — installments in `superseded`.
 *
 *   Active-installment monetary sums (BCMath at scale 4):
 *     total_expected_principal   — Σ expected_principal.
 *     total_expected_interest    — Σ expected_interest.
 *     total_expected_repayment   — Σ expected_total.
 *     total_settled_principal    — Σ settled_principal.
 *     total_settled_interest     — Σ settled_interest.
 *     total_settled_amount       — Σ settled_amount.
 *     total_remaining_principal  — Σ remaining_principal.
 *     total_remaining_interest   — Σ remaining_interest.
 *     total_remaining_total      — Σ remaining_total.
 *
 * The three `remaining_*` totals delegate to the model's helpers (A.19),
 * which floor each per-installment remainder at zero; the totals
 * therefore reflect the same floor-at-zero discipline at the aggregate
 * level.
 *
 * A well-formed loan satisfies two invariants, both of which are useful
 * for tests:
 *
 *     total_scheduled_repayment (from the loan row)
 *       == totals.total_expected_repayment
 *
 *     total_remaining_total
 *       == the loan's outstandingTotal() (A.18)
 *
 * =============================================================================
 * Every monetary field is a decimal string
 * =============================================================================
 * Both the schedule-summary columns from the loan row and the computed
 * totals emit as decimal strings at scale 4. No value in this resource's
 * output is a JSON number when it represents money.
 *
 * =============================================================================
 * Dates — ISO 8601 and localized display siblings  (CR-L2)
 * =============================================================================
 * One date field is emitted at the resource's top level: `maturity_date`.
 * It is accompanied by a `_display` sibling produced by
 * LocalizationService::formatEthiopianDateMedium() via the
 * RendersLocalizedDates trait (imported cross-domain from the
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
 * `maturity_date` is nullable — a pre-disbursement loan has no maturity
 * date set (it is written by `LoanScheduleService`, A.27, at
 * disbursement). The `_display` sibling is nullable too, so the pairing
 * on which rows carry a value is preserved without a special case.
 *
 * The embedded `installments` collection carries three date fields per
 * entry (`due_date`, `created_at`, `updated_at`), each with its own
 * `_display` sibling delivered by CR-L2 file 2
 * (`LoanInstallmentResource.php`). See that file's class docblock for
 * the installment-level date rendering.
 *
 * =============================================================================
 * The whenLoaded contract
 * =============================================================================
 * The `installments` and `totals` fields are both wrapped in
 * `whenLoaded('installments', ...)`. A resource serialized without the
 * relation eager-loaded omits both fields. Controllers MUST eager-load
 * the `installments` relation before instantiating this resource; the
 * schedule endpoint's whole purpose is to present those installments,
 * so the controller's eager-load set is a design obligation, not an
 * optional optimization.
 *
 * The resource NEVER triggers a lazy-load.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does NOT trigger lazy-loads. See §"The whenLoaded contract".
 *
 *   - It does NOT emit the loan's free-text fields, its outstanding
 *     projections, its actor FKs, or any of the loan's other relations.
 *     See §"Why this resource exists separately from LoanResource".
 *
 *   - It does NOT compute monetary values with floating-point
 *     arithmetic. Every sum uses BCMath at scale 4.
 *
 *   - It does NOT emit a `status` label or a per-installment status
 *     label. Enum backing values are emitted; localization is a
 *     presentation-layer concern.
 *
 *   - It does NOT emit a `days_until_due` or `days_overdue` field. Such
 *     computations depend on the request's clock and the tenant's
 *     timezone.
 *
 *   - It does NOT filter installments. Every installment of the loan is
 *     serialized, including superseded ones. A caller that wants the
 *     current schedule filters by status on their side; a caller that
 *     wants "only unsettled installments" reads the loan through
 *     `LoanInstallmentRepository::listUnsettledForLoan()` (A.57) at the
 *     query boundary and composes a different resource.
 *
 *   - It does NOT perform authorization. The controller gates the
 *     endpoint through `Gate::authorize('viewSchedule', $loan)` (A.52);
 *     the resource is a pure serializer.
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
 *           · One `_display` sibling field is added — for
 *             `maturity_date`. It appears immediately after its ISO
 *             sibling.
 *
 *           · The embedded `installments` collection's per-entry
 *             `_display` siblings are delivered by CR-L2 file 2
 *             (`LoanInstallmentResource.php`); this file's contribution
 *             to the amendment is the one top-level field.
 *
 *           · The ISO field is preserved byte-for-byte. Every existing
 *             key retains its name, its type, and its value; the
 *             `_display` field is additive. The `totals` block is
 *             unchanged.
 *
 *           · No structural change. No new dependency beyond the trait.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D132, D135
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation)
 *   Phase 2C A.18                                — `Loan` (the aggregate
 *                                                  this resource
 *                                                  projects)
 *   Phase 2C A.19                                — `LoanInstallment` (the
 *                                                  per-row model, whose
 *                                                  `remaining_*` helpers
 *                                                  the totals delegate
 *                                                  to)
 *   Phase 2C A.27                                — `LoanScheduleService`
 *                                                  (the writer of the
 *                                                  schedule-summary
 *                                                  columns)
 *   Phase 2C A.55                                — `LoanRepository` (the
 *                                                  `$with` composition
 *                                                  point for the
 *                                                  installments relation)
 *   Phase 2C A.69                                — `LoanResource` (the
 *                                                  full-loan
 *                                                  representation this
 *                                                  resource is the
 *                                                  narrow sibling of)
 *   Phase 2C A.71                                — `LoanInstallmentResource`
 *                                                  (the per-row
 *                                                  serializer for the
 *                                                  embedded collection,
 *                                                  whose `_display`
 *                                                  fields are delivered
 *                                                  by CR-L2 file 2)
 *   Phase 2C A.81                                — `LoanScheduleController`
 *                                                  (the sole Phase 2C
 *                                                  consumer)
 *   Phase 2B 2B.23                               — `WalletResource` (the
 *                                                  sibling resource)
 *   P3 · Unit 2                                  — Ethiopian calendar
 *                                                  date rendering (the
 *                                                  convention this
 *                                                  amendment extends)
 *   CR-W1                                        — the cross-domain trait
 *                                                  import precedent
 *   CR-L2                                        — This amendment
 *   Step 5 (manifest) A.73                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Resources
 * @version 1.1.0
 */
final class LoanScheduleResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * The monetary scale used for the aggregate sums.
     *
     * Matches the DECIMAL(20,4) monetary columns on the loan and
     * installment schemas, and the scale used by every service in the
     * Loan domain.
     */
    private const FINAL_SCALE = 4;

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
     * Transform the loan into its schedule-view representation.
     *
     * See the class docblock for the field layout and the
     * no-lazy-loads / no-floats / ISO-plus-`_display` contract.
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
            'loan_id'                 => (int) $this->id,
            'tenant_id'               => (int) $this->tenant_id,
            'borrower_shareholder_id' => (int) $this->borrower_shareholder_id,
            'loan_product_id'         => (int) $this->loan_product_id,

            // -----------------------------------------------------------------
            // Lifecycle status.
            //
            // The `status` column is enum-cast on the Loan model (A.18);
            // `->value` yields the snake_case backing string.
            // -----------------------------------------------------------------
            'status' => $this->status->value,

            // -----------------------------------------------------------------
            // Terms.
            // -----------------------------------------------------------------
            'currency'         => $this->currency,
            'principal_amount' => (string) $this->principal_amount,
            'term_months'      => (int) $this->term_months,

            // -----------------------------------------------------------------
            // Schedule summary (from the loan row).
            //
            // These are the persisted values written by
            // `LoanScheduleService::generate()` (A.27). They are
            // nullable before disbursement; the resource emits null in
            // that state.
            //
            // When the loan has a schedule, `total_scheduled_repayment`
            // equals the `totals.total_expected_repayment` computed
            // below; the two are redundant by design, with the row's
            // column serving as the persisted snapshot and the computed
            // value serving as the reconstruction from the rows.
            //
            // `maturity_date` is emitted as ISO 8601 plus a localized
            // `_display` sibling, per CR-L2. See the class docblock
            // §"Dates — ISO 8601 and localized display siblings".
            // -----------------------------------------------------------------
            'monthly_installment_amount' => $this->monthly_installment_amount,
            'total_scheduled_interest'   => $this->total_scheduled_interest,
            'total_scheduled_repayment'  => $this->total_scheduled_repayment,
            'maturity_date'              => $this->maturity_date?->toIso8601String(),
            'maturity_date_display'      => $this->localizedDate($this->maturity_date),

            // -----------------------------------------------------------------
            // Aggregated totals (whenLoaded).
            //
            // Computed from the loaded installments. Omitted entirely
            // when the relation is not loaded.
            // -----------------------------------------------------------------
            'totals' => $this->whenLoaded(
                'installments',
                fn (): array => $this->computeTotals($this->installments),
            ),

            // -----------------------------------------------------------------
            // Installments (whenLoaded).
            //
            // The full collection, serialized through the per-row
            // `LoanInstallmentResource` (A.71). Every installment of the
            // loan is emitted, including superseded ones from prior
            // schedules; the totals above distinguish active vs. total.
            //
            // Each entry carries its own `due_date_display`,
            // `created_at_display`, and `updated_at_display` fields,
            // delivered by CR-L2 file 2.
            // -----------------------------------------------------------------
            'installments' => $this->whenLoaded(
                'installments',
                fn () => LoanInstallmentResource::collection($this->installments),
            ),
        ];
    }

    // =========================================================================
    // Private — totals aggregation
    // =========================================================================
    /**
     * Compute the aggregate totals over the loaded installments.
     *
     * See the class docblock §"The totals object" for the shape and the
     * active-vs-superseded distinction. All monetary sums use BCMath at
     * scale 4; no floating-point arithmetic enters the computation.
     *
     * The method iterates the collection once, accumulating both the
     * counts and the sums. Installments in the `superseded` state
     * contribute to `superseded_count` but NOT to any monetary sum; the
     * three `remaining_*` totals delegate to the per-installment model
     * helpers (A.19), which floor at zero.
     *
     * @param Collection<int, LoanInstallment> $installments
     * @return array<string, int|string>
     */
    private function computeTotals(Collection $installments): array
    {
        $counts = [
            'pending'    => 0,
            'partial'    => 0,
            'settled'    => 0,
            'overdue'    => 0,
            'superseded' => 0,
        ];

        $activeCount = 0;

        $totalExpectedPrincipal = '0.0000';
        $totalExpectedInterest  = '0.0000';
        $totalExpectedRepayment = '0.0000';

        $totalSettledPrincipal = '0.0000';
        $totalSettledInterest  = '0.0000';
        $totalSettledAmount    = '0.0000';

        $totalRemainingPrincipal = '0.0000';
        $totalRemainingInterest  = '0.0000';
        $totalRemainingTotal     = '0.0000';

        foreach ($installments as $installment) {
            $statusValue = $installment->status->value;

            if (array_key_exists($statusValue, $counts)) {
                $counts[$statusValue]++;
            }

            // Superseded installments belong to a prior schedule
            // replaced by a restructure; their monetary values are
            // excluded from every sum.
            if ($statusValue === 'superseded') {
                continue;
            }

            $activeCount++;

            $totalExpectedPrincipal = bcadd(
                $totalExpectedPrincipal,
                (string) $installment->expected_principal,
                self::FINAL_SCALE,
            );

            $totalExpectedInterest = bcadd(
                $totalExpectedInterest,
                (string) $installment->expected_interest,
                self::FINAL_SCALE,
            );

            $totalExpectedRepayment = bcadd(
                $totalExpectedRepayment,
                (string) $installment->expected_total,
                self::FINAL_SCALE,
            );

            $totalSettledPrincipal = bcadd(
                $totalSettledPrincipal,
                (string) $installment->settled_principal,
                self::FINAL_SCALE,
            );

            $totalSettledInterest = bcadd(
                $totalSettledInterest,
                (string) $installment->settled_interest,
                self::FINAL_SCALE,
            );

            $totalSettledAmount = bcadd(
                $totalSettledAmount,
                (string) $installment->settled_amount,
                self::FINAL_SCALE,
            );

            $totalRemainingPrincipal = bcadd(
                $totalRemainingPrincipal,
                $installment->remainingPrincipal(),
                self::FINAL_SCALE,
            );

            $totalRemainingInterest = bcadd(
                $totalRemainingInterest,
                $installment->remainingInterest(),
                self::FINAL_SCALE,
            );

            $totalRemainingTotal = bcadd(
                $totalRemainingTotal,
                $installment->remainingTotal(),
                self::FINAL_SCALE,
            );
        }

        return [
            'installment_count'        => $installments->count(),
            'active_installment_count' => $activeCount,

            'pending_count'    => $counts['pending'],
            'partial_count'    => $counts['partial'],
            'settled_count'    => $counts['settled'],
            'overdue_count'    => $counts['overdue'],
            'superseded_count' => $counts['superseded'],

            'total_expected_principal' => $totalExpectedPrincipal,
            'total_expected_interest'  => $totalExpectedInterest,
            'total_expected_repayment' => $totalExpectedRepayment,

            'total_settled_principal' => $totalSettledPrincipal,
            'total_settled_interest'  => $totalSettledInterest,
            'total_settled_amount'    => $totalSettledAmount,

            'total_remaining_principal' => $totalRemainingPrincipal,
            'total_remaining_interest'  => $totalRemainingInterest,
            'total_remaining_total'     => $totalRemainingTotal,
        ];
    }
}