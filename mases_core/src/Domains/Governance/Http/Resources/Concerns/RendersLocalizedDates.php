<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 10 of 17
 * =============================================================================
 *
 * File:            Http/Resources/Concerns/RendersLocalizedDates.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (resource trait)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The Governance domain had no mechanism
 *   for rendering a localized Ethiopian calendar date from a
 *   resource. A resource that wanted to emit both an ISO 8601
 *   timestamp and its Ethiopian-calendar display sibling would have
 *   had to inline a call to `LocalizationService` in every field
 *   that carries a date — duplicating the null-handling and the
 *   service-lookup boilerplate across every such field and every
 *   resource that grows in G2b and beyond.
 *
 * After:
 *   One trait that declares a single `localizedDate()` method. The
 *   trait is consumed by every Governance resource that emits a
 *   date-bearing field, including `GovernanceBodyResource` (G2a
 *   file 11 of 17), which uses it for its `effective_from_display`
 *   and `effective_until_display` fields.
 *
 *   The method:
 *
 *     · Accepts a nullable date-like value (a `Carbon` instance, a
 *       `DateTimeInterface`, an ISO string, a Unix timestamp, or
 *       `null`).
 *
 *     · Returns `null` for a null input — the same shape as the
 *       ISO field it pairs with, so a client can branch on either
 *       form and see the same rows carrying a value.
 *
 *     · For any non-null input, delegates to
 *       `LocalizationService::formatEthiopianDateMedium()`, which
 *       produces the locale-appropriate Ethiopian-calendar medium
 *       form:
 *
 *           am:  ሐምሌ 9, 2018 ዓ.ም. (July 16, 2026)
 *           en:  Hamle 9, 2018 E.C. (July 16, 2026)
 *
 *   What the trait deliberately does NOT do:
 *
 *     · It does NOT cache the resolved `LocalizationService`. The
 *       service is a Phase 1 singleton bound by
 *       `CoreServiceProvider`; resolving it from the container is
 *       O(1) and returns the same instance on every call within a
 *       request. A cached instance property would be a
 *       micro-optimisation that complicates the trait's semantics
 *       (a trait cannot have instance-level construction logic
 *       without imposing it on every consuming class) without
 *       measurable benefit.
 *
 *     · It does NOT accept a format string. Every consumer of the
 *       trait wants the same medium form; the reasoning for that
 *       single format is documented in the
 *       `LocalizationService::formatEthiopianDateMedium()` method
 *       docblock. A resource that needs a different format calls
 *       the service directly rather than extending the trait.
 *
 *     · It does NOT accept a locale argument. The method resolves
 *       the current request's locale via the service's own
 *       precedence chain (user preference → session → cookie →
 *       Accept-Language → default). Passing an explicit locale
 *       would let a resource override the request's locale, which
 *       is not a capability any current consumer needs.
 *
 *     · It does NOT register itself with any provider. Traits are
 *       compile-time constructs; PHP resolves them when a consuming
 *       class is loaded. No `GovernanceServiceProvider` amendment
 *       is needed for a trait.
 *
 *     · It does NOT live in a shared namespace. Per ADR-GOV-011,
 *       the trait is a LOCAL COPY inside the Governance domain, not
 *       a cross-domain import from `Shareholder\...\Concerns` and
 *       not a Core extraction.
 *
 * =============================================================================
 * ADR-GOV-011 — the local-copy decision, in full
 * =============================================================================
 * The Shareholder domain has its own `RendersLocalizedDates` trait
 * at:
 *
 *     MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates
 *
 * That trait is not in Core. It is not a shared utility. It lives
 * inside the Shareholder domain's own namespace tree, where it was
 * introduced by Phase 3 · Unit 2 alongside the `LocalizationService`
 * method it delegates to.
 *
 * Three options were considered for the Governance domain:
 *
 *   Option A — import the Shareholder trait:
 *       `use MaaleArna\MASES\Core\Domains\Shareholder\Http\Resources\Concerns\RendersLocalizedDates;`
 *
 *       Rejected. It would create a Governance → Shareholder
 *       dependency on an internal resource detail. Governance and
 *       Shareholder are independent bounded contexts; a cross-
 *       context dependency on a resource-layer implementation
 *       detail is architecturally wrong, even if the trait's
 *       behaviour is generic.
 *
 *   Option B — copy the trait into the Governance namespace
 *       (adopted):
 *       `MaaleArna\MASES\Core\Domains\Governance\Http\Resources\Concerns\RendersLocalizedDates`
 *
 *       Accepted. The trait is small — a single method whose body
 *       is a null check and a service delegation. Duplicating it
 *       costs roughly ten lines of implementation code per domain.
 *       The cost is bounded and the domains remain independent.
 *
 *   Option C — extract the trait to Core, and migrate both
 *       Shareholder and Governance to consume it:
 *
 *       Deferred. Extracting to Core is the architecturally correct
 *       long-term answer, but it requires amending the Shareholder
 *       domain's resource files — which is outside the scope of a
 *       Governance delivery. The extraction is a separate CR whose
 *       file list spans both domains; it is not part of G2a.
 *
 * The adopted option is B. The three options and their consequences
 * are recorded here so a future reader understands why Governance
 * carries its own copy of a trait that already exists elsewhere in
 * the platform.
 *
 * When the Core extraction CR eventually lands, this file is
 * replaced by a one-line import change in the consuming resource
 * (file 11), and this file is deleted. The CR's diff is therefore
 * two small files in the Governance domain, plus the equivalent
 * changes in the Shareholder domain, plus the new Core file.
 *
 * =============================================================================
 * The method's contract
 * =============================================================================
 *     protected function localizedDate(mixed $date): ?string
 *
 * Inputs accepted by the underlying service:
 *   · A `Carbon\Carbon` instance — the form every Eloquent `date`
 *     or `datetime` cast yields.
 *   · Any `DateTimeInterface` implementation.
 *   · An ISO 8601 string — the form a resource might receive if it
 *     were built from raw request input, which no current consumer
 *     does.
 *   · A Unix timestamp as an integer.
 *   · `null`.
 *
 * Return values:
 *   · `null` when the input is `null`.
 *   · A non-empty string otherwise — the medium form described in
 *     the class docblock above.
 *
 * Note that the underlying service returns an empty string for an
 * unparseable non-null input (per its own docblock). In normal
 * service flow — every call site passes a `Carbon` instance or
 * `null` — the empty-string branch is unreachable. The trait does
 * not special-case it; a caller that somehow passes garbage
 * receives an empty string, which is the correct diagnostic surface
 * for a shape mismatch.
 *
 * =============================================================================
 * Usage
 * =============================================================================
 * A resource that emits a paired ISO + display date field uses the
 * trait like this:
 *
 *     final class GovernanceBodyResource extends JsonResource
 *     {
 *         use RendersLocalizedDates;
 *
 *         public function toArray(Request $request): array
 *         {
 *             $body = $this->resource;
 *
 *             return [
 *                 // ...
 *                 'effective_from'         => $body->effective_from?->toDateString(),
 *                 'effective_from_display' => $this->localizedDate($body->effective_from),
 *                 'effective_until'         => $body->effective_until?->toDateString(),
 *                 'effective_until_display' => $this->localizedDate($body->effective_until),
 *                 // ...
 *             ];
 *         }
 *     }
 *
 * The pair structure — ISO field first, `_display` sibling second,
 * adjacent in the payload — is the platform's convention. See
 * `ShareholderResource`, `ShareClassResource`,
 * `ShareHoldingResource`, and `ShareCertificateResource` for the
 * same shape at scale.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Http/Resources/Concerns/RendersLocalizedDates.php
 *
 * The directory `mases_core/src/Domains/Governance/Http/Resources/Concerns/`
 * must exist. If it does not, create it before uploading — the
 * file's path is the full path.
 *
 * After uploading, `composer dump-autoload` may be required if the
 * class map is cached. A trait is resolved by the autoloader when
 * its consuming class loads; if the class map was cached before
 * this file existed, the trait will not be found until the map is
 * regenerated.
 *
 * Verification:
 *
 *     php artisan tinker
 *     >>> trait_exists(\MaaleArna\MASES\Core\Domains\Governance\Http\Resources\Concerns\RendersLocalizedDates::class)
 *     => true
 *
 * No provider amendment, no config change, and no migration are
 * required for a trait.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Methods declared:          1 (localizedDate)
 * Container bindings:        none (LocalizationService is a Phase 1 singleton)
 * Provider amendments:       none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Resources\Concerns;

