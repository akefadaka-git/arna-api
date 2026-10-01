<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P3 · Unit 2 · File 3 of 13
 * =============================================================================
 *
 * File:            ShareholderResource.php
 * Unit:            P3 · Unit 2 — Ethiopian calendar date rendering
 * Delivery type:   Amendment
 * Net lines:       +1 import; +1 trait `use`; +2 fields; +~30 docblock lines
 *
 * Before:
 *   The resource emitted its two timestamps as raw ISO 8601 strings:
 *
 *     'created_at' => $shareholder->created_at?->toIso8601String(),
 *     'updated_at' => $shareholder->updated_at?->toIso8601String(),
 *
 *   A portal or workspace page that wanted to render either date had
 *   to slice the ISO string client-side — the pattern the portal pages
 *   used via their local `formatDate()` helper.
 *
 * After:
 *   Two sibling fields are added, immediately after their ISO
 *   counterparts:
 *
 *     'created_at_display' => $this->localizedDate($shareholder->created_at),
 *     'updated_at_display' => $this->localizedDate($shareholder->updated_at),
 *
 *   The trait `RendersLocalizedDates` (file 2 of this unit) provides
 *   `localizedDate()`, which delegates to
 *   LocalizationService::formatEthiopianDateMedium() (file 1) and
 *   produces the locale-appropriate medium form:
 *
 *     am:  ሐምሌ 9, 2018 ዓ.ም. (July 16, 2026)
 *     en:  Hamle 9, 2018 E.C. (July 16, 2026)
 *
 *   Every existing field is preserved byte-for-byte — same names, same
 *   order, same values. The change is strictly additive.
 *
 * =============================================================================
 * Why the ISO field is kept
 * =============================================================================
 * The `_display` field is a presentation aid, not a replacement. The
 * ISO field remains the wire's source of truth:
 *
 *   · It is machine-readable (a client can parse it directly, without
 *     reverse-engineering a display string).
 *   · It is sortable (ISO 8601 in UTC is lexicographically sortable).
 *   · It carries timezone information (the display string does not).
 *
 * A client that needs the exact instant reads `created_at`. A client
 * that renders it for a human reads `created_at_display`. The two are
 * derived from the same Carbon instance on the server, so they cannot
 * disagree about which row carried which value.
 *
 * =============================================================================
 * Deployment note — autoloader refresh required
 * =============================================================================
 * This file imports the trait `RendersLocalizedDates` (file 2 of this
 * unit), which is a new class. Before this file can load, the composer
 * class map must be regenerated:
 *
 *     composer dump-autoload     (or the equivalent Plesk Composer action)
 *
 * Without the refresh, the class map does not include the trait and
 * every request that serializes a ShareholderResource will fail with a
 * class-not-found error. Upload files 1–3 in the same batch, refresh
 * the autoloader once, then deploy groups B–E.
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Public surface added:      two resource fields (created_at_display,
 *                            updated_at_display)
 * Public surface removed:    none
 * Public surface modified:   none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources;

