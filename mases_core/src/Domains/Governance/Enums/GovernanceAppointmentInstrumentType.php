<?php

declare(strict_types=1);


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