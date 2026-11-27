<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\CarbonInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\Arna\Core\Domains\Loan\Enums\InstallmentStatus;

/**
 * LoanInstallment
 *
 * Eloquent model for a single scheduled installment of a loan.
 *
 * =============================================================================
 * Keep status outside mass assignment; lifecycle changes are service-controlled.
 * @property int                         $id
 * @property int                         $loan_id
 * @property int                         $installment_number
 * @property Carbon $due_date
 * @property string                      $expected_principal
 * @property string                      $expected_interest
 * @property string                      $expected_total
 * @property string                      $settled_principal
 * @property string                      $settled_interest
 * @property string                      $settled_amount
 * @property InstallmentStatus           $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Loan|null              $loan
 * @property-read LoanInterestAccrual|null $interestAccrual
 * @property-read LoanPenaltyAccrual|null  $penaltyAccrual
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Models
 * @version 1.0.1
 */
final class LoanInstallment extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'loan_installments';

    /**
     * The primary key column.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Whether the primary key auto-increments.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The primary key type.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * The attributes that are mass assignable.
     *
     * The list is authoritative for the ONE production call site that
     * constructs installments in a single call:
     *
     *   - `LoanScheduleService::generate()` (A.23) — creates the rows
     *     of a new schedule. It supplies exactly the six columns listed
     *     below. The `status` column is deliberately NOT supplied: the
     *     migration A.10's `pending` default is the correct initial
     *     state for every schedule-generated installment, and the
     *     service relies on that default (see its own class docblock
     *     and its create-block comment).
     *
     * The following columns are deliberately NOT mass-assignable:
     *
     *   id                     — auto-assigned.
     *   settled_principal,
     *   settled_interest,
     *   settled_amount         — initialized to `0.0000` by the migration's
     *                            column defaults; updated only by the
     *                            services through explicit attribute
     *                            assignment.
     *   status                 — initialized to `pending` by the migration's
     *                            column default; TRANSITIONED only by the
     *                            services through explicit attribute
     *                            assignment followed by `save()`.
     *   created_at, updated_at — managed by Eloquent.
     *
     * The design rule that governs `status` is the reason it is not
     * fillable: a status value is either (a) the initial `pending`
     * state supplied by the migration default, or (b) the result of a
     * legal TRANSITION, which only `LoanRepaymentService` (A.27),
     * `LoanArrearsService` (A.29), or `LoanRestructuringService`
     * (A.30) may perform — each composing its own transition-legality
     * checks and its own after-commit event dispatch. Making `status`
     * mass assignable would let any future `create()` call reach an
     * installment directly in `settled`, `overdue`, or `superseded`
     * state, bypassing the transition machinery that validates and
     * audits those moves.
     *
     * Test fixtures that need an installment in a specific state call
     * `forceFill(['status' => ...])->save()` on the freshly created
     * model, or use `LoanInstallment::forceCreate([...])`. `forceFill`
     * and `forceCreate` are Eloquent's designed bypasses of
     * `$fillable`; they are the correct tools for fixture setup and
     * cannot be reached from a production code path without an
     * explicit, greppable call.
     *
     * The immutable columns (`loan_id`, `installment_number`, `due_date`,
     * `expected_*`) ARE fillable, because the schedule service supplies
     * them at creation.
     *
     * @var list<string>
     */
    protected $fillable = [
        'loan_id',
        'installment_number',
        'due_date',
        'expected_principal',
        'expected_interest',
        'expected_total',
    ];

    /**
     * The attributes that should be cast.
     *
     * See the class docblock §"Casts" for the rationale on each group.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'loan_id'            => 'integer',
        'installment_number' => 'integer',
        'due_date'           => 'date',
        'expected_principal' => 'decimal:4',
        'expected_interest'  => 'decimal:4',
        'expected_total'     => 'decimal:4',
        'settled_principal'  => 'decimal:4',
        'settled_interest'   => 'decimal:4',
        'settled_amount'     => 'decimal:4',
        'status'             => InstallmentStatus::class,
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. The `LoanInstallmentResource` (A.66) selects
     * fields explicitly. The `remaining*` helpers are available on demand
     * but are not force-serialized.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The loan this installment belongs to.
     *
     * @return BelongsTo
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id', 'id');
    }

    /**
     * The interest accrual recognized against this installment, if any.
     *
     * At most one, per the UNIQUE constraint on
     * `loan_interest_accruals.accrual_period_key` (migration A.15). Null
     * until the installment's due date arrives and the daily
     * `AccrueInterestCommand` (A.87) records the accrual.
     *
     * @return HasOne
     */
    public function interestAccrual(): HasOne
    {
        return $this->hasOne(LoanInterestAccrual::class, 'installment_id', 'id');
    }

    /**
     * The penalty accrual recognized against this installment, if any.
     *
     * At most one, per the UNIQUE constraint on
     * `loan_penalty_accruals.accrual_period_key` (migration A.16). Null
     * until the installment crosses its grace threshold and the daily
     * `DetectArrearsCommand` (A.88) records the penalty.
     *
     * @return HasOne
     */
    public function penaltyAccrual(): HasOne
    {
        return $this->hasOne(LoanPenaltyAccrual::class, 'installment_id', 'id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific loan.
     *
     * Used by `LoanScheduleService` (A.23) and
     * `LoanReconciliationService` (A.32) to enumerate a loan's
     * installments.
     *
     * @param Builder $query
     * @param int     $loanId
     * @return Builder
     */
    public function scopeForLoan(Builder $query, int $loanId): Builder
    {
        return $query->where('loan_id', $loanId);
    }

    /**
     * Scope a query to installments in the `pending` state.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', InstallmentStatus::Pending->value);
    }

    /**
     * Scope a query to installments in the `partial` state.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePartial(Builder $query): Builder
    {
        return $query->where('status', InstallmentStatus::Partial->value);
    }

    /**
     * Scope a query to installments in the `settled` state.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->where('status', InstallmentStatus::Settled->value);
    }

    /**
     * Scope a query to installments in the `overdue` state.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', InstallmentStatus::Overdue->value);
    }

    /**
     * Scope a query to installments in the `superseded` state.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->where('status', InstallmentStatus::Superseded->value);
    }

    /**
     * Scope a query to installments that are NOT superseded.
     *
     * This is the "current schedule" filter: it includes pending, partial,
     * settled, and overdue installments, and excludes superseded ones. Note
     * that a loan whose schedule has never been restructured has no
     * superseded rows, so this scope returns the entire schedule for such
     * a loan.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            InstallmentStatus::Superseded->value,
        ]);
    }

    /**
     * Scope a query to installments that still have an outstanding amount
     * owed to the lender.
     *
     * The set is `pending`, `partial`, and `overdue` (per
     * `InstallmentStatus::unsettledValues()`, A.5). Settled and superseded
     * installments are excluded.
     *
     * This is the scope that `LoanReconciliationService` (A.32) uses when
     * summing an installment's outstanding contribution to the loan's
     * reconciliation invariant.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereIn(
            'status',
            InstallmentStatus::unsettledValues(),
        );
    }

    /**
     * Scope a query to installments due on or before a given date.
     *
     * @param Builder              $query
     * @param CarbonInterface $date
     * @return Builder
     */
    public function scopeDueOnOrBefore(Builder $query, CarbonInterface $date): Builder
    {
        return $query->where('due_date', '<=', $date->toDateString());
    }

    /**
     * Scope a query to installments due strictly before a given date.
     *
     * @param Builder              $query
     * @param CarbonInterface $date
     * @return Builder
     */
    public function scopeDueBefore(Builder $query, CarbonInterface $date): Builder
    {
        return $query->where('due_date', '<', $date->toDateString());
    }

    /**
     * Scope a query to installments due strictly after a given date.
     *
     * @param Builder              $query
     * @param CarbonInterface $date
     * @return Builder
     */
    public function scopeDueAfter(Builder $query, CarbonInterface $date): Builder
    {
        return $query->where('due_date', '>', $date->toDateString());
    }

    /**
     * Scope a query to installments in chronological order.
     *
     * Orders by `due_date` ascending, with an `id` tiebreaker for
     * installments that share a due date. Installments of the same loan
     * on the same day are rare (the schedule advances by one month per
     * installment) but possible in edge cases — the `id` tiebreaker makes
     * the order deterministic.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('due_date')->orderBy('id');
    }

    // =========================================================================
    // Domain helpers — lifecycle state
    // =========================================================================

    /**
     * The installment's current status as an `InstallmentStatus` case.
     *
     * Because the `status` column is cast to the enum (see the class
     * docblock §"Casts"), this helper is a convenience wrapper for
     * `$installment->status`. It exists so that callers that prefer a
     * method call over a property read have a consistent entry point,
     * matching the pattern on `Loan::status()` (A.18).
     *
     * @return InstallmentStatus
     */
    public function status(): InstallmentStatus
    {
        return $this->status;
    }

    /**
     * Whether the installment is in the `pending` state.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === InstallmentStatus::Pending;
    }

    /**
     * Whether the installment is in the `partial` state.
     *
     * @return bool
     */
    public function isPartial(): bool
    {
        return $this->status === InstallmentStatus::Partial;
    }

    /**
     * Whether the installment is in the `settled` state.
     *
     * @return bool
     */
    public function isSettled(): bool
    {
        return $this->status === InstallmentStatus::Settled;
    }

    /**
     * Whether the installment is in the `overdue` state.
     *
     * @return bool
     */
    public function isOverdue(): bool
    {
        return $this->status === InstallmentStatus::Overdue;
    }

    /**
     * Whether the installment is in the `superseded` state.
     *
     * @return bool
     */
    public function isSuperseded(): bool
    {
        return $this->status === InstallmentStatus::Superseded;
    }

    /**
     * Whether the installment is in a terminal state — one from which
     * there is no outgoing transition.
     *
     * Delegates to `InstallmentStatus::isTerminal()` (A.5). Terminal
     * states are `settled` and `superseded`.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Whether the installment still has an outstanding amount owed to the
     * lender.
     *
     * Delegates to `InstallmentStatus::isUnsettled()` (A.5). True for
     * pending, partial, and overdue; false for settled and superseded.
     *
     * @return bool
     */
    public function isUnsettled(): bool
    {
        return $this->status->isUnsettled();
    }

    /**
     * Whether a structural transition from the installment's current
     * status to the given status is legal.
     *
     * Delegates to `InstallmentStatus::canTransitionTo()` (A.5). The check
     * is STRUCTURAL only. The services that drive the transitions
     * (`LoanRepaymentService`, A.27; `LoanArrearsService`, A.29;
     * `LoanRestructuringService`, A.30) compose this check with their own
     * guards, permission enforcement where applicable, and event dispatch.
     *
     * Direct use of this helper outside the services is intended for
     * tests and for read-only diagnostics, not for production writes.
     *
     * @param InstallmentStatus $next
     * @return bool
     */
    public function canTransitionTo(InstallmentStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    // =========================================================================
    // Domain helpers — financial state
    // =========================================================================

    /**
     * Whether the installment is fully settled by the borrower.
     *
     * An installment is fully settled when its `settled_amount` is greater
     * than or equal to its `expected_total`. The comparison uses bcmath at
     * scale 4.
     *
     * This reports the FINANCIAL fact, not the lifecycle state. A
     * fully-settled installment may be in the `settled` state (the state
     * machine has caught up) or — briefly, inside a transaction — still
     * in `pending` or `partial` (the state transition has not yet fired).
     * Callers that need the lifecycle state use `isSettled()`; callers
     * that need the financial fact use this method.
     *
     * @return bool
     */
    public function isFullySettled(): bool
    {
        return bccomp(
            (string) $this->settled_amount,
            (string) $this->expected_total,
            4,
        ) >= 0;
    }

    /**
     * Whether the installment has an interest accrual recognized against
     * it.
     *
     * Uses `relationLoaded()` to avoid an extra query when the relation is
     * already loaded, and otherwise issues an `exists()` query scoped to
     * this installment.
     *
     * @return bool
     */
    public function hasInterestAccrual(): bool
    {
        if ($this->relationLoaded('interestAccrual')) {
            return $this->interestAccrual !== null;
        }

        return $this->interestAccrual()->exists();
    }

    /**
     * Whether the installment has a penalty accrual recognized against it.
     *
     * Same query-awareness as `hasInterestAccrual()`.
     *
     * @return bool
     */
    public function hasPenaltyAccrual(): bool
    {
        if ($this->relationLoaded('penaltyAccrual')) {
            return $this->penaltyAccrual !== null;
        }

        return $this->penaltyAccrual()->exists();
    }

    /**
     * The remaining principal owed on this installment, as a decimal
     * string at scale 4.
     *
     * Computed as `expected_principal − settled_principal`, floored at
     * zero. An overpaid installment — which should never occur in a
     * well-formed schedule but is defensively handled — reports
     * `"0.0000"`, not a negative value.
     *
     * @return string  Decimal string at scale 4.
     */
    public function remainingPrincipal(): string
    {
        $remaining = bcsub(
            (string) $this->expected_principal,
            (string) $this->settled_principal,
            4,
        );

        return bccomp($remaining, '0', 4) < 0 ? '0.0000' : $remaining;
    }

    /**
     * The remaining interest owed on this installment, as a decimal string
     * at scale 4.
     *
     * Computed as `expected_interest − settled_interest`, floored at zero.
     * Same defensive reasoning as `remainingPrincipal()`.
     *
     * @return string  Decimal string at scale 4.
     */
    public function remainingInterest(): string
    {
        $remaining = bcsub(
            (string) $this->expected_interest,
            (string) $this->settled_interest,
            4,
        );

        return bccomp($remaining, '0', 4) < 0 ? '0.0000' : $remaining;
    }

    /**
     * The remaining total owed on this installment, as a decimal string at
     * scale 4.
     *
     * Computed as `expected_total − settled_amount`, floored at zero.
     *
     * This is the value that `LoanArrearsService` (A.29) uses as the
     * "overdue amount" basis for the penalty computation, per D133.
     *
     * @return string  Decimal string at scale 4.
     */
    public function remainingTotal(): string
    {
        $remaining = bcsub(
            (string) $this->expected_total,
            (string) $this->settled_amount,
            4,
        );

        return bccomp($remaining, '0', 4) < 0 ? '0.0000' : $remaining;
    }
}