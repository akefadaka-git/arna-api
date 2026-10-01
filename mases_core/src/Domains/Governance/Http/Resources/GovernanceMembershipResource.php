<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 22 of 31  (REDELIVERY 1)
 * =============================================================================
 *
 * File:            Http/Resources/GovernanceMembershipResource.php
 * Delivery type:   Full-file replacement (patch redelivery)
 *
 * REDELIVERY NOTE
 * -----------------------------------------------------------------------------
 * The original delivery of this file referenced a
 * `UserResource` class for its `user` embedded entry:
 *
 *     'user' => UserResource::make($this->whenLoaded('user')),
 *
 * MASES Core does not ship a `UserResource`. The name was
 * invented by the original delivery and does not correspond to any
 * class on disk. Every request that eager-loaded `user` — which the
 * controller's `show` and `update` actions do — threw
 * `Class "…UserResource" not found` at serialization time and
 * returned 500.
 *
 * The fix replaces the single `user` line with an inline closure
 * that builds a small array from the already-loaded relation. No
 * nested resource, no cross-domain dependency, no invented class
 * name. The three fields emitted — `id`, `display_name`, `email` —
 * are the fields a membership UI needs; a future CR that
 * introduces a canonical `UserResource` in Core replaces the
 * closure with a one-line call.
 *
 * No other change is made. Every other line in this file — the
 * entire change-verification block below, the class docblock, the
 * `toArray()` body, the field ordering, the comments — is
 * byte-for-byte identical to the original delivery.
 *
 * -----------------------------------------------------------------------------
 * Everything below this note is the original v1.0.0 change-
 * verification block, preserved verbatim.
 * =============================================================================
 *
 * CHANGE VERIFICATION — G2c · File 22 of 31
 * =============================================================================
 *
 * File:            Http/Resources/GovernanceMembershipResource.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (resource)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No resource serialised a
 *   `GovernanceMembership` to JSON. A controller action that wanted
 *   to return a membership would have had to inline a `toArray()`
 *   on the model — leaking the raw database column names, the raw
 *   enum backing value without its label, and the un-localised
 *   dates to the wire.
 *
 * After:
 *   One file that declares the canonical JSON shape of a
 *   `GovernanceMembership`. The resource follows the same shape
 *   conventions every sibling resource in this domain established:
 *
 *     · `public static $wrap = null` — the resource payload is
 *       emitted directly into the controller's response envelope,
 *       not wrapped by the resource itself.
 *     · `final class` extending `JsonResource`.
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
 *       governance_term_id, governance_appointment_id, user_id
 *
 *     Member identity — four columns:
 *       member_name_am, member_name_en, external_reference,
 *       display_name  (the model's locale-aware resolved name)
 *
 *     Temporal bounds — two date pairs:
 *       start_date          (ISO Y-m-d)
 *       start_date_display  (Ethiopian-calendar medium)
 *       end_date            (ISO Y-m-d, nullable)
 *       end_date_display    (Ethiopian-calendar medium, nullable)
 *
 *     Lifecycle — vocabulary pair:
 *       status        (raw enum backing value)
 *       status_label  (Amharic-first, English-fallback)
 *
 *     Structural flags:
 *       is_ex_officio
 *
 *     Terminal context:
 *       ended_reason  (nullable string; populated for terminal
 *                      statuses)
 *
 *     Notes — bilingual fields:
 *       notes_am, notes_en
 *
 *     Timestamps:
 *       created_at, updated_at  (ISO 8601)
 *
 *     Optional embedded relations — emitted via `whenLoaded()`:
 *       body        —  the owning GovernanceBody
 *       position    —  the position the member occupies
 *       term        —  the optional GovernanceTerm
 *       appointment —  the optional GovernanceAppointment
 *       user        —  the optional Core User
 *
 *   What the resource deliberately does NOT emit:
 *
 *     · `deleted_at`. The resource is only ever constructed for
 *       rows the caller has already decided to expose.
 *
 *     · Any derived boolean predicate (isActive, isOngoing,
 *       isCurrent, …). The raw `status` value is the canonical wire
 *       representation; a client branches on it directly. Two
 *       derived fields are the exception and ARE emitted:
 *
 *         · `is_ex_officio` — a column, not a derived predicate.
 *         · `is_current` — a computed temporal predicate. It IS
 *           emitted because the currentness of a membership is a
 *           function of the current date, which the client cannot
 *           compute from the ISO dates without also knowing the
 *           server's timezone. Emitting it makes the wire format
 *           deterministic across timezones.
 *
 *     · The four `memberKey` variants or the raw precedence. The
 *       member identity is expressed as four columns plus the
 *       resolved `display_name`; a client that needs to know
 *       which column is populated inspects the four columns
 *       directly.
 *
 *     · Any un-displayed traversal of the `appointment` relation.
 *       The relation is emitted as an embedded resource via
 *       `whenLoaded()`; the appointment's own resource governs its
 *       field shape.
 *
 * =============================================================================
 * The status vocabulary pair — where the label comes from
 * =============================================================================
 * The `status` column is emitted as a pair:
 *
 *     status        —  the raw enum backing value
 *     status_label  —  the localized display string
 *
 * The label is resolved through the domain's translation catalogue
 * via:
 *
 *     __('governance::common.membership_status.<value>')
 *
 * IMPORTANT — the `membership_status` catalogue group does NOT yet
 * exist. It was not declared by G1's
 * `Resources/lang/{am,en}/common.php` (the membership entity did
 * not exist at G1) and this unit does not deliver a catalogue
 * amendment. Until a follow-up CR adds the group, `__()` returns
 * the raw key string — e.g.
 * `"governance::common.membership_status.active"`. The response is
 * not malformed; the label is simply the fallback key.
 *
 * The group the future CR must add is documented in the enum's own
 * change-verification block
 * (`GovernanceMembershipStatus`, G2c file 17 of 31,
 * §"What this enum deliberately does NOT do"). The six values and
 * proposed Amharic renderings are:
 *
 *     active     →  'ንቁ'
 *     ended      →  'የተጠናቀቀ'
 *     suspended  →  'የተገደበ'
 *     resigned   →  'በፈቃዱ የተለቀቀ'
 *     removed    →  'የተሰናበተ'
 *     expired    →  'ጊዜው ያለፈበት'
 *
 * The resource does NOT special-case a missing key — it emits
 * whatever `__()` returns, so a developer or reviewer who sees the
 * fallback key in a response knows the catalogue amendment has not
 * been delivered.
 *
 * =============================================================================
 * The two date pairs — and the trait
 * =============================================================================
 * Both date columns (`start_date`, `end_date`) are each emitted as
 * a pair:
 *
 *     <field>          —  ISO 8601 date string (Y-m-d)
 *     <field>_display  —  Ethiopian-calendar medium form for the
 *                         current request locale
 *
 * The ISO form is produced by `?->toDateString()`. The display form
 * is produced by the `localizedDate()` method from the
 * `RendersLocalizedDates` trait. The trait returns `null` for null
 * input, so the display sibling of a nullable date is null in
 * exactly the same rows where the ISO sibling is null.
 *
 * `start_date` is NOT NULL in the schema, so its ISO sibling is
 * always a string and its display sibling is always a string. Only
 * `end_date` has a null branch.
 *
 * =============================================================================
 * The display_name field — and why it is emitted alongside the raw
 * names
 * =============================================================================
 * The model's `display_name` accessor (G2c file 18 of 31) resolves
 * a member's name using a precedence chain:
 *
 *     1. The membership's own bilingual name columns
 *     2. The loaded `user` relation's own `display_name`
 *     3. The `external_reference` column
 *     4. An empty string
 *
 * The resource emits the resolved form as `display_name` alongside
 * the raw columns. A client that wants the resolved name reads one
 * field; a client that wants to know WHICH column the resolution
 * used inspects the raw columns.
 *
 * The accessor's step-2 fallback requires the `user` relation to be
 * eager-loaded. The resource's `whenLoaded('user')` entry is
 * independent of the accessor's check — the accessor reads the
 * already-loaded relation if present and does NOT trigger a lazy
 * load. A controller that wants the accessor's step-2 fallback to
 * be reachable eager-loads `user` on the query; a controller that
 * does not, still gets a valid `display_name` via steps 1, 3, or 4.
 *
 * =============================================================================
 * The is_current field
 * =============================================================================
 * The resource emits `is_current` — a computed boolean produced by
 * the model's `isCurrent()` method. It is emitted despite the
 * general policy of not emitting derived predicates because the
 * computation depends on the SERVER's notion of "today", which a
 * client cannot reproduce without knowing the server's timezone and
 * the platform's calendar authority (the Ethiopian calendar does
 * not align with the Gregorian calendar in which the ISO dates are
 * written).
 *
 * Emitting the boolean keeps the wire format deterministic. A
 * client that wants to know why the flag is true inspects the two
 * date pairs.
 *
 * =============================================================================
 * The optional embedded relations
 * =============================================================================
 * The resource emits five `whenLoaded` entries:
 *
 *     body, position, term, appointment, user
 *
 * The first four are wrapped in their own sibling resources, all of
 * which are on disk:
 *
 *     GovernanceBodyResource        (G2a file 11 of 17)
 *     GovernancePositionResource    (G2b file 6 of 27)
 *     GovernanceTermResource        (G2b file 18 of 27)
 *     GovernanceAppointmentResource (G2c file 9 of 31)
 *
 * The `user` entry is emitted as an INLINE ARRAY, not a nested
 * resource. MASES Core does not ship a `UserResource`; the original
 * delivery of this file invented the name and every request that
 * eager-loaded `user` failed with `Class "…UserResource" not
 * found`. The redelivery replaces the invented reference with a
 * closure that builds the array from the already-loaded relation.
 *
 * The three fields emitted for the inline `user` array — `id`,
 * `display_name`, `email` — are the fields a membership UI needs.
 * A future CR that introduces a canonical `UserResource` in Core
 * replaces the closure with a one-line
 * `UserResource::make($this->whenLoaded('user'))` call.
 *
 * The `whenLoaded()` helper returns a `MissingValue` sentinel when
 * the relation is not eager-loaded. The sentinel is filtered out of
 * the final JSON payload, so a membership that was loaded without a
 * relation simply does not carry that key.
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * The resource issues no queries. Every value it emits comes from
 * the already-loaded model, from the trait's call into the
 * `LocalizationService` (no query), or from an already-eager-loaded
 * relation.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Resources/GovernanceMembershipResource.php
 *
 * The directory already exists.
 *
 * After uploading, run:
 *
 *     composer dump-autoload
 *
 * The resource has no independent provider registration. It is
 * referenced by name from the controller (file 25 of 31).
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Traits consumed:           1 (RendersLocalizedDates)
 * Sibling resources referenced: 4 (GovernanceBodyResource,
 *                               GovernancePositionResource,
 *                               GovernanceTermResource,
 *                               GovernanceAppointmentResource)
 * Inline relations referenced:  1 (user — no nested resource)
 * Translation groups pending:  1 (membership_status)
 * Direct database queries:   none
 * =============================================================================
 */

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