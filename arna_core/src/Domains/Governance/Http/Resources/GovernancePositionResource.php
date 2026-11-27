<?php

declare(strict_types=1);



namespace MaaleArna\Arna\Core\Domains\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\Arna\Core\Domains\Governance\Http\Resources\Concerns\RendersLocalizedDates;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernancePosition;

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
 * @package MaaleArna\Arna\Core\Domains\Governance\Http\Resources
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