<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Task\Models;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianDate;

final class Task extends Model
{
    protected $table = 'tasks';

    protected $guarded = ['*'];

    protected $appends = ['due_on_ec'];

    protected $casts = [
        'due_on' => 'date:Y-m-d',
        'progress_percent' => 'integer',
        'version' => 'integer',
        'completed_at' => 'immutable_datetime',
    ];

    public function getDueOnEcAttribute(): ?array
    {
        if ($this->due_on === null) {
            return null;
        }

        $timezone = new DateTimeZone('Africa/Addis_Ababa');
        $date = new DateTimeImmutable($this->due_on->format('Y-m-d'), $timezone);
        $calendar = new EthiopianCalendar($timezone);
        $ethiopianDate = EthiopianDate::fromGregorian($date, $calendar);

        return [
            'year' => $ethiopianDate->year,
            'month' => $ethiopianDate->month,
            'day' => $ethiopianDate->day,
            'iso' => $ethiopianDate->toIso(),
            'formatted_am' => $ethiopianDate->formatFull('am', $calendar),
            'formatted_en' => $ethiopianDate->formatFull('en', $calendar),
        ];
    }
}
