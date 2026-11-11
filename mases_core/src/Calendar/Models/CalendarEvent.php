<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use DateTimeImmutable;
use DateTimeZone;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianDate;

final class CalendarEvent extends Model
{
    protected $table = 'calendar_events';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['*'];
    protected $appends = ['starts_on_ec', 'ends_on_ec'];

    protected $casts = [
        'all_day' => 'boolean',
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'recurrence_rule' => 'array',
        'version' => 'integer',
    ];

    public function getStartsOnEcAttribute(): ?array
    {
        return $this->ethiopianDatePayload($this->starts_on);
    }

    public function getEndsOnEcAttribute(): ?array
    {
        return $this->ethiopianDatePayload($this->ends_on);
    }

    private function ethiopianDatePayload(mixed $value): ?array
    {
        if (!$this->all_day || $value === null) {
            return null;
        }

        $timezone = new DateTimeZone($this->timezone ?: 'Africa/Addis_Ababa');
        $civilDate = new DateTimeImmutable($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value, $timezone);
        $date = EthiopianDate::fromGregorian($civilDate, new EthiopianCalendar($timezone));

        return [
            'year' => $date->year,
            'month' => $date->month,
            'day' => $date->day,
            'iso' => $date->toIso(),
            'formatted_am' => $date->formatFull('am', new EthiopianCalendar($timezone)),
            'formatted_en' => $date->formatFull('en', new EthiopianCalendar($timezone)),
        ];
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(CalendarAttendee::class, 'event_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(CalendarEventLink::class, 'event_id');
    }
}
