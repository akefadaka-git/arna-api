<?php

declare(strict_types=1);



namespace MaaleArna\Arna\Core\Domains\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\Arna\Core\Domains\Governance\Http\Resources\Concerns\RendersLocalizedDates;

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
 * @package MaaleArna\Arna\Core\Domains\Governance\Http\Resources
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