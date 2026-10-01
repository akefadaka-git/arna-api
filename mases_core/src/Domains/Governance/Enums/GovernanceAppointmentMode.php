<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 3 of 31
 * =============================================================================
 *
 * File:            Enums/GovernanceAppointmentMode.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (enum)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `governance_appointments.appointment_mode`
 *   column (created by G2c file 1 of 31) holds a `VARCHAR(32)` value,
 *   but had no application-layer vocabulary. Any string could be
 *   written to it — the schema does not enforce a closed set, by
 *   design, and no PHP enum yet existed to enforce one at the
 *   application boundary.
 *
 *   As a consequence, the four G0 business decisions the manifest's
 *   ruling B5 identified as configurable —
 *
 *       #5   Are members elected, appointed, or mixed?
 *       #6   Are committee members Board-appointed, ex-officio, or
 *            elected?
 *       #14  Is the Board Secretary elected or appointed?
 *       #15  Does GM/CEO hold a Board seat ex-officio?
 *
 *   — had no runtime representation. The DECISION to make them
 *   configurable (rather than code constraints) was approved, but
 *   the vocabulary through which the configuration is expressed was
 *   not yet declared.
 *
 * After:
 *   One file that supplies the runtime vocabulary for the
 *   `governance_appointments.appointment_mode` column. The enum
 *   mirrors `GovernanceAppointmentStatus` (G2c file 2 of 31) and
 *   `GovernanceBodyType` (G2a file 2 of 17) in structure: a
 *   string-backed enum with one case per declared value, direct
 *   `isXxx()` predicates, one derived predicate, and a `values()`
 *   static.
 *
 *   The four cases:
 *
 *     Elected    = 'elected'
 *     Appointed  = 'appointed'
 *     ExOfficio  = 'ex_officio'
 *     Mixed      = 'mixed'
 *
 *   The four direct predicates:
 *
 *     isElected(), isAppointed(), isExOfficio(), isMixed()
 *
 *   The one derived predicate:
 *
 *     involvesElection()  — elected || mixed
 *                           (an appointment whose creation process
 *                            includes a vote of some electing body)
 *
 *   The static vocabulary method:
 *
 *     values()  — array of the four backing strings
 *
 *   What this enum deliberately does NOT do:
 *
 *     · It does NOT declare a `label()` method. Display strings
 *       belong to the resource layer, resolved through the
 *       translation catalogue. The Amharic labels for these four
 *       values are NOT yet declared in
 *       `Resources/lang/am/common.php` (G1 file 2 of 10) — that
 *       file declared a `membership_type` group with eight
 *       values, but did not include an `appointment_mode` group,
 *       because the appointment entity had not yet been
 *       introduced at G1.
 *
 *       CONSEQUENCE FOR G2c: this file's delivery leaves the
 *       catalogue incomplete. A future file in this unit — or a
 *       follow-up CR — must add four new keys to both catalogues:
 *
 *           common.appointment_mode.elected    →  'የተመረጠ'
 *           common.appointment_mode.appointed  →  'የተሾመ'
 *           common.appointment_mode.ex_officio →  'በስልጣን መሠረት'
 *           common.appointment_mode.mixed      →  'የተቀላቀለ'
 *
 *       Three of the four (`የተመረጠ`, `የተሾመ`, `በስልጣን መሠረት`)
 *       already appear in the catalogue under
 *       `membership_type.elected`, `membership_type.appointed`,
 *       and `membership_type.ex_officio` respectively. The
 *       appointment-mode vocabulary deliberately reuses those
 *       existing strings — the two vocabularies describe the same
 *       acquisition concepts, and using different Amharic
 *       renderings for the two would confuse a reader.
 *
 *       The fourth (`የተቀላቀለ`, literally "mixed" / "blended") is
 *       NEW — the `membership_types` group has no equivalent,
 *       because the membership-type vocabulary distinguishes the
 *       RESULT of acquisition (elected vs. appointed vs. ex-
 *       officio) while the appointment-mode vocabulary additionally
 *       distinguishes the HYBRID case. A native-speaker reviewer
 *       may prefer `የተደባለቀ` or another rendering.
 *
 *       A future CR that touches this domain's catalogues will
 *       supply the group; the enum itself is complete without it.
 *
 *     · It does NOT declare a `requiresElection()` method. The
 *       `involvesElection()` predicate answers "does this mode's
 *       process include a vote?" — a classification of the mode
 *       itself. Whether a SPECIFIC appointment of that mode must
 *       have a recorded election before it can transition to
 *       `active` is a service-layer rule
 *       (`GovernanceAppointmentService`, G2c file 7 of 31), not a
 *       property of the enum. The two are deliberately separate:
 *       the enum classifies, the service enforces.
 *
 *     · It does NOT declare a `requiresInstrument()` method. Every
 *       appointment, regardless of mode, must reference SOME
 *       instrument. The instrument is not an option of the mode; it
 *       is a mandatory structural fact of the appointment entity.
 *       The migration (G2c file 1 of 31) declares
 *       `instrument_type` as NOT NULL for exactly that reason.
 *
 *     · It does NOT declare a `hasFixedTerm()` method. The length
 *       of an appointment's term is a property of the term the
 *       appointment is scoped to — or, for term-less appointments,
 *       of the appointment's own effective_date. The mode does not
 *       constrain the term's length. An `elected` Board member and
 *       an `ex_officio` CEO both hold their seats for as long as
 *       the applicable rule dictates; the rule is not encoded in
 *       the mode.
 *
 *     · It does NOT enforce the vocabulary at the database level.
 *       The migration stores `appointment_mode` as a `VARCHAR(32)`;
 *       a MySQL ENUM would couple the schema to a vocabulary that
 *       lives in application code. The enum is the application-
 *       layer enforcement point; the database is deliberately
 *       permissive.
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * The four values were approved in the G2c manifest's ruling B6
 * (see the manifest section §"B6 — Enum names/values"). They are:
 *
 *     elected
 *     appointed
 *     ex_officio
 *     mixed
 *
 * The list is frozen by that ruling. Adding, removing, or renaming
 * a case is a Change Request, because it changes the meaning of
 * historical rows in the `governance_appointments` table.
 *
 * The list is also expected to appear — once the catalogue
 * amendment described above is delivered — under an
 * `appointment_mode` group in `Resources/lang/{am,en}/common.php`.
 * The two must stay in lockstep; a CR that extends one extends the
 * other in the same delivery.
 *
 * =============================================================================
 * The four modes, and the concepts they name
 * =============================================================================
 *   Elected
 *       The appointee was chosen by a vote of an electing body. The
 *       voting body is organization-specific: the General Assembly
 *       elects the Board; the Board elects its chair and secretary;
 *       a committee may elect its own chair within the mandate the
 *       Board grants it.
 *
 *       An `elected` appointment's provenance includes an election
 *       — whether the election is recorded as a `GovernanceElection`
 *       entity (G10) or is a component of a resolution that the
 *       resolving body adopted.
 *
 *       `involvesElection()` returns true for this case.
 *
 *   Appointed
 *       The appointee was designated by an appointing authority,
 *       without a vote. An appointing authority might be the Board
 *       (for senior management positions), a shareholder (for
 *       their representative), or an external authority (for a
 *       regulator's observer seat).
 *
 *       An `appointed` appointment's provenance is the appointing
 *       authority's exercise of its appointment power, recorded in
 *       the instrument the appointment references.
 *
 *       `involvesElection()` returns false for this case.
 *
 *   ExOfficio
 *       The appointee holds the position by virtue of holding
 *       ANOTHER role. The appointment is not a separate decision;
 *       it is the automatic consequence of the other role's
 *       occupancy.
 *
 *       Business decision #15 ("Does GM/CEO hold a Board seat
 *       ex-officio?") is answered through this mode. If the
 *       organization's by-law grants the CEO a Board seat, the
 *       CEO's Board membership is recorded as an ex-officio
 *       appointment. If the by-law does not, no such appointment
 *       is created.
 *
 *       An `ex_officio` appointment typically has NO fixed end
 *       date; it ends when the underlying role ends. Its
 *       `governance_term_id` is usually null, because its duration
 *       is determined by the underlying role, not by a body term.
 *
 *       `involvesElection()` returns false for this case.
 *
 *   Mixed
 *       A hybrid in which the acquisition process includes both a
 *       vote and a non-electoral step. The canonical example: an
 *       election by the General Assembly of a slate of directors
 *       that the Board's Nomination Committee proposed. The
 *       election is real (a vote was held); the appointment was
 *       also constrained by a prior designated list.
 *
 *       The `mixed` case exists so the domain does not have to
 *       choose between `elected` and `appointed` when both are
 *       true. Recording the hybrid preserves the full provenance
 *       rather than collapsing it to the dominant step.
 *
 *       `involvesElection()` returns true for this case — a mixed
 *       appointment DID involve a vote. The predicate answers
 *       "was there a vote in the acquisition process at all?",
 *       not "was the vote the sole or dominant step?".
 *
 * =============================================================================
 * Why this is a backed string enum
 * =============================================================================
 * The enum is string-backed so that:
 *
 *   - It maps directly to the `governance_appointments.appointment_mode`
 *     column without a casting layer.
 *   - It survives database dumps: a `SHOW CREATE TABLE` on
 *     `governance_appointments` shows the plain string values, which
 *     match the enum cases exactly.
 *   - It can be used in validation rules via
 *     `Rule::enum(GovernanceAppointmentMode::class)`, which Laravel
 *     resolves against the enum's values.
 *
 * These three properties mirror the same properties on every other
 * string-backed enum in the platform.
 *
 * =============================================================================
 * The involvesElection() predicate
 * =============================================================================
 * The single derived predicate classifies the four cases into two
 * groups:
 *
 *     involvesElection()  →  Elected, Mixed
 *     !involvesElection() →  Appointed, ExOfficio
 *
 * The classification is not a partition of the enum's cases into
 * disjoint SETS in the mathematical sense — a caller does not
 * enumerate "the elected-modes" and "the non-elected-modes" as
 * complementary categories. It is a BOOLEAN QUESTION that a caller
 * asks about a specific mode: "did this acquisition process include
 * a vote?"
 *
 * The answer matters for a specific class of downstream logic:
 *
 *   · A UI that renders an appointment's "Election" tab shows the
 *     tab only when `involvesElection()` is true.
 *   · A validation rule that requires a linked election record (G10)
 *     is applied only when `involvesElection()` is true.
 *   · A report that counts "elected seats" versus "appointed seats"
 *     can partition the same way — with the caveat that `mixed`
 *     belongs to BOTH the elected count and the "not purely
 *     appointed" count, and a report that needs a strict partition
 *     must make its convention explicit.
 *
 * The predicate is deliberately a simple OR of two cases. A future
 * CR that adds a fifth case (for example, `co_opted` — a committee
 * filling its own vacancy without an election) must decide whether
 * the case involves an election. The decision is not inferred; the
 * CR must amend `involvesElection()` explicitly, and that amendment
 * is part of the review.
 *
 * =============================================================================
 * Relationship to the `membership_type` vocabulary
 * =============================================================================
 * The G1 config's `membership_types` array (FROZEN by master prompt
 * §13) declares eight values:
 *
 *     elected, appointed, ex_officio, representative,
 *     observer, alternate, acting, temporary
 *
 * The appointment-mode vocabulary overlaps with four of those
 * values (`elected`, `appointed`, `ex_officio`) and adds a fourth
 * (`mixed`) that has no membership-type counterpart.
 *
 * The overlap is deliberate but the two vocabularies are NOT the
 * same:
 *
 *   · `membership_type` describes the RESULTING OCCUPANCY — how a
 *     person HOLDS the seat. Values like `observer`, `alternate`,
 *     and `temporary` classify the OCCUPANCY's character, not the
 *     acquisition process.
 *
 *   · `appointment_mode` describes the ACQUISITION PROCESS — how a
 *     person CAME TO the seat. Values classify the procedure, not
 *     the resulting role.
 *
 * A single governance appointment may create memberships with
 * different `membership_type` values — for example, an election
 * (mode `elected`) that fills both a regular seat (membership_type
 * `elected`) and an alternate seat (membership_type `alternate`).
 * The appointment's mode classifies the election; each membership's
 * type classifies its own occupancy.
 *
 * This distinction is why the domain has two vocabularies rather
 * than one. A future CR that collapses them — for example, by
 * removing `appointment_mode` and deriving it from the memberships —
 * would lose the ability to record a mixed-mode election whose
 * resulting memberships are all typed `elected`.
 *
 * =============================================================================
 * Usage examples
 * =============================================================================
 *   // Resolve a string from the database to an enum case:
 *   $mode = GovernanceAppointmentMode::tryFrom($appointment->appointment_mode);
 *
 *   // Branch on a specific mode:
 *   if ($mode === GovernanceAppointmentMode::ExOfficio) { ... }
 *
 *   // Use a predicate:
 *   if ($mode?->involvesElection()) { ... }
 *
 *   // Validate input:
 *   Rule::enum(GovernanceAppointmentMode::class);
 *
 *   // Obtain the closed vocabulary:
 *   GovernanceAppointmentMode::values();
 *   // → ['elected', 'appointed', 'ex_officio', 'mixed']
 *
 * The `GovernanceAppointment` model (G2c file 5 of 31) will declare
 * a `mode()` helper that returns `?GovernanceAppointmentMode`,
 * mirroring the `GovernanceBody::type()` helper's contract. A `null`
 * return means the row holds an unrecognized value — a defensive
 * posture that allows a corrupted row to be diagnosed rather than
 * crashing.
 *
 * =============================================================================
 * Testability
 * =============================================================================
 * The enum is a pure value type with no dependencies. Unit tests
 * exercise `tryFrom()` for every backing value, `values()` for its
 * exact content, each direct predicate for its truth table, and the
 * `involvesElection()` predicate for its two-case partition.
 *
 * No framework boot, no database, no container resolution.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Enums
 * @version 1.0.0
 */
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