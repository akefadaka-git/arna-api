<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 6 of 27
 * =============================================================================
 *
 * File:            Http/Resources/GovernancePositionResource.php  (NEW)
 * Unit:            G2b — GovernancePosition vertical slice (resource)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernancePositionController` (G2b
 *   file 9 of 27, not yet delivered) had no resource class to
 *   transform a `GovernancePosition` model into the platform's
 *   canonical JSON envelope. A controller that returned the raw
 *   model would expose every column including internal timestamps,
 *   would not emit the `_display` siblings the Ethiopian-calendar
 *   convention requires, and would not guard the optional `body`
 *   relation against N+1 query hazards.
 *
 * After:
 *   One file that transforms a `GovernancePosition` model into the
 *   canonical MASES JSON payload. It follows the shape established
 *   by `GovernanceBodyResource` (G2a file 11 of 18) and the
 *   Shareholder resources:
 *
 *     · `$wrap = null` — the resource contributes no wrapper; the
 *       controller produces the canonical `{ success, data }`
 *       envelope.
 *
 *     · Emits ISO 8601 values alongside `_display` siblings for
 *       the two timestamp fields, using the local
 *       `RendersLocalizedDates` trait (G2a file 10 of 18).
 *
 *     · Guards the optional `body` relation with `whenLoaded()` —
 *       the resource issues no query of its own.
 *
 *     · Includes a minimal embedded summary for the body when it
 *       is eager-loaded by the controller.
 *
 *   What the resource exposes:
 *
 *     Identity                 id, tenant_id, governance_body_id,
 *                              code
 *
 *     Names                    name_am, name_en, display_name
 *
 *     Descriptions             description_am, description_en
 *
 *     Semantic flags           is_voting, is_officer, is_active,
 *                              is_standing (derived from the body
 *                              when the body is loaded)
 *
 *     Display metadata         sort_order
 *
 *     Optional relation        body (shallow summary, when
 *                              eager-loaded)
 *
 *     Timestamps               created_at, created_at_display,
 *                              updated_at, updated_at_display
 *
 *   What the resource deliberately does NOT expose:
 *
 *     · The `deleted_at` timestamp. Soft-deleted positions are
 *       excluded by the model's global scope; a non-deleted row's
 *       `deleted_at` is always null.
 *
 *     · The forward `memberships` relation. That target class does
 *       not yet exist on disk. When G2c delivers
 *       `GovernanceMembership` and the corresponding resource, this
 *       resource may be extended to expose a count or a summary of
 *       memberships via `whenCounted()` or `whenLoaded()`. Neither
 *       is offered today.
 *
 *     · Any computed counts (e.g. `membership_count`). The resource
 *       issues no queries; a count would have to be eager-loaded
 *       via `withCount()`, and no G2b endpoint does so.
 *
 *     · A composed "names" object. The bilingual pair is emitted as
 *       two flat fields (`name_am`, `name_en`) matching the column
 *       order in the migration. There is no ambiguity about which
 *       is populated; the flat shape is correct.
 *
 * =============================================================================
 * The `is_standing` derived field
 * =============================================================================
 * `is_standing` is a derived boolean, not a stored column. It
 * answers the question "does this position belong to a standing
 * body, as opposed to a temporary one?" — the same distinction
 * `GovernanceBodyResource` exposes as `is_standing` for bodies.
 *
 * The value is computed by `GovernancePosition::isStanding()`
 * (G2b file 2 of 27), which delegates to the parent body's
 * `GovernanceBodyType::isStanding()`. The method does NOT
 * lazy-load the body — it returns `false` when the `body` relation
 * is not eager-loaded. This means:
 *
 *   · When the controller eager-loads `body` (which G2b file 9
 *     does for `index`, `show`, `store`, `update`), `is_standing`
 *     reports the correct value.
 *
 *   · When the body is not eager-loaded, `is_standing` returns
 *     `false`. This is a deliberate fail-closed posture: a caller
 *     that forgot to eager-load the relation sees `false` rather
 *     than triggering a lazy-load query behind the resource's back.
 *     The platform's no-hidden-N+1 rule is preserved.
 *
 * =============================================================================
 * The `display_name` field
 * =============================================================================
 * The `display_name` field is the model's locale-aware name
 * accessor (`GovernancePosition::getDisplayNameAttribute()`, G2b
 * file 2 of 27). Its resolution order — Amharic → English →
 * other-language fallback → code as ultimate fallback — matches
 * `GovernanceBody` and `ShareClass` exactly.
 *
 * The resource calls the accessor; it does not reimplement the
 * resolution logic. The one place where the resolution order is
 * expressed is the model; the resource reads it.
 *
 * =============================================================================
 * The `body` embedded summary
 * =============================================================================
 * When the controller eager-loads the `body` relation, the resource
 * emits a shallow summary of the owning body:
 *
 *     'body' => [
 *         'id'           => ...,
 *         'code'         => ...,
 *         'body_type'    => ...,
 *         'name_am'      => ...,
 *         'name_en'      => ...,
 *         'display_name' => ...,
 *     ]
 *
 * The summary is deliberately shallow: six fields, no recursion
 * into the body's own relations. A client that needs the body's
 * parent, its positions, or any other property fetches the body
 * through its own endpoint.
 *
 * When the relation is not loaded, the `body` field is absent from
 * the payload (Laravel's `whenLoaded()` omits the key entirely
 * rather than emitting a null). A client distinguishes "body not
 * loaded" (field absent) from "body is null" (which cannot happen
 * — `governance_body_id` is NOT NULL, so the relation always
 * resolves to a model when loaded).
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * The resource performs no queries of its own. The `body` relation
 * is guarded by `whenLoaded()`. The two `_display` date siblings
 * do not change this property — they derive from the same Carbon
 * instances the ISO fields read.
 *
 * =============================================================================
 * Amharic-first
 * =============================================================================
 * The JSON payload orders `name_am` before `name_en` to mirror the
 * column order declared by the migration (G2b file 1 of 27) and the
 * model's `$fillable` array (G2b file 2 of 27). The `display_name`
 * field carries the locale-appropriate value.
 *
 * The two `_display` date fields (via the local
 * `RendersLocalizedDates` trait) follow the Amharic-first policy
 * for the Ethiopian half and use English month names for the
 * Gregorian parenthetical in both locales, exactly as
 * `GovernanceBodyResource` and the Shareholder resources do.
 *
 * No user-facing string is hard-coded in this file.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Resources/GovernancePositionResource.php
 *
 * The directory already exists (created for the G2a resource and
 * the local trait).
 *
 * The resource is consumed by `GovernancePositionController` (G2b
 * file 9 of 27). Until that controller is delivered and its routes
 * registered, this file is inert.
 *
 * A `composer dump-autoload` may be required if the class map is
 * cached.
 *
 * Verification (after the controller is delivered):
 *
 *     php artisan tinker
 *     >>> use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\GovernancePositionResource;
 *     >>> use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
 *
 *     >>> $p = new GovernancePosition([
 *     ...     'tenant_id'          => 1,
 *     ...     'governance_body_id' => 1,
 *     ...     'code'               => 'CHAIRPERSON',
 *     ...     'name_am'            => 'ሰብሳቢ',
 *     ...     'name_en'            => 'Chairperson',
 *     ...     'is_voting'          => true,
 *     ...     'is_officer'         => true,
 *     ...     'sort_order'         => 10,
 *     ...     'is_active'          => true,
 *     ... ]);
 *
 *     >>> (new GovernancePositionResource($p))->resolve()
 *     => [
 *          "id" => null,
 *          "tenant_id" => 1,
 *          "governance_body_id" => 1,
 *          "code" => "CHAIRPERSON",
 *          "name_am" => "ሰብሳቢ",
 *          "name_en" => "Chairperson",
 *          "display_name" => "ሰብሳቢ",
 *          "description_am" => null,
 *          "description_en" => null,
 *          "is_voting" => true,
 *          "is_officer" => true,
 *          "is_active" => true,
 *          "is_standing" => false,        ← body not eager-loaded
 *          "sort_order" => 10,
 *          "created_at" => null,
 *          "created_at_display" => null,
 *          "updated_at" => null,
 *          "updated_at_display" => null,
 *        ]
 *
 * (The `body` field is absent because the relation was not
 * eager-loaded on the fresh model instance.)
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Public fields emitted:     18 (plus 1 optional relation summary)
 * Trait consumed:            RendersLocalizedDates (G2a file 10 of 18)
 * Controller consumer:       GovernancePositionController (G2b file 9 of 27)
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\Concerns\RendersLocalizedDates;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;

/**
 * GovernancePositionResource
 *
 * Transforms a GovernancePosition model into the JSON payload
 * returned by the position endpoints.
 *
 * See the file-level change-verification block for the resource's
 * contract, the `is_standing` derived field, the `body` embedded
 * summary, and the reasoning for the resource's no-query property.
 *
 * =============================================================================
 * Field-by-field shape
 * =============================================================================
 *
 *   Identity
 *     id                 integer      — primary key
 *     tenant_id          integer      — owning tenant
 *     governance_body_id integer      — owning body
 *     code               string       — canonical uppercase identifier
 *
 *   Names
 *     name_am            string|null  — Amharic name
 *     name_en            string|null  — English name
 *     display_name       string       — locale-aware name accessor
 *
 *   Descriptions
 *     description_am     string|null  — Amharic description
 *     description_en     string|null  — English description
 *
 *   Flags
 *     is_voting          boolean      — carries voting rights?
 *     is_officer         boolean      — is an officer position?
 *     is_active          boolean      — the row's active flag
 *     is_standing        boolean      — parent body is standing
 *                                       (false when body not loaded)
 *
 *   Display metadata
 *     sort_order         integer      — display order within the body
 *
 *   Optional relation (when eager-loaded)
 *     body               object       — shallow summary
 *
 *   Timestamps
 *     created_at         string|null  — ISO 8601
 *     created_at_display string|null  — Ethiopian calendar display
 *     updated_at         string|null  — ISO 8601
 *     updated_at_display string|null  — Ethiopian calendar display
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Resources
 * @version 1.0.0
 */
final class GovernancePositionResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the resource-level wrapper.
     *
     * The canonical envelope `{ success, data }` is produced by the
     * controller (G2b file 9 of 27). Setting `$wrap = null`
     * suppresses the resource's own `data` wrapper.
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
        /** @var GovernancePosition $position */
        $position = $this->resource;

        return [
            // -----------------------------------------------------------------
            // Identity
            // -----------------------------------------------------------------
            'id'                 => $position->id,
            'tenant_id'          => $position->tenant_id,
            'governance_body_id' => $position->governance_body_id,
            'code'               => $position->code,

            // -----------------------------------------------------------------
            // Names — Amharic first, then English, then locale-aware.
            // -----------------------------------------------------------------
            'name_am'      => $position->name_am,
            'name_en'      => $position->name_en,
            'display_name' => $position->display_name,

            // -----------------------------------------------------------------
            // Descriptions — Amharic first, then English.
            // -----------------------------------------------------------------
            'description_am' => $position->description_am,
            'description_en' => $position->description_en,

            // -----------------------------------------------------------------
            // Semantic flags.
            //
            // `is_voting` and `is_officer` are stored columns; G2b
            // only records them, later phases consume them.
            //
            // `is_active` is the row's own flag.
            //
            // `is_standing` is derived from the parent body's type.
            // It returns `false` when the `body` relation is not
            // eager-loaded — see the file-level change-verification
            // block §"The is_standing derived field".
            // -----------------------------------------------------------------
            'is_voting'    => $position->is_voting,
            'is_officer'   => $position->is_officer,
            'is_active'    => $position->is_active,
            'is_standing'  => $position->isStanding(),

            // -----------------------------------------------------------------
            // Display metadata.
            // -----------------------------------------------------------------
            'sort_order' => $position->sort_order,

            // -----------------------------------------------------------------
            // Optional embedded summary — included only when the
            // controller eager-loads the `body` relation.
            //
            // The summary is shallow: six fields, no recursion into
            // the body's own relations.
            // -----------------------------------------------------------------
            'body' => $this->whenLoaded(
                'body',
                fn () => $this->bodySummary($position->body),
            ),

            // -----------------------------------------------------------------
            // Timestamps — ISO + display, paired.
            // -----------------------------------------------------------------
            'created_at'         => $position->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($position->created_at),
            'updated_at'         => $position->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($position->updated_at),
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Compose the minimal summary of the owning governance body.
     *
     * Used for the `body` relation. The summary exposes six fields
     * — enough to identify the related body in a list or detail
     * view, no more.
     *
     * Returns `null` when the input is `null`. In normal flow the
     * relation never resolves to null (the `governance_body_id`
     * column is NOT NULL and the FK guarantees a matching row); the
     * nullable signature is defensive.
     *
     * @param  GovernanceBody|null $body
     * @return array<string, mixed>|null
     */
    private function bodySummary(?GovernanceBody $body): ?array
    {
        if ($body === null) {
            return null;
        }

        return [
            'id'           => $body->id,
            'code'         => $body->code,
            'body_type'    => $body->body_type,
            'name_am'      => $body->name_am,
            'name_en'      => $body->name_en,
            'display_name' => $body->display_name,
        ];
    }
}