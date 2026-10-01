<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;

/**
 * LoanStatusHistory
 *
 * Eloquent model for a single status transition on a loan.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * One row per status transition. Every time a loan's `status` column (A.9)
 * changes, exactly one row is appended here recording the transition: the
 * prior status, the new status, the moment of the change, the actor who
 * performed it, and an optional reason.
 *
 * The table is APPEND-ONLY. Rows are never updated and never deleted. It
 * is the authoritative historical record of the loan's lifecycle; the
 * `loans.status` column is the CURRENT state, not the history.
 *
 * The distinction matters for audit: `loans` answers "what is the loan's
 * state now?"; this table answers "how did the loan get here?".
 *
 * =============================================================================
 * The two-column business-time convention
 * =============================================================================
 * Each row carries two timestamps:
 *
 *     occurred_at  — the moment the transition occurred, per the
 *                    domain's clock. The semantically meaningful time.
 *
 *     created_at   — the moment the row was inserted into the database.
 *                    Always equals `occurred_at` in Phase 2C, because
 *                    `LoanStateMachine` (A.26) writes both from the same
 *                    `Carbon::now()` value inside one transaction.
 *
 * The two are kept separate because a future Change Request that
 * introduces back-dated transitions (for example, an import of historical
 * loan records, or an administrative reconstruction of an earlier state)
 * would legitimately set `occurred_at` to a past moment while leaving
 * `created_at` at the insert time. The two-column design accommodates
 * that without a schema change. In Phase 2C, no code path produces a
 * divergence.
 *
 * =============================================================================
 * The initial-creation row
 * =============================================================================
 * The FIRST history row for a loan is a special case: the loan did not
 * exist before this moment, so there is no prior status. The
 * `LoanStateMachine` writes such a row when the loan is initially created
 * in `draft`, with:
 *
 *     from_status = null
 *     to_status   = 'draft'
 *
 * All subsequent rows have a non-null `from_status`, because every
 * subsequent transition originates from an existing state. The invariant
 * "from_status is null only on the initial row" is enforced by
 * `LoanStateMachine` (A.26), which is the sole writer.
 *
 * Because `from_status` is nullable, this model exposes it as
 * `?LoanStatus` (the cast returns null for the initial row). The
 * `to_status` column is NOT NULL and always casts to a `LoanStatus` case.
 *
 * =============================================================================
 * Immutability — enforced at both the service and model layers
 * =============================================================================
 * The FROZEN immutability posture for this table mirrors
 * `LoanRestructuring` (A.21) and `LoanWriteOff` (A.22):
 *
 *   1. The migration A.14 declares the table as append-only by design. It
 *      declares `created_at` as nullable and does NOT declare an
 *      `updated_at` column at all. There is no column to update.
 *
 *   2. `LoanStateMachine` (A.26) is the SOLE writer. It inserts one row
 *      per transition, inside the transition's transaction, and never
 *      updates the row afterwards.
 *
 *   3. This model ENFORCES the immutability at the language level, via a
 *      `booted()` guard that throws `LogicException` on any Eloquent-
 *      mediated `updating` or `deleting` event. The `updating` guard is
 *      doubly defensive: even though the table has no `updated_at`
 *      column, Eloquent's default `$timestamps = true` behaviour would
 *      attempt to set one on any `save()` call. This model therefore
 *      also sets `$timestamps = false`, so the `updating` event is the
 *      second line of defence, not the first.
 *
 * The three layers — migration convention, service discipline, and model
 * guard — are deliberate defense-in-depth. The reasoning mirrors the
 * arguments in A.21 and A.22: a silent mutation of a lifecycle-log row
 * would corrupt the audit trail and would break the invariant that the
 * sequence of `from_status` / `to_status` pairs forms a continuous path.
 *
 * =============================================================================
 * Timestamps — no `updated_at`
 * =============================================================================
 * The model sets `$timestamps = false`. This is REQUIRED, not merely
 * defensive: the migration A.14 does not declare an `updated_at` column,
 * and Eloquent's default timestamp management would attempt to write one
 * on `save()`. With `$timestamps = false`, both `created_at` and
 * `updated_at` are managed manually:
 *
 *     - `created_at` is written explicitly by `LoanStateMachine` (A.26)
 *       at insert time, from the same `Carbon::now()` value that
 *       produces `occurred_at`. The two values are therefore identical
 *       by construction.
 *
 *     - `updated_at` is never written, because there is no such column.
 *
 * This mirrors the pattern established by Phase 2A's `ShareHoldingEntry`
 * (2A.9) and Phase 2B's `WalletEntry` (2B.10), both of which set
 * `$timestamps = false` for the same reason.
 *
 * =============================================================================
 * Deletion semantics
 * =============================================================================
 * Status-history rows are NEVER deleted through any code path. The
 * `booted()` guard throws `LogicException` on any Eloquent-mediated
 * `deleting` event.
 *
 * The migration A.14 declares no `deleted_at` column and no SoftDeletes
 * trait on the model.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   loan()         — the loan the transition applies to.
 *   actor()        — the user who performed the transition, if any.
 *
 * The actor relation is nullable and uses `nullOnDelete()` per the
 * migration A.14: a hard-deleted user detaches from the row, which
 * survives with a null actor. The nullable column ALSO accommodates
 * automatic transitions — those driven by the domain's own logic in
 * response to a business event, without a human actor — which carry a
 * null actor by construction (see the migration A.14 §"`actor_user_id` is
 * nullable" for the full rationale).
 *
 * The relation is named `actor()` rather than `actorUser()` to express
 * the domain role ("the actor of this transition") rather than the
 * underlying model class. This matches the pattern established by
 * Phase 2B's actor relations, which are named for their domain role
 * (`createdBy`, `allocatedBy`, `reversedBy`).
 *
 * =============================================================================
 * Casts
 * =============================================================================
 *   from_status        — LoanStatus|null (nullable; null on the initial
 *                         creation row).
 *   to_status          — LoanStatus (never null).
 *   loan_id            — integer.
 *   actor_user_id      — integer|null.
 *   occurred_at        — datetime.
 *   created_at         — datetime|null (nullable because the column is
 *                         nullable and, in a well-formed row, always
 *                         written; the null branch is defensive).
 *
 * The enum casts mirror the pattern established by `Loan.status` (A.18)
 * and `LoanInstallment.status` (A.19): an unrecognised value is a defect
 * and should surface as an error rather than as a silently-tolerated
 * anomaly.
 *
 * =============================================================================
 * Scopes
 * =============================================================================
 *   scopeForLoan($query, $loanId)             — filter by owning loan.
 *   scopeToStatus($query, $status)            — filter by entered status.
 *   scopeFromStatus($query, $status)          — filter by exited status.
 *   scopeByActor($query, $actorUserId)        — filter by actor.
 *   scopeAutomatic($query)                    — actor_user_id IS NULL.
 *   scopeChronological($query)                — order by occurred_at, id.
 *   scopeMostRecentFirst($query)              — order by occurred_at DESC,
 *                                                id DESC.
 *
 * The scope set is deliberately exhaustive. Every read pattern that a
 * service, a report, or a test needs is expressible through a composition
 * of these scopes.
 *
 * =============================================================================
 * Domain helpers (read-only)
 * =============================================================================
 *   fromStatus()              — the LoanStatus the loan was in before, or
 *                                null on the initial-creation row.
 *   toStatus()                — the LoanStatus the loan entered.
 *   wasAutomatic()            — the transition had no human actor.
 *   isInitialCreation()       — the row is the loan's initial-creation
 *                                entry (from_status === null).
 *   isTransition()            — the row is a real transition (from_status
 *                                !== null); the logical complement of
 *                                isInitialCreation().
 *   durationFromPrevious()    — the interval between the previous row's
 *                                `occurred_at` and this row's, as a
 *                                Carbon `DateInterval`, or null on the
 *                                initial row. NOT implemented in this
 *                                model; the sequence gap is a query-time
 *                                concern, not a per-row computation. See
 *                                §"What this model deliberately does NOT
 *                                do".
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not expose any mutating method. The model is immutable; the
 *     `booted()` guard enforces it. See §"Immutability".
 *
 *   - It does not dispatch events. The `LoanStatusHistory` table is a
 *     passive log; the domain events that fire on transitions
 *     (`LoanApplicationSubmitted`, `LoanApproved`, `LoanRejected`,
 *     `LoanDisbursed`, `LoanEnteredArrears`, `LoanRestructured`,
 *     `LoanWrittenOff`, `LoanSettled`, `LoanClosed`) are dispatched by
 *     `LoanStateMachine` (A.26) after the transition transaction commits.
 *
 *   - It does not compute the interval between consecutive rows. That is a
 *     query-time concern: a caller that needs the duration between two
 *     transitions loads the ordered sequence and computes the interval in
 *     memory. A per-row `durationFromPrevious()` helper would either
 *     trigger a query per row (N+1) or require the caller to pass the
 *     previous row explicitly, neither of which belongs on the model.
 *
 *   - It does not evaluate any policy. Status transitions are gated by
 *     `LoanStateMachine`'s guards, permissions, and events; the history
 *     row records the outcome. No policy enters this model.
 *
 *   - It does not use SoftDeletes. Lifecycle history is permanent.
 *
 *   - It does not declare an `updated_at` property in its attribute
 *     docblock. The column does not exist.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — C4 (state freeze)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (transition matrix)
 *   Phase 2A migration 2A.40                     — `share_holding_entries`
 *                                                  (append-only pattern,
 *                                                  `$timestamps = false`)
 *   Phase 2B migration 2B.54                     — `wallet_entries`
 *                                                  (append-only pattern,
 *                                                  `$timestamps = false`)
 *   Core Policy CORE.3                           — `VersionedPolicy` (the
 *                                                  immutability-guard
 *                                                  precedent)
 *   Step 5 (manifest) A.23                       — This file
 *   Step 6 v1.1.0 §3.14                          — `loan_status_histories`
 *                                                  table specification
 *
 * @property int                     $id
 * @property int                     $loan_id
 * @property LoanStatus|null         $from_status
 * @property LoanStatus              $to_status
 * @property string|null             $transition_reason
 * @property int|null                $actor_user_id
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 *
 * @property-read Loan|null          $loan
 * @property-read User|null          $actor
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Models
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