<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 11 of 17
 * =============================================================================
 *
 * File:            Http/Resources/GovernanceBodyResource.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (resource)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceBodyController` (G2a file
 *   14 of 17, not yet delivered) had no resource class to transform a
 *   `GovernanceBody` model into the platform's canonical JSON
 *   envelope. A controller that returned the raw model would expose
 *   every column including internal timestamps, would not emit the
 *   `_label` siblings the Amharic-first convention requires, would
 *   not emit the `_display` siblings the Ethiopian-calendar
 *   convention requires, and would not guard optional relations
 *   against N+1 query hazards.
 *
 * After:
 *   One file that transforms a `GovernanceBody` model into the
 *   canonical MASES JSON payload. It follows the shape established
 *   by `ShareholderResource`, `ShareClassResource`, and every other
 *   resource in the platform:
 *
 *     · `$wrap = null` — the resource contributes no wrapper; the
 *       controller produces the canonical `{ success, data }`
 *       envelope.
 *
 *     · Emits raw values alongside `_label` siblings for every
 *       vocabulary value.
 *
 *     · Emits ISO 8601 values alongside `_display` siblings for
 *       every date field, using the local
 *       `RendersLocalizedDates` trait (G2a file 10 of 17).
 *
 *     · Guards optional relations with `whenLoaded()` — the
 *       resource issues no query of its own.
 *
 *     · Includes minimal embedded summaries for the two
 *       self-referencing relations (`parent`, `reporting_body`)
 *       when they are eager-loaded by the controller.
 *
 *   What the resource exposes:
 *
 *     Identity                 id, tenant_id, code,
 *                              body_type, body_type_label
 *
 *     Names                    name_am, name_en, display_name
 *
 *     Flags                    is_active, is_standing, is_temporary
 *
 *     Structural references    parent_body_id, reporting_body_id
 *
 *     Descriptive metadata     purpose, mandate, quorum_rule_json,
 *                              meeting_frequency
 *
 *     Effective range          effective_from, effective_from_display,
 *                              effective_until, effective_until_display
 *
 *     Optional relations       parent, reporting_body
 *                              (each a shallow summary,
 *                              when eager-loaded)
 *
 *     Timestamps               created_at, created_at_display,
 *                              updated_at, updated_at_display
 *
 *   What the resource deliberately does NOT expose:
 *
 *     · The `deleted_at` timestamp. Soft-deleted bodies are excluded
 *       by the model's global scope; a non-deleted row's
 *       `deleted_at` is always null, and emitting it would add a
 *       null field with no semantic value.
 *
 *     · The forward relations (`positions`, `terms`, `appointments`,
 *       `memberships`). Those classes do not yet exist on disk. When
 *       G2b and G2c deliver their models and the controllers that
 *       consume them, those controllers will pass eager-load lists
 *       to their own endpoints. This resource does not offer a
 *       forward-looking `whenLoaded()` guard for a relation that
 *       cannot resolve.
 *
 *     · The `children` and `reporting_children` relations. Both
 *       could be included via `whenLoaded()`, but the corresponding
 *       use case — rendering a tree of descendants in a single
 *       response — is not part of G2a's surface. A future CR that
 *       adds a tree view endpoint will extend this resource (or
 *       add a sibling `GovernanceBodyTreeResource`) at that time.
 *
 *     · Any computed counts (e.g. `membership_count`,
 *       `meeting_count`). The resource issues no queries; a count
 *       would have to be eager-loaded via `withCount()`, and no G2a
 *       endpoint does so. Adding the count fields now, without a
 *       producer, would be a placeholder.
 *
 *     · A composed "names" object. The bilingual pair is emitted as
 *       two flat fields (`name_am`, `name_en`) matching the column
 *       order in the migration. `ShareholderResource` groups its
 *       name columns into nested objects (`individual_name`,
 *       `entity_name`) because the two groups are mutually
 *       exclusive and the nesting clarifies which is populated;
 *       `GovernanceBody` has a single bilingual pair with no such
 *       ambiguity, so the flat shape is correct.
 *
 * =============================================================================
 * `body_type_label` — the label sibling
 * =============================================================================
 * The `body_type` field emits the raw backing string
 * (`'board'`, `'committee'`, etc.), which a client uses for logic.
 *
 * The `body_type_label` field emits the localized human-readable
 * string, resolved via the translation catalogue registered by the
 * Governance provider (G1 file 4 of 10):
 *
 *     __('governance::common.body_type.' . $type->value)
 *
 * In the `am` locale, the label is the Amharic term declared in
 * `Resources/lang/am/common.php` (G1 file 2 of 10): `'ቦርድ'`,
 * `'ኮሚቴ'`, etc. In the `en` locale, the label is the English term
 * declared in `Resources/lang/en/common.php` (G1 file 3 of 10):
 * `'Board'`, `'Committee'`, etc.
 *
 * If the row's `body_type` value is not recognized — a corrupted
 * row, or a row written by a future CR whose enum value has not yet
 * been deployed to this application instance — the label falls back
 * to the raw value. This mirrors the fallback in
 * `ShareholderResource::resolveTypeLabel()` and
 * `ShareCertificateResource::resolveStatusLabel()`. The fallback
 * keeps a corrupted row diagnosable from the API response without
 * throwing.
 *
 * =============================================================================
 * `quorum_rule_json` — emitted as-is
 * =============================================================================
 * The model casts `quorum_rule_json` to `array`. The resource emits
 * the resulting PHP array directly. When Laravel serializes the
 * response to JSON, the array becomes a nested JSON object with the
 * shape documented in the migration's docblock (G2a file 1 of 17):
 *
 *     {
 *       "type":     "percentage_of_members",
 *       "value":    50,
 *       "notes_am": "…",
 *       "notes_en": "…"
 *     }
 *
 * The resource does NOT validate or reshape the value. The service
 * layer validated it on write; the resource trusts the stored shape.
 * A future CR that needs a `quorum_rule_summary` field (a human-
 * readable one-line rendering of the rule) would compute it from
 * the JSON, but that computation belongs in the resource at the
 * time of the CR — not now, without a consumer.
 *
 * =============================================================================
 * The effective-range pair
 * =============================================================================
 * The two date columns (`effective_from`, `effective_until`) are
 * emitted as two ISO 8601 date strings (`YYYY-MM-DD`) plus their two
 * `_display` siblings:
 *
 *     'effective_from'          => $body->effective_from?->toDateString(),
 *     'effective_from_display'  => $this->localizedDate($body->effective_from),
 *
 * The ISO form uses `toDateString()` rather than `toIso8601String()`
 * because the columns are `DATE` (not `DATETIME`); the ISO form is a
 * calendar day, not an instant. A client that needs a machine-
 * readable date parses `YYYY-MM-DD` directly. A client that needs
 * the human-readable Ethiopian-calendar rendering reads the
 * `_display` sibling.
 *
 * Both pairs are null when the corresponding column is null. A body
 * with only one of the two dates set is a valid state (an open-ended
 * range); the resource does not enforce that both are set or both
 * are null.
 *
 * The reasoning for emitting both forms — and for using the local
 * `RendersLocalizedDates` trait rather than a cross-domain import —
 * is documented in the trait's file-level change-verification block
 * (G2a file 10 of 17), which records the ADR-GOV-011 local-copy
 * decision.
 *
 * =============================================================================
 * Embedded summaries
 * =============================================================================
 * The two self-referencing relations (`parent`, `reportingBody`)
 * are exposed as shallow summaries when eager-loaded:
 *
 *     'parent' => $this->whenLoaded('parent', fn () => $this->bodySummary($body->parent)),
 *
 * Each summary is an array of five fields:
 *
 *     id, code, body_type, name_am, name_en, display_name
 *
 * The `display_name` field is a locale-aware accessor on the model.
 * Including it in the summary spares the client from having to
 * re-resolve the name based on the request's locale.
 *
 * The summary does NOT recurse into the related body's own relations
 * (its parent, its children, its members, etc.). Recursion would
 * impose an unbounded load on the API; a client that needs a
 * multi-level view fetches the related body through its own endpoint
 * and traverses it explicitly.
 *
 * The `bodySummary()` helper returns `null` for a null input, so a
 * row whose `parent_body_id` is null still emits `'parent' => null`
 * when the relation is eager-loaded. A client can distinguish "no
 * parent set" (null) from "parent not loaded" (field absent).
 *
 * =============================================================================
 * Timestamps
 * =============================================================================
 * The two Eloquent timestamps (`created_at`, `updated_at`) are
 * emitted as ISO 8601 strings with their `_display` siblings:
 *
 *     'created_at'         => $body->created_at?->toIso8601String(),
 *     'created_at_display' => $this->localizedDate($body->created_at),
 *
 * `toIso8601String()` is used (not `toDateString()`) because these
 * columns are `TIMESTAMP` — instants, not calendar days. A client
 * that needs the exact instant reads the ISO form; a client that
 * renders the timestamp for a human reads the `_display` sibling.
 *
 * The `_display` rendering for a `TIMESTAMP` produces the medium
 * Ethiopian-calendar form of the date component only — the time of
 * day is not included. This matches the platform's convention: the
 * `_display` sibling is a calendar-day rendering, not a full
 * date+time rendering. A resource that needs the time component
 * emits a separate `_time` sibling via a future CR.
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * The resource performs no queries of its own. Every relation it
 * exposes is guarded by `whenLoaded()`, so a controller that forgets
 * to eager-load a relation simply does not receive it in the
 * payload — the resource does not silently issue a query behind the
 * caller's back.
 *
 * The two `_display` date siblings do not change this property.
 * They derive from the same Carbon instances the ISO fields already
 * read; no query, no lazy load, no additional accessor resolution
 * beyond the `LocalizationService` singleton the trait resolves.
 *
 * This rule is deliberately strict. A resource that lazily fetches
 * relations is the single most common source of hidden N+1
 * behaviour in Laravel APIs. Keeping the resource pure makes the
 * query cost of every endpoint visible from the controller. The
 * same rule is documented in `ShareholderResource`,
 * `ShareClassResource`, and every other resource in the platform.
 *
 * =============================================================================
 * Amharic-first
 * =============================================================================
 * The JSON payload orders `name_am` before `name_en` to mirror the
 * column order declared by the migration (G2a file 1 of 17) and by
 * the model's `$fillable` array (G2a file 3 of 17). The
 * `display_name` field carries the locale-appropriate value,
 * resolved by the model's accessor with the same Amharic-first,
 * English-fallback, code-as-ultimate-fallback policy `ShareClass`
 * uses.
 *
 * The `body_type_label` field resolves through the translation
 * catalogue, so a missing translation returns the translation key
 * itself — the correct diagnostic surface — rather than a fallback
 * that hides the gap. In normal operation, both catalogues are
 * complete and every label resolves.
 *
 * The two `_display` date fields (via the local trait) follow the
 * same Amharic-first policy for the Ethiopian half — Amharic month
 * names for the `am` locale, transliterated for the `en` locale —
 * and use English month names for the Gregorian parenthetical in
 * both locales. See the trait's docblock and the underlying
 * `LocalizationService::formatEthiopianDateMedium()` for the
 * reasoning.
 *
 * No user-facing string is hard-coded in this file. Every label
 * comes from the translation catalogue; every display string comes
 * from the localization service.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Resources/GovernanceBodyResource.php
 *
 * The directory `mases_core/src/Domains/Governance/Http/Resources/`
 * must exist — it was created for the trait (G2a file 10 of 17). If
 * your environment somehow lacks it, create it before uploading.
 *
 * The resource is consumed by `GovernanceBodyController` (G2a file
 * 14 of 17). Until that controller is delivered and its routes
 * registered, this file is inert — present on disk but not invoked.
 *
 * A `composer dump-autoload` may be required if the class map is
 * cached. The resource is a plain class resolved by the autoloader
 * when the controller references it.
 *
 * Verification (after the controller is delivered):
 *
 *     php artisan tinker
 *     >>> use MaaleArna\MASES\Core\Domains\Governance\Http\Resources\GovernanceBodyResource;
 *     >>> use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
 *
 *     >>> $body = new GovernanceBody([
 *     ...     'tenant_id'   => 1,
 *     ...     'code'        => 'BOARD',
 *     ...     'body_type'   => 'board',
 *     ...     'name_am'     => 'ቦርድ',
 *     ...     'name_en'     => 'Board',
 *     ...     'is_active'   => true,
 *     ... ]);
 *
 *     >>> (new GovernanceBodyResource($body))->resolve()
 *     => [
 *          "id" => null,
 *          "tenant_id" => 1,
 *          "code" => "BOARD",
 *          "body_type" => "board",
 *          "body_type_label" => "ቦርድ",
 *          "name_am" => "ቦርድ",
 *          "name_en" => "Board",
 *          "display_name" => "ቦርድ",
 *          "is_active" => true,
 *          "is_standing" => true,
 *          "is_temporary" => false,
 *          "parent_body_id" => null,
 *          "reporting_body_id" => null,
 *          "purpose" => null,
 *          "mandate" => null,
 *          "quorum_rule_json" => null,
 *          "meeting_frequency" => null,
 *          "effective_from" => null,
 *          "effective_from_display" => null,
 *          "effective_until" => null,
 *          "effective_until_display" => null,
 *          "created_at" => null,
 *          "created_at_display" => null,
 *          "updated_at" => null,
 *          "updated_at_display" => null,
 *        ]
 *
 * (The `parent` and `reporting_body` fields are absent because the
 * relations were not eager-loaded on the fresh model instance.)
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Public fields emitted:     26 (plus 2 optional relation summaries)
 * Trait consumed:            RendersLocalizedDates (G2a file 10 of 17)
 * Translation groups used:   governance::common.body_type (G1 files 2, 3)
 * Controller consumer:       GovernanceBodyController (G2a file 14 of 17)
 * =============================================================================
 */

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