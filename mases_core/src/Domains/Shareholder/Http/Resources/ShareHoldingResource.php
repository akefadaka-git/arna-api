<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P3 · Unit 2 · File 4 of 13
 * =============================================================================
 *
 * File:            ShareHoldingResource.php
 * Unit:            P3 · Unit 2 — Ethiopian calendar date rendering
 * Delivery type:   Amendment
 * Net lines:       +1 import; +1 trait `use`; +4 fields; +~35 docblock lines
 *
 * Before:
 *   The resource emitted four ISO 8601 timestamps and nothing else:
 *
 *     Holding-level:  created_at, updated_at
 *     Per-entry:      occurred_at, created_at
 *
 *   Every consumer that wanted to render one of those dates sliced the
 *   ISO string client-side.
 *
 * After:
 *   Four sibling fields are added. At the holding level, immediately
 *   after their ISO counterparts:
 *
 *     'created_at_display' => $this->localizedDate($holding->created_at),
 *     'updated_at_display' => $this->localizedDate($holding->updated_at),
 *
 *   Inside each entry of the `entries` array (see entryToArray()):
 *
 *     'occurred_at_display' => $this->localizedDate($entry->occurred_at),
 *     'created_at_display'  => $this->localizedDate($entry->created_at),
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
 * Why the two entry-level fields sit where they do
 * =============================================================================
 * The entry map has a fixed shape declared in the class docblock
 * §"Reading the ledger entries" — see the source's own documentation.
 * The `occurred_at_display` field is placed immediately after
 * `occurred_at`, and `created_at_display` is placed immediately after
 * `created_at`. The pair-adjacent placement matches the pattern
 * established by ShareholderResource (file 3) and ShareCertificateResource
 * (file 5): ISO first, display second, adjacent.
 *
 * The two fields use the same names as the holding-level fields — the
 * `created_at_display` name appears at both levels. This is not a
 * collision: the entry-level field lives inside an entry object, and
 * the holding-level field lives at the root of the resource payload.
 * A client that destructures the response distinguishes them by path,
 * e.g. `response.created_at_display` vs. `response.entries[0].created_at_display`.
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
 * Public surface added:      two holding-level fields
 *                            (created_at_display, updated_at_display)
 *                            two entry-level fields
 *                            (occurred_at_display, created_at_display)
 * Public surface removed:    none
 * Public surface modified:   none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources;

use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHoldingEntry;

