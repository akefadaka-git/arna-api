<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Localization;

use Illuminate\Http\Request;
use Illuminate\Session\Store;

final class LocalizationManager
{
    private EthiopianCalendar $calendar;
    private EthiopianFormatter $formatter;
    private LocaleManager $localeManager;

    public function __construct(
        ?EthiopianCalendar $calendar = null,
        ?EthiopianFormatter $formatter = null,
        ?LocaleManager $localeManager = null
    ) {
        $this->calendar = $calendar ?? new EthiopianCalendar();
        $this->formatter = $formatter ?? new EthiopianFormatter($this->calendar);
        $this->localeManager = $localeManager ?? new LocaleManager();
    }

    /**
     * Get the current locale.
     */
    public function getCurrentLocale(): string
    {
        return $this->localeManager->getCurrentLocale();
    }

    /**
     * Set the current locale.
     */
    public function setLocale(string $locale, ?Store $session = null): void
    {
        $this->localeManager->setLocale($locale, $session);
    }

    /**
     * Detect and apply locale from the request.
     */
    public function applyLocale(Request $request, ?Store $session = null): void
    {
        $this->localeManager->applyLocale($request, $session);
    }

    /**
     * Get the current Ethiopian date/time.
     */
    public function now(): EthiopianDateTime
    {
        return EthiopianDateTime::now($this->calendar);
    }

    /**
     * Create an EthiopianDateTime from a Gregorian date/time.
     */
    public function fromGregorian(DateTimeInterface $datetime): EthiopianDateTime
    {
        return EthiopianDateTime::fromGregorian($datetime, $this->calendar);
    }

    /**
     * Format the current date in dd/mm/yyyy format.
     */
    public function formatDate(?EthiopianDateTime $datetime = null, string $locale = 'am'): string
    {
        $dt = $datetime ?? $this->now();
        return $this->formatter->formatDate($dt, $locale);
    }

    /**
     * Format the current date with full month name.
     */
    public function formatDateLong(?EthiopianDateTime $datetime = null, string $locale = 'am'): string
    {
        $dt = $datetime ?? $this->now();
        return $this->formatter->formatDateLong($dt, $locale);
    }

    /**
     * Format the current time in 12‑hour format with AM/PM, local time.
     */
    public function formatTime(?EthiopianDateTime $datetime = null): string
    {
        $dt = $datetime ?? $this->now();
        return $this->formatter->formatTime($dt);
    }

    /**
     * Format date and time together: dd/mm/yyyy hh:mm AM/PM.
     */
    public function formatDateTime(?EthiopianDateTime $datetime = null, string $locale = 'am'): string
    {
        $dt = $datetime ?? $this->now();
        return $this->formatter->formatDateTime($dt, $locale);
    }

    /**
     * Format a number.
     */
    public function formatNumber(float|int $number, int $decimals = 2): string
    {
        return $this->formatter->formatNumber($number, $decimals);
    }

    /**
     * Format a currency amount.
     */
    public function formatCurrency(float $amount, string $currency = 'ETB'): string
    {
        return $this->formatter->formatCurrency($amount, $currency);
    }

    /**
     * Format a percentage.
     */
    public function formatPercent(float $value, int $decimals = 1): string
    {
        return $this->formatter->formatPercent($value, $decimals);
    }

    /**
     * Parse a date string in dd/mm/yyyy format to EthiopianDateTime.
     */
    public function parseDate(string $dateString): EthiopianDateTime
    {
        return $this->formatter->parseDate($dateString);
    }

    /**
     * Parse a datetime string (dd/mm/yyyy hh:mm AM/PM) to EthiopianDateTime.
     */
    public function parseDateTime(string $dateTimeString): EthiopianDateTime
    {
        return $this->formatter->parseDateTime($dateTimeString);
    }

    /**
     * Get the underlying formatter for advanced usage.
     */
    public function getFormatter(): EthiopianFormatter
    {
        return $this->formatter;
    }

    /**
     * Get the underlying calendar.
     */
    public function getCalendar(): EthiopianCalendar
    {
        return $this->calendar;
    }

    /**
     * Get the locale manager.
     */
    public function getLocaleManager(): LocaleManager
    {
        return $this->localeManager;
    }
}