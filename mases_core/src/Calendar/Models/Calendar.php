<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Calendar extends Model
{
    protected $table = 'calendar_calendars';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['id', 'tenant_id', 'version'];
    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean', 'version' => 'integer'];

    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class, 'calendar_id');
    }
}
