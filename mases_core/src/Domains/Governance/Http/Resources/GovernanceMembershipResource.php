<?php

declare(strict_types=1);



namespace MaaleArna\MASES\Core\Domains\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\Concerns\RendersLocalizedDates;

/**
 * GovernanceMembershipResource
 *
 * JSON resource for a `GovernanceMembership`.
 *
 * See the file-level change-verification block for the resource's
 * field shape, its status vocabulary pair (and the pending
 * catalogue amendment that will supply its label), its two date
 * pairs (and the trait that produces their display siblings), its
 * `is_current` field, and its optional embedded relations.
 *
 * The `user` entry is emitted as an inline array rather than a
 * nested resource — see the change-verification block
 * §"The optional embedded relations" for the reasoning.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Resources
 * @version 1.0.1
 */
final class GovernanceMembershipResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the `data` wrapper.
     *
     * The resource's payload is emitted directly into the
     * controller's response envelope. The controller wraps it; the
     * resource does not wrap itself.
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
        $membership = $this->resource;

        return [
            // -----------------------------------------------------------------
            // Identity and ownership.
            // -----------------------------------------------------------------
            'id'        => $membership->id,
            'tenant_id' => $membership->tenant_id,

            // -----------------------------------------------------------------
            // FK references.
            // -----------------------------------------------------------------
            'governance_body_id'        => $membership->governance_body_id,
            'governance_position_id'    => $membership->governance_position_id,
            'governance_term_id'        => $membership->governance_term_id,
            'governance_appointment_id' => $membership->governance_appointment_id,
            'user_id'                   => $membership->user_id,

            // -----------------------------------------------------------------
            // Member identity — the four raw columns plus the resolved
            // display name from the model's accessor.
            // -----------------------------------------------------------------
            'member_name_am'     => $membership->member_name_am,
            'member_name_en'     => $membership->member_name_en,
            'external_reference' => $membership->external_reference,
            'display_name'       => $membership->display_name,

            // -----------------------------------------------------------------
            // Temporal bounds — two date pairs.
            // -----------------------------------------------------------------
            'start_date'         => $membership->start_date?->toDateString(),
            'start_date_display' => $this->localizedDate($membership->start_date),

            'end_date'         => $membership->end_date?->toDateString(),
            'end_date_display' => $this->localizedDate($membership->end_date),

            // -----------------------------------------------------------------
            // Lifecycle — vocabulary pair.
            //
            // See the file-level change-verification block §"The status
            // vocabulary pair — where the label comes from" for the
            // pending catalogue amendment.
            // -----------------------------------------------------------------
            'status'       => $membership->status,
            'status_label' => __(
                'governance::common.membership_status.'
                . $membership->status
            ),

            // -----------------------------------------------------------------
            // Structural flag.
            // -----------------------------------------------------------------
            'is_ex_officio' => $membership->is_ex_officio,

            // -----------------------------------------------------------------
            // Computed temporal predicate.
            //
            // See the file-level change-verification block §"The
            // is_current field" for why this derived boolean is emitted
            // despite the general policy of not emitting derived
            // predicates.
            // -----------------------------------------------------------------
            'is_current' => $membership->isCurrent(),

            // -----------------------------------------------------------------
            // Terminal context.
            // -----------------------------------------------------------------
            'ended_reason' => $membership->ended_reason,

            // -----------------------------------------------------------------
            // Notes — bilingual fields.
            // -----------------------------------------------------------------
            'notes_am' => $membership->notes_am,
            'notes_en' => $membership->notes_en,

            // -----------------------------------------------------------------
            // Timestamps — ISO 8601.
            //
            // No display siblings. The platform's date-display
            // convention is for BUSINESS dates, not for audit
            // timestamps.
            // -----------------------------------------------------------------
            'created_at' => $membership->created_at?->toIso8601String(),
            'updated_at' => $membership->updated_at?->toIso8601String(),

            // -----------------------------------------------------------------
            // Optional embedded relations.
            //
            // Each is emitted only when the caller eager-loaded the
            // relation. The `whenLoaded()` sentinel is stripped by the
            // framework from the final payload.
            //
            // `user` is emitted as an inline array rather than a nested
            // resource. See the file-level change-verification block
            // §"The optional embedded relations" for the reasoning.
            // -----------------------------------------------------------------
            'body'        => GovernanceBodyResource::make($this->whenLoaded('body')),
            'position'    => GovernancePositionResource::make($this->whenLoaded('position')),
            'term'        => GovernanceTermResource::make($this->whenLoaded('term')),
            'appointment' => GovernanceAppointmentResource::make($this->whenLoaded('appointment')),

            'user' => $this->whenLoaded('user', function () use ($membership): ?array {
                $user = $membership->user;

                if ($user === null) {
                    return null;
                }

                return [
                    'id'           => $user->id,
                    'display_name' => $user->display_name,
                    'email'        => $user->email,
                ];
            }),
        ];
    }
}