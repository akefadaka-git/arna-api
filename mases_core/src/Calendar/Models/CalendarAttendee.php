<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Models;

use Illuminate\Database\Eloquent\Model;

final class CalendarAttendee extends Model
{
    protected $table = 'calendar_attendees';
    protected $guarded = ['id'];
    protected $casts = ['responded_at' => 'immutable_datetime'];
}
