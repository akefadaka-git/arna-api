<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\People\Models\Person;

final class GovernanceActingAssignment extends Model
{
    protected $table = 'governance_acting_assignments';

    protected $fillable = [
        'governance_seat_id', 'substantive_occupancy_id', 'acting_person_id',
        'grantor_person_id', 'created_by_user_id', 'source_decision_reference',
        'actions', 'start_date', 'end_date', 'reason', 'revoked_at',
        'revoked_by_user_id', 'revocation_reason',
    ];

    protected $casts = [
        'governance_seat_id' => 'integer',
        'substantive_occupancy_id' => 'integer',
        'acting_person_id' => 'integer',
        'grantor_person_id' => 'integer',
        'created_by_user_id' => 'integer',
        'revoked_by_user_id' => 'integer',
        'actions' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'revoked_at' => 'datetime',
    ];

    public function seat(): BelongsTo { return $this->belongsTo(GovernanceSeat::class, 'governance_seat_id'); }
    public function substantiveOccupancy(): BelongsTo { return $this->belongsTo(GovernanceSeatOccupancy::class, 'substantive_occupancy_id'); }
    public function actingPerson(): BelongsTo { return $this->belongsTo(Person::class, 'acting_person_id'); }
    public function grantor(): BelongsTo { return $this->belongsTo(Person::class, 'grantor_person_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function revoker(): BelongsTo { return $this->belongsTo(User::class, 'revoked_by_user_id'); }

    public function isEffectiveOn(?string $onDate = null): bool
    {
        $date = $onDate ?? now()->toDateString();
        return $this->revoked_at === null
            && $this->start_date->toDateString() <= $date
            && $this->end_date->toDateString() >= $date;
    }

    public function allowsAction(string $action, ?string $onDate = null): bool
    {
        return $this->isEffectiveOn($onDate) && in_array($action, $this->actions ?? [], true);
    }
}
