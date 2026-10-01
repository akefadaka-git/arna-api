<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * LoanStatementResource
 *
 * The HTTP representation of a loan's statement view — the composite that
 * a statement endpoint (`GET /loans/{loan}/statement`, A.82) returns.
 *
 * =============================================================================
 * Input contract — a controller-prepared composite
 * =============================================================================
 * Unlike every other Loan resource, this one does NOT wrap a single
 * Eloquent model. Its role is to serialize a STATEMENT VIEW, which is a
 * chronological aggregation of movements across the Loan and Wallet
 * domains — a shape that no single model carries.
 *
 * The resource therefore expects its underlying `$resource` to be an
 * ARRAY of exactly two keys:
 *
 *     [
 *         'loan'      => Loan,  // the loan being stated
 *         'statement' => array, // the controller-prepared statement view
 *     ]
 *
 * The controller (A.82, `LoanStatementController::show()`) is
 * responsible for:
 *
 *   1. Resolving the loan through the tenant-scoped `LoanRepository`
 *      (A.55), so that a cross-tenant request produces a 404 before the
 *      resource is instantiated.
 *
 *   2. Aggregating the statement data from:
 *        - the loan's own lifecycle dates and projections,
 *        - the loan's `interestAccruals` and `penaltyAccruals`
 *          relations (A.18),
 *        - the borrower's Wallet-side payments and allocations
 *          (Phase 2B tables, resolved through the Wallet domain's
 *          repositories),
 *        - the loan's `restructurings` and `writeOff` records (A.18),
 *        - the loan's `statusHistories` (A.18).
 *
 *   3. Passing the assembled structure to `LoanStatementResource::make()`.
 *
 * The resource does NOT query any of that data itself. The design
 * mirrors the platform-wide discipline that resources are pure
 * serializers (see `LoanResource`, A.69 §"The whenLoaded contract" for
 * the sibling rationale on eager-load discipline).
 *
 * =============================================================================
 * The prepared statement structure
 * =============================================================================
 * The `statement` array the controller prepares is expected to have the
 * following shape. Every monetary value is a decimal string at scale 4;
 * every timestamp is a DateTimeInterface or an ISO 8601 string (the
 * resource normalizes to ISO 8601 on output).
 *
 *     [
 *         'period' => [
 *             'from' => DateTimeInterface|string|null,
 *             'to'   => DateTimeInterface|string|null,
 *         ],
 *
 *         'opening_balance' => [
 *             'principal' => '0.0000',
 *             'interest'  => '0.0000',
 *             'penalty'   => '0.0000',
 *             'total'     => '0.0000',
 *         ],
 *
 *         'lines' => [
 *             [
 *                 'occurred_at'     => DateTimeInterface|string,
 *                 'type'            => 'disbursement' | 'interest_accrual'
 *                                     | 'penalty_accrual' | 'repayment'
 *                                     | 'restructure' | 'write_off'
 *                                     | 'collateral_release' | 'status_change',
 *                 'description'     => string,
 *                 'principal_delta' => '0.0000',
 *                 'interest_delta'  => '0.0000',
 *                 'penalty_delta'   => '0.0000',
 *                 'total_delta'     => '0.0000',
 *                 'running_balance' => [
 *                     'principal' => '0.0000',
 *                     'interest'  => '0.0000',
 *                     'penalty'   => '0.0000',
 *                     'total'     => '0.0000',
 *                 ],
 *                 'reference_type'  => string|null,
 *                 'reference_id'    => int|null,
 *                 'meta'            => array|null,
 *             ],
 *             // ...
 *         ],
 *
 *         'closing_balance' => [
 *             'principal' => '0.0000',
 *             'interest'  => '0.0000',
 *             'penalty'   => '0.0000',
 *             'total'     => '0.0000',
 *         ],
 *
 *         'totals' => [
 *             'total_disbursed'        => '0.0000',
 *             'total_interest_accrued' => '0.0000',
 *             'total_penalty_accrued'  => '0.0000',
 *             'total_repaid'           => '0.0000',
 *             'total_restructured'     => '0.0000',
 *             'total_written_off'      => '0.0000',
 *         ],
 *     ]
 *
 * The resource serializes this structure without transformation beyond
 * date normalization and null-safe emission. It does NOT recompute any
 * sum, does NOT re-sort the lines, and does NOT invent fields. The
 * aggregation's correctness is the controller's responsibility; the
 * resource's is the shape's.
 *
 * =============================================================================
 * Why a composite, not a Loan-with-relations
 * =============================================================================
 * A statement view spans three concerns that no single model carries:
 *
 *   1. The Loan's contractual identity and lifecycle dates.
 *
 *   2. The Loan-side accrual bookkeeping (which has its own timestamps
 *      and amounts).
 *
 *   3. The Wallet-side ledger (which lives in a different domain, per
 *      the B2 hybrid-ledger freeze).
 *
 * A resource that wrapped only the `Loan` model would have to lazy-load
 * relations to reach (2) and would have no path at all to (3) without
 * violating the "no queries in resources" discipline. The composite
 * input makes the controller's aggregation explicit and keeps the
 * resource a pure serializer.
 *
 * =============================================================================
 * The `lines` entry shape
 * =============================================================================
 * Each `lines` entry represents one movement in the statement's
 * chronology. The `type` field discriminates the source of the movement.
 * The three delta fields carry the movement's effect on the loan's
 * three outstanding components; `total_delta` is the sum of the three,
 * carried separately to spare the client a computation.
 *
 * The `running_balance` object is the loan's outstanding balance
 * IMMEDIATELY AFTER the movement was applied. The controller computes
 * this by walking the chronology and accumulating the deltas. The
 * resource does not recompute it — it emits the controller's value.
 *
 * The `reference_type` and `reference_id` fields link a movement back
 * to its source record:
 *
 *   - A disbursement links to the Wallet principal obligation.
 *   - An interest accrual links to the `loan_interest_accruals` row.
 *   - A penalty accrual links to the `loan_penalty_accruals` row.
 *   - A repayment links to the Wallet payment.
 *   - A restructure links to the `loan_restructurings` row.
 *   - A write-off links to the `loan_write_offs` row.
 *   - A collateral release links to the `loan_collaterals` row.
 *   - A status change links to the `loan_status_histories` row.
 *
 * The `meta` field is a free-form array the controller MAY populate with
 * additional type-specific detail (e.g., an accrual's `accrual_period_key`,
 * a repayment's `allocation_count`). The resource emits it as-is.
 *
 * =============================================================================
 * Date normalization
 * =============================================================================
 * Every date field the controller supplies MAY be a `DateTimeInterface`
 * (or any subclass) or an ISO 8601 string. The resource normalizes both
 * forms to ISO 8601 via the private `toIso8601()` helper. This allows
 * the controller to hand the resource whatever its aggregation produced,
 * without an extra formatting pass.
 *
 * =============================================================================
 * Every monetary field is a decimal string
 * =============================================================================
 * Every monetary value the controller supplies is preserved verbatim as
 * a string. The resource does NOT cast any value to float, does NOT
 * normalize a value to a fixed scale, and does NOT perform any
 * arithmetic. If the controller supplies `"100"`, the resource emits
 * `"100"` (not `"100.0000"`); if the controller supplies `"100.0000"`,
 * the resource emits `"100.0000"`. The contract is on the controller:
 * emit scale-4 decimal strings, per the platform-wide convention.
 *
 * =============================================================================
 * What this resource deliberately does NOT do
 * =============================================================================
 *   - It does NOT query any data. See §"Input contract".
 *
 *   - It does NOT recompute any sum, any running balance, or any
 *     aggregate. The controller's aggregation is authoritative.
 *
 *   - It does NOT sort the `lines` array. The controller emits the
 *     lines in the chronology it wants the client to read; the resource
 *     preserves that order.
 *
 *   - It does NOT validate the input structure's completeness. A
 *     controller that forgets to populate a key produces a payload with
 *     that key absent or null. Structural validation of the prepared
 *     statement is a controller-side concern (a form request cannot do
 *     it, since the input is not HTTP-supplied); the resource trusts the
 *     controller's discipline.
 *
 *   - It does NOT trigger lazy-loads on the `Loan` instance. It reads
 *     only the loan's own columns.
 *
 *   - It does NOT emit a `status` label, a per-line `type` label, or
 *     any other localized string. Raw enum/type backing values are
 *     emitted; localization is a presentation-layer concern.
 *
 *   - It does NOT perform authorization. The controller gates the
 *     endpoint through `Gate::authorize('viewStatement', $loan)`
 *     (A.52); the resource is a pure serializer.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D132, D133, D135,
 *                                                  B1, B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C4, C5
 *   Phase 2C A.15                                — `loan_interest_accruals`
 *   Phase 2C A.16                                — `loan_penalty_accruals`
 *   Phase 2C A.18                                — `Loan` (the model
 *                                                  whose identity and
 *                                                  lifecycle dates this
 *                                                  resource emits)
 *   Phase 2C A.24                                — `LoanInterestAccrual`
 *   Phase 2C A.25                                — `LoanPenaltyAccrual`
 *   Phase 2C A.36                                — `LoanReconciliationService`
 *                                                  (the sibling report
 *                                                  producer; its
 *                                                  `reconcile()`
 *                                                  output is the
 *                                                  closest existing
 *                                                  precedent for the
 *                                                  controller-assembled
 *                                                  structure shape)
 *   Phase 2C A.52                                — `LoanPolicy::viewStatement()`
 *                                                  (the authorization
 *                                                  ability)
 *   Phase 2C A.55                                — `LoanRepository` (the
 *                                                  tenant-scoped lookup
 *                                                  used by the
 *                                                  controller)
 *   Phase 2C A.75                                — `LoanReconciliationResource`
 *                                                  (the sibling that
 *                                                  passes through
 *                                                  the reconciler's
 *                                                  report shape)
 *   Phase 2C A.82                                — `LoanStatementController`
 *                                                  (the sole Phase 2C
 *                                                  consumer)
 *   Phase 2B `wallet_payments`                   — the source of the
 *                                                  repayment lines
 *   Phase 2B `wallet_obligations`                — the source of the
 *                                                  obligation lines
 *   Phase 2B 2B.23                               — `WalletResource` (the
 *                                                  sibling resource)
 *   Step 5 (manifest) A.74                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Http\Resources
 * @version 1.0.0
 */
final class LoanStatementResource extends JsonResource
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
     * Transform the composite into its array representation.
     *
     * See the class docblock §"Input contract" and §"The prepared
     * statement structure" for the input shape.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // ---------------------------------------------------------------------
        // Unpack the composite input.
        //
        // The controller supplies `['loan' => Loan, 'statement' => array]`.
        // A malformed input (missing either key) yields an empty
        // payload; the controller's aggregation is a precondition, not
        // a runtime responsibility of the resource.
        // ---------------------------------------------------------------------
        $loan = is_array($this->resource)
            ? ($this->resource['loan'] ?? null)
            : null;

        $statement = is_array($this->resource)
            ? ($this->resource['statement'] ?? [])
            : [];

        if (!$loan instanceof Loan) {
            return [];
        }

        return [
            // -----------------------------------------------------------------
            // Loan identity and lifecycle dates.
            //
            // Only the fields a statement header requires. The full loan
            // representation is available at `GET /loans/{loan}` through
            // `LoanResource` (A.69); a statement is a narrower view.
            // -----------------------------------------------------------------
            'loan' => [
                'id'                      => (int) $loan->id,
                'tenant_id'               => (int) $loan->tenant_id,
                'borrower_shareholder_id' => (int) $loan->borrower_shareholder_id,
                'loan_product_id'         => (int) $loan->loan_product_id,
                'status'                  => $loan->status->value,
                'currency'                => $loan->currency,
                'principal_amount'        => (string) $loan->principal_amount,
                'term_months'             => (int) $loan->term_months,
                'application_date'        => $loan->application_date?->toIso8601String(),
                'decision_date'           => $loan->decision_date?->toIso8601String(),
                'disbursement_date'       => $loan->disbursement_date?->toIso8601String(),
                'maturity_date'           => $loan->maturity_date?->toIso8601String(),
                'closed_at'               => $loan->closed_at?->toIso8601String(),
            ],

            // -----------------------------------------------------------------
            // Statement period.
            // -----------------------------------------------------------------
            'period' => [
                'from' => $this->toIso8601($statement['period']['from'] ?? null),
                'to'   => $this->toIso8601($statement['period']['to'] ?? null),
            ],

            // -----------------------------------------------------------------
            // Opening balance.
            // -----------------------------------------------------------------
            'opening_balance' => $this->emitBalance(
                $statement['opening_balance'] ?? [],
            ),

            // -----------------------------------------------------------------
            // Chronological lines.
            //
            // Emitted in the order the controller supplied. The resource
            // does NOT re-sort and does NOT validate each entry's
            // completeness; a malformed entry produces a partial line.
            // -----------------------------------------------------------------
            'lines' => $this->emitLines($statement['lines'] ?? []),

            // -----------------------------------------------------------------
            // Closing balance.
            // -----------------------------------------------------------------
            'closing_balance' => $this->emitBalance(
                $statement['closing_balance'] ?? [],
            ),

            // -----------------------------------------------------------------
            // Statement totals.
            //
            // A fixed set of aggregate movements across the statement's
            // period. A missing key in the controller's structure emits
            // as null.
            // -----------------------------------------------------------------
            'totals' => $this->emitTotals($statement['totals'] ?? []),
        ];
    }

    // =========================================================================
    // Private — balance and totals builders
    // =========================================================================

    /**
     * Serialize a four-component balance object.
     *
     * Every monetary value is emitted verbatim as supplied by the
     * controller. Missing values emit as `null` (not zero) — the resource
     * does not invent values.
     *
     * @param mixed $balance
     * @return array<string, string|null>
     */
    private function emitBalance(mixed $balance): array
    {
        $source = is_array($balance) ? $balance : [];

        return [
            'principal' => $this->toDecimalOrNull($source['principal'] ?? null),
            'interest'  => $this->toDecimalOrNull($source['interest']  ?? null),
            'penalty'   => $this->toDecimalOrNull($source['penalty']   ?? null),
            'total'     => $this->toDecimalOrNull($source['total']     ?? null),
        ];
    }

    /**
     * Serialize the statement's aggregate totals.
     *
     * @param mixed $totals
     * @return array<string, string|null>
     */
    private function emitTotals(mixed $totals): array
    {
        $source = is_array($totals) ? $totals : [];

        return [
            'total_disbursed'        => $this->toDecimalOrNull($source['total_disbursed']        ?? null),
            'total_interest_accrued' => $this->toDecimalOrNull($source['total_interest_accrued'] ?? null),
            'total_penalty_accrued'  => $this->toDecimalOrNull($source['total_penalty_accrued']  ?? null),
            'total_repaid'           => $this->toDecimalOrNull($source['total_repaid']           ?? null),
            'total_restructured'     => $this->toDecimalOrNull($source['total_restructured']     ?? null),
            'total_written_off'      => $this->toDecimalOrNull($source['total_written_off']      ?? null),
        ];
    }

    // =========================================================================
    // Private — line builders
    // =========================================================================

    /**
     * Serialize the statement's chronological lines.
     *
     * Each entry is normalized to the documented line shape. An entry
     * that is not an array is emitted as null, preserving the collection
     * cardinality without inventing a line.
     *
     * @param mixed $lines
     * @return list<array<string, mixed>|null>
     */
    private function emitLines(mixed $lines): array
    {
        if (!is_array($lines)) {
            return [];
        }

        $result = [];

        foreach ($lines as $line) {
            if (!is_array($line)) {
                $result[] = null;
                continue;
            }

            $result[] = $this->emitLine($line);
        }

        return $result;
    }

    /**
     * Serialize a single line entry.
     *
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function emitLine(array $line): array
    {
        return [
            'occurred_at' => $this->toIso8601($line['occurred_at'] ?? null),
            'type'        => $this->toStringOrNull($line['type'] ?? null),
            'description' => $this->toStringOrNull($line['description'] ?? null),

            'principal_delta' => $this->toDecimalOrNull($line['principal_delta'] ?? null),
            'interest_delta'  => $this->toDecimalOrNull($line['interest_delta']  ?? null),
            'penalty_delta'   => $this->toDecimalOrNull($line['penalty_delta']   ?? null),
            'total_delta'     => $this->toDecimalOrNull($line['total_delta']     ?? null),

            'running_balance' => $this->emitBalance(
                $line['running_balance'] ?? [],
            ),

            'reference_type' => $this->toStringOrNull($line['reference_type'] ?? null),
            'reference_id'   => $this->toIntOrNull($line['reference_id'] ?? null),

            'meta' => is_array($line['meta'] ?? null) ? $line['meta'] : null,
        ];
    }

    // =========================================================================
    // Private — value normalizers
    // =========================================================================

    /**
     * Normalize a date-like value to an ISO 8601 string.
     *
     * Accepts a `DateTimeInterface`, a string, or null. A string is
     * emitted verbatim (the controller is responsible for supplying ISO
     * 8601 formatted strings); a `DateTimeInterface` is formatted via
     * `->toIso8601String()`.
     *
     * @param mixed $value
     * @return string|null
     */
    private function toIso8601(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_string($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Normalize a decimal value to a string, or null.
     *
     * A string is emitted verbatim. A numeric value (int|float) is
     * coerced to string — this is defensive: the resource's contract
     * requires the controller to supply decimal strings, and a
     * controller that supplies a float is off-contract. The coercion
     * produces a string, not a native number, so the JSON output
     * preserves the decimal-string shape.
     *
     * @param mixed $value
     * @return string|null
     */
    private function toDecimalOrNull(mixed $value): ?string
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
     * Normalize a string value, or null.
     *
     * @param mixed $value
     * @return string|null
     */
    private function toStringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Normalize an integer value, or null.
     *
     * @param mixed $value
     * @return int|null
     */
    private function toIntOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}