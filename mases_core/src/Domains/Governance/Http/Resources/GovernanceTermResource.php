<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 18 of 27
 * =============================================================================
 *
 * File:            Http/Resources/GovernanceTermResource.php  (NEW)
 * Unit:            G2b — GovernanceTerm vertical slice (resource)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceTermController` (G2b file
 *   21 of 27, not yet delivered) had no resource class to transform a
 *   `GovernanceTerm` model into the platform's canonical JSON
 *   envelope. A controller that returned the raw model would expose
 *   every column including internal timestamps, would not emit the
 *   `_display` siblings the Ethiopian-calendar convention requires,
 *   and would not guard the optional `body` relation against N+1
 *   query hazards.
 *
 * After:
 *   One file that transforms a `GovernanceTerm` model into the
 *   canonical MASES JSON payload. It follows the shape established
 *   by `GovernanceBodyResource` (G2a file 11 of 18) and
 *   `GovernancePositionResource` (G2b file 6 of 27):
 *
 *     · `$wrap = null` — the resource contributes no wrapper; the
 *       controller produces the canonical `{ success, data }`
 *       envelope.
 *
 *     · Emits ISO 8601 date strings alongside `_display` siblings for
 *       the effective date range and the two timestamps, using the
 *       local `RendersLocalizedDates` trait (G2a file 10 of 18).
 *
 *     · Guards the optional `body` relation with `whenLoaded()` — the
 *       resource issues no query of its own.
 *
 *     · Includes a minimal embedded summary for the body when it is
 *       eager-loaded by the controller.
 *
 *   What the resource exposes:
 *
 *     Identity                 id, tenant_id, governance_body_id
 *
 *     Labels                   label_am, label_en, display_label
 *
 *     Effective range          effective_from,
 *                              effective_from_display,
 *                              effective_until,
 *                              effective_until_display
 *
 *     Notes                    notes
 *
 *     Flags                    is_active, is_current
 *
 *     Optional relation        body (shallow summary, when
 *                              eager-loaded)
 *
 *     Timestamps               created_at, created_at_display,
 *                              updated_at, updated_at_display
 *
 *   What the resource deliberately does NOT expose:
 *
 *     · The `deleted_at` timestamp. Soft-deleted terms are excluded
 *       by the model's global scope; a non-deleted row's
 *       `deleted_at` is always null.
 *
 *     · The forward `memberships` relation. That target class does
 *       not yet exist on disk. When G2c delivers
 *       `GovernanceMembership` and a corresponding resource, this
 *       resource may be extended to expose a count or a summary
 *       of memberships via `whenCounted()` or `whenLoaded()`.
 *
 *     · Any computed counts (e.g. `membership_count`). The resource
 *       issues no queries.
 *
 *     · A `code` field. Terms do not have a code — the label is
 *       their identity.
 *
 *     · A `has_label` boolean. The `display_label` field always
 *       has a value (the model accessor synthesises a date-range
 *       fallback when both labels are null). A client that wants
 *       to know whether the display was a real label or a
 *       fallback can compare `label_am` and `label_en` against
 *       null.
 *
 * =============================================================================
 * The `is_current` derived field
 * =============================================================================
 * `is_current` is a derived boolean, not a stored column. It
 * answers the question "does the term's effective date range
 * contain today?" — the model's `isCurrent()` accessor (G2b file 14
 * of 27).
 *
 * Null bounds are treated as unbounded:
 *
 *   · `effective_from = null` — the term is treated as having
 *     started.
 *   · `effective_until = null` — the term is treated as not having
 *     ended.
 *
 * A term with both dates null is therefore `is_current = true`. A
 * client that wants a stricter reading checks the date fields
 * directly.
 *
 * The `is_current` field is orthogonal to `is_active`. A term may
 * be active but not current, or current but not active. The two
 * booleans are emitted independently.
 *
 * =============================================================================
 * The effective date range — ISO + display pairs
 * =============================================================================
 * The two date columns (`effective_from`, `effective_until`) are
 * emitted as two ISO 8601 date strings (`YYYY-MM-DD`) plus their
 * two `_display` siblings:
 *
 *     'effective_from'          => $term->effective_from?->toDateString(),
 *     'effective_from_display'  => $this->localizedDate($term->effective_from),
 *
 * The ISO form uses `toDateString()` rather than `toIso8601String()`
 * because the columns are `DATE` (not `DATETIME`); the ISO form is a
 * calendar day, not an instant. A client that needs a machine-
 * readable date parses `YYYY-MM-DD` directly. A client that needs the
 * human-readable Ethiopian-calendar rendering reads the `_display`
 * sibling.
 *
 * Both pairs are null when the corresponding column is null. A term
 * with only one of the two dates set is a valid state (an open-ended
 * range); the resource does not enforce that both are set or both
 * are null.
 *
 * =============================================================================
 * The `display_label` field
 * =============================================================================
 * The `display_label` field is the model's locale-aware label
 * accessor (`GovernanceTerm::getDisplayLabelAttribute()`, G2b file
 * 14 of 27). Its resolution order is:
 *
 *   1. Amharic (`label_am`) when the active locale is `am`.
 *   2. English (`label_en`) when the active locale is not `am`.
 *   3. The other language as a fallback.
 *   4. A synthesised date-range string when neither label is set.
 *   5. An empty string when neither labels nor dates are set.
 *
 * The resource calls the accessor; it does not reimplement the
 * resolution logic. A client that wants to distinguish "real label"
 * from "synthesised fallback" checks `label_am` and `label_en`
 * directly.
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
 * The summary is deliberately shallow: six fields, no recursion into
 * the body's own relations. A client that needs the body's parent,
 * its positions, its terms, or any other property fetches the body
 * through its own endpoint.
 *
 * When the relation is not loaded, the `body` field is absent from
 * the payload (Laravel's `whenLoaded()` omits the key entirely
 * rather than emitting a null).
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * The resource performs no queries of its own. The `body` relation
 * is guarded by `whenLoaded()`. The four `_display` date siblings do
 * not change this property — they derive from the same Carbon
 * instances the ISO fields read.
 *
 * =============================================================================
 * Amharic-first
 * =============================================================================
 * The JSON payload orders `label_am` before `label_en` to mirror
 * the column order declared by the migration (G2b file 13 of 27)
 * and the model's `$fillable` array (G2b file 14 of 27). The
 * `display_label` field carries the locale-appropriate value.
 *
 * The four `_display` date fields (via the local
 * `RendersLocalizedDates` trait) follow the Amharic-first policy for
 * the Ethiopian half and use English month names for the Gregorian
 * parenthetical in both locales.
 *
 * No user-facing string is hard-coded in this file.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Resources/GovernanceTermResource.php
 *
 * The directory already exists.
 *
 * The resource is consumed by `GovernanceTermController` (G2b file 21
 * of 27). Until that controller is delivered and its routes
 * registered, this file is inert.
 *
 * A `composer dump-autoload` may be required if the class map is
 * cached.
 *
 * Verification (after the controller is delivered):
 *
 *     php artisan tinker
 *     >>> use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\GovernanceTermResource;
 *     >>> use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
 *
 *     >>> $t = new GovernanceTerm([
 *     ...     'tenant_id'          => 1,
 *     ...     'governance_body_id' => 1,
 *     ...     'label_am'           => 'የ2026 የሥራ ዘመን',
 *     ...     'label_en'           => 'Term 2026',
 *     ...     'effective_from'     => '2026-09-18',
 *     ...     'effective_until'    => '2029-09-17',
 *     ...     'is_active'          => true,
 *     ... ]);
 *
 *     >>> (new GovernanceTermResource($t))->resolve()
 *     => [
 *          "id" => null,
 *          "tenant_id" => 1,
 *          "governance_body_id" => 1,
 *          "label_am" => "የ2026 የሥራ ዘመን",
 *          "label_en" => "Term 2026",
 *          "display_label" => "የ2026 የሥራ ዘመን",
 *          "effective_from" => "2026-09-18",
 *          "effective_from_display" => "መስከረም 8, 2019 ዓ.ም. (September 18, 2026)",
 *          "effective_until" => "2029-09-17",
 *          "effective_until_display" => "መስከረም 7, 2022 ዓ.ም. (September 17, 2029)",
 *          "notes" => null,
 *          "is_active" => true,
 *          "is_current" => false,
 *          "created_at" => null,
 *          "created_at_display" => null,
 *          "updated_at" => null,
 *          "updated_at_display" => null,
 *        ]
 *
 * (The `body` field is absent because the relation was not
 * eager-loaded on the fresh model instance. The `is_current` value
 * depends on the current date; the example above assumes today is
 * before 2026-09-18.)
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Public fields emitted:     16 (plus 1 optional relation summary)
 * Trait consumed:            RendersLocalizedDates (G2a file 10 of 18)
 * Controller consumer:       GovernanceTermController (G2b file 21 of 27)
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\Concerns\RendersLocalizedDates;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;

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
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Resources
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