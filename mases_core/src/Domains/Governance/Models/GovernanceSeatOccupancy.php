<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MaaleArna\MASES\Core\Domains\People\Models\Person;

final class GovernanceSeatOccupancy extends Model
{
    protected $table = 'governance_seat_occupancies';

    protected $fillable = [
        'governance_seat_id', 'person_id', 'governance_membership_id',
        'start_date', 'end_date', 'status', 'ended_reason',
    ];

    protected $casts = [
        'governance_seat_id' => 'integer',
        'person_id' => 'integer',
        'governance_membership_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function seat(): BelongsTo { return $this->belongsTo(GovernanceSeat::class, 'governance_seat_id'); }
    public function person(): BelongsTo { return $this->belongsTo(Person::class, 'person_id'); }
    public function membership(): BelongsTo { return $this->belongsTo(GovernanceMembership::class, 'governance_membership_id'); }
    public function actingAssignments(): HasMany { return $this->hasMany(GovernanceActingAssignment::class, 'substantive_occupancy_id'); }

    public function isEffectiveOn(?string $onDate = null): bool
    {
        $date = $onDate ?? now()->toDateString();
        return $this->status === 'active'
            && $this->start_date->toDateString() <= $date
            && ($this->end_date === null || $this->end_date->toDateString() >= $date);
    }
}