use MaaleArna\MASES\Core\Localization\Services\LocalizationService;

/**
 * RendersLocalizedDates
 *
 * Provides the `localizedDate()` helper for Governance resources that
 * emit Ethiopian-calendar display siblings alongside their ISO 8601
 * date fields.
 *
 * See the file-level change-verification block for the trait's
 * contract, the ADR-GOV-011 local-copy decision, and the usage
 * pattern it enables.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Http\Resources\Concerns
 * @version 1.0.0
 */
trait RendersLocalizedDates
{
    /**
     * Render a date-like value in the Ethiopian-calendar medium form
     * for the current request's locale.
     *
     * Returns `null` for a `null` input, matching the shape of the
     * ISO field the display string is paired with. Returns a
     * non-empty string for any parseable input.
     *
     * The method resolves `LocalizationService` from the container on
     * every call. The service is a Phase 1 singleton; resolution is
     * O(1) and returns the same instance across the request.
     *
     * The service itself resolves the current locale via its own
     * precedence chain. The trait does not pass an explicit locale —
     * the request's locale is authoritative.
     *
     * @param  mixed $date  A `Carbon` instance, `DateTimeInterface`,
     *                      ISO string, Unix timestamp, or `null`.
     * @return string|null  The Ethiopian-calendar display form, or
     *                      `null` for a `null` input.
     */
    protected function localizedDate(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return app(LocalizationService::class)
            ->formatEthiopianDateMedium($date);
    }
}