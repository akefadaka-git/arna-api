<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Localization;

use DateTimeInterface;
use InvalidArgumentException;

final class EthiopianFormatter
{
    private EthiopianCalendar $calendar;
    private array $numberConfig;
    private string $defaultLocale;

    public function __construct(?EthiopianCalendar $calendar = null)
    {
        $this->calendar = $calendar ?? new EthiopianCalendar();
        $this->defaultLocale = config('mases_localization.locales.default', 'am');
        $this->numberConfig = config('mases_localization.number', [
            'decimal_separator' => '.',
            'thousands_separator' => ',',
            'currency_symbol' => 'Br',
            'currency_position' => 'before',
        ]);
    }

    /**
     * Format an Ethiopian date as dd/mm/yyyy (Ethiopian calendar).
     */
    public function formatDate(EthiopianDateTime $datetime, string $locale = 'am'): string
    {
        $eth = $datetime->toEthiopianArray($locale);
        return sprintf(
            '%02d/%02d/%04d',
            $eth['day'],
            $eth['month'],
            $eth['year']
        );
    }

    /**
     * Format an Ethiopian date with full month name (e.g., 12 Meskerem 2017).
     */
    public function formatDateLong(EthiopianDateTime $datetime, string $locale = 'am'): string
    {
        $eth = $datetime->toEthiopianArray($locale);
        return sprintf(
            '%d %s %d',
            $eth['day'],
            $eth['month_name'],
            $eth['year']
        );
    }

    /**
     * Format time in 12‑hour format with AM/PM (e.g., 02:30 PM).
     * The time is shown in Ethiopian local time (Africa/Addis_Ababa).
     */
    public function formatTime(EthiopianDateTime $datetime): string
    {
        $carbon = $datetime->toCarbon();
        // Ensure timezone is Africa/Addis_Ababa
        $local = $carbon->setTimezone('Africa/Addis_Ababa');
        return $local->format('h:i A');
    }

    /**
     * Format date and time together (dd/mm/yyyy hh:mm AM/PM).
     */
    public function formatDateTime(EthiopianDateTime $datetime, string $locale = 'am'): string
    {
        return $this->formatDate($datetime, $locale) . ' ' . $this->formatTime($datetime);
    }

    /**
     * Format a number with decimal and thousands separators according to locale.
     */
    public function formatNumber(float|int $number, int $decimals = 2): string
    {
        $decimal = $this->numberConfig['decimal_separator'] ?? '.';
        $thousands = $this->numberConfig['thousands_separator'] ?? ',';
        return number_format($number, $decimals, $decimal, $thousands);
    }

    /**
     * Format a currency amount with symbol.
     */
    public function formatCurrency(float $amount, string $currency = 'ETB'): string
    {
        $symbol = $this->numberConfig['currency_symbol'] ?? 'Br';
        $position = $this->numberConfig['currency_position'] ?? 'before';
        $formatted = $this->formatNumber($amount, 2);
        return $position === 'before'
            ? $symbol . ' ' . $formatted
            : $formatted . ' ' . $symbol;
    }

    /**
     * Format a percentage.
     */
    public function formatPercent(float $value, int $decimals = 1): string
    {
        return $this->formatNumber($value, $decimals) . '%';
    }

    /**
     * Parse an Ethiopian date string in dd/mm/yyyy format and return an EthiopianDateTime.
     * Throws if invalid.
     */
    public function parseDate(string $dateString): EthiopianDateTime
    {
        // Expect dd/mm/yyyy
        $parts = explode('/', $dateString);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException("Invalid date format. Expected dd/mm/yyyy.");
        }
        $day = (int)$parts[0];
        $month = (int)$parts[1];
        $year = (int)$parts[2];
        // Validate day/month
        $daysInMonth = $this->calendar->daysInMonth($year, $month);
        if ($day < 1 || $day > $daysInMonth) {
            throw new InvalidArgumentException("Invalid day for month {$month} in year {$year}.");
        }
        // Create at midnight, local time
        return EthiopianDateTime::fromEthiopian($year, $month, $day, 0, 0, 0, $this->calendar);
    }

    /**
     * Parse a time string in 12‑hour format (hh:mm AM/PM) and return hour/minutes.
     * Returns array [hour, minute].
     */
    public function parseTime(string $timeString): array
    {
        $pattern = '/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i';
        if (!preg_match($pattern, trim($timeString), $matches)) {
            throw new InvalidArgumentException("Invalid time format. Expected hh:mm AM/PM.");
        }
        $hour = (int)$matches[1];
        $minute = (int)$matches[2];
        $meridiem = strtoupper($matches[3]);
        if ($meridiem === 'PM' && $hour !== 12) {
            $hour += 12;
        } elseif ($meridiem === 'AM' && $hour === 12) {
            $hour = 0;
        }
        return ['hour' => $hour, 'minute' => $minute];
    }

    /**
     * Parse a full date+time string: dd/mm/yyyy hh:mm AM/PM.
     * Returns EthiopianDateTime.
     */
    public function parseDateTime(string $dateTimeString): EthiopianDateTime
    {
        // Split on first space
        $parts = explode(' ', $dateTimeString, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Expected date and time separated by a space.");
        }
        $date = $this->parseDate($parts[0]);
        $time = $this->parseTime($parts[1]);
        // Create a new EthiopianDateTime with the time set
        $eth = $date->toEthiopianArray();
        return EthiopianDateTime::fromEthiopian(
            $eth['year'],
            $eth['month'],
            $eth['day'],
            $time['hour'],
            $time['minute'],
            0,
            $this->calendar
        );
    }

    /**
     * Get the default locale.
     */
    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }
}