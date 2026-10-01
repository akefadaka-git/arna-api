<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;

/**
 * PaymentAllocation
 *
 * Eloquent model for a payment allocation — a dependent attribution entity
 * of the Wallet domain.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * An allocation is the explicit record that a specific payment, in whole or
 * in part, has been applied to a specific obligation. It is the mechanism
 * by which the wallet attributes a received payment to the components of
 * debt it settles (D110).
 *
 * A PaymentAllocation links:
 *
 *     one Payment       (the money that arrived)
 *     to
 *     one Obligation    (the debt component that the money settles)
 *     with
 *     a positive amount (the portion of the payment being applied)
 *
 * The three — payment, obligation, amount — are supplied explicitly on
 * every allocation request. No universal priority order is encoded; the
 * business's allocation policy is a future concern (D110, Architecture §10).
 *
 * =============================================================================
 * Not a ledger entry
 * =============================================================================
 * Allocations do NOT produce `wallet_entries` rows. They are attribution
 * records, not monetary records (Architecture §5.1). The wallet's ledger
 * records the payment's arrival and the obligation's recording; the
 * allocation records how the two are linked.
 *
 * Consequence: creating an allocation does not change
 * `wallet.current_balance`. The monetary effect of the payment was already
 * realized at the moment of recording; allocation is a reclassification,
 * not a movement of money.
 *
 * The separation is deliberate. Two payments recorded against the same
 * wallet and then allocated to the same obligation produce identical ledger
 * totals; the allocation records differ, and both are preserved.
 *
 * =============================================================================
 * Immutability (Architecture §3.5, Step 6 v1.1.0 §4.2)
 * =============================================================================
 * A PaymentAllocation row is FULLY IMMUTABLE once recorded. Unlike
 * Wallet/Obligation/Payment — which have projection columns
 * (`current_balance`, `outstanding_amount`, `unapplied_amount`) that their
 * respective services legitimately update inside their transactions —
 * PaymentAllocation has NO projection column and NO mutable field.
 *
 * Consequently, the model guard is stricter than its siblings: ANY update
 * attempt is rejected, not merely updates to a specific attribute list.
 *
 * The correct way to correct an allocation is to record a
 * PaymentAllocationReversal (2B.9) — a linked, immutable record that
 * reduces the allocation's effective amount without touching the original
 * row (Architecture §3.6). The effective allocated amount becomes:
 *
 *     effective_allocated(allocation)
 *         = allocation.amount
 *         − Σ(reversal.reversed_amount for all reversals of this allocation)
 *
 * Two derived projection columns — `wallet_payments.unapplied_amount` and
 * `wallet_obligations.outstanding_amount` — are updated transactionally by
 * AllocationService (2B.18) whenever an allocation or its reversal is
 * recorded.
 *
 * =============================================================================
 * Invariants enforced at the service layer (Architecture §6.3, §6.5)
 * =============================================================================
 * The following constraints are NOT expressible in the schema and are
 * enforced by AllocationService inside its transaction:
 *
 *   On allocation:
 *     1. allocation.amount > 0
 *     2. allocation.amount ≤ payment.unapplied_amount (at allocation time)
 *     3. allocation.amount ≤ obligation.outstanding_amount (at allocation time)
 *     4. payment.wallet_id == obligation.wallet_id
 *     5. payment.currency == obligation.currency == wallet.currency (Q8)
 *
 *   On reversal:
 *     1. reversal.reversed_amount > 0
 *     2. reversal.reversed_amount ≤ effective_allocated(parent) before reversal
 *     3. parent allocation is not itself a reversal (allocations are not
 *        reversals)
 *     4. a reversal row is never itself reversed
 *
 * The row locks required to enforce these invariants follow the fixed
 * order documented in Architecture §4.7: Wallet → Payment → Obligation →
 * PaymentAllocation.
 *
 * =============================================================================
 * No currency column (Q8)
 * =============================================================================
 * This model carries no `currency` column. The currency of an allocation
 * is unambiguous from its parents: the payment and the obligation both
 * carry a currency, and the Q8 invariant ensures both equal the wallet's
 * currency. Recording a third copy on the allocation would add a
 * three-way equality that the schema cannot enforce and that the service
 * would have to maintain in lockstep. Omitting the column is the
 * correct normalization.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   wallet()           — the owning Wallet (BelongsTo).
 *   payment()          — the Payment being applied (BelongsTo).
 *   obligation()       — the Obligation being settled (BelongsTo).
 *   allocatedBy()      — the recording user (BelongsTo, nullable).
 *   allocationPolicy() — the allocation policy version in force at the
 *                        time of allocation (BelongsTo, nullable).
 *   reversals()        — the PaymentAllocationReversal rows recorded
 *                        against this allocation (HasMany).
 *
 * The `reversals()` relation does not filter; a caller that needs the
 * effective allocated amount computes it by summing `amount` on the parent
 * and subtracting the sum of `reversed_amount` across all reversals.
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not produce a ledger entry. Attribution is not a monetary
 *     movement.
 *
 *   - It does not maintain `payment.unapplied_amount` or
 *     `obligation.outstanding_amount`. AllocationService owns both
 *     projections.
 *
 *   - It does not carry a universal priority. Explicit allocation only.
 *
 *   - It does not carry a currency column. The Q8 invariant derives the
 *     currency from the parents.
 *
 *   - It does not soft-delete. The 2B posture is that financial records
 *     are not deleted through application operations; corrections flow
 *     through the reversal mechanism.
 *
 *   - It exposes no `effectiveAmount()` helper. The effective amount is a
 *     query concern (it requires iterating the reversals relation); the
 *     projection on the parent Payment and Obligation already reflects the
 *     effective state. A caller that needs the effective amount of a
 *     specific allocation reads the parent projections or queries the
 *     reversals relation explicitly.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution decision
 *   Architecture v1.2.0 §3.5     — PaymentAllocation entity
 *   Architecture v1.2.0 §3.6     — PaymentAllocationReversal entity
 *   Architecture v1.2.0 §4.7     — Deterministic lock acquisition order
 *   Architecture v1.2.0 §5.1     — Allocations do not produce ledger entries
 *   Architecture v1.2.0 §6.1     — Obligation projection
 *   Architecture v1.2.0 §6.2     — Payment projection
 *   Architecture v1.2.0 §6.3     — Allocation invariants
 *   Architecture v1.2.0 §6.5     — Allocation-reversal invariants
 *   Architecture v1.2.0 §19      — Q8 resolution
 *   Step 5 (manifest) 2B.8       — This file
 *   Step 6 v1.1.0 §3.5           — wallet_payment_allocations table specification
 *   Step 6 v1.1.0 §4.2           — Immutability as service-layer guarantee
 *
 * @property int                       $id
 * @property int                       $wallet_id
 * @property int                       $payment_id
 * @property int                       $obligation_id
 * @property string                    $amount
 * @property Carbon $allocated_at
 * @property int|null                  $allocated_by
 * @property int|null                  $allocation_policy_version_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Models
 * @version 1.0.0
 */
