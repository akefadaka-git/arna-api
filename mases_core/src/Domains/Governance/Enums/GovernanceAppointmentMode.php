<?php

declare(strict_types=1);

/* Closed vocabulary for Governance appointment methods. */
namespace MaaleArna\MASES\Core\Domains\Governance\Enums;

enum GovernanceAppointmentMode: string
{
    /**
     * The appointee was chosen by a vote of an electing body.
     *
     * The voting body is organization-specific: the General Assembly
     * elects the Board; the Board elects its chair and secretary; a
     * committee may elect its own chair within the mandate the Board
     * grants it.
     *
     * B6 case 1 of 4.
     */
    case Elected = 'elected';

    /**
     * The appointee was designated by an appointing authority,
     * without a vote.
     *
     * The appointing authority might be the Board (for senior
     * management positions), a shareholder (for their
     * representative), or an external authority (for a regulator's
     * observer seat).
     *
     * B6 case 2 of 4.
     */
    case Appointed = 'appointed';

    /**
     * The appointee holds the position by virtue of holding another
     * role.
     *
     * The appointment is not a separate decision; it is the
     * automatic consequence of the other role's occupancy.
     *
     * Business decision #15 ("Does GM/CEO hold a Board seat
     * ex-officio?") is answered through this mode.
     *
     * B6 case 3 of 4.
     */
    case ExOfficio = 'ex_officio';

    /**
     * A hybrid acquisition process that includes both a vote and a
     * non-electoral step.
     *
     * The canonical example: an election by the General Assembly of
     * a slate of directors that the Board's Nomination Committee
     * proposed.
     *
     * B6 case 4 of 4.
     */
    case Mixed = 'mixed';

    // =========================================================================
    // Direct predicates — one per case
    // =========================================================================

    /**
     * Whether this case is the Elected mode.
     *
     * @return bool
     */
    public function isElected(): bool
    {
        return $this === self::Elected;
    }

    /**
     * Whether this case is the Appointed mode.
     *
     * @return bool
     */
    public function isAppointed(): bool
    {
        return $this === self::Appointed;
    }

    /**
     * Whether this case is the ExOfficio mode.
     *
     * @return bool
     */
    public function isExOfficio(): bool
    {
        return $this === self::ExOfficio;
    }

    /**
     * Whether this case is the Mixed mode.
     *
     * @return bool
     */
    public function isMixed(): bool
    {
        return $this === self::Mixed;
    }

    // =========================================================================
    // Derived predicates
    // =========================================================================

    /**
     * Whether this mode's acquisition process includes a vote of an
     * electing body.
     *
     * Returns true for Elected and Mixed; false for Appointed and
     * ExOfficio.
     *
     * The predicate answers "was there a vote in the acquisition
     * process at all?" — not "was the vote the sole or dominant
     * step?". A mixed appointment did involve a vote, even when a
     * prior designation constrained the slate that was voted on.
     *
     * See the file-level change-verification block
     * §"The involvesElection() predicate" for the reasoning and for
     * the note that a future case addition must amend this predicate
     * explicitly.
     *
     * @return bool
     */
    public function involvesElection(): bool
    {
        return $this === self::Elected
            || $this === self::Mixed;
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
     *     ['elected', 'appointed', 'ex_officio', 'mixed']
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