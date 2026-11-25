<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CalendarAppointment extends Model
{
    protected $table = 'calendar_appointments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $casts = [
        'confirmed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'declined_at' => 'immutable_datetime',
        'version' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }
}
