<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 2 of 31
 * =============================================================================
 *
 * File:            Enums/GovernanceAppointmentStatus.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (enum)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `governance_appointments.status`
 *   column (created by G2c file 1 of 31) holds a `VARCHAR(32)`
 *   value with a default of `'draft'`, but had no application-layer
 *   vocabulary. Any string could be written to it — the schema does
 *   not enforce a closed set, by design, and no PHP enum yet existed
 *   to enforce one at the application boundary.
 *
 * After:
 *   One file that supplies the runtime vocabulary for the
 *   `governance_appointments.status` column. The enum mirrors
 *   `GovernanceBodyType` (G2a file 2 of 17) in structure: a
 *   string-backed enum with one case per declared value, direct
 *   `isXxx()` predicates, two derived predicates, and a `values()`
 *   static.
 *
 *   The four cases, in lifecycle order:
 *
 *     Draft       = 'draft'
 *     Active      = 'active'
 *     Revoked     = 'revoked'
 *     Superseded  = 'superseded'
 *
 *   The four direct predicates:
 *
 *     isDraft(), isActive(), isRevoked(), isSuperseded()
 *
 *   The two derived predicates:
 *
 *     isTerminal()   — revoked || superseded
 *                      (a state from which no transition is
 *                       expected)
 *     isOpen()       — draft || active
 *                      (a state in which the appointment is either
 *                       not yet or currently in force; the inverse
 *                       of isTerminal())
 *
 *   The static vocabulary method:
 *
 *     values()       — array of the four backing strings
 *
 *   What this enum deliberately does NOT do:
 *
 *     · It does NOT declare a `label()` method. Display strings
 *       belong to the resource layer, resolved through the
 *       translation catalogue. The Amharic labels for these four
 *       values are NOT yet declared in `Resources/lang/am/common.php`
 *       (G1 file 2 of 10) — that file declared vocabularies for
 *       meeting, minute, resolution, delegation, election, and
 *       declaration lifecycles, but did not include an
 *       `appointment_status` group, because the appointment entity
 *       had not yet been introduced at G1.
 *
 *       CONSEQUENCE FOR G2c: this file's delivery leaves the
 *       catalogue incomplete. A future file in this unit — or a
 *       follow-up CR — must add four new keys to both catalogues:
 *
 *           common.appointment_status.draft       →  'ረቂቅ'
 *           common.appointment_status.active      →  'ንቁ'
 *           common.appointment_status.revoked     →  'የተሰረዘ'
 *           common.appointment_status.superseded  →  'የተተካ'
 *
 *       The Amharic renderings above are proposed; a native-speaker
 *       reviewer is the correct final gate. Three of the four
 *       (`ረቂቅ`, `ንቁ`, `የተሰረዘ`) already appear in the catalogue
 *       under `minute_approval_status.draft`,
 *       `delegation_status.active`, and `delegation_status.revoked`
 *       respectively — the appointment vocabulary is deliberately
 *       consistent with those precedents. The fourth (`የተተካ`) appears
 *       under `resolution_status.superseded`. So the catalogue
 *       amendment is a matter of declaring a new group that reuses
 *       four existing Amharic strings, not of inventing new ones.
 *
 *       A future CR that touches this domain's catalogues will
 *       supply the group; the enum itself is complete without it.
 *
 *     · It does NOT declare a `transitions()` method or encode a
 *       state machine. The set of legal transitions — `draft` →
 *       `active`, `active` → `revoked`, `active` → `superseded`,
 *       and the edge cases involving `draft` → `revoked` — is a
 *       service-layer concern
 *       (`GovernanceAppointmentService`, G2c file 7 of 31). The
 *       service refuses any transition outside its declared matrix.
 *       Encoding the graph in the enum would scatter the rule
 *       across two files and would make a future CR that tightens
 *       a specific transition have to touch both.
 *
 *     · It does NOT declare a `isForced()` helper for the `revoked`
 *       and `superseded` states separately. Whether a revocation is
 *       "forced" (by authority) or "voluntary" (by the appointee)
 *       is a fact about the ACT of revocation, not about the
 *       resulting state. If a future CR needs to distinguish the
 *       two, it introduces a `revocation_kind` field on the
 *       appointment row — not a second status value.
 *
 *     · It does NOT enforce the vocabulary at the database level.
 *       The migration stores `status` as a `VARCHAR(32)` with a
 *       default of `'draft'`; a MySQL ENUM would couple the schema
 *       to a vocabulary that lives in application code. The enum
 *       is the application-layer enforcement point; the database
 *       is deliberately permissive. This is the platform's
 *       convention across every domain.
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * The four values were approved in the G2c manifest's ruling B6
 * (see the manifest section §"B6 — Enum names/values"). They are:
 *
 *     draft
 *     active
 *     revoked
 *     superseded
 *
 * The list is frozen by that ruling. Adding, removing, or renaming
 * a case is a Change Request, because it changes the meaning of
 * historical rows in the `governance_appointments` table.
 *
 * The list is also expected to appear — once the catalogue amendment
 * described above is delivered — under an `appointment_status`
 * group in `Resources/lang/{am,en}/common.php`. The two must stay in
 * lockstep; a CR that extends one extends the other in the same
 * delivery.
 *
 * =============================================================================
 * The four states, and the concepts they name
 * =============================================================================
 *   Draft
 *       The appointment has been recorded but is not yet in force.
 *       A draft appointment creates no memberships that the domain
 *       treats as currently occupying their positions; it is a
 *       preparatory record. A service method transitions it to
 *       `active` when the instrument's effective date arrives or
 *       when an authorized operator explicitly activates it.
 *
 *       The `draft` state exists so that an operator may capture the
 *       intent of an appointment — the target position, the term,
 *       the instrument reference — before the instrument itself is
 *       finalised. It also supports the common governance workflow
 *       in which the secretary drafts the appointments created by a
 *       resolution, and the chair confirms them once the minutes of
 *       the deciding meeting are approved.
 *
 *   Active
 *       The appointment is in force. Memberships created by this
 *       appointment are treated as currently occupying their
 *       positions, subject to the temporal bounds of the term (if
 *       any) and of the membership's own effective range.
 *
 *       The `active` state is the normal steady state. An
 *       appointment remains `active` until it is either revoked
 *       (withdrawn before the end of its natural period) or
 *       superseded (replaced by a later instrument covering the
 *       same scope).
 *
 *   Revoked
 *       The appointment was withdrawn before its natural end. A
 *       revocation is a deliberate act by an authority competent to
 *       make it. Its reason is organizational policy — a
 *       disciplinary action, a correction of an earlier mistake, a
 *       statutory disqualification discovered after the appointment
 *       took effect.
 *
 *       A revoked appointment's memberships end as of the
 *       revocation's effective moment. The historical record is
 *       preserved — the appointment row remains, the memberships
 *       remain, only the current-ness ends.
 *
 *   Superseded
 *       The appointment was replaced by a later instrument covering
 *       the same scope. A supersession is a NON-DISCRETIONARY
 *       transition — the later instrument does not "withdraw" the
 *       earlier one, it renders it obsolete by supplying a new
 *       answer to the same question. The distinction from `revoked`
 *       matters: a revocation requires authority to revoke; a
 *       supersession requires only the authority to make the later
 *       appointment.
 *
 *       A superseded appointment's memberships end as of the later
 *       instrument's effective date. Again, the historical record
 *       is preserved.
 *
 * =============================================================================
 * Why this is a backed string enum
 * =============================================================================
 * The enum is string-backed so that:
 *
 *   - It maps directly to the `governance_appointments.status` column
 *     without a casting layer.
 *   - It survives database dumps: a `SHOW CREATE TABLE` on
 *     `governance_appointments` shows the plain string values, which
 *     match the enum cases exactly.
 *   - It can be used in validation rules via
 *     `Rule::enum(GovernanceAppointmentStatus::class)`, which
 *     Laravel resolves against the enum's values.
 *
 * These three properties mirror the same properties on
 * `GovernanceBodyType` and every other string-backed enum in the
 * platform.
 *
 * =============================================================================
 * The isTerminal() / isOpen() split
 * =============================================================================
 * The two derived predicates partition the four cases cleanly:
 *
 *     isOpen()      →  Draft,    Active
 *     isTerminal()  →  Revoked,  Superseded
 *
 * The partition is NOT a temporal classification. A `draft`
 * appointment may be open-ended (it may never be activated), and an
 * `active` appointment with an open-ended term may remain active
 * indefinitely. The partition is a STATE-MACHINE classification: an
 * `isOpen()` state is one from which the service may transition to
 * another state; an `isTerminal()` state is one from which no
 * further transition is expected.
 *
 * The two predicates are the inverse of one another:
 *
 *     $status->isTerminal() === !$status->isOpen()
 *
 * Both are provided as named predicates so that call sites read
 * naturally — `if ($appointment->status_enum->isTerminal())` reads
 * more directly than `if (!$appointment->status_enum->isOpen())`,
 * and vice versa, depending on the caller's frame of reference.
 *
 * A future CR that adds a fifth case (for example, a `suspended`
 * state for a temporary pause) must decide which partition the case
 * belongs to. The decision is not inferred; the CR must amend
 * `isTerminal()` and `isOpen()` explicitly, and that amendment is
 * part of the review.
 *
 * =============================================================================
 * Usage examples
 * =============================================================================
 *   // Resolve a string from the database to an enum case:
 *   $status = GovernanceAppointmentStatus::tryFrom($appointment->status);
 *
 *   // Branch on a specific state:
 *   if ($status === GovernanceAppointmentStatus::Active) { ... }
 *
 *   // Use a predicate:
 *   if ($status?->isTerminal()) { ... }
 *
 *   // Validate input:
 *   Rule::enum(GovernanceAppointmentStatus::class);
 *
 *   // Obtain the closed vocabulary:
 *   GovernanceAppointmentStatus::values();
 *   // → ['draft', 'active', 'revoked', 'superseded']
 *
 * The `GovernanceAppointment` model (G2c file 5 of 31) will declare a
 * `statusEnum()` helper that returns `?GovernanceAppointmentStatus`,
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
 * two derived predicates for their partition (exactly one of
 * `isOpen()` / `isTerminal()` is true for every case).
 *
 * No framework boot, no database, no container resolution.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Enums
 * @version 1.0.0
 */
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