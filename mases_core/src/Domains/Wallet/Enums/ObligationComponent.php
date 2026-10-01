<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Enums;

/**
 * ObligationComponent
 *
 * The closed vocabulary of economic components that a wallet obligation
 * can represent.
 *
 * =============================================================================
 * What this enum is
 * =============================================================================
 * A controlled vocabulary of six values, frozen by decision D109:
 *
 *     principal    — the original amount of an obligation
 *     interest     — accrual on principal
 *     fee          — a service or administrative charge
 *     penalty      — a charge applied for a breach or late action
 *     tax          — a levy imposed by an external authority
 *     other        — a component that does not fit the five above
 *
 * The set is closed. Adding a component is a Change Request that updates
 * this enum and the `wallet_obligations.component` column's accepted
 * values (documented in the migration, 2B.50). Removing a component is a
 * Change Request that must be accompanied by a data-migration plan for
 * historical rows.
 *
 * =============================================================================
 * Component vs. category (D109)
 * =============================================================================
 * D109 distinguishes an ECONOMIC COMPONENT from a PAYMENT/SCHEDULE CATEGORY:
 *
 *     Economic component   — what the money IS economically
 *                            (principal, interest, fee, …).
 *                            Vocabulary: THIS ENUM.
 *
 *     Payment/schedule     — what business event the money belongs to
 *     category                (loan_installment, subscription_payment,
 *                              share_payment, invoice_payment, …).
 *                            Vocabulary: owned by the consuming domain,
 *                            NOT by the Wallet domain.
 *
 * The Wallet domain stores the category as the free-form pair
 * `wallet_obligations.reference_type` + `reference_id` — an opaque label
 * supplied by the consuming domain — and never interprets it. This keeps
 * the wallet's ledger closed under its own vocabulary and prevents
 * business semantics of future domains (loans, subscriptions, share
 * issuances) from leaking into the wallet's data model.
 *
 * "Installment" is explicitly a CATEGORY, not a component. It is never a
 * value of this enum.
 *
 * =============================================================================
 * Storage
 * =============================================================================
 * The enum is string-backed. Values are snake_case; case names are
 * PascalCase. This mirrors the convention used by Phase 2A's
 * `ShareholderType`, `HoldingDirection`, and `CertificateStatus`.
 *
 * Persisted as `wallet_obligations.component VARCHAR(16)` (2B.50). The
 * VARCHAR(16) width accommodates every current value — the longest is
 * `principal` (9 characters) — with headroom for a future value that a
 * Change Request might add.
 *
 * =============================================================================
 * No display labels
 * =============================================================================
 * This enum deliberately provides no `label()` method. Display strings
 * belong to the resource layer (per §2.2 of the architecture conventions)
 * and are resolved through the localization system's `__()` mechanism in
 * the caller's active locale. A future translation key namespace
 * (`wallet::component.*`) will render these values in Amharic and English
 * at the presentation layer; the enum itself remains a technical
 * vocabulary.
 *
 * =============================================================================
 * No direction, no priority, no transition semantics
 * =============================================================================
 * The enum describes WHAT a component is, nothing else. It does not
 * classify components as debit-side or credit-side, does not assign them
 * a payment-allocation priority, and does not define transitions between
 * components. Those concerns are addressed elsewhere:
 *
 *   Direction       — determined by the sign of the entry in the ledger
 *                     (Architecture §4.2). No component is inherently
 *                     positive or negative.
 *
 *   Priority        — deliberately not encoded anywhere (D110). Allocation
 *                     is explicit; no universal priority order is defined.
 *
 *   Transitions     — an obligation does not change its component. A
 *                     correction is a new obligation plus a reversal of
 *                     the original (Architecture §6.1).
 *
 * =============================================================================
 * Usage
 * =============================================================================
 * Consumers reference the enum by its case:
 *
 *     ObligationComponent::Principal
 *     ObligationComponent::Interest
 *     ObligationComponent::Fee
 *     ObligationComponent::Penalty
 *     ObligationComponent::Tax
 *     ObligationComponent::Other
 *
 * Persistence writes `->value` (the snake_case string). Deserialization
 * uses `ObligationComponent::tryFrom($string)`; a null return signals an
 * unrecognised value — which, for a well-formed row, should never occur.
 *
 * The `values()` helper returns the full list of accepted string values
 * in declaration order. It is intended for validation rules and for
 * iterating the vocabulary in administrative contexts.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D109                         — Obligation types decision
 *   Architecture v1.2.0 §3.3     — Obligation entity
 *   Architecture v1.2.0 §6.1     — Obligation projection
 *   Architecture v1.2.0 §10      — Allocation-policy contract boundary
 *   Architecture v1.2.0 §18      — Payment/schedule categories deferred
 *   Step 5 (manifest) 2B.3       — This file
 *   Step 6 v1.1.0 §3.3           — wallet_obligations table specification
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Enums
 * @version 1.0.0
 */
enum ObligationComponent: string
{
    /**
     * The original amount of the obligation, before any accruals or
     * charges. Represented as a separate component so that the underlying
     * obligation and any interest/fees applied to it remain individually
     * observable in the ledger and on statements.
     */
    case Principal = 'principal';

    /**
     * An accrual on principal. Interest is represented as a distinct
     * component (not folded into principal) so that a payment can be
     * allocated to interest without changing the principal balance, and
     * vice versa.
     */
    case Interest = 'interest';

    /**
     * A service or administrative charge. Distinguished from `penalty` by
     * cause: a fee is a scheduled or contractual charge; a penalty is a
     * charge applied for a breach or late action.
     */
    case Fee = 'fee';

    /**
     * A charge applied for a breach or late action. Distinguished from
     * `fee` by cause: a penalty is reactive; a fee is proactive.
     */
    case Penalty = 'penalty';

    /**
     * A levy imposed by an external authority. Modelled as a distinct
     * component because tax obligations often follow different attribution
     * and reporting rules than internal fees and penalties; keeping them
     * separate preserves that distinction in the ledger without encoding
     * the reporting rules themselves.
     */
    case Tax = 'tax';

    /**
     * A component that does not fit the five above. The presence of this
     * case is deliberate: it prevents the vocabulary from being forced
     * open by an unforeseen obligation while a Change Request to add a
     * specific component is prepared. It is not a substitute for adding
     * a proper component when a stable new category of obligation is
     * recognised.
     */
    case Other = 'other';

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