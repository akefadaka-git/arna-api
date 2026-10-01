<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Enums;

/**
 * WalletEntryType
 *
 * The closed vocabulary of entry types that a wallet ledger entry can
 * carry.
 *
 * =============================================================================
 * What this enum is
 * =============================================================================
 * A controlled vocabulary of six values, frozen by the wallet architecture
 * (Architecture §5.1) and extended in Phase 2C by
 * CR-2C-WALLET-OBLIGATION-LIFECYCLE-001:
 *
 *     obligation_recorded      — an obligation has been recorded against
 *                                the wallet. Emitted by
 *                                ObligationService::record().
 *
 *     payment_received         — a payment has been received by the
 *                                wallet. Emitted by
 *                                PaymentService::record().
 *
 *     adjustment               — an operator-initiated balance adjustment.
 *                                Emitted by WalletService::adjust().
 *                                Privileged — protected by the dedicated
 *                                `wallet.adjust` permission (Q3).
 *
 *     reversal                 — a reversal of a previously posted entry.
 *                                Emitted by WalletService::reverseEntry().
 *
 *     obligation_cancelled     — an outstanding obligation has been
 *                                cancelled because the terms that
 *                                produced it are being replaced. Emitted
 *                                by ObligationService::discharge() when
 *                                called with this entry type — the
 *                                Phase 2C loan-restructure path
 *                                (A.34) is the sole producer.
 *
 *     obligation_written_off   — an outstanding obligation has been
 *                                forgiven. Emitted by
 *                                ObligationService::discharge() when
 *                                called with this entry type — the
 *                                Phase 2C loan write-off path (A.35)
 *                                is the sole producer.
 *
 * The set is closed. Adding a type is a Change Request that updates this
 * enum and the `wallet_entries.entry_type` column's accepted values
 * (documented in the migration, 2B.54). Removing a type is a Change
 * Request that must be accompanied by a data-migration plan for
 * historical rows.
 *
 * =============================================================================
 * Why the two discharge types are distinct (CR-2C-WALLET-OBLIGATION-LIFECYCLE-001)
 * =============================================================================
 * When a Loan-domain operation needs to reduce an outstanding Wallet
 * obligation to zero, it invokes `ObligationService::discharge()`. The
 * caller selects one of the two entry types based on the SEMANTIC of the
 * discharge:
 *
 *   obligation_cancelled   — the obligation is cancelled because the
 *                            terms that produced it are being replaced.
 *                            The borrower still owes the (recomputed)
 *                            debt under the new terms. Produced by the
 *                            loan-restructure path (A.34).
 *
 *   obligation_written_off — the obligation is forgiven. The borrower
 *                            owes nothing further on the loan. Produced
 *                            by the loan write-off path (A.35).
 *
 * Recording the two as DIFFERENT entry types preserves the semantic
 * distinction on the Wallet's append-only ledger. A future reader can
 * answer "was this obligation replaced, or was it forgiven?" from the
 * ledger entry's type alone, without needing to reconstruct the
 * Loan-domain state at the moment of discharge.
 *
 * The distinction is load-bearing for D133's clause that a write-off
 * does NOT erase the historical arrears ledger. A penalty obligation
 * that was discharged by a WRITE-OFF is recorded as
 * `obligation_written_off`; the corresponding `loan_penalty_accruals`
 * row remains in the database regardless.
 *
 * =============================================================================
 * What this enum is NOT
 * =============================================================================
 *   - It is NOT the attribution vocabulary. Payment allocations and
 *     allocation reversals do NOT produce ledger entries (Architecture
 *     §5.1). They are attribution records stored on
 *     `wallet_payment_allocations` and
 *     `wallet_payment_allocation_reversals`. There is no
 *     `allocation_created` or `allocation_reversed` entry type, and there
 *     will not be — the ledger records MONETARY movements, not
 *     attribution.
 *
 *   - It is NOT the obligation-component vocabulary. That vocabulary is
 *     `ObligationComponent` (2B.3). A wallet entry of type
 *     `obligation_recorded` carries a nullable `component` column whose
 *     value is an `ObligationComponent` case; the entry type and the
 *     component are orthogonal. The two discharge types
 *     (`obligation_cancelled`, `obligation_written_off`) ALSO carry the
 *     discharged obligation's component on their `component` column, so
 *     that a reader can see which component was cancelled or written off.
 *
 *   - It is NOT a transaction boundary. Multiple entries may be written
 *     in one database transaction; each entry independently satisfies the
 *     balance invariant (`balance_before + signed_quantity = balance_after`).
 *
 * =============================================================================
 * Sign convention (Architecture §4.2)
 * =============================================================================
 * The sign of a ledger entry's `signed_quantity` is determined by the
 * member's position, not by the entry type:
 *
 *     obligation_recorded      → negative (member owes)
 *     payment_received         → positive (member pays)
 *     adjustment               → either (per the operator's intent)
 *     reversal                 → the numeric negation of the original entry
 *     obligation_cancelled     → positive (member's position credited —
 *                                the owed amount is removed)
 *     obligation_written_off   → positive (member's position credited —
 *                                the owed amount is removed)
 *
 * The two discharge types ALWAYS carry a positive signed quantity equal
 * to the discharged amount. They are the mirror of `obligation_recorded`,
 * which always carries a negative signed quantity. The discharge's effect
 * is to cancel (part of) the debit the original obligation recorded.
 *
 * =============================================================================
 * Storage
 * =============================================================================
 * The enum is string-backed. Values are snake_case; case names are
 * PascalCase. This mirrors the convention used by Phase 2A's
 * `ShareholderType`, `HoldingDirection`, and `CertificateStatus`, and by
 * the sibling `ObligationComponent` (2B.3).
 *
 * Persisted as `wallet_entries.entry_type VARCHAR(32)` (2B.54). The
 * VARCHAR(32) width accommodates every current value — the longest is
 * `obligation_written_off` (23 characters) — with headroom for a future
 * value that a Change Request might add. The Phase 2C addition did NOT
 * require a schema change: both new values fit within the existing
 * VARCHAR(32) width.
 *
 * =============================================================================
 * Permitted reference combinations (Architecture §5.3, extended by
 * CR-2C-WALLET-OBLIGATION-LIFECYCLE-001)
 * =============================================================================
 * Each entry type maps to one or more permitted `reference_type` values.
 * The mapping is enforced by the wallet services at write time, not by
 * this enum or by the schema. The mapping is:
 *
 *     obligation_recorded      → reference_type ∈ {'Obligation', 'Loan'}
 *     payment_received         → reference_type ∈ {'Payment', 'LoanRepayment'}
 *     adjustment               → reference_type = 'Wallet'
 *     reversal                 → reference_type = 'WalletEntry'
 *     obligation_cancelled     → reference_type ∈ {'Obligation', 'LoanRestructure'}
 *     obligation_written_off   → reference_type ∈ {'Obligation', 'LoanWriteOff'}
 *
 * The `reference_type` value stored is the short class basename — not the
 * fully-qualified namespace — matching the Phase 2A convention for
 * `share_holding_entries.reference_type`.
 *
 * The Phase 2C additions extend the permitted sets:
 *
 *   - `obligation_recorded` now also accepts `'Loan'` — the Loan domain
 *     records principal, interest, penalty, and fee obligations with
 *     `reference_type = 'Loan'` and `reference_id = (string) $loan->id`
 *     (per the frozen B1 decision). The `'Obligation'` form remains
 *     valid for any non-Loan producer.
 *
 *   - `payment_received` now also accepts `'LoanRepayment'` — the Loan
 *     domain records repayments with `reference_type = 'LoanRepayment'`
 *     (per B1). The `'Payment'` form remains valid.
 *
 *   - `obligation_cancelled` accepts `'Obligation'` (a generic discharge)
 *     or `'LoanRestructure'` (the Loan-domain restructure path, A.34).
 *
 *   - `obligation_written_off` accepts `'Obligation'` (a generic
 *     discharge) or `'LoanWriteOff'` (the Loan-domain write-off path,
 *     A.35).
 *
 * The mapping is enforced by `WalletService::assertReferenceTypePermitted()`
 * (extended by CR-2C-WALLET-REFTYPE-001) and is the single source of
 * truth for the permitted `(entry_type, reference_type)` pairs.
 *
 * =============================================================================
 * No display labels
 * =============================================================================
 * This enum deliberately provides no `label()` method. Display strings
 * belong to the resource layer (per §2.2 of the architecture conventions)
 * and are resolved through the localization system's `__()` mechanism in
 * the caller's active locale. A future translation key namespace
 * (`wallet::entry_type.*`) will render these values in Amharic and English
 * at the presentation layer; the enum itself remains a technical
 * vocabulary.
 *
 * =============================================================================
 * No lifecycle transitions
 * =============================================================================
 * An entry's type is set once, at insert time, and never changes. The
 * ledger is append-only (Architecture §3.7). A correction does not
 * transition an entry from one type to another; it writes a NEW entry of
 * type `reversal` that points at the original via `reversed_entry_id`.
 *
 * The two Phase 2C discharge types participate in this discipline: a
 * discharge is recorded as a NEW entry (of type `obligation_cancelled` or
 * `obligation_written_off`) that reduces the original obligation's
 * `outstanding_amount` to zero. The original `obligation_recorded` entry
 * is NOT modified.
 *
 * =============================================================================
 * Usage
 * =============================================================================
 * Consumers reference the enum by its case:
 *
 *     WalletEntryType::ObligationRecorded
 *     WalletEntryType::PaymentReceived
 *     WalletEntryType::Adjustment
 *     WalletEntryType::Reversal
 *     WalletEntryType::ObligationCancelled
 *     WalletEntryType::ObligationWrittenOff
 *
 * Persistence writes `->value` (the snake_case string). Deserialization
 * uses `WalletEntryType::tryFrom($string)`; a null return signals an
 * unrecognised value — which, for a well-formed row, should never occur.
 *
 * The `values()` helper returns the full list of accepted string values
 * in declaration order. It is intended for validation rules and for
 * iterating the vocabulary in administrative contexts.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.7     — WalletEntry ledger table
 *   Architecture v1.2.0 §4.2     — Sign convention
 *   Architecture v1.2.0 §4.3     — Corrections
 *   Architecture v1.2.0 §5.1     — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3     — Permitted reference_type combinations
 *   Architecture v1.2.0 §19      — Q3 (adjustment in 2B, privileged)
 *   Phase 2C Decision Register   — B1 (reference types), B2 (hybrid
 *                                  ledger), D133 (write-off preserves
 *                                  historical arrears)
 *   Phase 2C A.34                — `LoanRestructuringService` (the
 *                                  `obligation_cancelled` producer)
 *   Phase 2C A.35                — `LoanWriteOffService` (the
 *                                  `obligation_written_off` producer)
 *   CR-2C-WALLET-OBLIGATION-LIFECYCLE-001 — adds `discharge()` and the
 *                                  two new entry types
 *   CR-2C-WALLET-REFTYPE-001     — extends the reference-type map
 *   Step 5 (manifest) 2B.4       — This file
 *   Step 6 v1.1.0 §3.7           — wallet_entries table specification
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Enums
 * @version 1.1.0
 */
