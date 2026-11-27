<?php

declare(strict_types=1);

/* Serializes Governance terms with localized Ethiopian-calendar date fields. */

namespace MaaleArna\Arna\Core\Domains\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\Arna\Core\Domains\Governance\Http\Resources\Concerns\RendersLocalizedDates;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceTerm;

/**
 * GovernanceTermResource
 *
 * Transforms a GovernanceTerm model into the JSON payload returned by
 * the term endpoints.
 *
 * See the file-level change-verification block for the resource's
 * contract, the `is_current` derived field, the `display_label`
 * field, and the `body` embedded summary.
 *
 * =============================================================================
 * Field-by-field shape
 * =============================================================================
 *
 *   Identity
 *     id                    integer      — primary key
 *     tenant_id             integer      — owning tenant
 *     governance_body_id    integer      — owning body
 *
 *   Labels
 *     label_am              string|null  — Amharic label
 *     label_en              string|null  — English label
 *     display_label         string       — locale-aware accessor,
 *                                          with date-range fallback
 *
 *   Effective range
 *     effective_from           string|null  — ISO date 'YYYY-MM-DD'
 *     effective_from_display   string|null  — Ethiopian calendar display
 *     effective_until          string|null  — ISO date
 *     effective_until_display  string|null  — Ethiopian calendar display
 *
 *   Notes
 *     notes                 string|null  — free-text context
 *
 *   Flags
 *     is_active             boolean      — the row's active flag
 *     is_current            boolean      — date range contains today
 *
 *   Optional relation (when eager-loaded)
 *     body                  object       — shallow summary
 *
 *   Timestamps
 *     created_at            string|null  — ISO 8601
 *     created_at_display    string|null  — Ethiopian calendar display
 *     updated_at            string|null  — ISO 8601
 *     updated_at_display    string|null  — Ethiopian calendar display
 *
 * =============================================================================
 * @package MaaleArna\Arna\Core\Domains\Governance\Http\Resources
 * @version 1.0.0
 */
final class GovernanceTermResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the resource-level wrapper.
     *
     * The canonical envelope `{ success, data }` is produced by the
     * controller (G2b file 21 of 27).
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
        /** @var GovernanceTerm $term */
        $term = $this->resource;

        return [
            // -----------------------------------------------------------------
            // Identity
            // -----------------------------------------------------------------
            'id'                 => $term->id,
            'tenant_id'          => $term->tenant_id,
            'governance_body_id' => $term->governance_body_id,

            // -----------------------------------------------------------------
            // Labels — Amharic first, then English, then locale-aware.
            // -----------------------------------------------------------------
            'label_am'      => $term->label_am,
            'label_en'      => $term->label_en,
            'display_label' => $term->display_label,

            // -----------------------------------------------------------------
            // Effective range — ISO + display, paired.
            //
            // The ISO form uses `toDateString()` because the columns
            // are DATE, not DATETIME. See the file-level
            // change-verification block
            // §"The effective date range — ISO + display pairs".
            // -----------------------------------------------------------------
            'effective_from'          => $term->effective_from?->toDateString(),
            'effective_from_display'  => $this->localizedDate($term->effective_from),
            'effective_until'         => $term->effective_until?->toDateString(),
            'effective_until_display' => $this->localizedDate($term->effective_until),

            // -----------------------------------------------------------------
            // Notes.
            // -----------------------------------------------------------------
            'notes' => $term->notes,

            // -----------------------------------------------------------------
            // Flags.
            //
            // `is_active` is the row's own flag.
            // `is_current` is derived from the date range. The two are
            // orthogonal — see the file-level change-verification
            // block §"The is_current derived field".
            // -----------------------------------------------------------------
            'is_active'  => $term->is_active,
            'is_current' => $term->isCurrent(),

            // -----------------------------------------------------------------
            // Optional embedded summary — included only when the
            // controller eager-loads the `body` relation.
            // -----------------------------------------------------------------
            'body' => $this->whenLoaded(
                'body',
                fn () => $this->bodySummary($term->body),
            ),

            // -----------------------------------------------------------------
            // Timestamps — ISO + display, paired.
            // -----------------------------------------------------------------
            'created_at'         => $term->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($term->created_at),
            'updated_at'         => $term->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($term->updated_at),
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Compose the minimal summary of the owning governance body.
     *
     * Used for the `body` relation. The summary exposes six fields
     * — enough to identify the related body in a list or detail view,
     * no more.
     *
     * Returns `null` when the input is `null`. In normal flow the
     * relation never resolves to null (the `governance_body_id`
     * column is NOT NULL and the FK guarantees a matching row).
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