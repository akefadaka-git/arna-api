<?php

declare(strict_types=1);

/* Closed vocabulary for Governance body classifications. */
namespace MaaleArna\Arna\Core\Domains\Governance\Enums;

enum GovernanceBodyType: string
{
    /**
     * The shareholders' General Assembly.
     *
     * The highest governing body in a share company. Attended by
     * shareholders or their representatives; elects the Board;
     * approves the Articles of Association and major structural
     * changes.
     *
     * §8 case 1 of 5.
     */
    case Assembly = 'assembly';

    /**
     * The Board of Directors.
     *
     * The body elected by the Assembly and charged with strategic
     * oversight, the appointment of the CEO, and the standing
     * committees. Typically the parent of every standing committee
     * in the organization.
     *
     * §8 case 2 of 5.
     */
    case Board = 'board';

    /**
     * A standing committee of another body.
     *
     * Procurement, Control, Election Executive, Audit, Risk, Credit,
     * Ethics, Disciplinary, Tender, Strategic Planning, Compliance,
     * Investment, and HR committees are all instances of this case.
     * Their distinct identities live in the `governance_bodies.code`
     * column, not in this enum.
     *
     * §8 case 3 of 5.
     */
    case Committee = 'committee';

    /**
     * A committee of a committee.
     *
     * A subcommittee's parent is always another committee (or, in
     * principle, another subcommittee). The case exists separately
     * from `Committee` because a subcommittee's reporting structure
     * and scope of authority differ from its parent's, and because
     * a future CR may attach different configuration to the two
     * cases.
     *
     * §8 case 4 of 5.
     */
    case Subcommittee = 'subcommittee';

    /**
     * A temporary body established for a specific purpose.
     *
     * An ad-hoc body exists to accomplish a specific task and
     * ceases to exist once the task is complete. It is the only
     * case for which `isStanding()` returns false and
     * `isTemporary()` returns true.
     *
     * §8 case 5 of 5.
     */
    case AdHoc = 'ad_hoc';

    // =========================================================================
    // Direct predicates — one per case
    // =========================================================================

    /**
     * Whether this case is the Assembly.
     *
     * @return bool
     */
    public function isAssembly(): bool
    {
        return $this === self::Assembly;
    }

    /**
     * Whether this case is the Board.
     *
     * @return bool
     */
    public function isBoard(): bool
    {
        return $this === self::Board;
    }

    /**
     * Whether this case is a standing committee.
     *
     * Note: this predicate is TRUE for the `Committee` case only.
     * It does NOT return true for `Subcommittee` — see
     * `isSubcommittee()` for that case. Callers that want "any
     * committee-shaped body, whether committee or subcommittee"
     * should test `isCommittee() || isSubcommittee()` explicitly,
     * or use `isStanding() && !isAssembly() && !isBoard()` if the
     * broader group is intended.
     *
     * The decision to keep the two cases distinguishable at the
     * predicate layer is deliberate: a caller that needs to know
     * whether a body is a top-level committee versus a nested
     * subcommittee cannot recover that distinction from a merged
     * predicate.
     *
     * @return bool
     */
    public function isCommittee(): bool
    {
        return $this === self::Committee;
    }

    /**
     * Whether this case is a subcommittee.
     *
     * @return bool
     */
    public function isSubcommittee(): bool
    {
        return $this === self::Subcommittee;
    }

    /**
     * Whether this case is an ad-hoc body.
     *
     * @return bool
     */
    public function isAdHoc(): bool
    {
        return $this === self::AdHoc;
    }

    // =========================================================================
    // Derived predicates
    // =========================================================================

    /**
     * Whether this case is a standing (ongoing) body.
     *
     * A standing body exists for an indefinite period. It is
     * established by a resolution, a charter, or the Articles of
     * Association, and it persists until it is explicitly dissolved.
     * Its `effective_until` is typically null.
     *
     * The four standing cases are Assembly, Board, Committee, and
     * Subcommittee. The one non-standing case is AdHoc.
     *
     * @return bool
     */
    public function isStanding(): bool
    {
        return $this !== self::AdHoc;
    }

    /**
     * Whether this case is a temporary (ad-hoc) body.
     *
     * The exact inverse of `isStanding()`. Provided as a named
     * predicate so that callers can express their intent directly
     * — `if ($type->isTemporary())` reads more clearly at the call
     * site than `if (!$type->isStanding())`.
     *
     * Only the AdHoc case satisfies this predicate.
     *
     * @return bool
     */
    public function isTemporary(): bool
    {
        return $this === self::AdHoc;
    }

    // =========================================================================
    // Vocabulary
    // =========================================================================

    /**
     * The raw string values of every case, in declaration order.
     *
     * Useful as a validation whitelist and as an input to
     * `Rule::in(...)` when the caller cannot use `Rule::enum(...)` —
     * e.g. inside a config file, a migration, or a non-Laravel
     * context.
     *
     * Declaration order is:
     *
     *     ['assembly', 'board', 'committee', 'subcommittee', 'ad_hoc']
     *
     * This order matches `Config/governance.php`'s `body_types`
     * array. The two must stay in lockstep; a future CR that
     * reorders one reorders the other.
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