enum WalletEntryType: string
{
    /**
     * An obligation has been recorded against the wallet.
     *
     * Emitted by ObligationService::record(). The entry's `component`
     * column carries the obligation's `ObligationComponent` value; the
     * entry's `reference_type` / `reference_id` point at the Obligation
     * row that caused the entry — or, for a Loan-domain obligation, at
     * the loan row via `reference_type = 'Loan'`.
     *
     * Sign: typically negative (the member owes). See Architecture §4.2.
     */
    case ObligationRecorded = 'obligation_recorded';

    /**
     * A payment has been received by the wallet.
     *
     * Emitted by PaymentService::record(). The entry's `component` column
     * is null — payments are not component-tagged; the components they
     * settle are recorded on the allocations that follow, not on the
     * payment entry itself. The entry's `reference_type` / `reference_id`
     * point at the Payment row — or, for a Loan-domain repayment, at the
     * loan row via `reference_type = 'LoanRepayment'`.
     *
     * Sign: positive (the member pays). See Architecture §4.2.
     */
    case PaymentReceived = 'payment_received';

    /**
     * An operator-initiated balance adjustment.
     *
     * Emitted by WalletService::adjust(). Privileged — protected by the
     * dedicated `wallet.adjust` permission, held only by `super_admin` in
     * the frozen RBAC matrix (Q3). This is the sole mechanism in Phase 2B
     * by which an operator may change a wallet's monetary balance outside
     * the obligation/payment lifecycle, and it exists only to handle
     * legitimate non-obligation/non-payment corrections.
     *
     * Sign: either, per the operator's intent. See Architecture §4.2.
     *
     * The entry's `reference_type` / `reference_id` point at the Wallet
     * itself — an adjustment is not caused by a separate business object.
     */
    case Adjustment = 'adjustment';

