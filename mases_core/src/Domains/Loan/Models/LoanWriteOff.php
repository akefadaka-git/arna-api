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

/**
 * LoanWriteOff
 *
 * Eloquent model for a single write-off event on a loan.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * One row per write-off event. A write-off is the lender's decision to
 * forgive the loan's outstanding balance: the loan transitions to the
 * terminal `written_off` state, every outstanding Wallet obligation
 * linked to the loan is discharged, and the loan's projections are
 * zeroed.
 *
 * Each row is an immutable historical record: the loan's state at the
 * moment of write-off (the three `*_at_writeoff` columns), the amount
 * forgiven, the reason, the actor, and the moment. The row is NEVER
 * updated and NEVER deleted.
 *
 * A loan can be written off at most once, because `LoanStatus::WrittenOff`
 * is a terminal state (A.2) and no transition from it exists. There is
 * therefore at most one row per `loan_id` in this table. The invariant is
 * enforced by `LoanStateMachine` (A.26), not by a schema-level UNIQUE
 * constraint — see the migration A.13's class docblock
 * §"What this table does NOT enforce" for the reasoning.
 *
 * =============================================================================
 * Immutability — enforced at both the service and model layers
 * =============================================================================
 * The FROZEN immutability posture for this table mirrors
 * `LoanRestructuring` (A.21):
 *
 *   1. The migration A.13 declares the table as append-only by convention.
 *      It does not declare a `deleted_at` column, and no service path
 *      performs an update after insert.
 *
 *   2. `LoanWriteOffService::writeOff()` (A.31) is the SOLE writer. It
 *      inserts one row per write-off, inside the write-off transaction,
 *      and never updates the row afterwards. There is no other code path
 *      — no controller, resource, repository, or command — that touches
 *      this model's rows for writes.
 *
 *   3. This model ENFORCES the immutability at the language level, via a
 *      `booted()` guard that throws `LogicException` on any Eloquent-
 *      mediated `updating` or `deleting` event. Even if a future
 *      contributor accidentally calls `$row->save()` on a loaded model,
 *      the attempt fails loudly rather than silently mutating a
 *      historical record.
 *
 * The three layers — migration convention, service discipline, and model
 * guard — are deliberate defense-in-depth. The reasoning mirrors the
 * argument for `VersionedPolicy` (Core Policy, CORE.3),
 * `WalletEntry` (Phase 2B, 2B.10), and `LoanRestructuring` (A.21): a
 * silent mutation of a historical financial fact is worse than a loud
 * failure, and the cost of the guard is negligible.
 *
 * =============================================================================
 * The reference-id ordering role (Phase 2C Final Reconciliation §1.3)
 * =============================================================================
 * This table's primary key is the FIRST value produced in the write-off
 * transaction. The frozen ordering is:
 *
 *   1. Validate loan state, permission, and reason.
 *   2. INSERT this row → obtain $writeOffId.
 *   3. For each outstanding Wallet obligation linked to the loan:
 *        ObligationService::discharge(
 *          referenceType: 'LoanWriteOff',
 *          referenceId:   (string) $writeOffId,
 *          ...
 *        );
 *   4. Set the loan's projections to zero.
 *   5. Transition the loan's status to `written_off`.
 *   6. Register after-commit dispatch of LoanWrittenOff.
 *   COMMIT
 *
 * The ordering fix ensures that the Wallet discharge entries have a valid
 * reference id to point at — the id of the write-off row that will be
 * visible to audit once the transaction commits.
 *
 * The cross-domain reference is by OPAQUE STRING (as
 * `wallet_entries.reference_id`), not by FK. The Wallet has no dependency
 * on this table. A hard delete of a write-off row would not be blocked by
 * the Wallet, which is why this model guards against delete at the
 * language level (see §"Immutability").
 *
 * =============================================================================
 * The sum invariant
 * =============================================================================
 * Under the frozen write-off flow, the four amount columns satisfy:
 *
 *     write_off_amount
 *       == outstanding_principal_at_writeoff
 *        + outstanding_interest_at_writeoff
 *        + outstanding_penalty_at_writeoff
 *
 * The invariant is guaranteed by construction in
 * `LoanWriteOffService` (A.31), which computes the sum from the same
 * three values it writes. The schema does not enforce it, and this model
 * does not re-verify it on every read. Two read-only helpers are exposed
 * so that a consumer that needs to check the invariant (a report, a test,
 * a future diagnostic command) can do so without reimplementing it:
 *
 *     outstandingAtWriteOffTotal()  — sum of the three `*_at_writeoff`
 *                                     columns, at scale 4.
 *     isFullWriteOff()              — whether `write_off_amount` equals
 *                                     that sum (the standard case).
 *     isPartialWriteOff()           — whether `write_off_amount` is
 *                                     strictly less than that sum (a
 *                                     case that the current freeze does
 *                                     not produce but that a future CR
 *                                     may introduce).
 *
 * =============================================================================
 * Deletion semantics
 * =============================================================================
 * Write-off rows are NEVER deleted through any code path. The `booted()`
 * guard throws `LogicException` on any Eloquent-mediated `deleting` event.
 *
 * The migration A.13 declares no `deleted_at` column and no SoftDeletes
 * trait on the model. A write-off is a financial fact; it is preserved
 * indefinitely, even after the loan is fully closed.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   loan()          — the loan the write-off applies to.
 *   writtenOffBy()  — the user who performed the write-off.
 *
 * The actor relation is nullable and uses `nullOnDelete()` per the
 * migration A.13: a hard-deleted user detaches from the row, which
 * survives with a null actor. The nullable column also accommodates a
 * future CR that introduces system-initiated write-offs.
 *
 * =============================================================================
 * Casts
 * =============================================================================
 *   write_off_amount,
 *   outstanding_principal_at_writeoff,
 *   outstanding_interest_at_writeoff,
 *   outstanding_penalty_at_writeoff   — decimal:4 (string).
 *
 *   written_off_at,
 *   created_at,
 *   updated_at                        — datetime.
 *
 *   loan_id,
 *   written_off_by                    — integer.
 *
 * All monetary values are strings at scale 4. The platform-wide
 * convention: financial arithmetic uses bcmath on strings, never floats.
 *
 * There are NO enum casts on this model: the write-off record carries
 * only concrete values (amounts, dates, ids), never an enum case.
 *
 * =============================================================================
 * Scopes
 * =============================================================================
 *   scopeForLoan($query, $loanId)   — filter by owning loan.
 *   scopeChronological($query)      — order by written_off_at, id.
 *   scopeMostRecentFirst($query)    — order by written_off_at DESC,
 *                                      id DESC.
 *
 * The two order scopes match the migration A.13's single composite index
 * `(loan_id, written_off_at)`. Because a loan has at most one write-off,
 * the ordering rarely differentiates rows for a single loan; the scopes
 * are meaningful when a report enumerates write-offs across many loans.
 *
 * =============================================================================
 * Domain helpers (read-only)
 * =============================================================================
 *   outstandingAtWriteOffTotal()  — sum of the three `*_at_writeoff`
 *                                    columns, as a decimal string at
 *                                    scale 4.
 *   isFullWriteOff()              — whether `write_off_amount` equals
 *                                    the sum (the standard case).
 *   isPartialWriteOff()           — whether `write_off_amount` is
 *                                    strictly less than the sum.
 *   isExcessWriteOff()            — whether `write_off_amount` is
 *                                    strictly greater than the sum
 *                                    (a defect the service does not
 *                                    produce; the helper surfaces it).
 *
 * The three classification helpers partition the space: exactly one of
 * `isFullWriteOff()`, `isPartialWriteOff()`, and `isExcessWriteOff()` is
 * true for any well-formed row, and the three are exhaustive. They use
 * bcmath at scale 4.
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not expose any mutating method. The model is immutable; the
 *     `booted()` guard enforces it. See §"Immutability".
 *
 *   - It does not dispatch events. The `LoanWrittenOff` event (A.43) is
 *     dispatched by `LoanWriteOffService` (A.31) after the write-off
 *     transaction commits.
 *
 *   - It does not evaluate any policy. The write-off amount is the sum of
 *     the loan's outstanding projections at the moment of write-off; no
 *     policy computation enters this model.
 *
 *   - It does not use SoftDeletes. Write-off history is permanent.
 *
 *   - It does not attempt a `reverse()` method. Phase 2C does not support
 *     reversing a write-off. A future Change Request that introduces
 *     reversal would add a linkage column (or a separate reversal table)
 *     and a corresponding service, and would also have to confront the
 *     state-machine implications (the loan is already in the terminal
 *     `written_off` state).
 *
 *   - It does not carry a denormalized snapshot of the loan's full
 *     financial state at write-off time (schedule summary, installments,
 *     etc.). The three `*_at_writeoff` columns and `write_off_amount` are
 *     sufficient to reconstruct "what was forgiven"; a consumer that
 *     needs "what the loan looked like before" reads the loan's
 *     `loan_status_histories` (A.14) and the (now-superseded or settled)
 *     installment rows.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130, A10, B2
 *   Phase 2C Clarification Response, 2026-09-12  — C4, C5
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.3 (reference-id
 *                                                  ordering), §3 (the
 *                                                  invariant that a
 *                                                  write-off preserves)
 *   Core Policy CORE.3                           — `VersionedPolicy` (the
 *                                                  immutability-guard
 *                                                  precedent)
 *   Phase 2B `WalletEntry`                       — the sibling
 *                                                  immutability-guard
 *                                                  precedent
 *   Phase 2C A.21                                — `LoanRestructuring`
 *                                                  (the structural
 *                                                  sibling)
 *   Step 5 (manifest) A.22                       — This file
 *   Step 6 v1.1.0 §3.13                          — `loan_write_offs`
 *                                                  table specification
 *
 * @property int                     $id
 * @property int                     $loan_id
 * @property string                  $write_off_amount
 * @property string                  $outstanding_principal_at_writeoff
 * @property string                  $outstanding_interest_at_writeoff
 * @property string                  $outstanding_penalty_at_writeoff
 * @property string                  $reason
 * @property Carbon $written_off_at
 * @property int|null                $written_off_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Loan|null          $loan
 * @property-read User|null          $writtenOffBy
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Models
 * @version 1.0.0
 */
