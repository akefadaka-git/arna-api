<?php

declare(strict_types=1);

/* Closed vocabulary for Governance membership lifecycle states. */
namespace MaaleArna\MASES\Core\Domains\Governance\Enums;

enum GovernanceMembershipStatus: string
{
    /**
     * The membership is currently in force.
     *
     * The member occupies the seat. The default state for a
     * newly-created membership — the migration (G2c file 16 of 31)
     * sets `status` to `'active'`.
     *
     * B6 case 1 of 6.
     */
    case Active = 'active';

    /**
     * The membership reached the natural end of its period.
     *
     * The end was not a resignation, a removal, or an expiry — it
     * was the ordinary completion of the term the membership was
     * created for.
     *
     * B6 case 2 of 6.
     */
    case Ended = 'ended';

    /**
     * The membership is temporarily paused.
     *
     * The member does not currently occupy the seat, but may resume
     * occupancy if the suspension is lifted. A suspended membership
     * still reserves its seat for the overlap invariant.
     *
     * B6 case 3 of 6.
     */
    case Suspended = 'suspended';

    /**
     * The member voluntarily ended their occupancy before the
     * natural end of the term.
     *
     * B6 case 4 of 6.
     */
    case Resigned = 'resigned';

    /**
     * The member was removed from the seat by an authority
     * competent to do so.
     *
     * The removal was not voluntary.
     *
     * B6 case 5 of 6.
     */
    case Removed = 'removed';

    /**
     * The membership's time bound elapsed without an explicit end
     * action.
     *
     * A catch-all terminal state for memberships whose end date
     * passed without anyone recording a specific reason.
     *
     * B6 case 6 of 6.
     */
    case Expired = 'expired';

    // =========================================================================
    // Direct predicates — one per case
    // =========================================================================

    /**
     * Whether this case is the Active state.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether this case is the Ended state.
     *
     * @return bool
     */
    public function isEnded(): bool
    {
        return $this === self::Ended;
    }

    /**
     * Whether this case is the Suspended state.
     *
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this === self::Suspended;
    }

    /**
     * Whether this case is the Resigned state.
     *
     * @return bool
     */
    public function isResigned(): bool
    {
        return $this === self::Resigned;
    }

    /**
     * Whether this case is the Removed state.
     *
     * @return bool
     */
    public function isRemoved(): bool
    {
        return $this === self::Removed;
    }

    /**
     * Whether this case is the Expired state.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this === self::Expired;
    }

    // =========================================================================
    // Derived predicates
    // =========================================================================

    /**
     * Whether this case is a terminal state.
     *
     * A terminal state is one from which the membership has reached
     * a final outcome and will not resume. The four terminal cases
     * are Ended, Resigned, Removed, and Expired.
     *
     * A member who returns to the seat after a terminal membership
     * occupies it through a NEW membership, not by transitioning
     * the old one out of its terminal state.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return $this === self::Ended
            || $this === self::Resigned
            || $this === self::Removed
            || $this === self::Expired;
    }

    /**
     * Whether this case is an ongoing state.
     *
     * An ongoing state is one from which the membership may
     * continue. The two ongoing cases are Active and Suspended.
     *
     * The exact inverse of `isTerminal()`:
     *
     *     $status->isOngoing() === !$status->isTerminal()
     *
     * A suspended membership still reserves its seat — the service
     * layer's overlap check (ruling B4) treats it the same as an
     * active one. See the file-level change-verification block
     * §"The suspended state and the overlap invariant".
     *
     * @return bool
     */
    public function isOngoing(): bool
    {
        return $this === self::Active
            || $this === self::Suspended;
    }

    // =========================================================================
    // Vocabulary
    // =========================================================================

    /**
     * The raw string values of every case, in declaration order.
     *
     * Useful as a validation whitelist and as an input to
     * `Rule::in(...)` when the caller cannot use
     * `Rule::enum(...)` — e.g. inside a config file, a migration, or
     * a non-Laravel context.
     *
     * Declaration order is:
     *
     *     ['active', 'ended', 'suspended', 'resigned', 'removed', 'expired']
     *
     * The order groups the two ONGOING cases first (active,
     * suspended) — well, actually the order is chosen so that the
     * most common lifecycle reads naturally: active, then the
     * natural end (ended), then the three non-natural outcomes
     * (suspended, resigned, removed) in procedural order, then the
     * time-based outcome (expired). This is the order the six
     * values will appear in the translation catalogue (once the
     * catalogue amendment described in the file-level
     * change-verification block is delivered). The two must stay in
     * lockstep.
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
}