    /**
     * A reversal of a previously posted entry.
     *
     * Emitted by WalletService::reverseEntry(). The entry's
     * `reversed_entry_id` points at the original entry, and its
     * `reference_type` / `reference_id` also identify that entry. The
     * reversal's signed quantity is the numeric negation of the original's;
     * the effect is to undo the original's monetary impact without
     * modifying the original row.
     *
     * Sign: opposite of the original. See Architecture §4.3.
     *
     * Constraints (Architecture §4.3):
     *   - An entry may be reversed at most once.
     *   - A reversal entry may not itself be reversed.
     *   - Reversal is rejected if it would drive the wallet balance below
     *     the legal state (default: not permitted to reverse across a
     *     closed period).
     */
    case Reversal = 'reversal';

    /**
     * An outstanding obligation has been cancelled because the terms that
     * produced it are being replaced.
     *
     * Emitted by ObligationService::discharge() when called with this
     * entry type. The Phase 2C loan-restructure path
     * (`LoanRestructuringService::restructure()`, A.34) is the sole
     * producer: when a loan is restructured, its outstanding Wallet
     * obligations (principal, interest, penalty) are discharged with
     * `entry_type = ObligationCancelled` and
     * `reference_type = 'LoanRestructure'`. The `reference_id` is the
     * primary key of the `loan_restructurings` row that produced the
     * discharge (the reference-id ordering fix per Phase 2C Final
     * Reconciliation §1.2).
     *
     * The `component` column carries the discharged obligation's
     * component, so a reader can see which component was cancelled.
     *
     * Sign: always positive (the member's position is credited — the
     * owed amount is removed). The signed quantity equals the discharged
     * amount.
     *
     * The original `obligation_recorded` entry is NOT modified. A
     * discharge is a new entry that reduces the obligation's
     * `outstanding_amount` to zero (or, for a partial discharge, by the
     * discharged amount).
     */
    case ObligationCancelled = 'obligation_cancelled';