final class LoanWriteOff extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'loan_write_offs';

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
     * The list is authoritative for the `LoanWriteOffService::writeOff()`
     * call site (A.31), which inserts one row per write-off. Because the
     * row is fully written at insert and never modified, the `$fillable`
     * list includes every column except the primary key and the Eloquent-
     * managed timestamps.
     *
     * `written_off_at` and `written_off_by` ARE fillable: the service
     * supplies them explicitly. The migration A.13 declares
     * `written_off_at` with a `useCurrent()` default, but the service
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
        'write_off_amount',
        'outstanding_principal_at_writeoff',
        'outstanding_interest_at_writeoff',
        'outstanding_penalty_at_writeoff',
        'reason',
        'written_off_at',
        'written_off_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * See the class docblock §"Casts" for the rationale on each group.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'loan_id'                            => 'integer',
        'write_off_amount'                   => 'decimal:4',
        'outstanding_principal_at_writeoff'  => 'decimal:4',
        'outstanding_interest_at_writeoff'   => 'decimal:4',
        'outstanding_penalty_at_writeoff'    => 'decimal:4',
        'written_off_at'                     => 'datetime',
        'written_off_by'                     => 'integer',
        'created_at'                         => 'datetime',
        'updated_at'                         => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. The `LoanResource` (A.64) embeds the
     * write-off via `whenLoaded()` when a controller chooses to load it;
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
     * `creating` is intentionally NOT guarded: `LoanWriteOffService`
     * (A.31) creates rows, and creation is the only legitimate write.
     *
     * The guard mirrors the pattern established by Core Policy's
     * `VersionedPolicy` (CORE.3), Phase 2B's `WalletEntry` (2B.10), and
     * the sibling `LoanRestructuring` (A.21).
     *
     * The error message points at the correct remedy: a write-off is a
     * terminal financial fact. Reversing a write-off is out of Phase 2C
     * scope and requires its own Change Request.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(static function (LoanWriteOff $row): void {
            throw new LogicException(
                'LoanWriteOff rows are immutable once written '
                . '(migration A.13, class docblock §"Immutability"). '
                . 'A write-off is a terminal financial fact; reversing '
                . 'one requires a dedicated Change Request. '
                . 'Attempted update on loan_write_offs.id='
                . $row->getKey() . '.'
            );
        });

        static::deleting(static function (LoanWriteOff $row): void {
            throw new LogicException(
                'LoanWriteOff rows cannot be deleted '
                . '(migration A.13, class docblock §"Deletion semantics"). '
                . 'Write-off history is permanent. '
                . 'Attempted delete on loan_write_offs.id='
                . $row->getKey() . '.'
            );
        });
    }

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The loan this write-off applies to.
     *
     * @return BelongsTo
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id', 'id');
    }

    /**
     * The user who performed the write-off.
     *
     * Nullable and `nullOnDelete()` per migration A.13: a hard-deleted
     * user detaches from the row, which survives with a null actor.
     *
     * The nullable column also accommodates a future Change Request that
     * introduces system-initiated write-offs (e.g. an end-of-fiscal-year
     * policy that forgives sub-threshold balances), for which there is no
     * human actor.
     *
     * @return BelongsTo
     */
    public function writtenOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_off_by', 'id');
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
     * Orders by `written_off_at` ascending, with an `id` tiebreaker for
     * same-instant write-offs. Matches the migration A.13's single
     * composite index `(loan_id, written_off_at)`; the `id` tiebreaker
     * uses the primary key.
     *
     * Because a loan has at most one write-off, the ordering is
     * meaningful only when a report enumerates write-offs across many
     * loans.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('written_off_at')->orderBy('id');
    }

    /**
     * Scope a query to most-recent-first order.
     *
     * The reverse of `scopeChronological()`. Used by administrative
     * dashboards that show the latest write-offs first.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeMostRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('written_off_at')->orderByDesc('id');
    }

    // =========================================================================
    // Domain helpers (read-only)
    // =========================================================================

    /**
     * The sum of the three `*_at_writeoff` columns, as a decimal string
     * at scale 4.
     *
     * This is the loan's total outstanding at the moment the write-off
     * was performed. Under the frozen write-off flow, this equals the
     * row's `write_off_amount`; the two are separate for the reasons
     * documented in the migration A.13 §"The 'at write-off' columns and
     * the sum invariant".
     *
     * The three columns are NOT NULL per migration A.13, so no null
     * handling is required.
     *
     * @return string  Decimal string at scale 4.
     */
    public function outstandingAtWriteOffTotal(): string
    {
        return bcadd(
            bcadd(
                (string) $this->outstanding_principal_at_writeoff,
                (string) $this->outstanding_interest_at_writeoff,
                4,
            ),
            (string) $this->outstanding_penalty_at_writeoff,
            4,
        );
    }

    /**
     * Whether this is a full write-off.
     *
     * True when `write_off_amount` equals the sum of the three
     * `*_at_writeoff` columns at scale 4. This is the standard case that
     * the frozen write-off flow produces: the entire outstanding balance
     * is forgiven.
     *
     * @return bool
     */
    public function isFullWriteOff(): bool
    {
        return bccomp(
            (string) $this->write_off_amount,
            $this->outstandingAtWriteOffTotal(),
            4,
        ) === 0;
    }

    /**
     * Whether this is a partial write-off.
     *
     * True when `write_off_amount` is strictly less than the sum of the
     * three `*_at_writeoff` columns at scale 4.
     *
     * The frozen Phase 2C write-off flow does NOT produce partial
     * write-offs — every write-off forgives the entire outstanding
     * balance, and the loan transitions to `written_off` in the same
     * transaction. The helper exists because:
     *
     *   1. A future Change Request may introduce partial write-offs (for
     *      example, a restructuring-adjacent operation that forgives a
     *      portion of the debt and reschedules the remainder).
     *
     *   2. A defensive check in an audit or reconciliation pass can use
     *      the helper to detect whether an unexpected partial write-off
     *      has appeared in the data.
     *
     * @return bool
     */
    public function isPartialWriteOff(): bool
    {
        return bccomp(
            (string) $this->write_off_amount,
            $this->outstandingAtWriteOffTotal(),
            4,
        ) < 0;
    }

    /**
     * Whether this is an excess write-off — a write-off amount greater
     * than the sum of the three `*_at_writeoff` columns.
     *
     * The frozen write-off flow cannot produce an excess write-off: the
     * amount is computed from the same three values that are stored on
     * the row. A `true` return from this helper indicates a data
     * integrity anomaly (either a service defect or an out-of-band edit
     * that bypassed the guard). It is surfaced so that an audit or
     * reconciliation pass can detect the anomaly rather than silently
     * ignore it.
     *
     * @return bool
     */
    public function isExcessWriteOff(): bool
    {
        return bccomp(
            (string) $this->write_off_amount,
            $this->outstandingAtWriteOffTotal(),
            4,
        ) > 0;
    }
}