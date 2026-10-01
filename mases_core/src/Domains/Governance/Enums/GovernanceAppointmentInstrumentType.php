<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 4 of 31
 * =============================================================================
 *
 * File:            Enums/GovernanceAppointmentInstrumentType.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (enum)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `governance_appointments.instrument_type`
 *   column (created by G2c file 1 of 31) holds a `VARCHAR(32)` value,
 *   but had no application-layer vocabulary. Any string could be
 *   written to it — the schema does not enforce a closed set, by
 *   design, and no PHP enum yet existed to enforce one at the
 *   application boundary.
 *
 *   As a consequence, the manifest's ruling B3 — "instrument source:
 *   store a type enum plus a free-text reference plus a date; do not
 *   declare a forward FK to a resolutions table that does not yet
 *   exist" — had no runtime representation. The DECISION to record
 *   the instrument's type was approved, but the vocabulary through
 *   which the type is expressed was not yet declared.
 *
 * After:
 *   One file that supplies the runtime vocabulary for the
 *   `governance_appointments.instrument_type` column. The enum
 *   mirrors `GovernanceAppointmentStatus` (G2c file 2 of 31),
 *   `GovernanceAppointmentMode` (G2c file 3 of 31), and
 *   `GovernanceBodyType` (G2a file 2 of 17) in structure: a
 *   string-backed enum with one case per declared value, direct
 *   `isXxx()` predicates, two derived predicates, and a `values()`
 *   static.
 *
 *   The five cases:
 *
 *     Resolution        = 'resolution'
 *     BoardDecision     = 'board_decision'
 *     GeneralAssembly   = 'general_assembly'
 *     ExternalDocument  = 'external_document'
 *     ByLaw             = 'by_law'
 *
 *   The five direct predicates:
 *
 *     isResolution(), isBoardDecision(), isGeneralAssembly(),
 *     isExternalDocument(), isByLaw()
 *
 *   The two derived predicates:
 *
 *     isInternal()  — resolution || board_decision || general_assembly
 *                     (the instrument originates from an internal
 *                      governance act recorded in the platform)
 *     isExternal()  — external_document || by_law
 *                     (the instrument originates from outside the
 *                      platform's act-recording surface)
 *
 *   The static vocabulary method:
 *
 *     values()  — array of the five backing strings
 *
 *   What this enum deliberately does NOT do:
 *
 *     · It does NOT declare a `label()` method. Display strings
 *       belong to the resource layer, resolved through the
 *       translation catalogue. The Amharic labels for these five
 *       values are NOT yet declared in
 *       `Resources/lang/am/common.php` (G1 file 2 of 10) — that
 *       file declared a `document_category` group with ten values,
 *       but did not include an `appointment_instrument_type` group,
 *       because the appointment entity had not yet been introduced
 *       at G1.
 *
 *       CONSEQUENCE FOR G2c: this file's delivery leaves the
 *       catalogue incomplete. A future file in this unit — or a
 *       follow-up CR — must add five new keys to both catalogues:
 *
 *           common.appointment_instrument_type.resolution
 *               →  'ውሳኔ'
 *           common.appointment_instrument_type.board_decision
 *               →  'የቦርድ ውሳኔ'
 *           common.appointment_instrument_type.general_assembly
 *               →  'የጠቅላላ ጉባኤ ውሳኔ'
 *           common.appointment_instrument_type.external_document
 *               →  'የውጭ ሰነድ'
 *           common.appointment_instrument_type.by_law
 *               →  'በሕግ ደንብ'
 *
 *       Two of the five (`ውሳኔ` for `resolution`) already appear in
 *       the catalogue under `resolution_status` labels and under
 *       `document_category.resolution`. The general-assembly label
 *       is a compound of `ጠቅላላ ጉባኤ` (`body_type.assembly`) and
 *       `ውሳኔ` — both already present. The board-decision and by-law
 *       labels are new compounds; a native-speaker reviewer is the
 *       correct final gate. The external-document label is a
 *       straight compound of `የውጭ` ("external") and `ሰነድ`
 *       ("document", already present under `document_category`
 *       labels).
 *
 *       A future CR that touches this domain's catalogues will
 *       supply the group; the enum itself is complete without it.
 *
 *     · It does NOT declare a `requiresResolutionId()` method. The
 *       manifest's ruling B3 explicitly deferred the forward FK to
 *       `governance_resolutions`. Whether a `resolution`-typed
 *       appointment will be linked to a resolution row when G8
 *       delivers is a decision for THAT future CR. The current
 *       design uses `instrument_reference` (VARCHAR) as the sole
 *       identity of the instrument; a future CR adds an optional
 *       `resolution_id` column and, with it, any cross-field rule.
 *       Encoding a "requires resolution id" rule in the enum now
 *       would presume the CR's shape.
 *
 *     · It does NOT declare a `hasDocument()` method. Whether an
 *       `external_document`-typed instrument has a stored file in
 *       the Core `files` table is a fact about the specific
 *       appointment, not about the type. A resolution-typed
 *       appointment may also have a signed-copy file attached; an
 *       external-document-typed appointment may be recorded without
 *       any file at all if the reference number is sufficient for
 *       the organization's purposes. The link between an
 *       appointment and its document lives in G11's
 *       `governance_document_links` table when that phase ships.
 *
 *     · It does NOT declare a `isPreferred()` or `isDefault()`
 *       helper. There is no default instrument type — the schema
 *       declares `instrument_type` as NOT NULL with no default,
 *       requiring the caller to choose explicitly. A silently-
 *       defaulted instrument type would be worse than a required
 *       choice, because a "resolution" that was recorded as a
 *       "board_decision" by an inattentive caller is a data-integrity
 *       defect the schema cannot detect after the fact.
 *
 *     · It does NOT enforce the vocabulary at the database level.
 *       The migration stores `instrument_type` as a `VARCHAR(32)`;
 *       a MySQL ENUM would couple the schema to a vocabulary that
 *       lives in application code. The enum is the application-
 *       layer enforcement point; the database is deliberately
 *       permissive.
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * The five values were approved in the G2c manifest's ruling B6
 * (see the manifest section §"B6 — Enum names/values"). They are:
 *
 *     resolution
 *     board_decision
 *     general_assembly
 *     external_document
 *     by_law
 *
 * The list is frozen by that ruling. Adding, removing, or renaming
 * a case is a Change Request, because it changes the meaning of
 * historical rows in the `governance_appointments` table.
 *
 * The list is also expected to appear — once the catalogue
 * amendment described above is delivered — under an
 * `appointment_instrument_type` group in
 * `Resources/lang/{am,en}/common.php`. The two must stay in
 * lockstep; a CR that extends one extends the other in the same
 * delivery.
 *
 * =============================================================================
 * The five instrument types, and the concepts they name
 * =============================================================================
 *   Resolution
 *       A governance resolution — an act of a body (typically the
 *       Board or the General Assembly) that the body adopted by
 *       vote and recorded as a resolution.
 *
 *       When G8 delivers `governance_resolutions`, a future CR may
 *       add an optional `resolution_id` FK to the appointments
 *       table, allowing a `resolution`-typed appointment to be
 *       linked to the specific resolution row it was created by.
 *       Until then, the instrument is identified by
 *       `instrument_reference` (e.g., the resolution's number).
 *
 *       Business decision #14 ("Is the Board Secretary elected or
 *       appointed?") is likely to be answered with a resolution in
 *       many organizations; the resolution that appoints the
 *       secretary would be recorded with this type.
 *
 *   BoardDecision
 *       A decision recorded by the Board that is NOT a formal
 *       resolution. Some organizations draw a distinction between
 *       formal resolutions (numbered, minuted, formally adopted)
 *       and informal board decisions (recorded in the minutes as
 *       "the Board decided to…"). The `board_decision` type
 *       accommodates the latter without forcing the organization
 *       to inflate an informal decision into a formal resolution.
 *
 *       The distinction between a board resolution and a board
 *       decision is organizational. The domain honours the
 *       organization's own classification rather than imposing
 *       one — this is the same ADR-GOV-009 "no universal workflow"
 *       posture that governs the domain's other vocabularies.
 *
 *   GeneralAssembly
 *       An act of the General Assembly — the shareholders' meeting
 *       — that is NOT classified as a formal resolution. The
 *       canonical case: the Assembly's election of directors.
 *       Some organizations record this as a resolution; others
 *       record it as the Assembly's direct act. The
 *       `general_assembly` type covers the latter.
 *
 *       As with `board_decision`, the distinction between a
 *       General Assembly resolution and a General Assembly act is
 *       organizational; the domain honours the organization's
 *       classification.
 *
 *   ExternalDocument
 *       A document that originates outside the platform — a
 *       ministerial directive, a regulator's appointment letter, a
 *       court order, a shareholder's written nomination.
 *
 *       The instrument's text lives wherever the external document
 *       lives; the appointment records only its reference and date.
 *       When G11 ships, a `governance_document_link` row may
 *       attach the scanned copy to the appointment.
 *
 *   ByLaw
 *       A provision of the organization's by-law, charter, or
 *       articles of association that directly establishes the
 *       appointment.
 *
 *       The canonical case: business decision #15 ("Does GM/CEO
 *       hold a Board seat ex-officio?"). If the by-law grants the
 *       CEO a Board seat ex-officio, the CEO's Board appointment
 *       is `by_law`-typed, with `instrument_reference` naming the
 *       specific article.
 *
 *       A `by_law`-typed appointment typically has NO recorded
 *       `instrument_date` (the by-law's own adoption date may
 *       differ from the appointment's effective date, or may be
 *       unknown). The schema declares `instrument_date` as
 *       nullable for exactly this reason.
 *
 * =============================================================================
 * Why this is a backed string enum
 * =============================================================================
 * The enum is string-backed so that:
 *
 *   - It maps directly to the `governance_appointments.instrument_type`
 *     column without a casting layer.
 *   - It survives database dumps: a `SHOW CREATE TABLE` on
 *     `governance_appointments` shows the plain string values, which
 *     match the enum cases exactly.
 *   - It can be used in validation rules via
 *     `Rule::enum(GovernanceAppointmentInstrumentType::class)`,
 *     which Laravel resolves against the enum's values.
 *
 * These three properties mirror the same properties on every other
 * string-backed enum in the platform.
 *
 * =============================================================================
 * The isInternal() / isExternal() split
 * =============================================================================
 * The two derived predicates classify the five cases into two
 * groups:
 *
 *     isInternal()  →  Resolution, BoardDecision, GeneralAssembly
 *     isExternal()  →  ExternalDocument, ByLaw
 *
 * The classification is about WHERE the instrument originates, not
 * about its legal weight or its authoritativeness:
 *
 *   · An INTERNAL instrument originates from an act that the
 *     platform is designed to record — a resolution of a body, a
 *     decision recorded in the body's minutes, an act of the
 *     General Assembly. When G8 ships, a `resolution`-typed
 *     appointment may gain a direct FK to the resolution row that
 *     created it. A `board_decision`- or `general_assembly`-typed
 *     appointment may gain a link to the minute that recorded the
 *     decision.
 *
 *   · An EXTERNAL instrument originates from outside the platform's
 *     act-recording surface — an external document or a by-law.
 *     The platform does not record the instrument's own adoption
 *     (it was adopted elsewhere, in a forum the platform does not
 *     represent); it records only the fact that the instrument
 *     exists and the appointment rests on it.
 *
 * The split is used by the domain to decide WHICH downstream link
 * is meaningful:
 *
 *   · An `isInternal()` appointment's provenance may eventually
 *     be a FK to a resolution or minute record.
 *   · An `isExternal()` appointment's provenance may eventually be
 *     a document link to a file in the Core `files` table.
 *
 * The two derived predicates are the inverse of one another:
 *
 *     $type->isInternal() === !$type->isExternal()
 *
 * Both are provided as named predicates so that call sites read
 * naturally — `if ($appointment->instrument_type_enum->isExternal())`
 * reads more directly than `if (!$appointment->instrument_type_enum->isInternal())`,
 * depending on the caller's frame of reference.
 *
 * A future CR that adds a sixth case must decide which partition
 * the case belongs to. The decision is not inferred; the CR must
 * amend `isInternal()` and `isExternal()` explicitly, and that
 * amendment is part of the review.
 *
 * =============================================================================
 * Relationship to the deferred `resolution_id` FK (ruling B3)
 * =============================================================================
 * The manifest's ruling B3 stated: "Store `instrument_type` (enum:
 * resolution, board_decision, general_assembly, external_document,
 * by_law) + `instrument_reference` (string — resolution number,
 * document ref) + `instrument_date` (date). When G8 ships,
 * `instrument_type='resolution'` rows gain an optional
 * `resolution_id` FK via a future CR. No forward FK to a
 * non-existent resolutions table now."
 *
 * This enum is the first half of that design. The second half — the
 * `resolution_id` FK — is deferred to a future CR. The enum does
 * NOT include a helper that anticipates the FK (e.g.,
 * `hasResolutionRecord()`); the future CR that adds the FK will
 * add such a helper alongside it, when the resolution entity exists
 * to link to.
 *
 * The `instrument_reference` column is the interim identity of the
 * instrument. When the FK lands, `instrument_reference` remains
 * useful as a human-readable label ("Resolution 2026-042") that
 * survives even if the linked resolution row is later renumbered or
 * corrected.
 *
 * =============================================================================
 * Usage examples
 * =============================================================================
 *   // Resolve a string from the database to an enum case:
 *   $type = GovernanceAppointmentInstrumentType::tryFrom($appointment->instrument_type);
 *
 *   // Branch on a specific type:
 *   if ($type === GovernanceAppointmentInstrumentType::ByLaw) { ... }
 *
 *   // Use a predicate:
 *   if ($type?->isInternal()) { ... }
 *   if ($type?->isExternal()) { ... }
 *
 *   // Validate input:
 *   Rule::enum(GovernanceAppointmentInstrumentType::class);
 *
 *   // Obtain the closed vocabulary:
 *   GovernanceAppointmentInstrumentType::values();
 *   // → ['resolution', 'board_decision', 'general_assembly',
 *   //    'external_document', 'by_law']
 *
 * The `GovernanceAppointment` model (G2c file 5 of 31) will declare
 * an `instrumentType()` helper that returns
 * `?GovernanceAppointmentInstrumentType`, mirroring the
 * `GovernanceBody::type()` helper's contract. A `null` return means
 * the row holds an unrecognized value — a defensive posture that
 * allows a corrupted row to be diagnosed rather than crashing.
 *
 * =============================================================================
 * Testability
 * =============================================================================
 * The enum is a pure value type with no dependencies. Unit tests
 * exercise `tryFrom()` for every backing value, `values()` for its
 * exact content, each direct predicate for its truth table, and the
 * two derived predicates for their partition (exactly one of
 * `isInternal()` / `isExternal()` is true for every case).
 *
 * No framework boot, no database, no container resolution.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Enums
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Enums;

enum GovernanceAppointmentInstrumentType: string
{
    /**
     * A governance resolution — an act of a body that the body
     * adopted by vote and recorded as a resolution.
     *
     * When G8 delivers `governance_resolutions`, a future CR may
     * add an optional `resolution_id` FK to the appointments table.
     *
     * B6 case 1 of 5.
     */
    case Resolution = 'resolution';

    /**
     * A decision recorded by the Board that is NOT a formal
     * resolution.
     *
     * The distinction between a board resolution and a board
     * decision is organizational; the domain honours the
     * organization's own classification.
     *
     * B6 case 2 of 5.
     */
    case BoardDecision = 'board_decision';

    /**
     * An act of the General Assembly — the shareholders' meeting —
     * that is NOT classified as a formal resolution.
     *
     * The canonical case: the Assembly's election of directors.
     *
     * B6 case 3 of 5.
     */
    case GeneralAssembly = 'general_assembly';

    /**
     * A document that originates outside the platform — a
     * ministerial directive, a regulator's appointment letter, a
     * court order, a shareholder's written nomination.
     *
     * When G11 ships, a `governance_document_link` row may attach
     * the scanned copy to the appointment.
     *
     * B6 case 4 of 5.
     */
    case ExternalDocument = 'external_document';

    /**
     * A provision of the organization's by-law, charter, or
     * articles of association that directly establishes the
     * appointment.
     *
     * The canonical case: business decision #15 — a by-law grant
     * of an ex-officio Board seat to the GM/CEO.
     *
     * B6 case 5 of 5.
     */
    case ByLaw = 'by_law';

    // =========================================================================
    // Direct predicates — one per case
    // =========================================================================

    /**
     * Whether this case is the Resolution instrument type.
     *
     * @return bool
     */
    public function isResolution(): bool
    {
        return $this === self::Resolution;
    }

    /**
     * Whether this case is the BoardDecision instrument type.
     *
     * @return bool
     */
    public function isBoardDecision(): bool
    {
        return $this === self::BoardDecision;
    }

    /**
     * Whether this case is the GeneralAssembly instrument type.
     *
     * @return bool
     */
    public function isGeneralAssembly(): bool
    {
        return $this === self::GeneralAssembly;
    }

    /**
     * Whether this case is the ExternalDocument instrument type.
     *
     * @return bool
     */
    public function isExternalDocument(): bool
    {
        return $this === self::ExternalDocument;
    }

    /**
     * Whether this case is the ByLaw instrument type.
     *
     * @return bool
     */
    public function isByLaw(): bool
    {
        return $this === self::ByLaw;
    }

    // =========================================================================
    // Derived predicates
    // =========================================================================

    /**
     * Whether this instrument type originates from an internal
     * governance act recorded in the platform.
     *
     * Returns true for Resolution, BoardDecision, and
     * GeneralAssembly; false for ExternalDocument and ByLaw.
     *
     * See the file-level change-verification block
     * §"The isInternal() / isExternal() split" for the reasoning.
     *
     * @return bool
     */
    public function isInternal(): bool
    {
        return $this === self::Resolution
            || $this === self::BoardDecision
            || $this === self::GeneralAssembly;
    }

    /**
     * Whether this instrument type originates from outside the
     * platform's act-recording surface.
     *
     * Returns true for ExternalDocument and ByLaw; false for
     * Resolution, BoardDecision, and GeneralAssembly.
     *
     * The exact inverse of `isInternal()`:
     *
     *     $type->isExternal() === !$type->isInternal()
     *
     * @return bool
     */
    public function isExternal(): bool
    {
        return $this === self::ExternalDocument
            || $this === self::ByLaw;
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
     *     ['resolution', 'board_decision', 'general_assembly',
     *      'external_document', 'by_law']
     *
     * This order groups the internal types first (resolution,
     * board_decision, general_assembly) and the external types
     * second (external_document, by_law), mirroring the
     * `isInternal()` / `isExternal()` partition. This is the order
     * the five values will appear in the translation catalogue (once
     * the catalogue amendment described in the file-level
     * change-verification block is delivered). The two must stay in
     * lockstep; a future CR that reorders one reorders the other.
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