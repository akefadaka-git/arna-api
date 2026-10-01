<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P3 · Unit 2 · File 2 of 13
 * =============================================================================
 *
 * File:            RendersLocalizedDates.php  (NEW)
 * Unit:            P3 · Unit 2 — Ethiopian calendar date rendering
 * Delivery type:   New file (trait)
 * Net lines:       +…
 *
 * Before:
 *   There was no reusable helper for producing an Ethiopian-calendar
 *   display string inside a resource class. The four Shareholder-domain
 *   resources (ShareholderResource, ShareHoldingResource,
 *   ShareCertificateResource, ShareClassResource) each emit their raw
 *   ISO 8601 timestamps and nothing else. A page that wants to render
 *   a date must slice the ISO string in the browser — the pattern the
 *   portal pages use today via their local `formatDate()` helper.
 *
 *   There was also no direct consumer of the newly-added
 *   LocalizationService::formatEthiopianDateMedium() (file 1 of this
 *   unit). Without a consumer, that method exists but is unreachable
 *   from the four resources this unit amends.
 *
 * After:
 *   A single trait with one protected method:
 *
 *     protected function localizedDate(?DateTimeInterface $date): ?string
 *
 *   The method:
 *     · Accepts a nullable DateTimeInterface.
 *     · Returns null for null input.
 *     · Otherwise delegates to
 *       LocalizationService::formatEthiopianDateMedium(), which returns
 *       the locale-appropriate medium form:
 *
 *         am:  ሐምሌ 9, 2018 ዓ.ም. (July 16, 2026)
 *         en:  Hamle 9, 2018 E.C. (July 16, 2026)
 *
 *   The trait is mixed into each of the four resources in Group B of
 *   this unit. Each resource emits a `_display` sibling for every date
 *   field it already carries — for example, `created_at_display` next
 *   to `created_at`. The ISO field is preserved byte-for-byte; the
 *   `_display` field is additive.
 *
 * =============================================================================
 * Why a trait, not a base class
 * =============================================================================
 * Three alternatives were considered:
 *
 *   1. A base class. All four resources extend
 *      Illuminate\Http\Resources\Json\JsonResource. PHP does not allow
 *      multiple inheritance; introducing an intermediate base class
 *      would force every resource to extend MASES's wrapper instead of
 *      Laravel's own, which is a bigger change than this unit warrants.
 *
 *   2. A method on LocalizationService. The trait could have been
 *      omitted entirely if the resources called
 *      `app(LocalizationService::class)->formatEthiopianDateMedium(...)`
 *      at every call site. That works, but every resource would carry
 *      the same three-line boilerplate for every date field. A trait
 *      centralises the boilerplate in one place.
 *
 *   3. A public static helper. Static helpers on resources are
 *      unconventional; the codebase's existing pattern is instance
 *      methods on the resource class. A trait preserves that pattern.
 *
 * The trait is chosen because it composes with each resource's existing
 * `extends JsonResource` without forcing a common base class, and
 * because it localises the two-line call into a method whose name reads
 * the same at every call site.
 *
 * =============================================================================
 * Why the method is protected, not private
 * =============================================================================
 * The codebase's four resource classes use `private` for their own
 * helper methods (resolveTypeLabel, shareholderSummary, entryToArray,
 * and so on). Those methods are private to the class that declares
 * them.
 *
 * A trait's methods conventionally use `protected` when the trait is
 * designed to be reused across classes, because `protected` expresses
 * the intent that the method is available to the composing class and to
 * any future subclass. The four Shareholder-domain resources are
 * declared `final class`, so no subclass exists today; the `protected`
 * visibility is a future-proofing choice, not a functional necessity.
 *
 * =============================================================================
 * What the method does NOT do
 * =============================================================================
 *   · It does not accept a locale parameter. The locale used is
 *     whatever LocalizationService::getCurrentLocale() resolves for the
 *     current request. LocalizationMiddleware applies the resolved
 *     locale before any resource serializes, so the trait sees the
 *     request-appropriate locale without the caller having to thread it
 *     through. A per-resource locale override is not in this unit's
 *     scope; if a future CR needs it, the method gains an optional
 *     `?string $locale = null` parameter.
 *
 *   · It does not accept a string or an integer. The method is
 *     deliberately typed to `?DateTimeInterface`. Model attributes that
 *     carry dates are cast to Carbon (a DateTimeInterface) by every
 *     affected model, so the caller passes the attribute directly:
 *
 *         'created_at_display' => $this->localizedDate($shareholder->created_at),
 *
 *     Accepting loose types would let a caller pass a string that the
 *     service then re-parses — an unnecessary conversion that risks
 *     timezone drift. The stricter signature matches the call sites
 *     exactly.
 *
 *   · It does not format anything itself. Every formatting decision —
 *     Ethiopian month names, the era suffix, the Gregorian parentheses
 *     — is owned by LocalizationService. A future CR that changes the
 *     display format changes one method in one class; the trait
 *     propagates the change to all four resources without a code edit
 *     here.
 *
 *   · It does not cache. Each call resolves LocalizationService from
 *     the container and delegates. The container binding is a
 *     singleton, so the resolution is a hash lookup. Caching at the
 *     trait layer would be premature.
 *
 * =============================================================================
 * Deployment note — autoloader refresh required
 * =============================================================================
 * This is a new file with a new class. The project's composer.json sets
 * "optimize-autoloader": true, which means the autoloader uses a
 * precomputed class map and does not fall back to PSR-4 path resolution
 * for classes absent from the map. After this file is uploaded, the
 * composer class map MUST be regenerated (composer dump-autoload, or
 * the equivalent action in Plesk's Composer panel) before any resource
 * that uses the trait can be loaded.
 *
 * This is the same requirement that unblocked ShareClassSeeder.php
 * earlier in this session. The four Group B resources that mix in this
 * trait will fail to load until the map is refreshed.
 *
 * No database migration. No config change. No route change.
 *
 * Files modified:            none
 * Files added:               this file only
 * Files deleted:             none
 * Dependencies added:        none (uses LocalizationService, already
 *                            bound as a singleton)
 * =============================================================================
 */

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