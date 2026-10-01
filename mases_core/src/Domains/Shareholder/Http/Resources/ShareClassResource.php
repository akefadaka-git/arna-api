<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P3 · Unit 2 · File 6 of 13
 * =============================================================================
 *
 * File:            ShareClassResource.php
 * Unit:            P3 · Unit 2 — Ethiopian calendar date rendering
 * Delivery type:   Amendment
 * Net lines:       +1 import; +1 trait `use`; +2 fields; +~30 docblock lines
 *
 * Before:
 *   The resource emitted its two timestamps as raw ISO 8601 strings:
 *
 *     'created_at' => $shareClass->created_at?->toIso8601String(),
 *     'updated_at' => $shareClass->updated_at?->toIso8601String(),
 *
 *   A page that wanted to render either date had to slice the ISO string
 *   client-side.
 *
 * After:
 *   Two sibling fields are added, immediately after their ISO
 *   counterparts:
 *
 *     'created_at_display' => $this->localizedDate($shareClass->created_at),
 *     'updated_at_display' => $this->localizedDate($shareClass->updated_at),
 *
 *   The trait `RendersLocalizedDates` (file 2 of this unit) provides
 *   `localizedDate()`, which delegates to
 *   LocalizationService::formatEthiopianDateMedium() (file 1) and
 *   produces the locale-appropriate medium form:
 *
 *     am:  ሐምሌ 9, 2018 ዓ.ም. (July 16, 2026)
 *     en:  Hamle 9, 2018 E.C. (July 16, 2026)
 *
 *   Every existing field is preserved byte-for-byte. The change is
 *   strictly additive.
 *
 * =============================================================================
 * Group B completion
 * =============================================================================
 * This file is the fourth and final resource in Group B of P3 · Unit 2.
 * After it deploys, the four Shareholder-domain resources that carry
 * date fields all emit `_display` siblings:
 *
 *     ShareholderResource         (file 3) — created_at, updated_at
 *     ShareHoldingResource        (file 4) — holding: created_at, updated_at
 *                                            entry: occurred_at, created_at
 *     ShareCertificateResource    (file 5) — issued_at, cancelled_at,
 *                                            created_at, updated_at
 *                                            replaced_by: issued_at
 *     ShareClassResource          (this file) — created_at, updated_at
 *
 * Any Shareholder-domain resource added in a future CR that carries a
 * date field should mix in the same trait and follow the same pattern.
 *
 * =============================================================================
 * Deployment note — autoloader refresh required (unchanged from file 3)
 * =============================================================================
 * This file imports the trait `RendersLocalizedDates`. If the composer
 * class map has not been regenerated since file 2 was uploaded, this
 * resource will fail to load. See file 3's change-verification block
 * for the deployment sequence; the same sequence applies here.
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Public surface added:      two resource fields
 *                            (created_at_display, updated_at_display)
 * Public surface removed:    none
 * Public surface modified:   none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;