/**
 * ShareHoldingResource
 *
 * Transforms a ShareHolding model into the JSON payload returned by the
 * share-holding endpoints.
 *
 * =============================================================================
 * What this resource represents
 * =============================================================================
 * A ShareHolding is the current-state projection of a (shareholder,
 * share-class) pair: how many shares of a given class a given shareholder
 * currently holds. The resource therefore exposes:
 *
 *   - The primary key and the two foreign keys that identify the pair.
 *   - The current quantity as a DECIMAL(20,4) string.
 *   - Timestamps, each as a raw ISO 8601 string AND as a localized
 *     display string. See §"Localized date fields" below.
 *   - Optional summaries of the owning shareholder and the share class,
 *     included only when the controller eager-loaded them.
 *   - An optional list of ledger entries, included only when the
 *     controller eager-loaded them. Each entry's two timestamps are also
 *     emitted as ISO + display pairs.
 *
 * The ledger entries are what makes this resource distinctive: a client
 * that fetches a holding can, in the same response, receive the history
 * of every balance-changing event that produced its current quantity.
 * The `ShareHoldingController::show()` and `store()` methods (2A.23) both
 * load this relation, so the entries are normally present on those paths;
 * `index()` does not load entries, so list responses omit them.
 *
 * =============================================================================
 * Numeric precision
 * =============================================================================
 * `current_quantity` and the entry `quantity` / `balance_before` /
 * `balance_after` fields are emitted as STRINGS. This is deliberate:
 *
 *   - The database column is DECIMAL(20,4). PHP floats cannot represent
 *     all decimal fractions exactly.
 *   - Emitting the value as a JSON number would force the client to parse
 *     it into a float (in most languages), losing precision at scale.
 *   - Emitting it as a string preserves the exact value; a client that
 *     needs arithmetic uses a decimal library on the string, and a client
 *     that needs to display it formats the string for the locale.
 *
 * The same policy applies to the `par_value` field on the share-class
 * resource (2A.26) and to any future monetary field.
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * This resource performs no queries of its own. Every relation it exposes
 * is guarded by `whenLoaded()`, so a controller that forgets to eager-load
 * a relation simply does not receive it in the payload — the resource does
 * not silently issue a query behind the caller's back.
 *
 * The one exception is the entry's `reversals` relation, used to compute
 * the `is_reversed` flag on each entry. See F-2A27-3: this flag defaults
 * to `false` when the relation is not loaded, and the resource does NOT
 * query it. Controllers that need an accurate `is_reversed` flag must
 * eager-load `entries.reversals`.
 *
 * The four new `_display` fields (two at the holding level, two per entry)
 * do not change this property. They derive from the same Carbon instances
 * the ISO fields already read; no query, no lazy load, no additional
 * accessor resolution.
 *
 * =============================================================================
 * Localized date fields (P3 · Unit 2)
 * =============================================================================
 * Every timestamp this resource emits — at the holding level and inside
 * each entry — is accompanied by a `_display` sibling produced by
 * LocalizationService::formatEthiopianDateMedium() via the
 * RendersLocalizedDates trait.
 *
 * Holding level:
 *
 *   · `created_at` / `updated_at` — raw ISO 8601. Machine-readable,
 *     sortable, timezone-explicit. Preserved for clients that need the
 *     exact instant.
 *
 *   · `created_at_display` / `updated_at_display` — the localized
 *     human-readable form. Rendered by the UI directly.
 *
 * Entry level (inside each object of the `entries` array):
 *
 *   · `occurred_at` / `created_at` — raw ISO 8601. `occurred_at` is the
 *     business time of the ledger event; `created_at` is the row's
 *     insert time (written explicitly by the service because the model
 *     sets `$timestamps = false`).
 *
 *   · `occurred_at_display` / `created_at_display` — the localized forms
 *     of the same two timestamps.
 *
 * The localized forms reflect the current request's locale, which
 * LocalizationMiddleware resolves before any resource serializes. The
 * display strings for the same row can therefore differ between two
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
 * The resource emits no user-facing labels. The embedded `shareholder` and
 * `share_class` summaries pull their `full_name` / `display_name` from
 * their respective models, both of which already implement the Amharic-
 * first resolution policy (2A.7, 2A.6). The entry transform emits no text
 * beyond the values stored on the row, plus the two `_display` date
 * fields introduced by this unit, which follow the same Amharic-first
 * policy for the Ethiopian half — Amharic month names for `am`,
 * transliterated for `en` — and English month names for the Gregorian
 * parenthetical in both locales.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources
 * @version 1.1.0
 */
