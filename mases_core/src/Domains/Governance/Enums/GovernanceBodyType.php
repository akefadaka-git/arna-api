<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 2 of 17
 * =============================================================================
 *
 * File:            Enums/GovernanceBodyType.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (enum)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `governance_bodies.body_type` column
 *   (created by G2a file 1 of 17) held a `VARCHAR(32)` value but had
 *   no application-layer vocabulary. Any string could be written to
 *   it — the schema does not enforce a closed set, by design, and no
 *   PHP enum yet existed to enforce one at the application boundary.
 *
 *   The `Config/governance.php` `body_types` array (G1 file 1 of 10)
 *   declared the five intended values (`assembly`, `board`,
 *   `committee`, `subcommittee`, `ad_hoc`) as configuration data,
 *   but had no corresponding runtime representation.
 *
 * After:
 *   One file that supplies the runtime vocabulary for the
 *   `governance_bodies.body_type` column. The enum mirrors
 *   `ShareholderType` (Phase 2A) in structure: a string-backed
 *   enum with one case per config-declared value, direct
 *   `isXxx()` predicates, and a `values()` static.
 *
 *   The five cases:
 *
 *     Assembly      = 'assembly'
 *     Board         = 'board'
 *     Committee     = 'committee'
 *     Subcommittee  = 'subcommittee'
 *     AdHoc         = 'ad_hoc'
 *
 *   The five direct predicates:
 *
 *     isAssembly(), isBoard(), isCommittee(), isSubcommittee(),
 *     isAdHoc()
 *
 *   The two derived predicates:
 *
 *     isStanding()   — assembly, board, committee, subcommittee
 *                      (every case except AdHoc)
 *     isTemporary()  — only AdHoc (the inverse of isStanding)
 *
 *   The static vocabulary method:
 *
 *     values()       — array of the five backing strings
 *
 *   What this enum deliberately does NOT do:
 *
 *     · It does NOT declare a `label()` method. Display strings
 *       belong to the resource layer, resolved through the
 *       translation catalogue registered by G1 file 4. The five
 *       Amharic labels are already declared in
 *       `Resources/lang/am/common.php` under the `body_type` group
 *       (G1 file 2 of 10); the English labels are declared in the
 *       `en` counterpart (G1 file 3 of 10). A `GovernanceBodyResource`
 *       (G2a file 11) resolves them via
 *       `__('governance::common.body_type.' . $type->value)`.
 *
 *       The ShareholderType enum's docblock records the same
 *       decision for its own domain, with the same reasoning:
 *       hard-coding English or Amharic display text in a domain
 *       enum would (a) violate the Amharic-first policy by
 *       presenting one language as canonical, and (b) require a
 *       Change Request to update, which is disproportionate for a
 *       display string.
 *
 *     · It does NOT declare a `requiresParent()` helper. Whether a
 *       given body type requires a `parent_body_id` is an
 *       organizational decision, not a definitional one. The one
 *       exception — a subcommittee is by definition a committee of
 *       another committee — is expressed by the `isSubcommittee()`
 *       predicate, not by a "requires parent" rule. The service
 *       layer (`GovernanceBodyService`, G2a file 5) enforces the
 *       presence or absence of a parent based on organizational
 *       policy once that policy is defined. Encoding the rule
 *       here would convert an assumption into a code constraint,
 *       which the master prompt §54 forbids.
 *
 *     · It does NOT declare a `isVoting()` or `hasVotingRights()`
 *       helper. Voting rights are a governance-policy decision
 *       (master prompt §10: "Do not assume all positions have
 *       voting rights"). They are a property of POSITIONS, not of
 *       body types, and the `governance_positions` table (G2b)
 *       will carry the voting-flag. This enum describes the
 *       structural class of a body, not the electoral entitlements
 *       of its members.
 *
 *     · It does NOT enforce the vocabulary at the database level.
 *       The migration (G2a file 1) stores `body_type` as a
 *       `VARCHAR(32)`; a MySQL ENUM would couple the schema to a
 *       vocabulary that lives in `Config/governance.php`. The
 *       enum is the application-layer enforcement point; the
 *       database is deliberately permissive. This is the platform's
 *       convention across every domain (see `ShareholderType`,
 *       `CertificateStatus`, `HoldingDirection`).
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * Decision §8 of the master prompt — Body types:
 *
 *     assembly
 *     board
 *     committee
 *     subcommittee
 *     ad_hoc
 *
 * The five values above are the ONLY values permitted in the
 * `governance_bodies.body_type` column. They are also the only
 * values the Governance domain accepts at its HTTP boundary
 * (validated by `StoreGovernanceBodyRequest` and
 * `UpdateGovernanceBodyRequest`, G2a files 12 and 13) and the only
 * values `GovernanceBodyService` (G2a file 5) will process.
 *
 * The list is frozen by §8. Adding, removing, or renaming a case
 * is a Change Request, because it changes the meaning of
 * historical rows in the `governance_bodies` table.
 *
 * The list also appears in `Config/governance.php` under the
 * `body_types` array. The two are intended to stay in lockstep;
 * a CR that extends one extends the other in the same delivery.
 *
 * =============================================================================
 * The five values, and the concepts they name
 * =============================================================================
 *   Assembly
 *       The shareholders' General Assembly — the highest governing
 *       body in a share company. Attended by shareholders or their
 *       representatives; elects the Board; approves the Articles of
 *       Association and major structural changes.
 *
 *   Board
 *       The Board of Directors — the body elected by the Assembly
 *       and charged with strategic oversight, appointment of the
 *       CEO, and the standing committees. In MASES governance
 *       models, the Board is typically the parent of every standing
 *       committee.
 *
 *   Committee
 *       A standing committee of another body — the Board, the
 *       Assembly, or (in a nested configuration) another committee.
 *       Procurement, Control, Election Executive, Audit, Risk,
 *       Credit, Ethics, Disciplinary, Tender, Strategic Planning,
 *       Compliance, Investment, and HR committees are all
 *       instances of this case; their distinct identities live in
 *       the `code` column, not in this enum.
 *
 *   Subcommittee
 *       A committee of a committee. A subcommittee's parent is
 *       always another committee (or, in principle, another
 *       subcommittee, though a deep nesting is unusual). The case
 *       exists separately from `Committee` because a subcommittee's
 *       reporting structure and its scope of authority differ from
 *       its parent committee's, and because a future CR may attach
 *       different configuration to the two cases.
 *
 *   AdHoc
 *       A temporary body established for a specific purpose and
 *       dissolved when the purpose is complete. Unlike the four
 *       standing cases, an ad-hoc body has no ongoing existence —
 *       it is `isTemporary()` in the enum's own terms.
 *
 * =============================================================================
 * Why this is a backed string enum
 * =============================================================================
 * The enum is string-backed so that:
 *
 *   - It maps directly to the `governance_bodies.body_type` column
 *     without a casting layer.
 *   - It survives database dumps: a `SHOW CREATE TABLE` on
 *     `governance_bodies` shows the plain string values, which
 *     match the enum cases exactly.
 *   - It can be used in validation rules via
 *     `Rule::enum(GovernanceBodyType::class)`, which Laravel
 *     resolves against the enum's values.
 *
 * These three properties mirror the ShareholderType enum's own
 * rationale for a string backing, and the CertificateStatus and
 * HoldingDirection enums' rationale in the same domain.
 *
 * =============================================================================
 * The isStanding() / isTemporary() split
 * =============================================================================
 * The four standing cases — Assembly, Board, Committee,
 * Subcommittee — are all bodies that exist for an indefinite
 * period. They are established by a resolution, a charter, or the
 * Articles of Association, and they persist until they are
 * explicitly dissolved. Their `effective_until` is typically null.
 *
 * The AdHoc case is definitional in its temporariness. The Latin
 * phrase "ad hoc" means "for this purpose"; an ad-hoc body exists
 * to accomplish a specific task and ceases to exist once the task
 * is complete. Its `effective_until` is expected to be non-null
 * (or the body is expected to be deactivated) when the task ends.
 *
 * The split is definitional, not organizational. It is encoded as
 * two predicates (`isStanding()` and `isTemporary()`) rather than
 * a config-driven helper because no organizational decision can
 * make an ad-hoc body permanent or make a Board temporary — the
 * two cases occupy different structural categories.
 *
 * A future CR that adds a sixth case must decide which category
 * the case belongs to. The decision is not inferred; the CR must
 * amend `isStanding()` and `isTemporary()` explicitly, and that
 * amendment is part of the review.
 *
 * =============================================================================
 * Usage examples
 * =============================================================================
 *   // Resolve a string from the database to an enum case:
 *   $type = GovernanceBodyType::tryFrom($body->body_type);
 *
 *   // Branch on a specific type:
 *   if ($body->type() === GovernanceBodyType::Board) { ... }
 *
 *   // Use a predicate:
 *   if ($body->type()?->isStanding()) { ... }
 *
 *   // Validate input:
 *   Rule::enum(GovernanceBodyType::class);
 *
 *   // Obtain the closed vocabulary:
 *   GovernanceBodyType::values();
 *   // → ['assembly', 'board', 'committee', 'subcommittee', 'ad_hoc']
 *
 * The `GovernanceBody` model (G2a file 3) will declare a `type()`
 * helper that returns `?GovernanceBodyType`, mirroring the
 * `Shareholder::type()` helper's contract. A `null` return means
 * the row holds an unrecognized value — a defensive posture that
 * allows a corrupted row to be diagnosed rather than crashing.
 *
 * =============================================================================
 * Testability
 * =============================================================================
 * The enum is a pure value type with no dependencies. Unit tests
 * exercise `tryFrom()` for every backing value, `values()` for its
 * exact content, and each predicate for its truth table. No
 * framework boot, no database, no container resolution.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Enums
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Enums;

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