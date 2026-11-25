<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * Serializes the controller-prepared loan/statement composite. The controller must resolve the loan tenant-scoped and provide chronological Loan/Wallet movements.
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