use MaaleArna\MASES\Core\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\ShareholderType;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * ShareholderResource
 *
 * Transforms a Shareholder model into the JSON payload returned by the
 * shareholder endpoints.
 *
 * =============================================================================
 * What this resource exposes
 * =============================================================================
 * The shareholder's IDENTITY, as defined by decision D8:
 *
 *   - The primary key and tenant id.
 *   - The shareholder type (D9 vocabulary).
 *   - The six bilingual individual-name columns (null for legal entities).
 *   - The two bilingual entity-name columns (null for individuals).
 *   - The direct user link (nullable, individual-only) and the
 *     representative user link (nullable in schema, mandatory for legal
 *     entities at the service layer per D11).
 *   - The active flag.
 *   - Timestamps, each as a raw ISO 8601 string AND as a localized
 *     display string. See §"Localized date fields" below.
 *
 * And three computed fields derived from the above:
 *
 *   - full_name             — Amharic-first composed name.
 *   - name                  — alias of full_name (mirrors User::getNameAttribute).
 *   - display_name          — locale-aware composed name.
 *   - type_label            — localized label for the shareholder type.
 *   - status_label          — localized label for the active flag.
 *   - requires_representative — boolean derived from the type (D11).
 *
 * =============================================================================
 * What this resource deliberately does NOT expose
 * =============================================================================
 *   - KYC data. The D12 field matrix is pending. Every field a KYC reviewer
 *     might expect (national_id, passport_number, tin, date_of_birth,
 *     gender, address, id_document, photo, ...) is absent. Adding any of
 *     them requires the D12 field matrix decision and a CR.
 *
 *   - The tenant object. The `tenant_id` scalar is present; the tenant
 *     itself is a stable reference and does not belong in a shareholder
 *     payload. If a future UI needs it, a CR adds conditional inclusion.
 *
 *   - Share holdings and certificates. Those are separate resources with
 *     their own endpoints. Expanding them here would impose an N+1 cost
 *     on every shareholder list query. If a future client needs an
 *     opt-in expansion, a CR adds a `?with=holdings` query parameter and
 *     the corresponding `whenLoaded` block.
 *
 *   - User password, tokens, roles, permissions. The linked `user` and
 *     `representative` sub-objects expose only `id`, `name`, `email`,
 *     `locale` — enough for display. Full user details belong to a user
 *     endpoint, not to a shareholder payload.
 *
 *   - Soft-delete metadata. If a shareholder is soft-deleted, the
 *     standard query filters it out and this resource is never invoked
 *     for it. A `deleted_at` field would be misleading.
 *
 * =============================================================================
 * Localized date fields (P3 · Unit 2)
 * =============================================================================
 * Every timestamp this resource emits is accompanied by a `_display`
 * sibling — a locale-appropriate medium-form string produced by
 * LocalizationService::formatEthiopianDateMedium() via the
 * RendersLocalizedDates trait.
 *
 * The two forms serve different consumers:
 *
 *   · `created_at` / `updated_at` — the raw ISO 8601 value. Machine-
 *     readable, sortable, timezone-explicit. Preserved for clients that
 *     need the exact instant.
 *
 *   · `created_at_display` / `updated_at_display` — the localized
 *     human-readable form. Amharic month names for `am`, transliterated
 *     Latin for `en`, with the Gregorian equivalent in parentheses.
 *     Rendered by the UI directly.
 *
 * The localized form reflects the current request's locale, which
 * LocalizationMiddleware resolves before any resource serializes. The
 * display string for the same row can therefore differ between two
 * requests in different locales — a deliberate consequence of the
 * locale-driven rendering this unit introduces.
 *
 * A future CR that needs a different format (a short form, a
 * weekday-inclusive form, a different era suffix) changes one method in
 * LocalizationService and the change propagates to every resource that
 * uses this trait, without a resource-level edit.
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * This resource performs no queries of its own. Every relation it exposes
 * is guarded by `whenLoaded()`, which returns the relation only if the
 * caller eager-loaded it. A controller that forgets to eager-load does not
 * produce an N+1 hidden inside the resource — it simply omits the relation
 * from the payload.
 *
 * This rule is deliberately strict. A resource that lazily fetches
 * relations is the single most common source of hidden N+1 behaviour in
 * Laravel APIs. Keeping the resource pure makes the query cost of every
 * endpoint visible from the controller.
 *
 * The two new `_display` fields do not change this property. They derive
 * from the same Carbon instance the ISO fields already read; no query,
 * no lazy load, no additional accessor resolution.
 *
 * =============================================================================
 * Amharic-first
 * =============================================================================
 * The composed name fields (`full_name`, `name`, `display_name`) follow
 * the Amharic-first policy:
 *
 *   - For each name component (first / father / grandfather / entity), the
 *     Amharic column is preferred when both the component and the active
 *     locale permit it.
 *   - `full_name` uses Amharic-first per-component, regardless of locale.
 *   - `display_name` honours the active locale: `am` selects Amharic,
 *     anything else selects English, with per-component fallback.
 *
 * The JSON payload orders the two name objects and their fields so that
 * `_am` precedes `_en`, mirroring the column order in the migration and
 * the `$fillable` array on the model.
 *
 * The `type_label` and `status_label` fields are resolved through the
 * translation system. No English (or Amharic) string is hard-coded in
 * this file. A missing translation key returns the key string itself,
 * which surfaces the gap in the response and in the log.
 *
 * The two `_display` date fields follow the same Amharic-first policy
 * for the Ethiopian half — Amharic month names for `am`, transliterated
 * for `en` — and use English month names for the Gregorian parenthetical
 * in both locales. See LocalizationService::formatEthiopianDateMedium()
 * for the reasoning.
 *
 * =============================================================================
 * The `name` alias
 * =============================================================================
 * `name` is included as a sibling of `full_name`. It carries the same
 * value. The alias exists because MASES identity models (User,
 * Shareholder) uniformly expose a `name` attribute for consumers that
 * iterate polymorphically over identity resources. See User::getNameAttribute
 * (Phase 1 Amendment A4) for the precedent.
 *
 * If a consumer needs to distinguish "the shareholder's composed full
 * name" from "the generic identity name", it should read `full_name` and
 * treat `name` as a compatibility alias.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources
 * @version 1.1.0
 */
