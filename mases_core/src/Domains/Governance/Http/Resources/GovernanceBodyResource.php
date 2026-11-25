<?php

declare(strict_types=1);



namespace MaaleArna\MASES\Core\Domains\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\Concerns\RendersLocalizedDates;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;

/**
 * GovernanceBodyResource
 *
 * Transforms a GovernanceBody model into the JSON payload returned by
 * the governance-body endpoints.
 *
 * See the file-level change-verification block for the resource's
 * contract, the fields it exposes, the fields it deliberately
 * omits, the `body_type_label` resolution, the effective-range pair,
 * the embedded summary shape, and the reasoning for the resource's
 * no-query property.
 *
 * =============================================================================
 * Field-by-field shape
 * =============================================================================
 *
 *   Identity
 *     id                 integer      — primary key
 *     tenant_id          integer      — owning tenant
 *     code               string       — canonical uppercase identifier
 *     body_type          string       — raw backing value ('board', …)
 *     body_type_label    string       — localized label ('ቦርድ', 'Board')
 *
 *   Names
 *     name_am            string|null  — Amharic name
 *     name_en            string|null  — English name
 *     display_name       string       — locale-aware name accessor
 *
 *   Flags
 *     is_active          boolean      — the row's active flag
 *     is_standing        boolean      — the type is a standing body
 *     is_temporary       boolean      — the type is an ad-hoc body
 *
 *   Structural references
 *     parent_body_id     integer|null — structural parent
 *     reporting_body_id  integer|null — reporting target
 *
 *   Descriptive metadata
 *     purpose            string|null  — one-line purpose
 *     mandate            string|null  — full charter text
 *     quorum_rule_json   object|null  — structured quorum rule
 *     meeting_frequency  string|null  — free-text frequency
 *
 *   Effective range
 *     effective_from           string|null  — ISO date ('YYYY-MM-DD')
 *     effective_from_display   string|null  — Ethiopian calendar display
 *     effective_until          string|null  — ISO date
 *     effective_until_display  string|null  — Ethiopian calendar display
 *
 *   Optional relations (when eager-loaded)
 *     parent             object|null  — shallow summary
 *     reporting_body     object|null  — shallow summary
 *
 *   Timestamps
 *     created_at               string|null  — ISO 8601
 *     created_at_display       string|null  — Ethiopian calendar display
 *     updated_at               string|null  — ISO 8601
 *     updated_at_display       string|null  — Ethiopian calendar display
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Resources
 * @version 1.0.0
 */
