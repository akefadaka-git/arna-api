<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 9 of 31
 * =============================================================================
 *
 * File:            Http/Resources/GovernanceAppointmentResource.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (resource)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No resource serialised a
 *   `GovernanceAppointment` to JSON. A controller action that
 *   wanted to return an appointment would have had to inline a
 *   `toArray()` on the model — leaking the raw database column
 *   names, the raw enum backing values without labels, and the
 *   un-localised dates to the wire.
 *
 * After:
 *   One file that declares the canonical JSON shape of a
 *   `GovernanceAppointment`. The resource is a `JsonResource` with
 *   the same shape conventions the three sibling resources
 *   established at G2a and G2b:
 *
 *     · `public static $wrap = null` — the resource payload is
 *       emitted directly into the controller's response envelope,
 *       not wrapped in a `data` key by the resource itself. The
 *       controller wraps it; the resource does not.
 *
 *     · `final class` extending `JsonResource`.
 *
 *     · Consumes `RendersLocalizedDates` (G2a file 10 of 17) for
 *       its two date-display siblings.
 *
 *   What the resource emits:
 *
 *     Identity and ownership:
 *       id, tenant_id
 *
 *     FK references:
 *       governance_body_id, governance_position_id,
 *       governance_term_id
 *
 *     Instrument — vocabulary pair:
 *       instrument_type         (raw enum backing value)
 *       instrument_type_label   (Amharic-first, English-fallback)
 *
 *     Instrument — free-text and date pair:
 *       instrument_reference    (nullable string)
 *       instrument_date         (ISO Y-m-d, nullable)
 *       instrument_date_display (Ethiopian-calendar medium, nullable)
 *
 *     Effective date — date pair:
 *       effective_date          (ISO Y-m-d)
 *       effective_date_display  (Ethiopian-calendar medium)
 *
 *     Appointment mode — vocabulary pair:
 *       appointment_mode        (raw enum backing value)
 *       appointment_mode_label  (Amharic-first, English-fallback)
 *
 *     Lifecycle — vocabulary pair:
 *       status                  (raw enum backing value)
 *       status_label            (Amharic-first, English-fallback)
 *
 *     Titles and notes — bilingual fields:
 *       title_am, title_en, display_label
 *       notes_am, notes_en
 *
 *     Timestamps:
 *       created_at, updated_at  (ISO 8601)
 *
 *     Optional embedded relations — emitted via `whenLoaded()`:
 *       body       —  the owning GovernanceBody, via the sibling
 *                     GovernanceBodyResource
 *       position   —  the optional target GovernancePosition, via
 *                     the sibling GovernancePositionResource
 *       term       —  the optional target GovernanceTerm, via the
 *                     sibling GovernanceTermResource
 *
 *   What the resource deliberately does NOT emit:
 *
 *     · `deleted_at`. The resource is only ever constructed for
 *       rows the caller has already decided to expose; a
 *       soft-deleted appointment is not exposed by any
 *       controller action in G2c. If a future administrative
 *       endpoint needs to surface soft-deleted rows, that
 *       endpoint's resource adds the field.
 *
 *     · `memberships`. The forward relation exists on the model
 *       (G2c file 5 of 31) but its target resource
 *       (`GovernanceMembershipResource`) does not exist on disk
 *       until later in this unit. Emitting the relation now would
 *       require a `class_exists()` guard that adds no value while
 *       the relation itself is inert. A future CR — delivered
 *       alongside the membership resource — adds the
 *       `whenLoaded('memberships')` entry with no other change to
 *       this file's shape.
 *
 *     · `tenant`. The `tenant_id` scalar is emitted; the full
 *       tenant object is not. Traversing to the tenant record from
 *       an appointment resource is not a pattern any current
 *       consumer needs, and the tenant is already available on the
 *       request context via the platform's tenant resolver.
 *
 *     · Any derived boolean predicate (isDraft, isActive, …). The
 *       raw `status` value is the canonical wire representation;
 *       a client branches on it directly. Deriving booleans
 *       server-side would freeze the state machine's partition
 *       into the wire format and would require a coordinated
 *       client change if the partition ever changes.
 *
 * =============================================================================
 * The three vocabulary pairs — where the labels come from
 * =============================================================================
 * The three enum-typed columns (`instrument_type`,
 * `appointment_mode`, `status`) are each emitted as a pair:
 *
 *     <field>        —  the raw enum backing value
 *     <field>_label  —  the localized display string
 *
 * The raw value is what a client filters on, sorts by, or branches
 * on. The label is what a client renders to the user. Emitting both
 * keeps the wire format machine-readable and human-readable at
 * once — the same pattern the platform's resource layer uses for
 * every vocabulary field.
 *
 * The labels are resolved through the domain's translation catalogue
 * via the `__()` helper:
 *
 *     __('governance::common.appointment_instrument_type.<value>')
 *     __('governance::common.appointment_mode.<value>')
 *     __('governance::common.appointment_status.<value>')
 *
 * IMPORTANT — the three catalogue groups do NOT yet exist. They
 * were not declared by G1's `Resources/lang/{am,en}/common.php`
 * (the appointment entity did not exist at G1) and this unit does
 * not deliver a catalogue amendment. Until a follow-up CR adds the
 * three groups, `__()` returns the raw key string for every label
 * — e.g. `"governance::common.appointment_status.draft"`. The
 * response is not malformed; the labels are simply the fallback
 * key.
 *
 * The three groups the future CR must add are:
 *
 *   `appointment_status`
 *       draft       →  'ረቂቅ'
 *       active      →  'ንቁ'
 *       revoked     →  'የተሰረዘ'
 *       superseded  →  'የተተካ'
 *
 *   `appointment_mode`
 *       elected     →  'የተመረጠ'
 *       appointed   →  'የተሾመ'
 *       ex_officio  →  'በስልጣን መሠረት'
 *       mixed       →  'የተቀላቀለ'
 *
 *   `appointment_instrument_type`
 *       resolution        →  'ውሳኔ'
 *       board_decision    →  'የቦርድ ውሳኔ'
 *       general_assembly  →  'የጠቅላላ ጉባኤ ውሳኔ'
 *       external_document →  'የውጭ ሰነድ'
 *       by_law            →  'በሕግ ደንብ'
 *
 * The three enum files (G2c files 2, 3, 4 of 31) each record the
 * same pending amendment in their own change-verification blocks.
 * The amendment's file list is therefore: this resource's docblock
 * (informational), the three enum files' docblocks (informational),
 * and the two catalogue files (the actual change). A follow-up CR
 * touches only the two catalogue files.
 *
 * The resource does NOT special-case a missing key — it emits
 * whatever `__()` returns. This is deliberate: the correct
 * behaviour when a translation is missing is to surface the key,
 * not to hide it behind an English fallback that would mask the
 * gap. A developer or reviewer who sees
 * `"governance::common.appointment_status.draft"` in a response
 * immediately knows the catalogue amendment has not been
 * delivered.
 *
 * =============================================================================
 * The two date pairs — and why they use the trait
 * =============================================================================
 * The two date columns (`instrument_date`, `effective_date`) are
 * each emitted as a pair:
 *
 *     <field>          —  ISO 8601 date string (Y-m-d)
 *     <field>_display  —  Ethiopian-calendar medium form for the
 *                         current request locale
 *
 * The ISO form is the wire format; the display form is what a UI
 * renders. Both are emitted because the platform's Amharic-first
 * policy (design system §9.1) makes the Ethiopian-calendar display
 * a first-class field, not a client-side concern.
 *
 * The ISO form is produced by `?->toDateString()` — the same
 * convention the model's `display_label` accessor's fallback uses
 * for its own date string. The display form is produced by the
 * `localizedDate()` method from the `RendersLocalizedDates` trait:
 *
 *     $this->localizedDate($appointment->instrument_date)
 *
 * The trait returns `null` for `null` input, so the display
 * sibling of a nullable date is `null` in exactly the same rows
 * where the ISO sibling is `null`. A client that branches on one
 * can branch on the other with the same logic.
 *
 * =============================================================================
 * The optional embedded relations
 * =============================================================================
 * The resource emits `body`, `position`, and `term` as
 * `whenLoaded` entries. Each is wrapped in its own sibling resource
 * (`GovernanceBodyResource`, `GovernancePositionResource`,
 * `GovernanceTermResource`) — the same resources the domain's other
 * endpoints return.
 *
 * The `whenLoaded()` helper returns a `MissingValue` sentinel when
 * the relation is not eager-loaded. The sentinel is filtered out of
 * the final JSON payload by Laravel's resource framework, so an
 * appointment that was loaded without its relations simply does not
 * carry those keys. A controller that wants them eager-loads them
 * on the query; the resource does not force the load.
 *
 * The three sibling resources are referenced by class name only in
 * this file. They are on disk (G2a file 11 of 17 for the body,
 * G2b file 6 of 27 for the position, G2b file 18 of 27 for the
 * term), so no `class_exists()` guard is required.
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * The resource issues no queries. Every value it emits comes from
 * the already-loaded model or from the trait's call into the
 * `LocalizationService` (which the framework resolves from the
 * container — no query). The `whenLoaded()` entries are emitted
 * only when the caller has eager-loaded the corresponding relation;
 * the resource never triggers a lazy load.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Resources/GovernanceAppointmentResource.php
 *
 * The directory already exists.
 *
 * After uploading, run:
 *
 *     composer dump-autoload
 *
 * (Via Plesk Composer UI or the PHP Command runner.) The autoloader
 * must regenerate its class map to include the new class.
 *
 * The resource has no independent provider registration. It is
 * referenced by name from the controller (file 12 of 31), which is
 * delivered later in the unit.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Traits consumed:           1 (RendersLocalizedDates)
 * Sibling resources referenced: 3 (GovernanceBodyResource,
 *                               GovernancePositionResource,
 *                               GovernanceTermResource)
 * Translation groups pending:  3 (appointment_status,
 *                               appointment_mode,
 *                               appointment_instrument_type)
 * Direct database queries:   none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\Concerns\RendersLocalizedDates;

