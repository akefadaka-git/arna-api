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
use MaaleArna\Arna\Core\Policy\Models\VersionedPolicy;

/**
 * Immutable restructuring history; multiple events may replace contractual terms and regenerate the repayment schedule.
 *
 * @property int                     $id
 * @property int                     $loan_id
 * @property int|null                $previous_policy_version_id
 * @property string                  $previous_outstanding_principal
 * @property string                  $previous_outstanding_interest
 * @property string                  $previous_outstanding_penalty
 * @property Carbon|null $previous_maturity_date
 * @property int                     $previous_installment_count
 * @property int|null                $new_policy_version_id
 * @property string                  $new_principal_amount
 * @property int                     $new_term_months
 * @property Carbon $new_maturity_date
 * @property string                  $new_total_scheduled_repayment
 * @property int                     $new_installment_count
 * @property string                  $reason
 * @property Carbon $restructured_at
 * @property int|null                $restructured_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Loan|null               $loan
 * @property-read VersionedPolicy|null    $previousPolicyVersion
 * @property-read VersionedPolicy|null    $newPolicyVersion
 * @property-read User|null               $restructuredBy
 * @package MaaleArna\Arna\Core\Domains\Loan\Models
 * @version 1.0.0
 */
final class LoanRestructuring extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'loan_restructurings';

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
     * The list is authoritative for the `LoanRestructuringService::restructure()`
     * call site (A.30), which inserts one row per restructure. Because the
     * row is fully written at insert and never modified, the `$fillable`
     * list includes every column except the primary key and the Eloquent-
     * managed timestamps.
     *
     * `restructured_at` and `restructured_by` ARE fillable: the service
     * supplies them explicitly. The migration A.12 declares
     * `restructured_at` with a `useCurrent()` default, but the service
     * supplies the value for consistency with `created_at`.
     *
     * `id` is NOT fillable — it is auto-assigned by the database.
     * `created_at` and `updated_at` are NOT fillable — Eloquent manages
     * them via the `$timestamps` mechanism.
     *
     * @var list<string>
     */
    protected $fillable = [
        'loan_id',
        'previous_policy_version_id',
        'previous_outstanding_principal',
        'previous_outstanding_interest',
        'previous_outstanding_penalty',
        'previous_maturity_date',
        'previous_installment_count',
        'new_policy_version_id',
        'new_principal_amount',
        'new_term_months',
        'new_maturity_date',
        'new_total_scheduled_repayment',
        'new_installment_count',
        'reason',
        'restructured_at',
        'restructured_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * See the class docblock §"Casts" for the rationale on each group.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'loan_id'                        => 'integer',
        'previous_policy_version_id'     => 'integer',
        'previous_outstanding_principal' => 'decimal:4',
        'previous_outstanding_interest'  => 'decimal:4',
        'previous_outstanding_penalty'   => 'decimal:4',
        'previous_maturity_date'         => 'datetime',
        'previous_installment_count'     => 'integer',
        'new_policy_version_id'          => 'integer',
        'new_principal_amount'           => 'decimal:4',
        'new_term_months'                => 'integer',
        'new_maturity_date'              => 'datetime',
        'new_total_scheduled_repayment'  => 'decimal:4',
        'new_installment_count'          => 'integer',
        'restructured_at'                => 'datetime',
        'restructured_by'                => 'integer',
        'created_at'                     => 'datetime',
        'updated_at'                     => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. The `LoanResource` (A.64) embeds restructuring
     * history via `whenLoaded()` when a controller chooses to load it;
     * the model does not force-serialize a computed value.
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
     * `creating` is intentionally NOT guarded: `LoanRestructuringService`
     * (A.30) creates rows, and creation is the only legitimate write.
     *
     * The guard mirrors the pattern established by Core Policy's
     * `VersionedPolicy` (CORE.3) and Phase 2B's `WalletEntry` (2B.10).
     * Both throw `LogicException` on `updating` and `deleting`; the
     * rationale is documented in each file's class docblock.
     *
     * The error message points at the correct remedy: a restructure is
     * corrected by a NEW restructuring (or, in Phase 2C, not at all —
     * reversing a restructure is out of scope and requires its own CR).
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(static function (LoanRestructuring $row): void {
            throw new LogicException(
                'LoanRestructuring rows are immutable once written '
                . '(migration A.12, class docblock §"Immutability"). '
                . 'A restructuring is corrected by a new restructuring '
                . 'event, not by modifying this row. '
                . 'Attempted update on loan_restructurings.id='
                . $row->getKey() . '.'
            );
        });

        static::deleting(static function (LoanRestructuring $row): void {
            throw new LogicException(
                'LoanRestructuring rows cannot be deleted '
                . '(migration A.12, class docblock §"Deletion semantics"). '
                . 'Restructuring history is permanent. '
                . 'Attempted delete on loan_restructurings.id='
                . $row->getKey() . '.'
            );
        });
    }

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The loan this restructuring applies to.
     *
     * @return BelongsTo
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id', 'id');
    }

    /**
     * The policy version in effect before the restructure.
     *
     * Nullable: the column is nullable in the migration, and a defensive
     * null is tolerated. `LoanRestructuringService` (A.30) supplies a
     * non-null value on every row in practice.
     *
     * @return BelongsTo
     */
    public function previousPolicyVersion(): BelongsTo
    {
        return $this->belongsTo(
            VersionedPolicy::class,
            'previous_policy_version_id',
            'id',
        );
    }

    /**
     * The policy version governing the restructured loan.
     *
     * Nullable for the same reason as `previousPolicyVersion()`.
     *
     * @return BelongsTo
     */
    public function newPolicyVersion(): BelongsTo
    {
        return $this->belongsTo(
            VersionedPolicy::class,
            'new_policy_version_id',
            'id',
        );
    }

    /**
     * The user who performed the restructure.
     *
     * Nullable and `nullOnDelete()` per migration A.12: a hard-deleted
     * user detaches from the row, which survives with a null actor.
     *
     * @return BelongsTo
     */
    public function restructuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restructured_by', 'id');
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
     * Scope a query to chronological order.
     *
     * Orders by `restructured_at` ascending, with an `id` tiebreaker for
     * same-instant restructures. Matches the migration A.12's primary
     * composite index `(loan_id, restructured_at)`; the `id` tiebreaker
     * is served by the second index `(loan_id, id)`.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('restructured_at')->orderBy('id');
    }

    /**
     * Scope a query to most-recent-first order.
     *
     * The reverse of `scopeChronological()`. Used by the loan detail view
     * and by administrative dashboards that show the latest restructure
     * first.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeMostRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('restructured_at')->orderByDesc('id');
    }

    // =========================================================================
    // Domain helpers (read-only)
    // =========================================================================

    /**
     * The sum of the three `previous_outstanding_*` columns, as a decimal
     * string at scale 4.
     *
     * This is the loan's total outstanding at the moment the restructure
     * was performed. It is used by the loan detail view and by reports
     * that summarize "how much was owed before the restructure".
     *
     * The three columns are NOT NULL per migration A.12, so no null
     * handling is required.
     *
     * @return string  Decimal string at scale 4.
     */
    public function previousOutstandingTotal(): string
    {
        return bcadd(
            bcadd(
                (string) $this->previous_outstanding_principal,
                (string) $this->previous_outstanding_interest,
                4,
            ),
            (string) $this->previous_outstanding_penalty,
            4,
        );
    }

    /**
     * Whether the restructure changed the loan's policy version.
     *
     * True when `previous_policy_version_id` and `new_policy_version_id`
     * are both non-null and differ. A null on either side is treated as
     * "no change" — the helper does not distinguish "no policy before" or
     * "no policy after" from "same policy", because both sides being
     * present is the invariant that `LoanRestructuringService` (A.30)
     * maintains. A caller that needs the null case distinguished reads
     * the two columns directly.
     *
     * @return bool
     */
    public function hasPolicyChanged(): bool
    {
        if ($this->previous_policy_version_id === null
            || $this->new_policy_version_id === null
        ) {
            return false;
        }

        return $this->previous_policy_version_id !== $this->new_policy_version_id;
    }

    /**
     * Whether the restructure increased the loan's principal.
     *
     * Compares `new_principal_amount` against
     * `previous_outstanding_principal` using bcmath at scale 4. The
     * comparison is against the PREVIOUS OUTSTANDING PRINCIPAL, not
     * against the prior schedule's original principal, because a
     * restructure is measured against what was still owed at the moment
     * of the restructure.
     *
     * @return bool
     */
    public function isPrincipalIncrease(): bool
    {
        return bccomp(
            (string) $this->new_principal_amount,
            (string) $this->previous_outstanding_principal,
            4,
        ) > 0;
    }

    /**
     * Whether the restructure decreased the loan's principal.
     *
     * @return bool
     */
    public function isPrincipalDecrease(): bool
    {
        return bccomp(
            (string) $this->new_principal_amount,
            (string) $this->previous_outstanding_principal,
            4,
        ) < 0;
    }

    /**
     * Whether the restructure left the loan's principal unchanged.
     *
     * A "term-only" restructure — one that extends or shortens the loan
     * without changing the outstanding principal — satisfies this
     * predicate. It is the most common case for a voluntary restructuring
     * requested by a borrower in temporary hardship.
     *
     * @return bool
     */
    public function isPrincipalUnchanged(): bool
    {
        return bccomp(
            (string) $this->new_principal_amount,
            (string) $this->previous_outstanding_principal,
            4,
        ) === 0;
    }
}