    /**
     * An outstanding obligation has been forgiven.
     *
     * Emitted by ObligationService::discharge() when called with this
     * entry type. The Phase 2C loan write-off path
     * (`LoanWriteOffService::writeOff()`, A.35) is the sole producer:
     * when a loan is written off, its outstanding Wallet obligations
     * (principal, interest, penalty) are discharged with
     * `entry_type = ObligationWrittenOff` and
     * `reference_type = 'LoanWriteOff'`. The `reference_id` is the
     * primary key of the `loan_write_offs` row that produced the
     * discharge (the reference-id ordering fix per Phase 2C Final
     * Reconciliation §1.3).
     *
     * The `component` column carries the discharged obligation's
     * component, so a reader can see which component was forgiven.
     *
     * Sign: always positive (the member's position is credited — the
     * owed amount is removed). The signed quantity equals the forgiven
     * amount.
     *
     * The distinction from `ObligationCancelled` is semantically
     * load-bearing: a cancellation means the terms are being replaced
     * (the borrower still owes under new terms); a write-off means the
     * debt is forgiven (the borrower owes nothing further on the loan).
     * The distinction is preserved on the ledger so that a future reader
     * can answer "was this replaced, or forgiven?" from the entry type
     * alone. See D133's "write-off does not silently erase the
     * historical arrears ledger" clause: any `loan_penalty_accruals`
     * rows recorded before the write-off remain in the database
     * regardless of the discharge recorded on the Wallet.
     */
    case ObligationWrittenOff = 'obligation_written_off';

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Return the full list of accepted string values, in declaration order.
     *
     * Intended for validation rules and for administrative iteration. The
     * returned array is `list<string>` — a numerically-indexed array whose
     * order matches the enum's case declaration.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}