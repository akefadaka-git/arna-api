<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Localization\\Calendar\\Contracts;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Absolute point in time, normalized to UTC, with an explicit IANA event zone.
 *
 * The timezone is retained separately because recurring events are defined
 * in local wall-clock time even though individual occurrences are instants.
 */
final readonly class CalendarInstant implements JsonSerializable, Stringable
{
    private function __construct(
        private DateTimeImmutable $utc,
        private string $eventTimezone,
    ) {
    }

    public static function fromDateTime(
        DateTimeInterface $instant,
        string $eventTimezone = 'Africa/Addis_Ababa',
    ): self {
        try {
            $zone = new DateTimeZone($eventTimezone);
        } catch (\\Throwable $exception) {
            throw new InvalidArgumentException('A valid IANA event timezone is required.', previous: $exception);
        }

        return new self(
            DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone('UTC')),
            $zone->getName(),
        );
    }

    public static function fromString(
        string $instant,
        string $eventTimezone = 'Africa/Addis_Ababa',
    ): self {
        try {
            $parsed = new DateTimeImmutable($instant);
        } catch (\\Throwable $exception) {
            throw new InvalidArgumentException('A valid ISO-8601 instant is required.', previous: $exception);
        }

        if (!preg_match('/(?:Z|[+-]\\d{2}:?\\d{2})$/i', $instant)) {
            throw new InvalidArgumentException('Instant must include Z or an explicit UTC offset.');
        }

        return self::fromDateTime($parsed, $eventTimezone);
    }

    public function utc(): DateTimeImmutable
    {
        return $this->utc;
    }

    public function eventTimezone(): string
    {
        return $this->eventTimezone;
    }

    public function inEventTimezone(): DateTimeImmutable
    {
        return $this->utc->setTimezone(new DateTimeZone($this->eventTimezone));
    }

    public function jsonSerialize(): array
    {
        return [
            'starts_at' => $this->utc->format('Y-m-d\\TH:i:s.u\\Z'),
            'timezone' => $this->eventTimezone,
        ];
    }

    public function __toString(): string
    {
        return $this->utc->format('Y-m-d\\TH:i:s.u\\Z');
    }
}
