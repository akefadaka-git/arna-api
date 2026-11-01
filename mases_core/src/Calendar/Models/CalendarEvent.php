<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CalendarEvent extends Model
{
    protected $table = 'calendar_events';
    public $incrementing = false;
    protected $keyType = 'string';

    // CalendarService constructs tenant_id and created_by from trusted context.
    protected $guarded = [];

    protected $casts = [
        'all_day' => 'boolean',
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'recurrence_rule' => 'array',
        'version' => 'integer',
    ];

    public function attendees(): HasMany
    {
        return $this->hasMany(CalendarAttendee::class, 'event_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(CalendarEventLink::class, 'event_id');
    }
}
