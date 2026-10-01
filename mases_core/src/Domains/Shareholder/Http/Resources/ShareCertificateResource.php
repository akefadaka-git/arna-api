<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P3 · Unit 2 · File 5 of 13
 * =============================================================================
 *
 * File:            ShareCertificateResource.php
 * Unit:            P3 · Unit 2 — Ethiopian calendar date rendering
 * Delivery type:   Amendment
 * Net lines:       +1 import; +1 trait `use`; +5 fields; +~40 docblock lines
 *
 * Before:
 *   The resource emitted four ISO 8601 timestamps plus a fifth inside
 *   the `replaced_by` summary:
 *
 *     Certificate-level:  issued_at, cancelled_at, created_at, updated_at
 *     Replaced-by summary: issued_at
 *
 *   Every consumer that wanted to render one of those dates sliced the
 *   ISO string client-side.
 *
 * After:
 *   Five sibling fields are added. At the certificate level, immediately
 *   after their ISO counterparts:
 *
 *     'issued_at_display'    => $this->localizedDate($certificate->issued_at),
 *     'cancelled_at_display' => $this->localizedDate($certificate->cancelled_at),
 *     'created_at_display'   => $this->localizedDate($certificate->created_at),
 *     'updated_at_display'   => $this->localizedDate($certificate->updated_at),
 *
 *   Inside the `replaced_by` summary (see replacedBySummary()):
 *
 *     'issued_at_display'    => $this->localizedDate($replacement->issued_at),
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
 * Null handling — the two certificate-level nullable dates
 * =============================================================================
 * Two of the four certificate-level timestamps are nullable by schema:
 *
 *   · `issued_at` — nullable. A certificate in a hypothetical `draft`
 *     state has no issuance time. Phase 2A does not produce draft
 *     certificates through the service (`CertificateService::issue()`
 *     always sets `issued_at`), but the column permits null and the
 *     resource must not assume non-null.
 *
 *   · `cancelled_at` — nullable. Only cancelled certificates carry a
 *     value. Every other certificate emits `cancelled_at: null`.
 *
 *   · `created_at` and `updated_at` — non-null in practice, though the
 *     schema declares them nullable for Laravel's standard shape.
 *
 * The trait's `localizedDate()` method returns `null` for a null input,
 * so the `_display` sibling of a nullable ISO field is null for the same
 * rows. A client that branches on `cancelled_at !== null` sees the same
 * branch for `cancelled_at_display`; the two fields agree on which rows
 * carry a value. This is deliberate and is stated in the trait's own
 * docblock.
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
 * Public surface added:      four certificate-level fields
 *                            (issued_at_display, cancelled_at_display,
 *                             created_at_display, updated_at_display)
 *                            one replaced_by-summary field
 *                            (issued_at_display)
 * Public surface removed:    none
 * Public surface modified:   none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources;

use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\CertificateStatus;
use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareCertificate;