/**
 * GovernanceAppointmentResource
 *
 * JSON resource for a `GovernanceAppointment`.
 *
 * See the file-level change-verification block for the resource's
 * field shape, its three vocabulary pairs (and the pending
 * catalogue amendment that will supply their labels), its two date
 * pairs (and the trait that produces their display siblings), and
 * its three optional embedded relations.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Resources
 * @version 1.0.0
 */
final class GovernanceAppointmentResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the `data` wrapper.
     *
     * The resource's payload is emitted directly into the
     * controller's response envelope. The controller wraps it; the
     * resource does not wrap itself.
     *
     * Matches the convention every Governance resource uses.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @param  Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $appointment = $this->resource;

        return [
            // -----------------------------------------------------------------
            // Identity and ownership.
            // -----------------------------------------------------------------
            'id'        => $appointment->id,
            'tenant_id' => $appointment->tenant_id,

            // -----------------------------------------------------------------
            // FK references.
            // -----------------------------------------------------------------
            'governance_body_id'     => $appointment->governance_body_id,
            'governance_position_id' => $appointment->governance_position_id,
            'governance_term_id'     => $appointment->governance_term_id,

            // -----------------------------------------------------------------
            // Instrument — vocabulary pair.
            // -----------------------------------------------------------------
            'instrument_type'       => $appointment->instrument_type,
            'instrument_type_label' => __(
                'governance::common.appointment_instrument_type.'
                . $appointment->instrument_type
            ),

            // -----------------------------------------------------------------
            // Instrument — free-text reference and date pair.
            // -----------------------------------------------------------------
            'instrument_reference'    => $appointment->instrument_reference,
            'instrument_date'         => $appointment->instrument_date?->toDateString(),
            'instrument_date_display' => $this->localizedDate($appointment->instrument_date),

            // -----------------------------------------------------------------
            // Effective date — date pair.
            // -----------------------------------------------------------------
            'effective_date'         => $appointment->effective_date?->toDateString(),
            'effective_date_display' => $this->localizedDate($appointment->effective_date),
            'effective_until'        => $appointment->effective_until?->toDateString(),
            'appointment_role'       => $appointment->appointment_role ?? 'primary',
            'acting_for_appointment_id' => $appointment->acting_for_appointment_id,

            // -----------------------------------------------------------------
            // Appointment mode — vocabulary pair.
            // -----------------------------------------------------------------
            'appointment_mode'       => $appointment->appointment_mode,
            'appointment_mode_label' => __(
                'governance::common.appointment_mode.'
                . $appointment->appointment_mode
            ),

            // -----------------------------------------------------------------
            // Lifecycle — vocabulary pair.
            // -----------------------------------------------------------------
            'status'       => $appointment->status,
            'status_label' => __(
                'governance::common.appointment_status.'
                . $appointment->status
            ),

            // -----------------------------------------------------------------
            // Titles and notes — bilingual fields.
            //
            // `display_label` is the model accessor's locale-aware
            // resolution with its fallback chain (title → reference →
            // synthesised). It is emitted as a sibling of the two raw
            // titles so a client that wants the resolved form does not
            // have to re-implement the fallback.
            // -----------------------------------------------------------------
            'title_am'      => $appointment->title_am,
            'title_en'      => $appointment->title_en,
            'display_label' => $appointment->display_label,

            'notes_am' => $appointment->notes_am,
            'notes_en' => $appointment->notes_en,

            // -----------------------------------------------------------------
            // Timestamps — ISO 8601.
            //
            // No display siblings. The platform's date-display
            // convention is for BUSINESS dates (effective dates,
            // instrument dates, due dates), not for audit timestamps.
            // A UI that wants to render `created_at` in the
            // Ethiopian calendar uses the ISO value and the platform's
            // client-side date library.
            // -----------------------------------------------------------------
            'created_at' => $appointment->created_at?->toIso8601String(),
            'updated_at' => $appointment->updated_at?->toIso8601String(),

            // -----------------------------------------------------------------
            // Optional embedded relations.
            //
            // Each is emitted only when the caller eager-loaded the
            // relation. The `whenLoaded()` sentinel is stripped by
            // the framework from the final payload.
            //
            // The three sibling resources are referenced by class
            // name; they are on disk. See the file-level
            // change-verification block §"The optional embedded
            // relations".
            // -----------------------------------------------------------------
            'body'     => GovernanceBodyResource::make($this->whenLoaded('body')),
            'position' => GovernancePositionResource::make($this->whenLoaded('position')),
            'term'     => GovernanceTermResource::make($this->whenLoaded('term')),
        ];
    }
}