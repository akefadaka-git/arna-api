<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LoanReconciliationResource
 *
 * The HTTP representation of the loan-reconciliation report produced by
 * `LoanReconciliationService::reconcile()` (A.36) and returned by
 * `GET /loans/{loan}/reconciliation` (A.83,
 * `LoanReconciliationController::show()`).
 *
 * =============================================================================
 * A near-passthrough — the report is the contract
 * =============================================================================
 * Unlike every other Loan resource, this one is a NEAR-PASSTHROUGH. Its
 * input is the reconciler's report array, already produced by
 * `LoanReconciliationService::reconcile()` (A.36). The report is a
 * well-structured associative array whose every field is already in its
 * canonical form:
 *
 *   - Monetary values are decimal strings at scale 4, produced by the
 *     reconciler via BCMath.
 *
 *   - The `reconciled_at` timestamp is already an ISO 8601 string.
 *
 *   - Every nested object (`contractual`, `loan_projected`,
 *     `wallet_linked`, `wallet_payments`, `per_installment`,
 *     `divergence`, `provenance`) has a fixed shape documented on A.36.
 *
 * The resource's role is therefore narrow:
 *
 *   1. Disable Laravel's automatic `data` wrapping via `$wrap = null`,
 *      so the controller's own response envelope is the single wrapping
 *      layer.
 *
 *   2. Emit each documented top-level field with an explicit type
 *      coercion and a defensive default, so that a report array that is
 *      malformed at the top level produces a well-typed payload rather
 *      than a PHP type error.
 *
 *   3. Pass through every nested structure verbatim. The resource does
 *      NOT reshape, rekey, sort, or filter any nested content.
 *
 * =============================================================================
 * Input contract — the reconciler's report array
 * =============================================================================
 * The controller (A.83, `LoanReconciliationController::show()`) calls:
 *
 *     $report = $this->reconciler->reconcile($loan);
 *     return LoanReconciliationResource::make($report);
 *
 * The resource's underlying `$resource` is therefore the report array
 * itself — NOT a `Loan` instance. It is the only Loan resource whose
 * input is not a model.
 *
 * The shape of the input is defined entirely by A.36's
 * `LoanReconciliationService::reconcile()` return value:
 *
 *     [
 *         'loan_id'         => int,
 *         'currency'        => string|null,
 *         'status'          => string,
 *         'reconciled_at'   => ISO 8601 string,
 *         'contractual'     => array,
 *         'loan_projected'  => array,
 *         'wallet_linked'   => array,
 *         'wallet_payments' => array,
 *         'per_installment' => array,
 *         'divergence'      => array,
 *         'is_balanced'     => bool,
 *         'provenance'      => array,
 *     ]
 *
 * The resource emits exactly these keys, in this order. Any additional
 * key the reconciler might add in a future CR is NOT emitted unless this
 * resource is regenerated to include it — the resource is deliberately
 * an allowlist of the frozen C5 report shape.
 *
 * =============================================================================
 * What the resource emits, and what each section means
 * =============================================================================
 * The report answers the frozen C5 reconciliation invariant:
 *
 *     Σ(wallet_obligations.outstanding_amount
 *         WHERE reference_type = 'Loan'
 *           AND reference_id   = (string) loans.id)
 *       ==
 *     loans.outstanding_principal
 *       + loans.outstanding_interest
 *       + loans.outstanding_penalty
 *
 * The resource's sections map to the invariant's components:
 *
 *   loan_id       — the loan the report is for.
 *   currency      — the loan's currency (nullable for a pre-disbursement
 *                   loan).
 *   status        — the loan's lifecycle status at the moment of the
 *                   report.
 *   reconciled_at — the moment the report was produced.
 *
 *   contractual       — the loan's schedule as reconstructed from the
 *                       non-superseded installments: totals of expected
 *                       principal, interest, and repayment, plus counts
 *                       per installment status.
 *
 *   loan_projected    — the loan's persisted projections
 *                       (`outstanding_principal`, `outstanding_interest`,
 *                       `outstanding_penalty`, `settled_amount`) plus
 *                       the computed `outstanding_total`.
 *
 *   wallet_linked     — the Wallet-side aggregate: the borrower's Wallet
 *                       id, the count of loan-linked obligations
 *                       (total and settled), and the sum of their
 *                       `outstanding_amount` grouped by component.
 *
 *   wallet_payments   — the loan-linked Wallet payments: count, total
 *                       received, total allocated, total unapplied.
 *
 *   per_installment   — a per-installment side-by-side view of the
 *                       contractual expected amounts and the Wallet-side
 *                       linked interest/penalty obligations. INFORMATIONAL
 *                       (see A.36 §"The per-installment breakdown").
 *
 *   divergence        — the per-component differences between the
 *                       Wallet-side sums and the loan-projected sums,
 *                       plus the total. INFORMATIONAL.
 *
 *   is_balanced       — the SINGLE authoritative boolean derived from
 *                       the aggregate invariant check. True when
 *                       `wallet_linked.outstanding_total` equals
 *                       `loan_projected.outstanding_total` at scale 4.
 *
 *   provenance        — metadata about the reconciler itself (its class
 *                       and version) and the Wallet id it read.
 *
 * =============================================================================
 * What the C5 invariant explicitly forbids
 * =============================================================================
 * Per the Phase 2C Final Reconciliation §3 correction, the invariant is
 * checked against the LOAN-LINKED Wallet obligations only. It must NOT
 * compare against `wallets.current_balance` — that column includes
 * obligations not linked to the loan and adjustments made for unrelated
 * reasons.
 *
 * The reconciler (A.36) honors this exclusion; the resource emits the
 * reconciler's report verbatim. A reader of the payload can verify the
 * exclusion by inspecting `wallet_linked`: it carries the loan-linked
 * obligation sums, NOT the Wallet's `current_balance`.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does NOT run the reconciliation. That is
 *     `LoanReconciliationService::reconcile()`'s job (A.36). The
 *     resource wraps the report the service already produced.
 *
 *   - It does NOT query any data. It has no dependencies, no repository
 *     access, and no I/O.
 *
 *   - It does NOT recompute any sum, any divergence, or the
 *     `is_balanced` flag. Every value in the emitted payload is the
 *     reconciler's authoritative value.
 *
 *   - It does NOT reshape nested structures. Every nested object
 *     (`contractual`, `loan_projected`, `wallet_linked`,
 *     `wallet_payments`, `per_installment`, `divergence`, `provenance`)
 *     is emitted as the reconciler built it.
 *
 *   - It does NOT filter or sort `per_installment`. The reconciler
 *     emits the entries in installment-number order; the resource
 *     preserves that order.
 *
 *   - It does NOT cast any monetary value to float. Every decimal value
 *     the reconciler produced is a string; the resource preserves the
 *     string shape.
 *
 *   - It does NOT emit a top-level `is_balanced` label (e.g. "Balanced"
 *     / "Divergent"). The boolean is emitted raw; a localized label is
 *     a presentation-layer concern.
 *
 *   - It does NOT perform authorization. The controller gates the
 *     endpoint through `Gate::authorize('view', $loan)` (A.52); the
 *     resource is a pure serializer.
 *
 * =============================================================================
 * Why the input is the report, not the Loan
 * =============================================================================
 * The alternative design — wrapping the `Loan` model and computing the
 * report inside `toArray()` — would place a non-trivial multi-query
 * aggregation inside a resource. That would violate two platform-wide
 * disciplines:
 *
 *   1. Resources are pure serializers (see `LoanResource`, A.69
 *      §"The `whenLoaded` contract").
 *
 *   2. All I/O is composed at the controller or service layer, where
 *      the read path is visible and testable in isolation.
 *
 * The reconciler (`LoanReconciliationService`, A.36) already owns the
 * multi-query aggregation; the resource wraps its output. The design is
 * consistent with the platform's layering.
 *
 * This is the SAME pattern `LoanStatementResource` (A.74) uses for its
 * `statement` composite, and the SAME pattern `LoanScheduleResource`
 * (A.73) uses for its eagerly-loaded `installments`. The three resources
 * together establish a small family of "controller-prepared composite"
 * resources, distinct from the "model-wrapping" resources (A.69, A.70,
 * A.71, A.72).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — B1, B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation
 *                                                  combination)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §3 (the exact
 *                                                  invariant and the
 *                                                  wallet-balance
 *                                                  exclusion)
 *   Phase 2C A.36                                — `LoanReconciliationService`
 *                                                  (the report
 *                                                  producer; its
 *                                                  `reconcile()` method
 *                                                  defines this
 *                                                  resource's input
 *                                                  shape)
 *   Phase 2C A.52                                — `LoanPolicy::view()`
 *                                                  (the authorization
 *                                                  ability; the
 *                                                  reconciliation
 *                                                  endpoint shares the
 *                                                  loan-view ability)
 *   Phase 2C A.74                                — `LoanStatementResource`
 *                                                  (the sibling
 *                                                  composite-input
 *                                                  resource)
 *   Phase 2C A.83                                — `LoanReconciliationController`
 *                                                  (the sole Phase 2C
 *                                                  consumer)
 *   Phase 2B migration 2B.49                     — `wallets` (the
 *                                                  `current_balance`
 *                                                  column explicitly
 *                                                  excluded from the
 *                                                  report)
 *   Phase 2B migration 2B.50                     — `wallet_obligations`
 *   Phase 2B migration 2B.51                     — `wallet_payments`
 *   Step 5 (manifest) A.75                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Resources
 * @version 1.0.0
 */
