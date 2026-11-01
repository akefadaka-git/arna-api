<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Models;

use Illuminate\Database\Eloquent\Model;

final class CalendarEventLink extends Model
{
    protected $table = 'calendar_event_links';
    protected $guarded = ['id'];
}