final class ShareholderResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the resource's wrapping behaviour.
     *
     * The canonical envelope `{ success, data }` is produced by the
     * controller. If the resource wrapped its output in an additional
     * `data` key, the response would become `{ success, data: { data: ... } }`.
     * Setting `$wrap = null` suppresses the resource-level wrapper so the
     * controller's envelope is the only one.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Shareholder $shareholder */
        $shareholder = $this->resource;

        $type = $shareholder->type();

        return [
            // -----------------------------------------------------------------
            // Identity
            // -----------------------------------------------------------------
            'id'                 => $shareholder->id,
            'tenant_id'          => $shareholder->tenant_id,
            'shareholder_type'   => $shareholder->shareholder_type,

            // -----------------------------------------------------------------
            // Names — Amharic-first per-component composition, then aliases.
            // -----------------------------------------------------------------
            'full_name'          => $shareholder->full_name,
            'name'               => $shareholder->name,
            'display_name'       => $shareholder->display_name,

            // -----------------------------------------------------------------
            // Structured names — Amharic column before English column.
            // Populated only for the appropriate shareholder type; the
            // other type's block is null.
            // -----------------------------------------------------------------
            'individual_name'    => [
                'first_name_am'       => $shareholder->first_name_am,
                'first_name_en'       => $shareholder->first_name_en,
                'father_name_am'      => $shareholder->father_name_am,
                'father_name_en'      => $shareholder->father_name_en,
                'grandfather_name_am' => $shareholder->grandfather_name_am,
                'grandfather_name_en' => $shareholder->grandfather_name_en,
            ],

            'entity_name'        => [
                'entity_name_am'      => $shareholder->entity_name_am,
                'entity_name_en'      => $shareholder->entity_name_en,
            ],

            // -----------------------------------------------------------------
            // User links (D11)
            // -----------------------------------------------------------------
            'user_id'                => $shareholder->user_id,
            'representative_user_id' => $shareholder->representative_user_id,
            'requires_representative' => $type?->requiresRepresentative() ?? false,

            // -----------------------------------------------------------------
            // Active flag and localized label.
            // -----------------------------------------------------------------
            'is_active'    => $shareholder->is_active,
            'status_label' => $this->resolveStatusLabel($shareholder),

            // -----------------------------------------------------------------
            // Localized type label.
            // -----------------------------------------------------------------
            'type_label'   => $this->resolveTypeLabel($type),

            // -----------------------------------------------------------------
            // Optional relations — included only when eager-loaded.
            // -----------------------------------------------------------------
            'user'           => $this->whenLoaded('user', fn () => $this->userSummary($shareholder->user)),
            'representative' => $this->whenLoaded('representative', fn () => $this->userSummary($shareholder->representative)),

            // -----------------------------------------------------------------
            // Timestamps — ISO and localized display, paired.
            //
            // The two forms are derived from the same Carbon instance, so
            // they cannot disagree about which value the row carries. See
            // the class docblock §"Localized date fields".
            // -----------------------------------------------------------------
            'created_at'         => $shareholder->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($shareholder->created_at),
            'updated_at'         => $shareholder->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($shareholder->updated_at),
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the localized label for a shareholder type.
     *
     * Returns the raw value when the enum case cannot be resolved (a row
     * whose `shareholder_type` holds an unrecognised value). This keeps a
     * corrupted row diagnosable from the API without throwing.
     *
     * @param ShareholderType|null $type
     * @return string
     */
    private function resolveTypeLabel(?ShareholderType $type): string
    {
        if ($type === null) {
            return (string) ($this->resource->shareholder_type ?? '');
        }

        return __('shareholder::type.' . $type->value);
    }

    /**
     * Resolve the localized label for the active flag.
     *
     * @param Shareholder $shareholder
     * @return string
     */
    private function resolveStatusLabel(Shareholder $shareholder): string
    {
        return $shareholder->is_active
            ? __('shareholder::status.active')
            : __('shareholder::status.inactive');
    }

    /**
     * Compose a minimal summary of a User model for display purposes.
     *
     * Returns null when the input is null (an unlinked user or an
     * unloaded relation). The summary exposes exactly four fields — enough
     * to render an attribution line, no more.
     *
     * @param User|null $user
     * @return array<string, mixed>|null
     */
    private function userSummary($user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id'     => $user->id,
            'name'   => $user->name,
            'email'  => $user->email,
            'locale' => $user->locale,
        ];
    }
}