/**
 * ShareClassResource
 *
 * Transforms a ShareClass model into the JSON payload returned by the
 * share-class endpoints.
 *
 * =============================================================================
 * What this resource exposes
 * =============================================================================
 * The canonical identity of a share class (D3):
 *
 *   - The primary key and the immutable `code` (FOUNDERS, STANDARD, ...).
 *   - The bilingual names, Amharic first (`name_am`, then `name_en`).
 *   - A locale-aware `display_name` (Amharic if the locale is `am`,
 *     English otherwise, with per-language fallback and `code` as the
 *     ultimate fallback).
 *   - The par value as a DECIMAL(20,4) string, and the currency code.
 *   - The `is_active` and `is_treasury_eligible` flags.
 *   - Two derived booleans:
 *       `is_founder` — true for the class identified by
 *                      `config('shareholder.share_classes.founder_code')`.
 *       `is_default` — true for the class identified by
 *                      `config('shareholder.share_classes.default_code')`.
 *   - Timestamps, each as a raw ISO 8601 string AND as a localized
 *     display string. See §"Localized date fields" below.
 *
 * The two derived booleans exist so that a client does not need to know
 * the config vocabulary to identify which class is the founder class and
 * which is the default. A UI can render "Founders' shares" specially
 * without hard-coding the string `"FOUNDERS"`.
 *
 * =============================================================================
 * What this resource deliberately does NOT expose
 * =============================================================================
 *   - Related holdings or certificates. Those are separate resources with
 *     their own endpoints. Expanding them here would impose an N+1 cost
 *     on every list query and would duplicate the /api/share-holdings and
 *     /api/share-certificates endpoints.
 *
 *   - Counts. `share_holdings_count` and `share_certificates_count` would
 *     require aggregate queries the resource must not perform. If a future
 *     UI needs them, a CR adds an opt-in query parameter and a conditional
 *     `whenCounted` block.
 *
 *   - Display labels for the booleans. `is_active` and
 *     `is_treasury_eligible` are emitted as booleans. A localized label
 *     ("Active" / "Inactive") is a client-side rendering concern and can
 *     use the same `shareholder::status.*` keys that
 *     ShareholderResource already relies on.
 *
 *   - A composed "par value with currency" string. The two fields are
 *     emitted separately; the client formats them together when needed.
 *     Combining them here would force one presentation choice on every
 *     consumer.
 *
 *   - The `deleted_at` timestamp. Soft-deleted classes are filtered out
 *     by the default query scope on the model. Emitting `deleted_at` for
 *     a non-deleted row would be `null` noise; emitting it for a
 *     soft-deleted row would never occur because the row would not reach
 *     the resource.
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * This resource performs no queries. The model's relations
 * (`shareHoldings`, `shareCertificates`) are not touched. Every field the
 * resource reads is a column on the `share_classes` row.
 *
 * The two new `_display` fields do not change this property. They derive
 * from the same Carbon instances the ISO fields already read; no query,
 * no lazy load, no additional accessor resolution.
 *
 * =============================================================================
 * Localized date fields (P3 · Unit 2)
 * =============================================================================
 * Every timestamp this resource emits is accompanied by a `_display`
 * sibling produced by LocalizationService::formatEthiopianDateMedium()
 * via the RendersLocalizedDates trait.
 *
 *   · `created_at` / `updated_at` — the raw ISO 8601 values. Machine-
 *     readable, sortable, timezone-explicit. Preserved for clients that
 *     need the exact instant.
 *
 *   · `created_at_display` / `updated_at_display` — the localized
 *     human-readable forms. Amharic month names for `am`, transliterated
 *     Latin for `en`, with the Gregorian equivalent in parentheses.
 *     Rendered by the UI directly.
 *
 * The localized forms reflect the current request's locale, which
 * LocalizationMiddleware resolves before any resource serializes. The
 * display string for the same row can therefore differ between two
 * requests in different locales — a deliberate consequence of the
 * locale-driven rendering this unit introduces.
 *
 * A future CR that changes the display format changes one method in
 * LocalizationService and the change propagates to every resource that
 * uses this trait, without a resource-level edit.
 *
 * =============================================================================
 * Amharic-first
 * =============================================================================
 * The JSON payload orders `name_am` before `name_en` to mirror the column
 * order declared by migration 2A.37 and by the model's `$fillable` array
 * (2A.6). The `display_name` field carries the locale-appropriate value,
 * resolved by the model's accessor (`ShareClass::getDisplayNameAttribute`,
 * 2A.6) which reads `name_am` when the active locale is `am`, `name_en`
 * otherwise, and falls back to the canonical `code` when both name
 * columns are empty.
 *
 * The two `_display` date fields follow the same Amharic-first policy
 * for the Ethiopian half — Amharic month names for `am`, transliterated
 * for `en` — and use English month names for the Gregorian
 * parenthetical in both locales. See
 * LocalizationService::formatEthiopianDateMedium() for the reasoning.
 *
 * No user-facing string is hard-coded in this file.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources
 * @version 1.1.0
 */
final class ShareClassResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the resource-level wrapper.
     *
     * The canonical envelope `{ success, data }` is produced by the
     * controller (2A.22). Setting `$wrap = null` suppresses the resource's
     * own `data` wrapper so the controller's envelope is the only one
     * applied.
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
        /** @var ShareClass $shareClass */
        $shareClass = $this->resource;

        return [
            // -----------------------------------------------------------------
            // Identity
            // -----------------------------------------------------------------
            'id'    => $shareClass->id,
            'code'  => $shareClass->code,

            // -----------------------------------------------------------------
            // Names — Amharic first, then English.
            // -----------------------------------------------------------------
            'name_am'      => $shareClass->name_am,
            'name_en'      => $shareClass->name_en,
            'display_name' => $shareClass->display_name,

            // -----------------------------------------------------------------
            // Par value and currency
            // -----------------------------------------------------------------
            'par_value' => $shareClass->par_value,
            'currency'  => $shareClass->currency,

            // -----------------------------------------------------------------
            // Flags
            // -----------------------------------------------------------------
            'is_active'            => $shareClass->is_active,
            'is_treasury_eligible' => $shareClass->is_treasury_eligible,

            // -----------------------------------------------------------------
            // Derived classification flags
            // -----------------------------------------------------------------
            'is_founder' => $shareClass->isFounderClass(),
            'is_default' => $shareClass->isDefaultClass(),

            // -----------------------------------------------------------------
            // Timestamps — ISO and localized display, paired.
            //
            // The two forms are derived from the same Carbon instance, so
            // they cannot disagree about which value the row carries. See
            // the class docblock §"Localized date fields".
            // -----------------------------------------------------------------
            'created_at'         => $shareClass->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($shareClass->created_at),
            'updated_at'         => $shareClass->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($shareClass->updated_at),
        ];
    }
}