<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\Arna\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\Arna\Core\Policy\Models\VersionedPolicy;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;

/**
 * Tenant-scoped financial fact. Contract terms freeze at approval; lifecycle and financial mutations go through LoanStateMachine and transactional domain services. Restructures are explicit immutable events.
 *
 * @property int                        $id
 * @property int                        $tenant_id
 * @property int                        $borrower_shareholder_id
 * @property int                        $loan_product_id
 * @property LoanStatus                 $status
 * @property string                     $principal_amount
 * @property int                        $term_months
 * @property string|null                $currency
 * @property int|null                   $frozen_policy_version_id
 * @property string|null                $monthly_installment_amount
 * @property string|null                $total_scheduled_interest
 * @property string|null                $total_scheduled_repayment
 * @property string                     $settled_amount
 * @property string|null                $outstanding_principal
 * @property string|null                $outstanding_interest
 * @property string                     $outstanding_penalty
 * @property Carbon $application_date
 * @property Carbon|null $decision_date
 * @property Carbon|null $disbursement_date
 * @property Carbon|null $maturity_date
 * @property Carbon|null $closed_at
 * @property string|null                $purpose
 * @property string|null                $external_reference
 * @property int|null                   $approved_by
 * @property int|null                   $disbursed_by
 * @property int|null                   $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant|null           $tenant
 * @property-read Shareholder|null      $borrower
 * @property-read LoanProduct|null      $product
 * @property-read VersionedPolicy|null  $policyVersion
 * @property-read User|null             $approvedBy
 * @property-read User|null             $disbursedBy
 * @property-read User|null             $createdBy
 * @property-read Collection<int, LoanInstallment> $installments
 * @property-read Collection<int, LoanCollateral> $collaterals
 * @property-read Collection<int, LoanRestructuring> $restructurings
 * @property-read LoanWriteOff|null     $writeOff
 * @property-read Collection<int, LoanStatusHistory> $statusHistories
 * @property-read Collection<int, LoanInterestAccrual> $interestAccruals
 * @property-read Collection<int, LoanPenaltyAccrual> $penaltyAccruals
 * @package MaaleArna\Arna\Core\Domains\Loan\Models
 * @version 1.0.0
 */
