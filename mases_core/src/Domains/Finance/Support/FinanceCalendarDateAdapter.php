<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianDate;

/**
 * Finance boundary adapter for the platform's canonical Ethiopian calendar.
 *
 * Accounting persistence remains Gregorian DATE; this adapter translates
 * user-facing Ethiopian dates at the Finance boundary without duplicating
 * calendar arithmetic or changing the ledger's date storage contract.
 */
final class FinanceCalendarDateAdapter
{
    private readonly EthiopianCalendar $calendar;

    public function __construct(?EthiopianCalendar $calendar = null)
    {
        $this->calendar = $calendar ?? new EthiopianCalendar(
            new DateTimeZone('Africa/Addis_Ababa')
        );
    }

    /**
     * Convert an ISO-like Ethiopian date (YYYY-MM-DD) to Gregorian DATE.
     */
    public function toGregorianDate(string $ethiopianDate): string
    {
        return EthiopianDate::fromString($ethiopianDate)
            ->toGregorian($this->calendar)
            ->format('Y-m-d');
    }

    /**
     * Convert a Gregorian DATE or DateTime value to ISO-like Ethiopian date.
     */
    public function toEthiopianDate(DateTimeInterface|string|null $gregorianDate): ?string
    {
        if ($gregorianDate === null || $gregorianDate === '') {
            return null;
        }

        if (is_string($gregorianDate)) {
            $parsed = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $gregorianDate,
                new DateTimeZone('Africa/Addis_Ababa')
            );
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new InvalidArgumentException('Gregorian date must use YYYY-MM-DD format.');
            }

            $gregorianDate = $parsed;
        }

        return EthiopianDate::fromGregorian($gregorianDate, $this->calendar)->toIso();
    }
}