final class GovernanceBodyResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the resource-level wrapper.
     *
     * The canonical envelope `{ success, data }` is produced by the
     * controller (G2a file 14 of 17). Setting `$wrap = null`
     * suppresses the resource's own `data` wrapper so the
     * controller's envelope is the only one applied.
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
        /** @var GovernanceBody $body */
        $body = $this->resource;

        return [
            // -----------------------------------------------------------------
            // Identity
            // -----------------------------------------------------------------
            'id'              => $body->id,
            'tenant_id'       => $body->tenant_id,
            'code'            => $body->code,
            'body_type'       => $body->body_type,
            'body_type_label' => $this->resolveBodyTypeLabel($body),

            // -----------------------------------------------------------------
            // Names — Amharic first, then English, then locale-aware.
            // -----------------------------------------------------------------
            'name_am'      => $body->name_am,
            'name_en'      => $body->name_en,
            'display_name' => $body->display_name,

            // -----------------------------------------------------------------
            // Flags
            //
            // `is_active` comes from the row; `is_standing` and
            // `is_temporary` are derived from the body type. The
            // pair is mutually exclusive: a body is either standing
            // or temporary, never both, never neither (for a
            // recognized type). A row with an unrecognized type
            // yields false for both — the same fail-closed
            // behaviour the model's helpers provide.
            // -----------------------------------------------------------------
            'is_active'    => $body->is_active,
            'is_standing'  => $body->isStanding(),
            'is_temporary' => $body->isTemporary(),

            // -----------------------------------------------------------------
            // Structural references.
            //
            // The two ids are emitted as-is. The corresponding
            // records are optionally embedded via the `parent` and
            // `reporting_body` fields below, when eager-loaded.
            // -----------------------------------------------------------------
            'parent_body_id'    => $body->parent_body_id,
            'reporting_body_id' => $body->reporting_body_id,

            // -----------------------------------------------------------------
            // Descriptive metadata.
            //
            // `quorum_rule_json` is emitted as the PHP array the
            // model's `array` cast yields; Laravel serializes it
            // to a nested JSON object. See the file-level docblock
            // §"quorum_rule_json — emitted as-is".
            // -----------------------------------------------------------------
            'purpose'           => $body->purpose,
            'mandate'           => $body->mandate,
            'quorum_rule_json'  => $body->quorum_rule_json,
            'meeting_frequency' => $body->meeting_frequency,

            // -----------------------------------------------------------------
            // Effective range — ISO + display, paired.
            //
            // The ISO form uses `toDateString()` because the columns
            // are DATE, not DATETIME. See the file-level docblock
            // §"The effective-range pair".
            // -----------------------------------------------------------------
            'effective_from'          => $body->effective_from?->toDateString(),
            'effective_from_display'  => $this->localizedDate($body->effective_from),
            'effective_until'         => $body->effective_until?->toDateString(),
            'effective_until_display' => $this->localizedDate($body->effective_until),

            // -----------------------------------------------------------------
            // Optional embedded summaries — included only when the
            // controller eager-loads the relation.
            //
            // A `null` return from the summary helper is emitted as
            // `null`, distinguishing "relation loaded but id is
            // null" from "relation not loaded" (field absent).
            // -----------------------------------------------------------------
            'parent' => $this->whenLoaded(
                'parent',
                fn () => $this->bodySummary($body->parent),
            ),

            'reporting_body' => $this->whenLoaded(
                'reportingBody',
                fn () => $this->bodySummary($body->reportingBody),
            ),

            // -----------------------------------------------------------------
            // Timestamps — ISO + display, paired.
            //
            // `toIso8601String()` because the columns are TIMESTAMP.
            // See the file-level docblock §"Timestamps".
            // -----------------------------------------------------------------
            'created_at'         => $body->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($body->created_at),
            'updated_at'         => $body->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($body->updated_at),
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the localized label for a governance body type.
     *
     * Returns the raw `body_type` value when the enum case cannot be
     * resolved (a row whose `body_type` holds an unrecognized value).
     * This keeps a corrupted row diagnosable from the API without
     * throwing.
     *
     * The translation key follows the pattern declared in the
     * catalogue files:
     *
     *     __('governance::common.body_type.' . $type->value)
     *
     * For example, `body_type = 'board'` resolves to
     * `__('governance::common.body_type.board')` → `'ቦርድ'` (am)
     * or `'Board'` (en).
     *
     * The fallback path — echoing the raw value — is the same shape
     * `ShareholderResource::resolveTypeLabel()` uses for an
     * unrecognized `shareholder_type`.
     *
     * @param  GovernanceBody $body
     * @return string
     */
    private function resolveBodyTypeLabel(GovernanceBody $body): string
    {
        $type = $body->type();

        if ($type === null) {
            return (string) ($body->body_type ?? '');
        }

        return __('governance::common.body_type.' . $type->value);
    }

    /**
     * Compose the minimal summary of a related governance body.
     *
     * Used for the two self-referencing relations (`parent` and
     * `reporting_body`). The summary exposes exactly six fields —
     * enough to identify the related body in a list or detail
     * view, no more.
     *
     * The summary does not recurse into the related body's own
     * relations. A client that needs a multi-level view fetches the
     * related body through its own endpoint and traverses it
     * explicitly.
     *
     * Returns `null` when the input is `null` (an unset self-
     * reference).
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