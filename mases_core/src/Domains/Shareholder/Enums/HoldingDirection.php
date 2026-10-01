<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Enums;

/**
 * HoldingDirection
 *
 * Service-layer vocabulary for the direction of a ShareHoldingEntry.
 *
 * =============================================================================
 * Why this enum exists
 * =============================================================================
 * The approved schema for `share_holding_entries` (D16-X1) stores a SIGNED
 * `quantity` column, not a direction column. The invariant approved by
 * D16-R4 is:
 *
 *     balance_before + quantity = balance_after
 *
 * For this invariant to hold for both increases (subscriptions, transfers in)
 * and decreases (transfers out, reversals), `quantity` must carry the sign:
 *
 *     +50  → a subscription of 50 shares
 *     -30  → a transfer out of 30 shares
 *
 * The ShareHoldingService (2A.14) could accept a signed integer directly,
 * but doing so invites caller errors: is `recordEntry(quantity: -50)` a
 * subtraction of 50, or an addition of 50 to a "negative quantity" already?
 * Ambiguous.
 *
 * HoldingDirection removes the ambiguity. The service accepts a direction
 * (in / out / neutral) plus a non-negative magnitude, and computes the
 * signed quantity itself. The caller's intent is expressed in one
 * dimension, not two.
 *
 * =============================================================================
 * Not a stored column
 * =============================================================================
 * This enum is NOT persisted. It does not appear as a column in the
 * `share_holding_entries` migration (2A.40). It exists only between the
 * caller and the service layer. Readers of the persisted ledger infer
 * direction from the sign of `quantity`, not from a direction column.
 *
 * This preserves the self-describing nature of the ledger: the entry itself
 * records what changed, without a redundant direction column that could
 * disagree with the stored quantity.
 *
 * =============================================================================
 * The `Neutral` case
 * =============================================================================
 * `Neutral` represents a zero-magnitude entry — a ledger row that records
 * a fact without changing the holding balance. In Phase 2A the service
 * layer does not produce neutral entries; every entry is either an increase
 * or a decrease. `Neutral` exists in the vocabulary because:
 *
 *   1. Future phases may record memo entries, class-swap events, or
 *      informational rows where the balance is intentionally unchanged.
 *
 *   2. Keeping the vocabulary complete now avoids a schema/enum change
 *      (which would require a CR) when those phases arrive.
 *
 * If the service layer receives `Neutral` with a non-zero magnitude, that
 * is a caller error and the service MUST reject it. Passing `Neutral` with
 * magnitude 0 is technically valid but produces an entry with no effect.
 *
 * =============================================================================
 * Relationship to EntryType
 * =============================================================================
 * The entry-type vocabulary (D16-R8) is a separate concept stored as a
 * plain string on the entry. It describes WHAT happened; the direction
 * describes WHICH WAY the balance moved. Most entry types imply a
 * direction unambiguously:
 *
 *     subscription    → In
 *     transfer_in     → In
 *     allocation      → In
 *     transfer_out    → Out
 *
 * Two entry types do NOT imply a direction from the type alone:
 *
 *     adjustment      → can be either, depending on the correction
 *     reversal        → opposite of the original entry's direction
 *
 * The `tryFromEntryType()` helper below resolves the unambiguous cases and
 * returns null for the ambiguous ones. Callers that need a definite
 * direction for `adjustment` or `reversal` must consult the entry itself
 * (its stored `quantity` sign, or the original entry it reverses).
 *
 * =============================================================================
 * Localization
 * =============================================================================
 * As with ShareholderType, this enum does NOT provide display strings.
 * Localized labels belong to the resource layer (2A.27), resolved through
 * translation keys of the form `shareholder.direction.{value}`.
 *
 * =============================================================================
 * Source decisions
 * =============================================================================
 *   D16-CORE — ShareHolding is a projection; entries are the ledger.
 *   D16-R3   — controlled entry-type vocabulary.
 *   D16-R4   — balance_before + quantity = balance_after; quantity signed.
 *   D16-R8   — the six entry-type values.
 *   D16-X1   — the approved column set (no direction column).
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Enums
 */
