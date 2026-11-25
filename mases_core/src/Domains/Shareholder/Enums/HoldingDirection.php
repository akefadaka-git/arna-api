<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Enums;

/**
 * Direction for share-ledger entries. Services apply it to a non-negative quantity so balance_before + signed quantity = balance_after.
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