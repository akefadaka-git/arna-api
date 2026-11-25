<?php

declare(strict_types=1);


namespace MaaleArna\MASES\Core\Domains\Governance\Enums;

enum GovernanceAppointmentStatus: string
{
    /**
     * The appointment is recorded but not yet in force.
     *
     * A draft appointment creates no memberships that the domain
     * treats as currently occupying their positions. A service
     * method transitions it to `active` when the instrument's
     * effective date arrives or when an authorized operator
     * explicitly activates it.
     *
     * The default state for a newly-created appointment — the
     * migration (G2c file 1 of 31) sets `status` to `'draft'`.
     *
     * B6 case 1 of 4.
     */
    case Draft = 'draft';

    /**
     * The appointment is in force.
     *
     * Memberships created by this appointment are treated as
     * currently occupying their positions, subject to the temporal
     * bounds of the term (if any) and of the membership's own
     * effective range.
     *
     * An appointment remains `active` until it is either revoked or
     * superseded.
     *
     * B6 case 2 of 4.
     */
    case Active = 'active';

    /**
     * The appointment was withdrawn before its natural end.
     *
     * A revocation is a deliberate act by an authority competent to
     * make it. The appointment's memberships end as of the
     * revocation's effective moment; the historical record is
     * preserved.
     *
     * B6 case 3 of 4.
     */
    case Revoked = 'revoked';

    /**
     * The appointment was replaced by a later instrument covering
     * the same scope.
     *
     * A supersession is non-discretionary — the later instrument
     * renders the earlier one obsolete by supplying a new answer to
     * the same question. The appointment's memberships end as of the
     * later instrument's effective date; the historical record is
     * preserved.
     *
     * B6 case 4 of 4.
     */
    case Superseded = 'superseded';

    // =========================================================================
    // Direct predicates — one per case
    // =========================================================================

    /**
     * Whether this case is the Draft state.
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

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
     * Whether this case is the Revoked state.
     *
     * @return bool
     */
    public function isRevoked(): bool
    {
        return $this === self::Revoked;
    }

    /**
     * Whether this case is the Superseded state.
     *
     * @return bool
     */
    public function isSuperseded(): bool
    {
        return $this === self::Superseded;
    }

    // =========================================================================
    // Derived predicates
    // =========================================================================

    /**
     * Whether this case is a terminal state.
     *
     * A terminal state is one from which no further transition is
     * expected. The two terminal cases are Revoked and Superseded.
     *
     * The name is STATE-MACHINE terminology, not temporal. A `draft`
     * appointment that is never activated is not "terminal" by this
     * predicate, even though it may remain in `draft` forever.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return $this === self::Revoked
            || $this === self::Superseded;
    }

    /**
     * Whether this case is an open state.
     *
     * An open state is one from which the service may transition to
     * another state. The two open cases are Draft and Active.
     *
     * The exact inverse of `isTerminal()`:
     *
     *     $status->isOpen() === !$status->isTerminal()
     *
     * Provided as a named predicate so that call sites read
     * naturally when their frame of reference is "is this still in
     * play?" rather than "has this finished?".
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this === self::Draft
            || $this === self::Active;
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
     * Declaration order is lifecycle order:
     *
     *     ['draft', 'active', 'revoked', 'superseded']
     *
     * This order is the order the four values will appear in the
     * translation catalogue (once the catalogue amendment described
     * in the file-level change-verification block is delivered).
     * The two must stay in lockstep; a future CR that reorders one
     * reorders the other.
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