enum HoldingDirection: string
{
    /**
     * The holding balance increases.
     *
     * Signed quantity produced by the service: POSITIVE.
     *
     * Typical entry types: subscription, transfer_in, allocation.
     */
    case In = 'in';

    /**
     * The holding balance decreases.
     *
     * Signed quantity produced by the service: NEGATIVE.
     *
     * Typical entry types: transfer_out, reversal.
     *
     * A decrease MUST NOT drive the resulting balance below zero — enforced
     * by the D16-R5 invariant inside ShareHoldingService (2A.14).
     */
    case Out = 'out';

    /**
     * The holding balance is unchanged.
     *
     * Signed quantity produced by the service: ZERO.
     *
     * Not produced by the Phase 2A service layer. Reserved for future
     * phases that record informational or memo entries on the ledger.
     */
    case Neutral = 'neutral';

    /**
     * The multiplicative sign this direction applies to a magnitude.
     *
     *   In      →  +1
     *   Out     →  -1
     *   Neutral →   0
     *
     * Used by the service layer to compute the signed quantity that will
     * be persisted on the entry row.
     *
     * @return int
     */
    public function sign(): int
    {
        return match ($this) {
            self::In      => 1,
            self::Out     => -1,
            self::Neutral => 0,
        };
    }

    /**
     * Apply this direction to a magnitude, producing the signed value that
     * the service layer will persist on `share_holding_entries.quantity`.
     *
     * The magnitude is treated as a non-negative count. Its absolute value
     * is used, so a caller who accidentally passes a negative number will
     * still get a coherent result determined solely by the direction. The
     * direction argument is the authoritative source of sign.
     *
     * Examples:
     *
     *     HoldingDirection::In->applyTo(50)      ===  50
     *     HoldingDirection::Out->applyTo(30)     === -30
     *     HoldingDirection::Neutral->applyTo(0)  ===   0
     *     HoldingDirection::Out->applyTo(-30)    === -30   (abs)
     *
     * @param int $magnitude  The unsigned quantity of shares involved.
     * @return int            The signed quantity to be persisted.
     */
    public function applyTo(int $magnitude): int
    {
        return $this->sign() * abs($magnitude);
    }

    /**
     * Whether this direction increases the holding balance.
     *
     * @return bool
     */
    public function isIncrease(): bool
    {
        return $this === self::In;
    }

    /**
     * Whether this direction decreases the holding balance.
     *
     * @return bool
     */
    public function isDecrease(): bool
    {
        return $this === self::Out;
    }

    /**
     * Whether this direction leaves the holding balance unchanged.
     *
     * @return bool
     */
    public function isNeutral(): bool
    {
        return $this === self::Neutral;
    }

    /**
     * All raw string values of the enum, in declaration order.
     *
     * Useful for validation whitelists and for tests that assert the
     * vocabulary is unchanged.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases()
        );
    }

    /**
     * Derive the direction implied by an entry-type string, when the type
     * unambiguously implies one.
     *
     * Returns null when the type does not determine direction on its own.
     * Callers that receive null must consult the entry's stored `quantity`
     * sign — or, for reversals, the original entry being reversed — to
     * determine the effective direction.
     *
     * Mapping source: decision D16-R8 entry-type vocabulary. If that
     * vocabulary changes, this method must be updated in the same Change
     * Request, because the two are semantically coupled.
     *
     * @param string|null $entryType  Raw entry-type value as stored on the entry.
     * @return self|null              The implied direction, or null if ambiguous.
     */
    public static function tryFromEntryType(?string $entryType): ?self
    {
        if ($entryType === null) {
            return null;
        }

        return match ($entryType) {
            'subscription' => self::In,
            'transfer_in'  => self::In,
            'allocation'   => self::In,
            'transfer_out' => self::Out,
            'adjustment'   => null,
            'reversal'     => null,
            default        => null,
        };
    }
}