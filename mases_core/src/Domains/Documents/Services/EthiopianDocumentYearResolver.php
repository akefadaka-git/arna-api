<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;

/** Resolves the full Ethiopian Calendar year from an Addis Ababa issuance instant. */
final class EthiopianDocumentYearResolver
{
    private readonly DateTimeZone $timezone;

    public function __construct(private readonly EthiopianCalendar $calendar)
    {
        $this->timezone = new DateTimeZone('Africa/Addis_Ababa');
    }

    public function currentYear(): int
    {
        return $this->forInstant(new DateTimeImmutable('now', $this->timezone));
    }

    public function forInstant(DateTimeInterface $instant): int
    {
        $localized = DateTimeImmutable::createFromInterface($instant)->setTimezone($this->timezone);
        return (int) $this->calendar->toEthiopian($localized)['year'];
    }
}
