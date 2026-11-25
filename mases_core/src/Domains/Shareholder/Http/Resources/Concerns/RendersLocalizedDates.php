<?php

declare(strict_types=1);



namespace MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns;

use DateTimeInterface;
use MaaleArna\MASES\Core\Localization\Services\LocalizationService;

/**
 * RendersLocalizedDates
 *
 * A reusable helper for Shareholder-domain resources that emit date
 * fields. Provides a single protected method that converts a nullable
 * DateTimeInterface into the localised medium-form date string.
 *
 * =============================================================================
 * Purpose
 * =============================================================================
 * Every resource in the Shareholder domain that carries a timestamp
 * emits the raw ISO 8601 value — created_at, updated_at, issued_at,
 * occurred_at, cancelled_at. Those ISO values are the wire's source of
 * truth: they are machine-readable, sortable, and directly comparable
 * across clients. They are also unsuitable for direct display: a
 * shareholder reading "2026-07-16T00:00:00+03:00" cannot readily
 * translate it to the Ethiopian date the platform's readers expect.
 *
 * This trait bridges that gap. A resource that mixes it in gains a
 * `localizedDate()` method, and the resource emits a `_display` sibling
 * for every ISO date field it already carries:
 *
 *     'created_at'         => $shareholder->created_at?->toIso8601String(),
 *     'created_at_display' => $this->localizedDate($shareholder->created_at),
 *
 * The ISO field is preserved for machine consumers; the `_display`
 * field is provided for the human reader. Neither is derived from the
 * other at the client — the server produces both, from the same
 * underlying Carbon instance, so they cannot disagree.
 *
 * =============================================================================
 * Locale and timezone
 * =============================================================================
 * Both concerns are handled by LocalizationService:
 *
 *   · Locale. The service resolves the current request's locale from
 *     the authenticated user's preference, the session, the cookie, the
 *     Accept-Language header, or the Amharic-first default. The
 *     LocalizationMiddleware applies this resolution before any
 *     resource serializes. The trait therefore does not need to accept
 *     a locale.
 *
 *   · Timezone. The service's EthiopianCalendar carries the display
 *     timezone — `Africa/Addis_Ababa` unless a deployment overrides
 *     `mases.localization.timezone`. Both the Ethiopian part and the
 *     Gregorian parenthetical are derived from the same localized
 *     instant, so the two parts of the display string always describe
 *     the same calendar day. See LocalizationService::formatEthiopianDateMedium()
 *     for the derivation.
 *
 * =============================================================================
 * Where this trait is used
 * =============================================================================
 * Group B of P3 · Unit 2 mixes the trait into four resources:
 *
 *     ShareholderResource
 *     ShareHoldingResource
 *     ShareCertificateResource
 *     ShareClassResource
 *
 * Each emits a `_display` sibling for every date field it currently
 * carries. The frontend consumes those `_display` fields directly,
 * replacing its local `formatDate()` ISO-slice helper. See the unit's
 * scoping document for the full file list.
 *
 * The trait is placed in the Shareholder domain rather than in Core
 * because its four current consumers live there. If a future CR
 * demonstrates a need in another domain's resources, the trait can be
 * relocated to Core with a corresponding namespace change — a
 * mechanical CR, not a redesign.
 *
 * =============================================================================
 * Usage
 * =============================================================================
 * In a resource class:
 *
 *     use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;
 *
 *     final class ExampleResource extends JsonResource
 *     {
 *         use RendersLocalizedDates;
 *
 *         public function toArray(Request $request): array
 *         {
 *             return [
 *                 'created_at'         => $this->resource->created_at?->toIso8601String(),
 *                 'created_at_display' => $this->localizedDate($this->resource->created_at),
 *             ];
 *         }
 *     }
 *
 * The method is protected; the resource's own methods and any future
 * subclass can call it. External consumers cannot.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns
 * @version 1.0.0
 */
trait RendersLocalizedDates
{
    /**
     * Render a nullable date in the localised medium Ethiopian form.
     *
     * The output is whatever LocalizationService::formatEthiopianDateMedium()
     * produces for the current request's locale:
     *
     *     am:  ሐምሌ 9, 2018 ዓ.ም. (July 16, 2026)
     *     en:  Hamle 9, 2018 E.C. (July 16, 2026)
     *
     * A null input returns null — the caller can therefore pass a
     * nullable model attribute directly without a null-check:
     *
     *     'cancelled_at_display' => $this->localizedDate($certificate->cancelled_at),
     *
     * Because ShareCertificateResource emits `cancelled_at` as null for
     * any non-cancelled certificate, and the corresponding `_display`
     * sibling is null for the same rows, a client that branches on
     * `cancelled_at !== null` sees the same branch for the display
     * sibling. The two fields agree on which rows carry a value.
     *
     * @param DateTimeInterface|null $date
     *
     * @return string|null  The localised display string, or null when
     *                      the input is null.
     */
    protected function localizedDate(?DateTimeInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        /*
         * Resolve LocalizationService from the container. The service is
         * bound as a singleton in CoreServiceProvider, so every call
         * within a request resolves to the same instance — and therefore
         * the same EthiopianCalendar and the same display timezone.
         *
         * The service's getCurrentLocale() reads the request-scoped
         * locale set by LocalizationMiddleware. No locale is passed
         * explicitly; the trait's contract is that the request's locale
         * governs the display.
         */
        return app(LocalizationService::class)
            ->formatEthiopianDateMedium($date);
    }
}