final class PaymentAllocation extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'wallet_payment_allocations';

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
     * Order mirrors the migration's column order (2B.52). Every attribute
     * is set once, at insert time, by AllocationService (2B.18). There is
     * no update path.
     *
     * @var list<string>
     */
    protected $fillable = [
        'wallet_id',
        'payment_id',
        'obligation_id',
        'amount',
        'allocated_at',
        'allocated_by',
        'allocation_policy_version_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * `amount` is cast as `decimal:4` so that reads return the exact
     * decimal string that bcmath operates on. Laravel's decimal cast
     * returns a string in Laravel 11+, avoiding float rounding for
     * monetary values.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'wallet_id'                    => 'integer',
        'payment_id'                   => 'integer',
        'obligation_id'                => 'integer',
        'amount'                       => 'decimal:4',
        'allocated_at'                 => 'datetime',
        'allocated_by'                 => 'integer',
        'allocation_policy_version_id' => 'integer',
        'created_at'                   => 'datetime',
        'updated_at'                   => 'datetime',
    ];

    /**
     * The attributes that should be hidden from array/JSON serialization.
     *
     * Empty. Nothing on the allocation row is secret in the ordinary
     * sense. The resource layer (2B.34) selects which fields to expose.
     *
     * @var list<string>
     */
    protected $hidden = [];

    /**
     * The accessors to append to the model's array form.
     *
     * Empty on purpose. The model does not force-serialize any computed
     * attribute. Resources select fields explicitly.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Lifecycle — full immutability
    // =========================================================================

    /**
     * Register the model's event listeners.
     *
     * Rejects ANY update attempt. PaymentAllocation is fully immutable
     * once recorded: unlike Wallet/Obligation/Payment, it has no projection
     * column and no mutable field. Every attribute is an identity or an
     * economic fact that must never change.
     *
     * The correct way to correct an allocation is to record a
     * PaymentAllocationReversal (2B.9) — a linked, immutable record that
     * reduces the allocation's effective amount. The original allocation
     * row remains as posted.
     *
     * Note on Eloquent mechanics: the `updating` event fires when `save()`
     * detects at least one dirty attribute. By the time the event fires,
     * Eloquent has already marked `updated_at` as dirty. This means the
     * guard below rejects every attempted update — including ones that
     * would change no user-visible attribute. That is the intended
     * behaviour: a PaymentAllocation row, once committed, is a permanent
     * historical record.
     *
     * Note on the limit of this guard: the check applies to Eloquent-
     * mediated writes (`save()`, `update()`, `fill()->save()`,
     * `increment()`, `decrement()`). It does NOT intercept raw
     * query-builder updates (`Model::where(...)->update(...)`). Those
     * bypass model events entirely. The service-layer discipline —
     * AllocationService exposes no update path — is the ultimate
     * guarantee against such bypasses.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(static function (PaymentAllocation $allocation): void {
            throw new LogicException(
                sprintf(
                    'PaymentAllocation rows are immutable once recorded '
                    . '(Architecture §3.5). To correct an allocation, record '
                    . 'a PaymentAllocationReversal (2B.9) — do not modify the '
                    . 'allocation. '
                    . 'Attempted update on wallet_payment_allocations.id=%s.',
                    (string) $allocation->getKey(),
                )
            );
        });
    }

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The owning wallet.
     *
     * An allocation belongs to exactly one wallet. The wallet carries the
     * tenant scope; this allocation resolves tenant transitively through
     * its wallet.
     *
     * The `wallet_id` column is denormalized from the payment (and equals
     * the obligation's wallet by the §6.3 invariant). It is retained as a
     * direct column so that the wallet's full attribution history is
     * queryable without traversing either parent.
     *
     * @return BelongsTo
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'id');
    }

    /**
     * The payment being applied.
     *
     * @return BelongsTo
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
    }

    /**
     * The obligation being settled.
     *
     * @return BelongsTo
     */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(Obligation::class, 'obligation_id', 'id');
    }

    /**
     * The user who recorded this allocation.
     *
     * Nullable at the schema level because system-generated allocations
     * (a future policy-driven auto-allocation) have no human actor. Null
     * is a legitimate state, not an error.
     *
     * @return BelongsTo
     */
    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by', 'id');
    }

    /**
     * The allocation policy version in force at the time of allocation.
     *
     * Nullable. When null, no allocation policy was attached to the wallet
     * at the time of allocation. When non-null, this records the specific
     * policy version that governed — or would have governed — the
     * allocation. Phase 2B does not evaluate the policy; the reference is
     * recorded for historical fidelity.
     *
     * Type compatibility (that the referenced policy's `policy_type` is
     * `'allocation'`) is NOT enforced by the FK; see Architecture §8.6.
     *
     * @return BelongsTo
     */
    public function allocationPolicy(): BelongsTo
    {
        return $this->belongsTo(
            VersionedPolicy::class,
            'allocation_policy_version_id',
            'id',
        );
    }

    /**
     * The reversals recorded against this allocation.
     *
     * A reversal reduces the allocation's effective amount without
     * modifying the allocation row itself. The effective allocated amount
     * of this allocation at any point in time is:
     *
     *     allocation.amount
     *         − Σ(reversal.reversed_amount for all reversals of this allocation)
     *
     * The relation does not filter or aggregate; callers that need the
     * effective amount compute it explicitly. The projection-cached values
     * on the parent payment (`unapplied_amount`) and parent obligation
     * (`outstanding_amount`) already reflect the effective state.
     *
     * @return HasMany
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(
            PaymentAllocationReversal::class,
            'payment_allocation_id',
            'id',
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific wallet.
     *
     * The primary wallet-scoping entry point for allocations, mirroring the
     * pattern used by the Wallet model's `scopeForTenant()` and by the
     * sibling Obligation/Payment models' `scopeForWallet()`. The
     * AllocationRepository composes this scope with the tenant-scoped
     * wallet lookup to enforce the invariant recorded in Step 7 v1.1.0
     * §C.6 — cross-tenant references produce the same 404 a non-existent
     * id would produce.
     *
     * @param Builder $query
     * @param int     $walletId
     * @return Builder
     */
    public function scopeForWallet(Builder $query, int $walletId): Builder
    {
        return $query->where('wallet_id', $walletId);
    }

    /**
     * Scope a query to a specific payment.
     *
     * Supports "all allocations of payment P" queries, used by the
     * PaymentResource (2B.33) when embedding allocations and by any
     * reporting surface that needs the allocation history of a single
     * payment.
     *
     * @param Builder $query
     * @param int     $paymentId
     * @return Builder
     */
    public function scopeForPayment(Builder $query, int $paymentId): Builder
    {
        return $query->where('payment_id', $paymentId);
    }

    /**
     * Scope a query to a specific obligation.
     *
     * Supports "all allocations settling obligation O" queries, used by
     * the ObligationResource (2B.32) when embedding allocations and by any
     * reporting surface that needs the settlement history of a single
     * obligation.
     *
     * @param Builder $query
     * @param int     $obligationId
     * @return Builder
     */
    public function scopeForObligation(Builder $query, int $obligationId): Builder
    {
        return $query->where('obligation_id', $obligationId);
    }

    // =========================================================================
    // Domain helpers
    // =========================================================================

    /**
     * Whether the allocation is associated with an allocation policy
     * version.
     *
     * Checks the reference only; does not load or evaluate the policy.
     *
     * @return bool
     */
    public function hasPolicy(): bool
    {
        return $this->allocation_policy_version_id !== null;
    }
}