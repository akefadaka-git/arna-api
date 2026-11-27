<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Domains\Loan\Enums\LoanStatus;

/**
 * Append-only authoritative loan lifecycle history; each status change records prior state, new state, actor and time.
 *
 * @property int                     $id
 * @property int                     $loan_id
 * @property LoanStatus|null         $from_status
 * @property LoanStatus              $to_status
 * @property string|null             $transition_reason
 * @property int|null                $actor_user_id
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property-read Loan|null          $loan
 * @property-read User|null          $actor
 * @package MaaleArna\Arna\Core\Domains\Loan\Models
 * @version 1.0.0
 */
final class LoanStatusHistory extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'loan_status_histories';

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
     * Whether Eloquent should manage `created_at` / `updated_at`
     * automatically.
     *
     * Set to false. The migration A.14 declares `created_at` as nullable
     * and does NOT declare an `updated_at` column at all. With
     * `$timestamps = false`, Eloquent will not attempt to write either
     * column on `save()`. `created_at` is written explicitly by
     * `LoanStateMachine` (A.26) at insert time.
     *
     * This mirrors the pattern established by Phase 2A's
     * `ShareHoldingEntry` (2A.9) and Phase 2B's `WalletEntry` (2B.10).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * The list is authoritative for the `LoanStateMachine` (A.26) insert
     * call site. Because the row is fully written at insert and never
     * modified, the `$fillable` list includes every column except the
     * primary key. `created_at` IS fillable: the state machine writes it
     * explicitly, and `$timestamps = false` means Eloquent will not
     * manage it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'loan_id',
        'from_status',
        'to_status',
        'transition_reason',
        'actor_user_id',
        'occurred_at',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * See the class docblock §"Casts" for the rationale on each group.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'loan_id'           => 'integer',
        'from_status'       => LoanStatus::class,
        'to_status'         => LoanStatus::class,
        'actor_user_id'     => 'integer',
        'occurred_at'       => 'datetime',
        'created_at'        => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. The `LoanStatusHistoryResource` (A.71)
     * selects fields explicitly.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Lifecycle — immutability enforcement
    // =========================================================================

    /**
     * Register the model's immutability guard.
     *
     * Called once per model class, after the model's traits have booted.
     * Registers two guards:
     *
     *   - `updating` — throws on any attempt to persist a change to a
     *                   loaded row.
     *   - `deleting` — throws on any attempt to remove a row.
     *
     * `creating` is intentionally NOT guarded: `LoanStateMachine` (A.26)
     * creates rows, and creation is the only legitimate write.
     *
     * The `updating` guard is doubly defensive on this model: because
     * `$timestamps = false`, Eloquent does not touch timestamps on
     * `save()`, but the guard still fires on any attempt to persist a
     * mutation to the row's other columns. The guard ensures that a
     * silent edit of the lifecycle log is impossible.
     *
     * The error message points at the correct remedy: the lifecycle log
     * is append-only; a correction is a NEW history entry, not an edit
     * of an existing one.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(static function (LoanStatusHistory $row): void {
            throw new LogicException(
                'LoanStatusHistory rows are immutable once written '
                . '(migration A.14, class docblock §"Immutability"). '
                . 'The lifecycle log is append-only; a correction is a '
                . 'new history entry, not an edit of an existing one. '
                . 'Attempted update on loan_status_histories.id='
                . $row->getKey() . '.'
            );
        });

        static::deleting(static function (LoanStatusHistory $row): void {
            throw new LogicException(
                'LoanStatusHistory rows cannot be deleted '
                . '(migration A.14, class docblock §"Deletion semantics"). '
                . 'Lifecycle history is permanent. '
                . 'Attempted delete on loan_status_histories.id='
                . $row->getKey() . '.'
            );
        });
    }

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The loan this transition applies to.
     *
     * @return BelongsTo
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id', 'id');
    }

    /**
     * The user who performed the transition, if any.
     *
     * Nullable: the column is nullable in the migration (A.14), and both
     * automatic transitions and hard-deleted users produce a null value.
     * See the migration A.14 §"`actor_user_id` is nullable" for the full
     * rationale.
     *
     * The relation is named `actor()` — not `actorUser()` or `performedBy()`
     * — to express the domain role. See the class docblock §"Relations".
     *
     * @return BelongsTo
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific loan.
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
     * Scope a query to transitions that ENTERED a specific status.
     *
     * Accepts either a `LoanStatus` case or its string value, so callers
     * do not need to normalise before invoking the scope.
     *
     * @param Builder              $query
     * @param LoanStatus|string    $status
     * @return Builder
     */
    public function scopeToStatus(Builder $query, LoanStatus|string $status): Builder
    {
        $value = $status instanceof LoanStatus ? $status->value : $status;

        return $query->where('to_status', $value);
    }

    /**
     * Scope a query to transitions that EXITED a specific status.
     *
     * Because `from_status` is nullable on the initial-creation row, a
     * caller that passes a non-null status filters out the initial row
     * automatically. To find the initial row, use `scopeAutomatic()` with
     * a null filter, or query `whereNull('from_status')` directly.
     *
     * @param Builder              $query
     * @param LoanStatus|string    $status
     * @return Builder
     */
    public function scopeFromStatus(Builder $query, LoanStatus|string $status): Builder
    {
        $value = $status instanceof LoanStatus ? $status->value : $status;

        return $query->where('from_status', $value);
    }

    /**
     * Scope a query to transitions performed by a specific actor.
     *
     * @param Builder $query
     * @param int     $actorUserId
     * @return Builder
     */
    public function scopeByActor(Builder $query, int $actorUserId): Builder
    {
        return $query->where('actor_user_id', $actorUserId);
    }

    /**
     * Scope a query to automatic transitions — those with no human actor.
     *
     * Automatic transitions include the initial-creation row
     * (`submitted → under_review` when the domain assigns a reviewer,
     * `disbursed → active`, `active → in_arrears`, `active → settled`,
     * `restructured → active`). See the migration A.14 for the full
     * enumeration.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeAutomatic(Builder $query): Builder
    {
        return $query->whereNull('actor_user_id');
    }

    /**
     * Scope a query to chronological order.
     *
     * Orders by `occurred_at` ascending, with an `id` tiebreaker for
     * same-instant transitions. Matches the migration A.14's primary
     * composite index `(loan_id, occurred_at)`; the `id` tiebreaker is
     * served by the second index `(loan_id, id)`.
     *
     * Same-instant transitions are legitimate: a disbursement transaction
     * that transitions `approved → disbursed` and, in the same
     * transaction, `disbursed → active`, writes two rows with identical
     * `occurred_at` values.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * Scope a query to most-recent-first order.
     *
     * The reverse of `scopeChronological()`. Used by the loan detail view
     * and by administrative dashboards that show the latest transitions
     * first.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeMostRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    // =========================================================================
    // Domain helpers (read-only)
    // =========================================================================

    /**
     * The status the loan was in before this transition, or null on the
     * initial-creation row.
     *
     * Because the `from_status` column is cast to the enum, this helper
     * is a convenience wrapper for `$history->from_status`. It exists so
     * that callers that prefer a method call over a property read have a
     * consistent entry point, matching the pattern on `Loan::status()`
     * (A.18) and `LoanInstallment::status()` (A.19).
     *
     * @return LoanStatus|null
     */
    public function fromStatus(): ?LoanStatus
    {
        return $this->from_status;
    }

    /**
     * The status the loan entered as a result of this transition.
     *
     * Because the `to_status` column is cast to the enum, this helper is
     * a convenience wrapper for `$history->to_status`. It never returns
     * null: the column is NOT NULL per the migration A.14.
     *
     * @return LoanStatus
     */
    public function toStatus(): LoanStatus
    {
        return $this->to_status;
    }

    /**
     * Whether the transition had no human actor.
     *
     * True when `actor_user_id` is null, which is the case for the
     * initial-creation row and for every automatic transition. See the
     * class docblock §"Relations" and the migration A.14
     * §"`actor_user_id` is nullable".
     *
     * @return bool
     */
    public function wasAutomatic(): bool
    {
        return $this->actor_user_id === null;
    }

    /**
     * Whether this row is the loan's initial-creation entry.
     *
     * True when `from_status` is null: the loan did not exist before this
     * row, and the row records the loan's entry into its first state
     * (`draft`).
     *
     * At most one row per loan satisfies this predicate, because a loan
     * is created exactly once.
     *
     * @return bool
     */
    public function isInitialCreation(): bool
    {
        return $this->from_status === null;
    }

    /**
     * Whether this row is a real transition.
     *
     * The logical complement of `isInitialCreation()`: true when
     * `from_status` is non-null, meaning the loan moved from an existing
     * state to a new one.
     *
     * @return bool
     */
    public function isTransition(): bool
    {
        return $this->from_status !== null;
    }
}