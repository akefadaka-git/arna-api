<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Enums;

/**
 * Closed wallet-ledger event vocabulary. Entries are service-written; signed quantity controls balance direction and adjustments require wallet.adjust.
 *
 * @package MaaleArna\Arna\Core\Domains\Wallet\Enums
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