/**
 * ShareCertificateResource
 *
 * Transforms a ShareCertificate model into the JSON payload returned by
 * the certificate endpoints.
 *
 * =============================================================================
 * What this resource exposes
 * =============================================================================
 * The certificate's identity and lifecycle:
 *
 *   - The primary key and the three foreign keys that identify the
 *     certificate's anchor: shareholder, share class, and share holding.
 *   - The optional certificate number (nullable, manually assigned — see
 *     2A.15).
 *   - The lifecycle state as BOTH a machine-readable enum value
 *     (`status`) and a localized label (`status_label`).
 *   - Timestamps: `issued_at`, `cancelled_at`, plus `created_at` and
 *     `updated_at` — each as a raw ISO 8601 string AND as a localized
 *     display string. See §"Localized date fields" below.
 *   - The `replaced_by_id` link and, when eager-loaded, a summary of the
 *     replacing certificate.
 *   - Free-text `notes`, emitted verbatim.
 *   - Four boolean flags derived from the lifecycle: `is_active`,
 *     `is_terminal`, `is_draft`, `was_replaced`.
 *
 * And, when eager-loaded by the controller, minimal summaries of:
 *
 *   - the owning shareholder,
 *   - the share class,
 *   - the linked share holding,
 *   - the replacing certificate (for a certificate in `replaced` state).
 *
 * =============================================================================
 * Raw value vs localized label
 * =============================================================================
 * The `status` field emits the enum's backed value (`"issued"`,
 * `"cancelled"`, etc.). A client uses it for logic: filtering, branching,
 * and status comparisons.
 *
 * The `status_label` field emits the localized human-readable string.
 * It is resolved via `__('shareholder::certificate.status.{value}')` in
 * the active locale. A client uses it for direct display.
 *
 * Both are emitted. A consumer must not confuse them: matching on
 * `status_label` (a translated string) is a category error.
 *
 * If the translation key is missing, Laravel returns the key string
 * itself (e.g. `"shareholder::certificate.status.issued"`), which surfaces
 * the missing translation in the API response. CR-009 adds the actual
 * translations for the `am` and `en` locales.
 *
 * =============================================================================
 * Boolean flags
 * =============================================================================
 * Four booleans are computed from the certificate's `status`:
 *
 *   is_active    — true when status === 'issued'. The certificate is in
 *                  force.
 *   is_terminal  — true when status is 'cancelled' or 'replaced'. No
 *                  further transitions are possible.
 *   is_draft     — true when status === 'draft'. Reserved for a future
 *                  prepare-then-issue workflow.
 *   was_replaced — true when status === 'replaced' AND a `replaced_by_id`
 *                  is set. Distinguishes an actually-replaced certificate
 *                  from a hypothetical row with a `replaced` status but no
 *                  replacement link (a state the service prevents, but
 *                  that a manual DB edit could produce).
 *
 * The four flags are derived from the enum on each call; they do not
 * require database access.
 *
 * =============================================================================
 * Certificate number
 * =============================================================================
 * `certificate_number` is nullable. When the operator issues a certificate
 * without a number, the field is `null` in the payload. A client that
 * needs a non-null number must reject null locally — the resource does
 * not substitute a placeholder, because a fabricated value would be worse
 * than null.
 *
 * =============================================================================
 * No database queries
 * =============================================================================
 * Every relation is guarded by `whenLoaded()`. The resource does not query.
 * A controller that forgets to eager-load a relation simply receives a
 * payload without that summary — the resource does not silently issue a
 * query.
 *
 * The five new `_display` fields (four at the certificate level, one
 * inside the `replaced_by` summary) do not change this property. They
 * derive from the same Carbon instances the ISO fields already read; no
 * query, no lazy load, no additional accessor resolution.
 *
 * =============================================================================
 * Localized date fields (P3 · Unit 2)
 * =============================================================================
 * Every timestamp this resource emits — at the certificate level and
 * inside the `replaced_by` summary — is accompanied by a `_display`
 * sibling produced by LocalizationService::formatEthiopianDateMedium()
 * via the RendersLocalizedDates trait.
 *
 * Certificate level:
 *
 *   · `issued_at` / `cancelled_at` / `created_at` / `updated_at` — raw
 *     ISO 8601. Machine-readable, sortable, timezone-explicit. Preserved
 *     for clients that need the exact instant.
 *
 *   · `issued_at_display` / `cancelled_at_display` / `created_at_display`
 *     / `updated_at_display` — the localized human-readable forms.
 *     Rendered by the UI directly.
 *
 * Replaced-by summary (inside the `replaced_by` object):
 *
 *   · `issued_at` — raw ISO 8601.
 *   · `issued_at_display` — the localized form of the same timestamp.
 *
 * The localized forms reflect the current request's locale, which
 * LocalizationMiddleware resolves before any resource serializes. The
 * display strings for the same row can therefore differ between two
 * requests in different locales — a deliberate consequence of the
 * locale-driven rendering this unit introduces.
 *
 * The two nullable date fields (`issued_at`, `cancelled_at`) have
 * nullable `_display` siblings: the trait returns null for a null input.
 * A client can therefore branch on either form and see the same branch —
 * the two agree on which rows carry a value. See the file-level
 * change-verification block §"Null handling".
 *
 * A future CR that changes the display format changes one method in
 * LocalizationService and the change propagates to every resource that
 * uses this trait, without a resource-level edit.
 *
 * =============================================================================
 * Amharic-first
 * =============================================================================
 * The only user-facing string this resource produces beyond the values
 * stored on the row is `status_label`, resolved from a translation key,
 * and the five `_display` date strings introduced by this unit, which
 * follow the Amharic-first policy for the Ethiopian half — Amharic
 * month names for `am`, transliterated for `en` — and English month
 * names for the Gregorian parenthetical in both locales. All other
 * fields are values on the row or derived booleans. The embedded
 * summaries rely on the linked models' own Amharic-first accessors
 * (`full_name`, `display_name`).
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources
 * @version 1.1.0
 */
final class ShareCertificateResource extends JsonResource
{
    use RendersLocalizedDates;

    /**
     * Disable the resource-level wrapper.
     *
     * The canonical envelope is produced by the controller (2A.24).
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
        /** @var ShareCertificate $certificate */
        $certificate = $this->resource;

        $status = $certificate->status;