final class LoanReconciliationResource extends JsonResource
{
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
     * Transform the reconciler's report into its array representation.
     *
     * See the class docblock §"Input contract" and §"What the resource
     * emits" for the shape. Every nested structure is emitted verbatim;
     * every top-level field receives an explicit type coercion and a
     * defensive default.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // ---------------------------------------------------------------------
        // Normalize the input to an array. A caller that passes a
        // non-array (e.g. a Loan, by mistake) gets an empty but
        // well-typed payload rather than a PHP type error.
        // ---------------------------------------------------------------------
        $report = is_array($this->resource) ? $this->resource : [];

        return [
            // -----------------------------------------------------------------
            // Header.
            // -----------------------------------------------------------------
            'loan_id'       => (int) ($report['loan_id'] ?? 0),
            'currency'      => $this->toStringOrNull($report['currency'] ?? null),
            'status'        => $this->toStringOrNull($report['status'] ?? null),
            'reconciled_at' => $this->toStringOrNull($report['reconciled_at'] ?? null),

            // -----------------------------------------------------------------
            // The two sides of the invariant.
            //
            // Both are emitted verbatim as the reconciler built them:
            // nested monetary values are already decimal strings at
            // scale 4; nested dates (if any) are already ISO 8601.
            // -----------------------------------------------------------------
            'loan_projected' => $this->toArrayOrEmpty($report['loan_projected'] ?? null),
            'wallet_linked'  => $this->toArrayOrEmpty($report['wallet_linked'] ?? null),

            // -----------------------------------------------------------------
            // Supporting evidence.
            //
            // Emitted verbatim. `contractual` summarizes the schedule;
            // `wallet_payments` summarizes the payment population;
            // `per_installment` is the informational per-row breakdown.
            // -----------------------------------------------------------------
            'contractual'     => $this->toArrayOrEmpty($report['contractual'] ?? null),
            'wallet_payments' => $this->toArrayOrEmpty($report['wallet_payments'] ?? null),
            'per_installment' => $this->toListOrEmpty($report['per_installment'] ?? null),

            // -----------------------------------------------------------------
            // Divergence — informational.
            //
            // Per-component differences plus a total. A caller that
            // wants the authoritative balance verdict reads
            // `is_balanced` below.
            // -----------------------------------------------------------------
            'divergence' => $this->toArrayOrEmpty($report['divergence'] ?? null),

            // -----------------------------------------------------------------
            // The authoritative verdict.
            //
            // Derived by the reconciler from the aggregate-only check.
            // The cast is defensive: the reconciler emits a bool, but a
            // future CR that emits a truthy non-bool still produces a
            // well-typed payload.
            // -----------------------------------------------------------------
            'is_balanced' => (bool) ($report['is_balanced'] ?? false),

            // -----------------------------------------------------------------
            // Provenance.
            //
            // The reconciler's class name and version, plus the Wallet
            // id it read. Emitted verbatim.
            // -----------------------------------------------------------------
            'provenance' => $this->toArrayOrEmpty($report['provenance'] ?? null),
        ];
    }

    // =========================================================================
    // Private — value normalizers
    // =========================================================================

    /**
     * Normalize a value to a string, or null.
     *
     * @param mixed $value
     * @return string|null
     */
    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Normalize a value to an associative array, or an empty array.
     *
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function toArrayOrEmpty(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return $value;
    }

    /**
     * Normalize a value to a list, or an empty list.
     *
     * A list is an array whose keys are a contiguous sequence of
     * integers from 0. An associative array passed to this normalizer
     * is returned as-is; the caller's discipline is trusted, and the
     * JSON encoder produces an object in that case. This mirrors the
     * `per_installment` field's contract, which the reconciler always
     * populates as a proper list.
     *
     * @param mixed $value
     * @return array<int|string, mixed>
     */
    private function toListOrEmpty(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return $value;
    }
}