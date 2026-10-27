<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class GovernanceSeat extends Model
{
    protected $table = 'governance_seats';

    protected $fillable = [
        'tenant_id', 'governance_body_id', 'governance_position_id',
        'seat_code', 'label_am', 'label_en', 'status',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'governance_body_id' => 'integer',
        'governance_position_id' => 'integer',
    ];

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class, 'tenant_id'); }
    public function body(): BelongsTo { return $this->belongsTo(GovernanceBody::class, 'governance_body_id'); }
    public function position(): BelongsTo { return $this->belongsTo(GovernancePosition::class, 'governance_position_id'); }
    public function occupancies(): HasMany { return $this->hasMany(GovernanceSeatOccupancy::class, 'governance_seat_id'); }
    public function actingAssignments(): HasMany { return $this->hasMany(GovernanceActingAssignment::class, 'governance_seat_id'); }

    public function effectiveOccupancy(?string $onDate = null): ?GovernanceSeatOccupancy
    {
        $date = $onDate ?? now()->toDateString();

        return $this->occupancies()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $date)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
            ->first();
    }
}