final class Loan extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'loans';

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
     * The list is authoritative for the `LoanApplicationService::createDraft()`
     * call site. Every other state transition and projection update is
     * performed by the corresponding service through explicit attribute
     * assignment followed by `save()`, not through mass assignment —
     * precisely so that a caller cannot mass-assign a status change.
     *
     * The non-mass-assignable columns are:
     *
     *   id                  — auto-assigned.
     *   status              — set exclusively by `LoanStateMachine`.
     *   decision_date       — set exclusively by `LoanApprovalService`.
     *   disbursement_date   — set exclusively by `LoanDisbursementService`.
     *   maturity_date       — set exclusively by `LoanDisbursementService`.
     *   closed_at           — set exclusively by `LoanStateMachine`.
     *   settled_amount      — set exclusively by `LoanRepaymentService`.
     *   outstanding_*       — set exclusively by their owning services.
     *   monthly_installment_amount,
     *   total_scheduled_*   — set exclusively by `LoanScheduleService`.
     *   approved_by         — set exclusively by `LoanApprovalService`.
     *   disbursed_by        — set exclusively by `LoanDisbursementService`.
     *   created_at, updated_at — managed by Eloquent.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'borrower_shareholder_id',
        'loan_product_id',
        'principal_amount',
        'term_months',
        'purpose',
        'external_reference',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * See the class docblock §"Casts" for the rationale on each group.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tenant_id'                       => 'integer',
        'borrower_shareholder_id'         => 'integer',
        'loan_product_id'                 => 'integer',
        'status'                          => LoanStatus::class,
        'principal_amount'                => 'decimal:4',
        'term_months'                     => 'integer',
        'frozen_policy_version_id'        => 'integer',
        'monthly_installment_amount'      => 'decimal:4',
        'total_scheduled_interest'        => 'decimal:4',
        'total_scheduled_repayment'       => 'decimal:4',
        'settled_amount'                  => 'decimal:4',
        'outstanding_principal'           => 'decimal:4',
        'outstanding_interest'            => 'decimal:4',
        'outstanding_penalty'             => 'decimal:4',
        'application_date'                => 'datetime',
        'decision_date'                   => 'datetime',
        'disbursement_date'               => 'datetime',
        'maturity_date'                   => 'datetime',
        'closed_at'                       => 'datetime',
        'approved_by'                     => 'integer',
        'disbursed_by'                    => 'integer',
        'created_by'                      => 'integer',
        'created_at'                      => 'datetime',
        'updated_at'                      => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. The `LoanResource` (A.64) selects fields
     * explicitly. The `outstandingTotal()` helper is available on demand
     * but is not force-serialized, so that a resource that prefers to
     * compose the value itself is not forced to include it.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The tenant that owns this loan.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * The shareholder who is the borrower.
     *
     * Per D129, the lender is the company by construction; there is no
     * `lender()` relation, because no other lender exists. The FK column
     * is `borrower_shareholder_id`, and the relation targets the Phase 2A
     * `Shareholder` model.
     *
     * @return BelongsTo
     */
    public function borrower(): BelongsTo
    {
        return $this->belongsTo(
            Shareholder::class,
            'borrower_shareholder_id',
            'id',
        );
    }

    /**
     * The loan product this loan was originated under.
     *
     * The product identifies the loan's identity. Its FINANCIAL rules are
     * governed by the frozen policy version referenced through
     * `policyVersion()` below, not by the product's current policy
     * reference. A product whose policy version was superseded after the
     * loan was approved does not affect this loan.
     *
     * @return BelongsTo
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(
            LoanProduct::class,
            'loan_product_id',
            'id',
        );
    }

    /**
     * The versioned `loan_product_rules` policy frozen at approval.
     *
     * Copied by `LoanApprovalService` (A.25) from the product's
     * `product_rules_policy_version_id` at the under_review → approved
     * transition, and never changed thereafter. A subsequent publication
     * of a new policy version does not retroactively affect this loan.
     *
     * Nullable: a loan in a pre-approval state has no frozen version. The
     * `hasPolicyVersion()` helper reports whether the freeze has occurred.
     *
     * @return BelongsTo
     */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(
            VersionedPolicy::class,
            'frozen_policy_version_id',
            'id',
        );
    }

    /**
     * The user who approved this loan.
     *
     * Set by `LoanApprovalService` (A.25) at the under_review → approved
     * transition. Nullable before approval. A hard-deleted user detaches
     * from the loan, which survives with a null actor (migration A.9's
     * `nullOnDelete()` FK behavior).
     *
     * @return BelongsTo
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'id');
    }

    /**
     * The user who disbursed this loan.
     *
     * Set by `LoanDisbursementService` (A.26) at the approved → disbursed
     * transition. Nullable before disbursement.
     *
     * @return BelongsTo
     */
    public function disbursedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by', 'id');
    }

    /**
     * The user who created the loan's draft.
     *
     * Set by `LoanApplicationService` (A.24) at draft creation. Nullable
     * for system-initiated drafts (a future CR that introduces programmatic
     * loan creation).
     *
     * @return BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * The loan's installment schedule.
     *
     * Every installment row for this loan, including rows from
     * superseded schedules (a restructure does not delete the previous
     * schedule's rows; it transitions them to
     * `InstallmentStatus::Superseded`, per A.10). Callers that need the
     * CURRENT schedule filter by status; see
     * `InstallmentStatus::activeValues()` (A.5).
     *
     * Ordered by `installment_number` ascending, which is monotonic
     * across all schedules of the loan (per A.10's numbering discipline).
     *
     * @return HasMany
     */
    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class, 'loan_id', 'id')
                    ->orderBy('installment_number');
    }

    /**
     * The loan's collateral records.
     *
     * Ordered by `registered_at` ascending — the collateral registration
     * sequence is the natural reading order.
     *
     * @return HasMany
     */
    public function collaterals(): HasMany
    {
        return $this->hasMany(LoanCollateral::class, 'loan_id', 'id')
                    ->orderBy('registered_at')
                    ->orderBy('id');
    }

    /**
     * The loan's restructuring history.
     *
     * Ordered by `restructured_at` ascending, with an `id` tiebreaker for
     * same-instant restructures (per migration A.12's second composite
     * index).
     *
     * @return HasMany
     */
    public function restructurings(): HasMany
    {
        return $this->hasMany(LoanRestructuring::class, 'loan_id', 'id')
                    ->orderBy('restructured_at')
                    ->orderBy('id');
    }

    /**
     * The loan's write-off record, if any.
     *
     * A HasOne relation: a loan may be written off at most once, because
     * `LoanStatus::WrittenOff` is a terminal state (A.2). A loan that has
     * not been written off returns `null` from this relation.
     *
     * @return HasOne
     */
    public function writeOff(): HasOne
    {
        return $this->hasOne(LoanWriteOff::class, 'loan_id', 'id');
    }

    /**
     * The loan's status transition history.
     *
     * Ordered by `occurred_at` ascending, with an `id` tiebreaker for
     * same-instant transitions (per migration A.14's second composite
     * index). The full path from `null` (initial creation) to the loan's
     * current state is reconstructible by iterating the collection.
     *
     * @return HasMany
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(LoanStatusHistory::class, 'loan_id', 'id')
                    ->orderBy('occurred_at')
                    ->orderBy('id');
    }

    /**
     * The loan's interest-accrual bookkeeping.
     *
     * One row per installment whose due date has arrived and whose
     * interest obligation has been recorded on the Wallet. See the
     * migration A.15 and `LoanInterestAccrualService` (A.28).
     *
     * Ordered by `recorded_at` ascending, with an `id` tiebreaker.
     *
     * @return HasMany
     */
    public function interestAccruals(): HasMany
    {
        return $this->hasMany(LoanInterestAccrual::class, 'loan_id', 'id')
                    ->orderBy('recorded_at')
                    ->orderBy('id');
    }

    /**
     * The loan's penalty-accrual bookkeeping.
     *
     * One row per overdue installment whose penalty obligation has been
     * recorded on the Wallet. See the migration A.16 and
     * `LoanArrearsService` (A.29).
     *
     * Ordered by `recorded_at` ascending, with an `id` tiebreaker.
     *
     * @return HasMany
     */
    public function penaltyAccruals(): HasMany
    {
        return $this->hasMany(LoanPenaltyAccrual::class, 'loan_id', 'id')
                    ->orderBy('recorded_at')
                    ->orderBy('id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific tenant.
     *
     * Every read through `LoanRepository` (A.51) is composed with this
     * scope; the repository is the single enforcement point for tenant
     * isolation on this model.
     *
     * @param Builder $query
     * @param int     $tenantId
     * @return Builder
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to a specific borrower.
     *
     * Used by the borrower-facing read paths: the shareholder portal's
     * "my loans" view, and the `loan.view_own` scope resolution in the
     * controller (which narrows the tenant-wide list to the caller's own
     * loans).
     *
     * @param Builder $query
     * @param int     $shareholderId
     * @return Builder
     */
    public function scopeForBorrower(Builder $query, int $shareholderId): Builder
    {
        return $query->where('borrower_shareholder_id', $shareholderId);
    }

    /**
     * Scope a query to a specific product.
     *
     * Used by administrative reports and by the product's own aggregate
     * queries. No Phase 2C endpoint exposes a "list loans by product"
     * query; the scope exists for future use.
     *
     * @param Builder $query
     * @param int     $productId
     * @return Builder
     */
    public function scopeOfProduct(Builder $query, int $productId): Builder
    {
        return $query->where('loan_product_id', $productId);
    }

    /**
     * Scope a query to a specific status.
     *
     * Accepts either a `LoanStatus` case or its string value, so callers
     * do not need to normalise before invoking the scope. This mirrors the
     * pattern established by Phase 2A's `Shareholder.scopeOfType()`.
     *
     * @param Builder              $query
     * @param LoanStatus|string    $status
     * @return Builder
     */
    public function scopeInStatus(Builder $query, LoanStatus|string $status): Builder
    {
        $value = $status instanceof LoanStatus ? $status->value : $status;

        return $query->where('status', $value);
    }

    /**
     * Scope a query to any of a set of statuses.
     *
     * @param Builder                    $query
     * @param array<int, LoanStatus|string> $statuses
     * @return Builder
     */
    public function scopeInAnyStatus(Builder $query, array $statuses): Builder
    {
        $values = array_map(
            static fn (LoanStatus|string $s): string => $s instanceof LoanStatus ? $s->value : $s,
            $statuses,
        );

        return $query->whereIn('status', $values);
    }

    /**
     * Scope a query to exclude a set of statuses.
     *
     * @param Builder                    $query
     * @param array<int, LoanStatus|string> $statuses
     * @return Builder
     */
    public function scopeNotInAnyStatus(Builder $query, array $statuses): Builder
    {
        $values = array_map(
            static fn (LoanStatus|string $s): string => $s instanceof LoanStatus ? $s->value : $s,
            $statuses,
        );

        return $query->whereNotIn('status', $values);
    }

    /**
     * Scope a query to loans still being serviced.
     *
     * "Active" here means NOT in any terminal status. Terminal statuses
     * are `rejected`, `written_off`, `settled`, and `closed` (per
     * `LoanStatus::isTerminal()`, A.2). Every other status — draft,
     * submitted, under_review, approved, disbursed, active, in_arrears,
     * restructured — returns true.
     *
     * This is the scope used by "list loans under management" style
     * reports and by the borrower portal's active-loans view.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', LoanStatus::terminalValues());
    }

    /**
     * Scope a query to loans currently in arrears.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeInArrears(Builder $query): Builder
    {
        return $query->where('status', LoanStatus::InArrears->value);
    }

    /**
     * Scope a query to loans past disbursement.
     *
     * Includes every working state a disbursed loan can be in: active,
     * in_arrears, restructured. Excludes settled, written_off, and
     * closed, because those are terminal.
     *
     * Also excludes the transient `disbursed` state itself: it is
     * intermediate and settles into `active` in the same transaction.
     * A loan that is observed in `disbursed` state is mid-transaction.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDisbursed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LoanStatus::Active->value,
            LoanStatus::InArrears->value,
            LoanStatus::Restructured->value,
        ]);
    }

    // =========================================================================
    // Domain helpers — lifecycle state
    // =========================================================================

    /**
     * The loan's current status as a `LoanStatus` case.
     *
     * Because the `status` column is cast to the enum (see the class
     * docblock §"Why `status` is cast to the enum"), this helper is a
     * convenience wrapper for `$loan->status`. It exists so that callers
     * that prefer a method call over a property read have a consistent
     * entry point, matching the pattern established by Phase 2A's
     * `Shareholder::type()` and Phase 2B's `ShareCertificate::status()`.
     *
     * @return LoanStatus
     */
    public function status(): LoanStatus
    {
        return $this->status;
    }

    /**
     * Whether the loan is in the `draft` state.
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->status === LoanStatus::Draft;
    }

    /**
     * Whether the loan is in the `submitted` state.
     *
     * @return bool
     */
    public function isSubmitted(): bool
    {
        return $this->status === LoanStatus::Submitted;
    }

    /**
     * Whether the loan is in the `under_review` state.
     *
     * @return bool
     */
    public function isUnderReview(): bool
    {
        return $this->status === LoanStatus::UnderReview;
    }

    /**
     * Whether the loan is in the `approved` state (approved but not yet
     * disbursed).
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->status === LoanStatus::Approved;
    }

    /**
     * Whether the loan is in the `rejected` state (terminal).
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->status === LoanStatus::Rejected;
    }

    /**
     * Whether the loan is in the `disbursed` state (transient; settles to
     * `active` in the same transaction).
     *
     * @return bool
     */
    public function isDisbursed(): bool
    {
        return $this->status === LoanStatus::Disbursed;
    }

    /**
     * Whether the loan is in the `active` state (in good standing,
     * installments being repaid on schedule).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === LoanStatus::Active;
    }

    /**
     * Whether the loan is in the `in_arrears` state.
     *
     * @return bool
     */
    public function isInArrears(): bool
    {
        return $this->status === LoanStatus::InArrears;
    }

    /**
     * Whether the loan is in the `restructured` state (transient; settles
     * to `active` in the same transaction as the restructure).
     *
     * @return bool
     */
    public function isRestructured(): bool
    {
        return $this->status === LoanStatus::Restructured;
    }

    /**
     * Whether the loan is in the `written_off` state (terminal).
     *
     * @return bool
     */
    public function isWrittenOff(): bool
    {
        return $this->status === LoanStatus::WrittenOff;
    }

    /**
     * Whether the loan is in the `settled` state (all installments fully
     * settled; the only outgoing transition is to `closed`).
     *
     * @return bool
     */
    public function isSettled(): bool
    {
        return $this->status === LoanStatus::Settled;
    }

    /**
     * Whether the loan is in the `closed` state (terminal; no outgoing
     * transitions).
     *
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->status === LoanStatus::Closed;
    }

    /**
     * Whether the loan is in a terminal state — one from which there is
     * no path back to an active working state.
     *
     * Delegates to `LoanStatus::isTerminal()` (A.2). Terminal states are
     * `rejected`, `written_off`, `settled`, and `closed`.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Whether the loan is in a final state — one with no outgoing
     * transitions at all.
     *
     * Delegates to `LoanStatus::isFinal()` (A.2). Final states are
     * `rejected`, `written_off`, and `closed`. Note that `settled` is
     * terminal but not final: it has one outgoing transition (to
     * `closed`).
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    /**
     * Whether a structural transition from the loan's current status to
     * the given status is legal.
     *
     * Delegates to `LoanStatus::canTransitionTo()` (A.2). The check is
     * STRUCTURAL only; the caller MUST consult `LoanStateMachine` (A.26)
     * before persisting a status change, because the state machine
     * composes this check with guards, permission enforcement, and event
     * dispatch.
     *
     * Direct use of this helper outside the state machine is intended for
     * tests and for read-only diagnostics, not for production writes.
     *
     * @param LoanStatus $next
     * @return bool
     */
    public function canTransitionTo(LoanStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    // =========================================================================
    // Domain helpers — financial state
    // =========================================================================

    /**
     * Whether the loan has a frozen policy version.
     *
     * A loan without a frozen policy version has not been approved; the
     * `frozen_policy_version_id` column is set at the under_review →
     * approved transition by `LoanApprovalService` (A.25).
     *
     * @return bool
     */
    public function hasPolicyVersion(): bool
    {
        return $this->frozen_policy_version_id !== null;
    }

    /**
     * Whether the loan has at least one installment row.
     *
     * Uses `relationLoaded()` to avoid an extra query when the relation
     * is already loaded, and otherwise issues a `count()` query scoped to
     * this loan. The method is intentionally conservative: it does not
     * assert a specific count, only presence.
     *
     * @return bool
     */
    public function hasSchedule(): bool
    {
        if ($this->relationLoaded('installments')) {
            return $this->installments->isNotEmpty();
        }

        return $this->installments()->exists();
    }

    /**
     * Whether the loan has scheduled amounts set.
     *
     * The three columns `monthly_installment_amount`,
     * `total_scheduled_interest`, and `total_scheduled_repayment` are set
     * together at approval time by `LoanScheduleService` (A.23). The
     * helper checks the first of the three as a proxy for the group; if
     * the first is set, the others are set by construction.
     *
     * @return bool
     */
    public function hasScheduledAmounts(): bool
    {
        return $this->monthly_installment_amount !== null;
    }

    /**
     * Whether the loan is fully settled.
     *
     * A loan is fully settled when its `settled_amount` is greater than
     * or equal to its `total_scheduled_repayment`. The comparison uses
     * bcmath at scale 4.
     *
     * This helper reports the FINANCIAL fact, not the lifecycle state. A
     * loan that is fully settled financially may be in the `settled` state
     * (the state machine has caught up) or — briefly, inside a
     * transaction — still in `active` or `in_arrears` (the state
     * transition has not yet fired). Callers that need the lifecycle state
     * use `isSettled()`; callers that need the financial fact use this
     * method.
     *
     * @return bool
     */
    public function isFullySettled(): bool
    {
        if (!$this->hasScheduledAmounts()) {
            return false;
        }

        return bccomp(
            (string) $this->settled_amount,
            (string) $this->total_scheduled_repayment,
            4,
        ) >= 0;
    }

    /**
     * Whether the loan is fully written off.
     *
     * A loan is fully written off when its status is `written_off` AND all
     * three outstanding projections are zero. The second condition is
     * redundant with the first in a well-formed system — the write-off
     * transaction (A.13) sets the projections to zero inside the same
     * transaction that transitions the status — but the helper is
     * defensive: it reports `false` if a defect has left the two out of
     * sync.
     *
     * @return bool
     */
    public function isFullyWrittenOff(): bool
    {
        if ($this->status !== LoanStatus::WrittenOff) {
            return false;
        }

        return bccomp((string) ($this->outstanding_principal ?? '0'), '0', 4) === 0
            && bccomp((string) ($this->outstanding_interest  ?? '0'), '0', 4) === 0
            && bccomp((string) $this->outstanding_penalty,         '0', 4) === 0;
    }

    /**
     * The sum of the loan's three outstanding projections, as a decimal
     * string at scale 4.
     *
     * This is the Loan domain's authoritative answer to "how much does
     * the borrower still owe on this loan?", computed from the loan's
     * persisted projections. It is NOT the Wallet's answer — the Wallet's
     * answer is the sum of the loan-linked Wallet obligations'
     * `outstanding_amount` (see the migration A.9 for the reconciliation
     * invariant). The two values should agree at every transaction
     * boundary; a divergence is what `LoanReconciliationService` (A.32)
     * detects.
     *
     * The helper handles null `outstanding_principal` and
     * `outstanding_interest` by treating them as zero, which is correct
     * for a loan that has not yet been disbursed.
     *
     * @return string  Decimal string at scale 4.
     */
    public function outstandingTotal(): string
    {
        $principal = (string) ($this->outstanding_principal ?? '0');
        $interest  = (string) ($this->outstanding_interest  ?? '0');
        $penalty   = (string) $this->outstanding_penalty;

        return bcadd(
            bcadd($principal, $interest, 4),
            $penalty,
            4,
        );
    }
}