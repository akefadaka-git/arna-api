<?php

declare(strict_types=1);



namespace MaaleArna\Arna\Core\Domains\Governance\Http\Resources\Concerns;

use MaaleArna\Arna\Core\Localization\Services\LocalizationService;

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
 * @package MaaleArna\Arna\Core\Domains\Governance\Http\Resources\Concerns
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