        return [
            // -----------------------------------------------------------------
            // Identity
            // -----------------------------------------------------------------
            'id'                 => $certificate->id,
            'shareholder_id'     => $certificate->shareholder_id,
            'share_class_id'     => $certificate->share_class_id,
            'share_holding_id'   => $certificate->share_holding_id,
            'certificate_number' => $certificate->certificate_number,

            // -----------------------------------------------------------------
            // Lifecycle — raw value and localized label.
            // -----------------------------------------------------------------
            'status'       => $status->value,
            'status_label' => $this->resolveStatusLabel($status),

            // -----------------------------------------------------------------
            // Derived lifecycle flags.
            // -----------------------------------------------------------------
            'is_active'    => $certificate->isActive(),
            'is_terminal'  => $certificate->isTerminal(),
            'is_draft'     => $certificate->isDraft(),
            'was_replaced' => $certificate->wasReplaced(),

            // -----------------------------------------------------------------
            // Replacement link.
            // -----------------------------------------------------------------
            'replaced_by_id' => $certificate->replaced_by_id,

            // -----------------------------------------------------------------
            // Notes.
            // -----------------------------------------------------------------
            'notes' => $certificate->notes,

            // -----------------------------------------------------------------
            // Optional embedded summaries — included only when eager-loaded.
            // -----------------------------------------------------------------
            'shareholder' => $this->whenLoaded(
                'shareholder',
                fn () => $this->shareholderSummary($certificate->shareholder),
            ),

            'share_class' => $this->whenLoaded(
                'shareClass',
                fn () => $this->shareClassSummary($certificate->shareClass),
            ),

            'share_holding' => $this->whenLoaded(
                'shareHolding',
                fn () => $this->shareHoldingSummary($certificate->shareHolding),
            ),

            'replaced_by' => $this->whenLoaded(
                'replacedBy',
                fn () => $this->replacedBySummary($certificate->replacedBy),
            ),

            // -----------------------------------------------------------------
            // Timestamps — ISO and localized display, paired.
            //
            // The two forms are derived from the same Carbon instance, so
            // they cannot disagree about which value the row carries. The
            // nullable ISO fields (issued_at, cancelled_at) have nullable
            // `_display` siblings — the trait returns null for null input.
            // See the class docblock §"Localized date fields".
            // -----------------------------------------------------------------
            'issued_at'            => $certificate->issued_at?->toIso8601String(),
            'issued_at_display'    => $this->localizedDate($certificate->issued_at),
            'cancelled_at'         => $certificate->cancelled_at?->toIso8601String(),
            'cancelled_at_display' => $this->localizedDate($certificate->cancelled_at),
            'created_at'           => $certificate->created_at?->toIso8601String(),
            'created_at_display'   => $this->localizedDate($certificate->created_at),
            'updated_at'           => $certificate->updated_at?->toIso8601String(),
            'updated_at_display'   => $this->localizedDate($certificate->updated_at),
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the localized label for a certificate status.
     *
     * @param CertificateStatus $status
     * @return string
     */
    private function resolveStatusLabel(CertificateStatus $status): string
    {
        return __('shareholder::certificate.status.' . $status->value);
    }

    /**
     * Compose the minimal summary of the owning shareholder.
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
     * Compose the minimal summary of the linked share holding.
     *
     * Exposes the current quantity as a decimal string; that is the value
     * the certificate attests to at the moment of the request. It is the
     * CURRENT quantity of the holding, which may have changed since the
     * certificate was issued — the certificate records what was certified
     * at issue time, not the current balance.
     *
     * @param ShareHolding|null $holding
     * @return array<string, mixed>|null
     */
    private function shareHoldingSummary($holding): ?array
    {
        if ($holding === null) {
            return null;
        }

        return [
            'id'               => $holding->id,
            'current_quantity' => $holding->quantityString(),
        ];
    }

    /**
     * Compose the minimal summary of the replacing certificate.
     *
     * Emitted only when the `replacedBy` relation is eager-loaded. The
     * summary is intentionally shallow — it identifies the replacement but
     * does not recurse into the replacement's own relations.
     *
     * P3 · Unit 2 adds a `issued_at_display` sibling to the summary's
     * `issued_at` field, following the same ISO-plus-display pairing
     * used at the certificate level.
     *
     * @param ShareCertificate|null $replacement
     * @return array<string, mixed>|null
     */
    private function replacedBySummary($replacement): ?array
    {
        if ($replacement === null) {
            return null;
        }

        return [
            'id'                 => $replacement->id,
            'certificate_number' => $replacement->certificate_number,
            'status'             => $replacement->status->value,
            'issued_at'          => $replacement->issued_at?->toIso8601String(),
            'issued_at_display'  => $this->localizedDate($replacement->issued_at),
        ];
    }
}