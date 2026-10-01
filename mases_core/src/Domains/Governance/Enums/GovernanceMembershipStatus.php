<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 17 of 31
 * =============================================================================
 *
 * File:            Enums/GovernanceMembershipStatus.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (enum)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `governance_memberships.status`
 *   column (created by G2c file 16 of 31) holds a `VARCHAR(32)`
 *   value with a default of `'active'`, but had no application-layer
 *   vocabulary. Any string could be written to it — the schema does
 *   not enforce a closed set, by design, and no PHP enum yet existed
 *   to enforce one at the application boundary.
 *
 * After:
 *   One file that supplies the runtime vocabulary for the
 *   `governance_memberships.status` column. The enum mirrors
 *   `GovernanceAppointmentStatus` (G2c file 2 of 31) and the two
 *   sibling enums in structure: a string-backed enum with one case
 *   per declared value, direct `isXxx()` predicates, two derived
 *   predicates, and a `values()` static.
 *
 *   The six cases:
 *
 *     Active     = 'active'
 *     Ended      = 'ended'
 *     Suspended  = 'suspended'
 *     Resigned   = 'resigned'
 *     Removed    = 'removed'
 *     Expired    = 'expired'
 *
 *   The six direct predicates:
 *
 *     isActive(), isEnded(), isSuspended(), isResigned(),
 *     isRemoved(), isExpired()
 *
 *   The two derived predicates:
 *
 *     isTerminal()  — ended || resigned || removed || expired
 *                      (the four states from which the membership
 *                       has reached a final outcome and will not
 *                       resume)
 *     isOngoing()   — active || suspended
 *                      (the two states from which the membership
 *                       may continue — an active membership runs
 *                       normally; a suspended membership may be
 *                       resumed. The exact inverse of
 *                       isTerminal().)
 *
 *   The static vocabulary method:
 *
 *     values()  — array of the six backing strings
 *
 *   What this enum deliberately does NOT do:
 *
 *     · It does NOT declare a `label()` method. Display strings
 *       belong to the resource layer, resolved through the
 *       translation catalogue. The Amharic labels for these six
 *       values are NOT yet declared in
 *       `Resources/lang/am/common.php` (G1 file 2 of 10) — that
 *       file declared lifecycles for meetings, minutes,
 *       resolutions, actions, delegations, declarations, elections,
 *       and documents, but did not include a `membership_status`
 *       group, because the membership entity had not yet been
 *       introduced at G1.
 *
 *       CONSEQUENCE FOR G2c: this file's delivery leaves the
 *       catalogue incomplete. A future file in this unit — or a
 *       follow-up CR — must add six new keys to both catalogues:
 *
 *           common.membership_status.active     →  'ንቁ'
 *           common.membership_status.ended      →  'የተጠናቀቀ'
 *           common.membership_status.suspended  →  'የተገደበ'
 *           common.membership_status.resigned   →  'በፈቃዱ የተለቀቀ'
 *           common.membership_status.removed    →  'የተሰናበተ'
 *           common.membership_status.expired    →  'ጊዜው ያለፈበት'
 *
 *       Three of the six (`ንቁ`, `የተገደበ` alternative, `ጊዜው ያለፈበት`)
 *       appear elsewhere in the catalogue under
 *       `delegation_status.active`, `delegation_status.revoked`
 *       (related but distinct), and `action_item_status.overdue`
 *       and `delegation_status.expired`. The membership vocabulary
 *       deliberately reuses strings where the underlying concept is
 *       the same; the three genuinely new strings
 *       (`የተጠናቀቀ`, `በፈቃዱ የተለቀቀ`, `የተሰናበተ`) are compounds of
 *       existing vocabulary. A native-speaker reviewer is the
 *       correct final gate for all six.
 *
 *       A future CR that touches this domain's catalogues will
 *       supply the group; the enum itself is complete without it.
 *
 *     · It does NOT declare a `isRemovedByAuthority()` helper.
 *       Whether a `removed` status was reached via a disciplinary
 *       action, an administrative correction, or a by-law
 *       consequence is a fact about the ACT of removal, not about
 *       the resulting state. The membership row's `ended_reason`
 *       column carries the specifics; a future CR that needs a
 *       structured reason vocabulary introduces a separate
 *       `removal_reason` column — not a second status value.
 *
 *     · It does NOT declare a `wasForced()` or `wasVoluntary()`
 *       helper. The two classifications overlap ambiguously with
 *       the six cases: a `resigned` membership is voluntary; an
 *       `expired` membership is neither voluntary nor forced — it
 *       simply ceased. A future CR that needs the classification
 *       introduces it as a separate computed helper alongside the
 *       data that justifies it.
 *
 *     · It does NOT declare a `transitions()` method or encode a
 *       state machine. The set of legal transitions — `active` →
 *       `ended`, `active` → `resigned`, `active` → `removed`,
 *       `active` → `expired`, `active` → `suspended`, `suspended`
 *       → `active` — is a service-layer concern
 *       (`GovernanceMembershipService`, G2c file 20 of 31). The
 *       service refuses any transition outside its declared
 *       matrix. Encoding the graph in the enum would scatter the
 *       rule across two files.
 *
 *     · It does NOT enforce the vocabulary at the database level.
 *       The migration stores `status` as a `VARCHAR(32)` with a
 *       default of `'active'`; a MySQL ENUM would couple the
 *       schema to a vocabulary that lives in application code.
 *       The enum is the application-layer enforcement point; the
 *       database is deliberately permissive.
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * The six values were approved in the G2c manifest's ruling B6
 * (see the manifest section §"B6 — Enum names/values"). They are:
 *
 *     active
 *     ended
 *     suspended
 *     resigned
 *     removed
 *     expired
 *
 * The list is frozen by that ruling. Adding, removing, or renaming
 * a case is a Change Request, because it changes the meaning of
 * historical rows in the `governance_memberships` table.
 *
 * =============================================================================
 * The six states, and the concepts they name
 * =============================================================================
 *   Active
 *       The membership is currently in force. The member occupies
 *       the seat. Meetings of the body treat the member as
 *       present-capable and (where the position carries voting
 *       rights) voting.
 *
 *       The default state for a newly-created membership — the
 *       migration (G2c file 16 of 31) sets `status` to `'active'`.
 *
 *   Ended
 *       The membership reached the natural end of its period. The
 *       end date is set and has passed; the membership is no longer
 *       in force. The end was not a resignation, a removal, or an
 *       expiry — it was the ordinary completion of the term the
 *       membership was created for.
 *
 *       This is the canonical terminal state for a membership whose
 *       term ran its full course.
 *
 *   Suspended
 *       The membership is temporarily paused. The member does not
 *       currently occupy the seat, but may resume occupancy if the
 *       suspension is lifted.
 *
 *       Suspension is NOT terminal — a suspended membership may be
 *       reactivated. This distinction matters for the overlap
 *       invariant (ruling B4): a suspended membership is treated
 *       the same as an active one for overlap purposes, because it
 *       still reserves the seat. See the service layer's
 *       `normaliseForRegister()` and `normaliseForUpdate()` for the
 *       exact treatment.
 *
 *   Resigned
 *       The member voluntarily ended their occupancy before the
 *       natural end of the term.
 *
 *       The distinction from `ended` matters for reporting: a term
 *       that completed naturally is not the same as a term that
 *       ended early because the member chose to leave.
 *
 *   Removed
 *       The member was removed from the seat by an authority
 *       competent to do so. The removal was not voluntary.
 *
 *       The distinction from `resigned` matters for the same reason
 *       and has additional consequences: a removal is likely to
 *       appear in a governance report or an audit, and the
 *       circumstances may be sensitive.
 *
 *   Expired
 *       The membership's time bound elapsed without an explicit end
 *       action. This is a catch-all terminal state for memberships
 *       whose end date passed without anyone recording a specific
 *       reason.
 *
 *       The distinction from `ended` matters for process: an
 *       `ended` membership had its natural termination recorded at
 *       the time it occurred; an `expired` membership is typically
 *       discovered after the fact by a query that notices its end
 *       date has passed. A future CR could introduce a scheduled
 *       job that transitions `active` memberships to `expired`
 *       when their end date passes without another transition; that
 *       CR is outside this delivery's scope.
 *
 * =============================================================================
 * Why this is a backed string enum
 * =============================================================================
 * The enum is string-backed so that:
 *
 *   - It maps directly to the `governance_memberships.status`
 *     column without a casting layer.
 *   - It survives database dumps: a `SHOW CREATE TABLE` on
 *     `governance_memberships` shows the plain string values, which
 *     match the enum cases exactly.
 *   - It can be used in validation rules via
 *     `Rule::enum(GovernanceMembershipStatus::class)`, which
 *     Laravel resolves against the enum's values.
 *
 * These three properties mirror the same properties on every other
 * string-backed enum in the platform.
 *
 * =============================================================================
 * The isTerminal() / isOngoing() split
 * =============================================================================
 * The two derived predicates partition the six cases cleanly:
 *
 *     isOngoing()   →  Active,   Suspended
 *     isTerminal()  →  Ended,    Resigned, Removed, Expired
 *
 * The partition is STATE-MACHINE terminology, not temporal:
 *
 *   · An `active` membership is ongoing even if its natural end
 *     date is in the past (a data-entry defect the domain does not
 *     correct automatically) — the membership has not been
 *     EXPLICITLY transitioned out of `active`.
 *
 *   · A `suspended` membership is ongoing: the seat is reserved,
 *     the membership may resume.
 *
 *   · An `ended`, `resigned`, `removed`, or `expired` membership is
 *     terminal: the outcome is final, no further transition is
 *     expected. A member who left and returns occupies the seat
 *     through a NEW membership, not by resurrecting the old one.
 *
 * The two predicates are the inverse of one another:
 *
 *     $status->isTerminal() === !$status->isOngoing()
 *
 * Both are provided as named predicates so that call sites read
 * naturally depending on the caller's frame of reference — "is this
 * still in play?" (`isOngoing()`) vs. "has this finished?"
 * (`isTerminal()`).
 *
 * A future CR that adds a seventh case must decide which partition
 * the case belongs to. The decision is not inferred; the CR must
 * amend `isTerminal()` and `isOngoing()` explicitly, and that
 * amendment is part of the review.
 *
 * =============================================================================
 * The suspended state and the overlap invariant
 * =============================================================================
 * The overlap invariant (ruling B4) restricts ACTIVE memberships
 * for the same (person reference, position, body). The service
 * layer's overlap query filters on status. A precise reading of
 * ruling B4 says "no two ACTIVE memberships". The service layer
 * interprets this to include `suspended` as well:
 *
 *   · A suspended membership still reserves its seat. If a second
 *     membership were created for the same seat while the first was
 *     suspended, the two would collide when the first resumed.
 *
 *   · The overlap check therefore treats `active` and `suspended`
 *     as the same "occupancy" class — matching the enum's
 *     `isOngoing()` partition.
 *
 * This is recorded here so a future reader comparing the enum's
 * partition against the service's overlap query sees the alignment.
 * A future CR that changes the treatment (e.g. allowing a seat to be
 * filled while its incumbent is suspended) would need to change both
 * — the enum's `isOngoing()` if the semantic classification changes,
 * and the service's overlap query regardless.
 *
 * =============================================================================
 * Usage examples
 * =============================================================================
 *   // Resolve a string from the database to an enum case:
 *   $status = GovernanceMembershipStatus::tryFrom($membership->status);
 *
 *   // Branch on a specific state:
 *   if ($status === GovernanceMembershipStatus::Active) { ... }
 *
 *   // Use a predicate:
 *   if ($status?->isTerminal()) { ... }
 *
 *   // Validate input:
 *   Rule::enum(GovernanceMembershipStatus::class);
 *
 *   // Obtain the closed vocabulary:
 *   GovernanceMembershipStatus::values();
 *   // → ['active', 'ended', 'suspended', 'resigned', 'removed', 'expired']
 *
 * The `GovernanceMembership` model (G2c file 18 of 31) will declare
 * a `statusEnum()` helper that returns
 * `?GovernanceMembershipStatus`, mirroring the sibling models'
 * resolver contracts.
 *
 * =============================================================================
 * Testability
 * =============================================================================
 * The enum is a pure value type with no dependencies. Unit tests
 * exercise `tryFrom()` for every backing value, `values()` for its
 * exact content, each direct predicate for its truth table, and the
 * two derived predicates for their partition (exactly one of
 * `isOngoing()` / `isTerminal()` is true for every case).
 *
 * No framework boot, no database, no container resolution.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Enums
 * @version 1.0.0
 */
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