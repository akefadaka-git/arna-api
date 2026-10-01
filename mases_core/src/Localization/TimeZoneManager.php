<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Localization;

use Carbon\Carbon;
use DateTimeInterface;
use DateTimeZone;

final class TimeZoneManager
{
    private string $defaultTimezone;

    public function __construct()
    {
        // Use config value or default to Africa/Addis_Ababa
        $this->defaultTimezone = config('mases_localization.timezone', 'Africa/Addis_Ababa');
    }

    /**
     * Get the canonical Ethiopian timezone string.
     */
    public function getDefaultTimezone(): string
    {
        return $this->defaultTimezone;
    }

    /**
     * Create a Carbon instance with the default timezone.
     */
    public function now(): Carbon
    {
        return Carbon::now($this->defaultTimezone);
    }

    /**
     * Convert a given DateTimeInterface to the default timezone.
     */
    public function toDefaultTimezone(DateTimeInterface $datetime): Carbon
    {
        return Carbon::instance($datetime)->setTimezone($this->defaultTimezone);
    }

    /**
     * Convert a given DateTimeInterface to UTC.
     */
    public function toUtc(DateTimeInterface $datetime): Carbon
    {
        return Carbon::instance($datetime)->setTimezone('UTC');
    }

    /**
     * Convert a given DateTimeInterface to a specified timezone.
     */
    public function toTimezone(DateTimeInterface $datetime, string $timezone): Carbon
    {
        return Carbon::instance($datetime)->setTimezone($timezone);
    }

    /**
     * Check if a given timezone string is valid.
     */
    public function isValidTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the offset (in seconds) of the default timezone against UTC for a given date.
     */
    public function getOffsetForDateTime(DateTimeInterface $datetime): int
    {
        return $this->toDefaultTimezone($datetime)->getOffset();
    }

    /**
     * Format a datetime in the default timezone using a given format.
     */
    public function formatDefault(DateTimeInterface $datetime, string $format): string
    {
        return $this->toDefaultTimezone($datetime)->format($format);
    }

    /**
     * Format a datetime in UTC.
     */
    public function formatUtc(DateTimeInterface $datetime, string $format): string
    {
        return $this->toUtc($datetime)->format($format);
    }
}