final class ShareHoldingResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the resource-level wrapper.
     *
     * The canonical envelope is produced by the controller (2A.23).
     * Setting `$wrap = null` prevents the resource from adding its own
     * `data` wrapper inside the controller's envelope.
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
        /** @var ShareHolding $holding */
        $holding = $this->resource;

        return [
            // -----------------------------------------------------------------
            // Identity
            // -----------------------------------------------------------------
            'id'               => $holding->id,
            'shareholder_id'   => $holding->shareholder_id,
            'share_class_id'   => $holding->share_class_id,

            // -----------------------------------------------------------------
            // Current quantity — decimal string, not a number.
            // -----------------------------------------------------------------
            'current_quantity' => $holding->quantityString(),

            // -----------------------------------------------------------------
            // Convenience flags — read-only helpers on the model (2A.8).
            // -----------------------------------------------------------------
            'is_positive'      => $holding->hasPositiveQuantity(),
            'is_zero'          => $holding->isZeroQuantity(),

            // -----------------------------------------------------------------
            // Optional embedded summaries — included only when eager-loaded.
            // -----------------------------------------------------------------
            'shareholder'      => $this->whenLoaded(
                'shareholder',
                fn () => $this->shareholderSummary($holding->shareholder),
            ),

            'share_class'      => $this->whenLoaded(
                'shareClass',
                fn () => $this->shareClassSummary($holding->shareClass),
            ),

            // -----------------------------------------------------------------
            // Optional embedded ledger entries — included only when
            // eager-loaded. The controller's show() and store() methods
            // load this relation; the index() method does not.
            // -----------------------------------------------------------------
            'entries'          => $this->whenLoaded(
                'entries',
                fn () => $this->entriesToArray($holding),
            ),

            // -----------------------------------------------------------------
            // Timestamps — ISO and localized display, paired.
            //
            // The two forms are derived from the same Carbon instance, so
            // they cannot disagree about which value the row carries. See
            // the class docblock §"Localized date fields".
            // -----------------------------------------------------------------
            'created_at'         => $holding->created_at?->toIso8601String(),
            'created_at_display' => $this->localizedDate($holding->created_at),
            'updated_at'         => $holding->updated_at?->toIso8601String(),
            'updated_at_display' => $this->localizedDate($holding->updated_at),
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================
    /**
     * Compose the minimal summary of the owning shareholder.
     *
     * Exposes only the fields a consumer needs to identify the holder in a
     * holding payload: id, the composed names, and the type. It does not
     * recurse into the shareholder's own relations (users, holdings,
     * certificates).
     *
     * @param Shareholder|null $shareholder
     * @return array<string, mixed>|null
     */
    private function shareholderSummary($shareholder): ?array
    {
        if ($shareholder === null) {
            return null;
        }

        return [
            'id'               => $shareholder->id,
            'shareholder_type' => $shareholder->shareholder_type,
            'full_name'        => $shareholder->full_name,
            'name'             => $shareholder->name,
            'display_name'     => $shareholder->display_name,
        ];
    }

    /**
     * Compose the minimal summary of the share class.
     *
     * Exposes the canonical code and both language names. It does not
     * expose par value, currency, or flags — those belong to the
     * share-class resource (2A.26) and can be fetched from
     * /api/share-classes when needed.
     *
     * @param ShareClass|null $shareClass
     * @return array<string, mixed>|null
     */
    private function shareClassSummary($shareClass): ?array
    {
        if ($shareClass === null) {
            return null;
        }

        return [
            'id'           => $shareClass->id,
            'code'         => $shareClass->code,
            'name_am'      => $shareClass->name_am,
            'name_en'      => $shareClass->name_en,
            'display_name' => $shareClass->display_name,
        ];
    }

    /**
     * Transform the holding's entries into an array.
     *
     * The entries collection is whatever the controller loaded. This
     * method maps each entry to a fixed-shape array; it does not query,
     * sort, or filter.
     *
     * @param ShareHolding $holding
     * @return array<int, array<string, mixed>>
     */
    private function entriesToArray(ShareHolding $holding): array
    {
        $entries = $holding->entries;

        if ($entries === null) {
            return [];
        }

        $result = [];

        foreach ($entries as $entry) {
            /** @var ShareHoldingEntry $entry */
            $result[] = $this->entryToArray($entry);
        }

        return $result;
    }

    /**
     * Transform a single ShareHoldingEntry into an array.
     *
     * The shape is deliberately stable: adding a field is backward
     * compatible, removing one is not. The fields mirror the columns on
     * the `share_holding_entries` table (D16-X1) plus two derived values:
     *
     *   `direction`  — the enumerated direction (in / out / neutral),
     *                  derived from the sign of `quantity`.
     *   `is_reversed`— whether another entry points at this one via
     *                  `reversed_entry_id`. This flag is `false` unless
     *                  the `reversals` relation was eager-loaded on this
     *                  entry (see F-2A27-3).
     *
     * Two `_display` siblings are added by P3 · Unit 2 — one for
     * `occurred_at` and one for `created_at` — each produced by the
     * RendersLocalizedDates trait's `localizedDate()` method. The two
     * follow their ISO counterparts immediately, matching the pair-
     * adjacent pattern used by the holding-level timestamps and by the
     * other resources in this unit.
     *
     * @param ShareHoldingEntry $entry
     * @return array<string, mixed>
     */
    private function entryToArray(ShareHoldingEntry $entry): array
    {
        return [
            'id'               => $entry->id,
            'entry_type'       => $entry->entry_type,
            'direction'        => $entry->direction()->value,
            'quantity'         => (string) $entry->quantity,
            'balance_before'   => (string) $entry->balance_before,
            'balance_after'    => (string) $entry->balance_after,
            'reference_type'   => $entry->reference_type,
            'reference_id'     => $entry->reference_id,
            'description'      => $entry->description,

            // ISO + display, paired. See the class docblock
            // §"Localized date fields".
            'occurred_at'         => $entry->occurred_at?->toIso8601String(),
            'occurred_at_display' => $this->localizedDate($entry->occurred_at),
            'created_at'          => $entry->created_at?->toIso8601String(),
            'created_at_display'  => $this->localizedDate($entry->created_at),

            'created_by'       => $entry->created_by,
            'reversed_entry_id' => $entry->reversed_entry_id,
            'is_reversal'      => $entry->isReversal(),
            'is_reversed'      => $entry->isReversed(),
        ];
    }
}