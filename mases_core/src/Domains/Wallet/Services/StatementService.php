<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;

/**
 * StatementService
 *
 * On-demand projection of a wallet's ledger into a statement-of-account
 * for a specified period.
 *
 * =============================================================================
 * Read-only service — no transaction, no event, no persistence
 * =============================================================================
 * Unlike every other service in the Wallet domain (2B.15–2B.18), this one
 * is purely read-only. It:
 *
 *   - Opens NO database transaction.
 *   - Writes NO rows to any table.
 *   - Dispatches NO domain event.
 *   - Persists NO materialized statement.
 *
 * This is the direct consequence of Architecture §11.1 (D113): statements
 * are PROJECTIONS of the ledger, not independent sources of financial
 * truth. Phase 2B ships on-demand generation only; there is no
 * `wallet_statements` table and no scheduled trigger.
 *
 * The absence of persistence has two architectural benefits, both frozen
 * at Step 4:
 *
 *   1. D113's requirement that "historical statements shall not be edited
 *      to rewrite transaction history" is structurally satisfied. A
 *      statement is computed fresh on every request; there is no stored
 *      artifact to edit. Corrections to history flow through the ledger
 *      via reversals (2B.15 §"reverseEntry"), and every subsequent
 *      statement reflects the corrected ledger.
 *
 *   2. The Plesk hosting constraint (no SSH, no scheduled tasks) is
 *      respected without exception: statement generation is reachable
 *      from the HTTP request lifecycle alone.
 *
 * =============================================================================
 * Public API
 * =============================================================================
 * One operation:
 *
 *     generate()  — produce a statement projection for a wallet and a
 *                   period.
 *
 * The method returns a plain `array` with a stable, documented schema (see
 * the method's docblock). It does NOT return a model, because no
 * `WalletStatement` model exists in the 2B manifest and none is needed:
 * the projection is a value object, assembled on demand and discarded
 * after the response is rendered.
 *
 * =============================================================================
 * Period semantics — [from, to)
 * =============================================================================
 * The period is inclusive on `from` and exclusive on `to`:
 *
 *     entries WHERE occurred_at >= from AND occurred_at < to
 *
 * The half-open convention is deliberate. It makes consecutive periods
 * tile cleanly: a statement covering [Jan 1, Feb 1) followed by a
 * statement covering [Feb 1, Mar 1) will not double-count an entry whose
 * `occurred_at` is exactly `Feb 1 00:00:00`. A caller that wants an
 * inclusive upper bound subtracts one second from its intended `to`.
 *
 * This convention is documented here because the architecture does not
 * specify it. The choice is deterministic and reproducible; any future
 * statement-generation consumer gets the same semantics.
 *
 * =============================================================================
 * Opening balance
 * =============================================================================
 * The opening balance of a period is the `balance_after` of the latest
 * ledger entry with `occurred_at < from`, or `'0.0000'` if no such entry
 * exists. Because the ledger is append-only and every entry's
 * `balance_after` is a running total (Architecture §4.6), this single
 * lookup is exact: no summation is required to establish the balance at
 * the start of the period.
 *
 * The lookup orders by `(occurred_at DESC, id DESC)` to guarantee a
 * deterministic result when two entries share an `occurred_at` — the same
 * tiebreaker used by `WalletEntry::scopeChronological()` (2B.10) in the
 * ascending direction.
 *
 * =============================================================================
 * Closing balance
 * =============================================================================
 * The closing balance of a period is:
 *
 *   - The `balance_after` of the last entry in the period, when the period
 *     contains at least one entry.
 *   - The opening balance, when the period is empty.
 *
 * Because every entry's `balance_after` is the running total at that
 * instant, the last entry's `balance_after` IS the wallet's balance at
 * time `to` (exclusive). The service therefore does not need a separate
 * "balance at `to`" lookup.
 *
 * The invariant `opening_balance + credit_total − debit_total = closing_balance`
 * holds by construction and is asserted in the return value's own fields;
 * a consumer that wants to verify it can. The service does not raise on
 * a violation because such a violation is impossible for a well-formed
 * ledger — the assertion is a diagnostic, not a guard.
 *
 * =============================================================================
 * Debit / credit (Architecture §11.4)
 * =============================================================================
 * The convention is frozen in Architecture §11.4:
 *
 *     Debit  = negative wallet movement (member obligation; obligation
 *              recorded; debt adjustment)
 *     Credit = positive wallet movement (payment received; member credit
 *              adjustment)
 *
 * For each entry, exactly one of the two is non-zero:
 *
 *   - A negative `signed_quantity` produces `debit = |signed_quantity|`
 *     and `credit = '0.0000'`.
 *   - A positive `signed_quantity` produces `debit = '0.0000'` and
 *     `credit = |signed_quantity|`.
 *
 * A zero `signed_quantity` cannot occur: the ledger-write primitive
 * (2B.15 §"assertSignedQuantityIsWellFormed") rejects zero-value entries.
 *
 * The per-period `debit_total` and `credit_total` are the sums of the
 * corresponding columns across all entries in the period. Reversals are
 * counted in the SAME entry stream as their originals; a reversal of a
 * debit appears as a credit of equal magnitude, which is the economically
 * correct representation. There is no separate "reversal total" column.
 *
 * =============================================================================
 * Currency (Q8)
 * =============================================================================
 * The statement carries the wallet's currency in its header. Every
 * monetary value in the statement is expressed in that currency — the Q8
 * single-currency invariant guarantees this, and the ledger's signed
 * quantities are themselves denominated in it. Downstream consumers of
 * the statement therefore do not need to fetch the wallet row to
 * interpret the amounts.
 *
 * =============================================================================
 * Provenance (D113)
 * =============================================================================
 * The statement carries a `provenance` block with:
 *
 *   - `wallet_id`               — the wallet the statement covers.
 *   - `period_from`             — ISO 8601 lower bound.
 *   - `period_to`               — ISO 8601 upper bound.
 *   - `ledger_max_entry_id`     — the highest `wallet_entries.id` in the
 *                                 wallet at the moment of generation.
 *   - `generator`               — the producing service class name.
 *   - `generator_version`       — the service's semantic version.
 *
 * The `ledger_max_entry_id` field is the reproduction key. Because the
 * ledger is append-only and every row is immutable, replaying a statement
 * for the same period with the same max-entry-id bound reconstructs the
 * same output — even if a subsequent backfill inserted entries with a
 * past `occurred_at`. Without the max-entry-id bound, such a backfill
 * would silently change a previously-generated statement on the next
 * request.
 *
 * =============================================================================
 * Dependencies
 * =============================================================================
 * The constructor takes NO dependencies.
 *
 * This is a documented deviation from Architecture §16.2, whose summary
 * lists `WalletRepository`, `ObligationRepository`, `PaymentRepository`,
 * and `AllocationRepository` as `StatementService` dependencies. None of
 * the four is used, and none is injected. The reasoning:
 *
 *   - `WalletRepository` is not needed: the controller resolves the
 *     target wallet through `WalletRepository::findForTenant()` before
 *     invoking this service, and passes the hydrated, tenant-scoped
 *     `Wallet` model in. This mirrors the same pattern established by
 *     2B.16, 2B.17, and 2B.18.
 *
 *   - `ObligationRepository`, `PaymentRepository`, and
 *     `AllocationRepository` are not needed: D113 defines statements as
 *     projections of the LEDGER. The ledger's entries carry the reference
 *     pairs (`reference_type` + `reference_id`) that point at the
 *     originating obligation, payment, or wallet. The statement renders
 *     those references as opaque identifiers; it does not resolve them
 *     to models, and it does not read the parent tables. Reaching into
 *     the parent repositories would invert the architectural principle
 *     that the ledger is self-describing.
 *
 * The architecture's §16.2 summary is a catalogue of possible
 * dependencies, not a mandate. A service with no dependencies that
 * satisfies every constraint of §11 is a strictly simpler design than one
 * that injects four unused collaborators.
 *
 * The service reads `WalletEntry` rows directly through the model's
 * query builder. It does not introduce a `WalletEntryRepository` because
 * the manifest does not contain one, and because the ledger's read
 * surface is expressed by two scopes on the model
 * (`forWallet`, `chronological`) that this service composes directly.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not persist a statement. There is no `wallet_statements`
 *     table in 2B. See Architecture §11.5.
 *
 *   - It does not dispatch an event. Statement generation is a read; it
 *     does not produce a state change.
 *
 *   - It does not paginate `period_entries`. The entry count in a
 *     well-scoped period is bounded by the wallet's activity within that
 *     period — a small number in every deployment scenario 2B
 *     anticipates. A caller that needs a bounded window issues a shorter
 *     period.
 *
 *   - It does not aggregate by day, week, or month. It returns the raw
 *     entries and the two period totals. Any grouping is the consumer's
 *     concern.
 *
 *   - It does not evaluate policies. The wallet's `interest_policy_version_id`,
 *     `allocation_policy_version_id`, and `kyc_policy_version_id` are not
 *     read; the statement is a pure ledger projection.
 *
 *   - It does not enforce a maximum period length. A caller that requests
 *     a ten-year statement receives it. If a future operational concern
 *     requires a bound (memory, response size), a Change Request adds
 *     one.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D113                         — Statement model
 *   Architecture v1.2.0 §3.7    — WalletEntry ledger table
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §11     — Statement projection boundary
 *   Architecture v1.2.0 §11.1   — Projection, not materialization
 *   Architecture v1.2.0 §11.2   — Content (per D113)
 *   Architecture v1.2.0 §11.3   — Historical immutability
 *   Architecture v1.2.0 §11.4   — Debit / credit column definitions
 *   Architecture v1.2.0 §11.5   — Materialization deferred
 *   Architecture v1.2.0 §16.2   — Services (and the deviation noted above)
 *   Step 5 (manifest) 2B.19     — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Services
 * @version 1.0.0
 */
