<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Task\Models;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use MaaleArna\Arna\Core\Localization\Calendar\EthiopianCalendar;
use MaaleArna\Arna\Core\Localization\Calendar\EthiopianDate;

final class Task extends Model
{
    protected $table = 'tasks';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $appends = ['due_on_ec'];

    protected $casts = [
        'due_on' => 'date:Y-m-d',
        'progress_percent' => 'integer',
        'version' => 'integer',
        'completed_at' => 'immutable_datetime',
    ];

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'task_dependencies',
            'task_id',
            'depends_on_task_id',
        )->withPivot(['tenant_id', 'created_by', 'created_at']);
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'task_dependencies',
            'depends_on_task_id',
            'task_id',
        )->withPivot(['tenant_id', 'created_by', 'created_at']);
    }

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