final class StatementService
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.54.
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * The all-zero decimal string used for absent debit or credit sides
     * of a ledger entry, and for the opening balance of an empty
     * pre-period ledger.
     */
    private const ZERO = '0.0000';

    /**
     * Generator identifier recorded in the statement's provenance block.
     */
    private const GENERATOR = self::class;

    /**
     * Generator version recorded in the statement's provenance block.
     *
     * Kept in sync with the class's `@version` docblock tag. A change to
     * this constant is a semantic change to the statement schema and
     * should be accompanied by a change to the class's `@version` tag.
     */
    private const GENERATOR_VERSION = '1.0.0';

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Generate a statement projection for a wallet and a period.
     *
     * The period follows the half-open convention `[from, to)`: an entry
     * is included when `occurred_at >= from` AND `occurred_at < to`. See
     * the class docblock §"Period semantics".
     *
     * The returned array has the following stable schema:
     *
     *     [
     *         'wallet_id'        => int,
     *         'currency'         => string,           // ISO 4217
     *         'period' => [
     *             'from'         => string,           // ISO 8601
     *             'to'           => string,           // ISO 8601
     *         ],
     *         'opening_balance'  => string,           // decimal(20,4)
     *         'period_entries'   => [
     *             [
     *                 'id'              => int,
     *                 'occurred_at'     => string,    // ISO 8601
     *                 'entry_type'      => string,
     *                 'component'       => string|null,
     *                 'signed_quantity' => string,    // decimal(20,4)
     *                 'debit'           => string,    // decimal(20,4), magnitude or '0.0000'
     *                 'credit'          => string,    // decimal(20,4), magnitude or '0.0000'
     *                 'balance_after'   => string,    // decimal(20,4)
     *                 'reference_type'  => string|null,
     *                 'reference_id'    => string|null,
     *                 'description'     => string|null,
     *             ],
     *             ...
     *         ],
     *         'debit_total'      => string,           // decimal(20,4)
     *         'credit_total'     => string,           // decimal(20,4)
     *         'closing_balance'  => string,           // decimal(20,4)
     *         'generated_at'     => string,           // ISO 8601
     *         'generated_by'     => int|null,
     *         'provenance'       => [
     *             'wallet_id'           => int,
     *             'period_from'         => string,    // ISO 8601
     *             'period_to'           => string,    // ISO 8601
     *             'ledger_max_entry_id' => int,
     *             'generator'           => string,
     *             'generator_version'   => string,
     *         ],
     *     ]
     *
     * The schema is frozen for Phase 2B. Any change is a CR.
     *
     * @param Wallet               $wallet
     * @param CarbonInterface      $from
     * @param CarbonInterface      $to
     * @param int|null             $generatedBy   Optional actor user id.
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function generate(
        Wallet $wallet,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $generatedBy = null,
    ): array {
        $this->assertWalletUsable($wallet);

        $fromAt = Carbon::instance($from);
        $toAt   = Carbon::instance($to);

        $this->assertPeriodIsWellFormed($fromAt, $toAt);

        // ---------------------------------------------------------------------
        // 1. Fetch the entries in the period, in chronological order.
        //
        // The `chronological()` scope (2B.10) orders by occurred_at
        // ascending, then id ascending as a deterministic tiebreaker for
        // entries sharing a business timestamp.
        // ---------------------------------------------------------------------
        /** @var Collection<int, WalletEntry> $entries */
        $entries = WalletEntry::query()
            ->forWallet($wallet->id)
            ->where('occurred_at', '>=', $fromAt)
            ->where('occurred_at', '<', $toAt)
            ->chronological()
            ->get();

        // ---------------------------------------------------------------------
        // 2. Compute the opening balance.
        //
        // The opening balance is the balance_after of the latest entry
        // strictly before `from`. Ordering by (occurred_at DESC, id DESC)
        // matches the ascending tiebreaker used by `chronological()`, in
        // reverse.
        //
        // A wallet with no pre-period entries opens at zero, which is the
        // wallet's balance at creation (2B.15 §"open").
        // ---------------------------------------------------------------------
        $openingEntry = WalletEntry::query()
            ->forWallet($wallet->id)
            ->where('occurred_at', '<', $fromAt)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        $openingBalance = $openingEntry !== null
            ? (string) $openingEntry->balance_after
            : self::ZERO;

        // ---------------------------------------------------------------------
        // 3. Project each entry into the statement row shape.
        //
        // The projection computes the debit / credit split per entry.
        // Each entry's signed_quantity is either strictly negative
        // (produces debit) or strictly positive (produces credit); zero
        // is rejected by the ledger-write primitive.
        // ---------------------------------------------------------------------
        $periodEntries = [];
        $debitTotal    = self::ZERO;
        $creditTotal   = self::ZERO;

        foreach ($entries as $entry) {
            $signed = (string) $entry->signed_quantity;

            if (bccomp($signed, '0', self::MAX_DECIMAL_SCALE) < 0) {
                $debit  = bcmul($signed, '-1', self::MAX_DECIMAL_SCALE);
                $credit = self::ZERO;
                $debitTotal = bcadd($debitTotal, $debit, self::MAX_DECIMAL_SCALE);
            } else {
                $debit  = self::ZERO;
                $credit = $signed;
                $creditTotal = bcadd($creditTotal, $credit, self::MAX_DECIMAL_SCALE);
            }

            $periodEntries[] = [
                'id'              => (int) $entry->id,
                'occurred_at'     => $entry->occurred_at->toIso8601String(),
                'entry_type'      => (string) $entry->entry_type->value,
                'component'       => $entry->component?->value,
                'signed_quantity' => $signed,
                'debit'           => $debit,
                'credit'          => $credit,
                'balance_after'   => (string) $entry->balance_after,
                'reference_type'  => $entry->reference_type,
                'reference_id'    => $entry->reference_id,
                'description'     => $entry->description,
            ];
        }

        // ---------------------------------------------------------------------
        // 4. Compute the closing balance.
        //
        // When the period contains at least one entry, the last entry's
        // balance_after is the running total at that instant — which is
        // the wallet's balance at `to` (exclusive). When the period is
        // empty, the closing balance equals the opening balance.
        // ---------------------------------------------------------------------
        $closingBalance = $periodEntries !== []
            ? $periodEntries[array_key_last($periodEntries)]['balance_after']
            : $openingBalance;

        // ---------------------------------------------------------------------
        // 5. Capture the reproduction key.
        //
        // The maximum wallet_entries.id at the moment of generation. See
        // the class docblock §"Provenance" for the rationale.
        // ---------------------------------------------------------------------
        $ledgerMaxEntryId = (int) (WalletEntry::query()
            ->forWallet($wallet->id)
            ->max('id') ?? 0);

        $generatedAt = Carbon::now();

        // ---------------------------------------------------------------------
        // 6. Assemble the statement.
        // ---------------------------------------------------------------------
        return [
            'wallet_id'       => (int) $wallet->id,
            'currency'        => (string) $wallet->currency,
            'period'          => [
                'from' => $fromAt->toIso8601String(),
                'to'   => $toAt->toIso8601String(),
            ],
            'opening_balance' => $openingBalance,
            'period_entries'  => $periodEntries,
            'debit_total'     => $debitTotal,
            'credit_total'    => $creditTotal,
            'closing_balance' => $closingBalance,
            'generated_at'    => $generatedAt->toIso8601String(),
            'generated_by'    => $generatedBy,
            'provenance'      => [
                'wallet_id'           => (int) $wallet->id,
                'period_from'         => $fromAt->toIso8601String(),
                'period_to'           => $toAt->toIso8601String(),
                'ledger_max_entry_id' => $ledgerMaxEntryId,
                'generator'           => self::GENERATOR,
                'generator_version'   => self::GENERATOR_VERSION,
            ],
        ];
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that the wallet is usable as the subject of a statement.
     *
     * Mirrors the analogous guard in 2B.16–2B.18:
     *
     *   1. The wallet must be persisted.
     *   2. The wallet must carry a non-empty, well-formed ISO 4217
     *      currency code. The currency is stamped into the statement's
     *      header; a malformed value would render a nonsensical statement.
     *
     * Tenant scoping is NOT re-verified here: it was established by the
     * controller's `WalletRepository::findForTenant()` call before this
     * service was invoked (Step 7 v1.1.0 §C.6).
     *
     * @param Wallet $wallet
     * @return void
     *
     * @throws ValidationException
     */
    private function assertWalletUsable(Wallet $wallet): void
    {
        if (!$wallet->exists) {
            throw ValidationException::withMessages([
                'wallet_id' => ['The wallet must be a persisted record.'],
            ]);
        }

        $currency = (string) $wallet->currency;

        if ($currency === '') {
            throw ValidationException::withMessages([
                'wallet_id' => [
                    'The wallet has no currency. A statement cannot be '
                    . 'generated for a wallet with a missing currency.',
                ],
            ]);
        }

        if (!preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw ValidationException::withMessages([
                'wallet_id' => [
                    'The wallet currency is not a valid ISO 4217 code. '
                    . 'This indicates a data anomaly; the statement is '
                    . 'rejected rather than rendered against a malformed wallet.',
                ],
            ]);
        }
    }

    /**
     * Assert that the period is well-formed.
     *
     * The period follows the half-open convention `[from, to)`. The
     * requirement is `from` strictly before `to`: an empty or inverted
     * period cannot describe a statement, and a same-instant period is
     * degenerate (its entry set is always empty).
     *
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @return void
     *
     * @throws ValidationException
     */
    private function assertPeriodIsWellFormed(
        CarbonInterface $from,
        CarbonInterface $to,
    ): void {
        if (!$from->lessThan($to)) {
            throw ValidationException::withMessages([
                'period' => [
                    'The statement period must have a from-timestamp that '
                    . 'lies strictly before the to-timestamp. The period is '
                    . 'interpreted as [from, to).',
                ],
            ]);
        }
    }
}