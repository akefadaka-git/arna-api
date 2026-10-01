<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use MaaleArna\MASES\Core\Auth\Models\User;

/**
 * PaymentAllocationReversal
 *
 * Eloquent model for a payment-allocation reversal — a dependent reversal
 * entity of the Wallet domain.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * A reversal is the immutable record that a specific portion of a
 * previously-recorded payment allocation has been undone. It is the sole
 * correction mechanism for allocations in Phase 2B (D110, Architecture
 * §3.6).
 *
 * A PaymentAllocationReversal links:
 *
 *     one PaymentAllocation   (the allocation being corrected)
 *     with
 *     a positive reversed_amount (the portion of the allocation being undone)
 *     a required reason          (why the correction is being made)
 *     an actor                   (reversed_by)
 *     a business timestamp       (reversed_at)
 *
 * The original allocation row remains untouched. The reversal row is a
 * separate, permanent record. The effective allocated amount of the parent
 * allocation is reduced by the sum of its reversals:
 *
 *     effective_allocated(allocation)
 *         = allocation.amount
 *         − Σ(reversal.reversed_amount for all reversals of that allocation)
 *
 * =============================================================================
 * Why a dedicated reversal entity (Architecture §3.6, amendment A1)
 * =============================================================================
 * The 2B architecture explicitly chose a dedicated reversal entity over
 * mutating the allocation. The rationale, recorded in amendment A1, is:
 *
 *   1. Attribution history must remain unambiguous. If the allocation's
 *      amount column were reducible in place, an observer could not tell
 *      whether the original allocation was smaller or whether a reversal
 *      had occurred.
 *
 *   2. The reversal records WHY the correction happened (the required
 *      `reason`) and WHO made it (the required `reversed_by`) — metadata
 *      that has no place on the original allocation row.
 *
 *   3. Partial reversals must be representable. A single reversal record
 *      with an explicit `reversed_amount` is the mechanism.
 *
 * The invariant that keeps the wallet domain internally coherent:
 *
 *     effective_allocated(allocation) ≥ 0
 *
 * which the service enforces by requiring
 *
 *     reversal.reversed_amount
 *         ≤ effective_allocated(parent allocation) before this reversal
 *
 * =============================================================================
 * Not a ledger entry
 * =============================================================================
 * Reversals do NOT produce `wallet_entries` rows. They are attribution
 * records, not monetary records (Architecture §5.1). The monetary effect
 * of a payment's arrival and an obligation's recording was already
 * realized when those rows were written; the allocation, and now its
 * reversal, are reclassifications of that monetary fact. Reversing an
 * allocation does not move money; it changes which obligation the payment
 * is considered to have settled.
 *
 * Consequence: creating a reversal does not change
 * `wallet.current_balance`. It changes the two derived projections —
 * `wallet_payments.unapplied_amount` (increases) and
 * `wallet_obligations.outstanding_amount` (increases) — by the reversed
 * amount, inside AllocationService's transaction.
 *
 * =============================================================================
 * Full immutability
 * =============================================================================
 * A PaymentAllocationReversal row is FULLY IMMUTABLE once recorded. It has
 * no projection column, no mutable metadata, and no lifecycle beyond
 * creation. Every attribute is set once at insert time by AllocationService
 * (2B.18).
 *
 * The model guard rejects ANY update attempt — including one that would
 * change no user-visible attribute, since Eloquent has already marked
 * `updated_at` as dirty by the time the `updating` event fires. That is the
 * intended behaviour: a reversal, once committed, is a permanent
 * historical record.
 *
 * A reversal cannot itself be reversed. If a correction to a correction is
 * ever needed, the correct remedy is a new allocation with a new
 * allocation record — not a reversal of the reversal. This constraint is
 * enforced at the service layer (Architecture §6.5, invariant 4); the
 * schema does not express it, because a "reversal of a reversal" would be
 * a new allocation row with new semantics, not a row in this table.
 *
 * =============================================================================
 * Invariants enforced at the service layer (Architecture §6.5)
 * =============================================================================
 * The following constraints are NOT expressible in the schema and are
 * enforced by AllocationService inside its transaction:
 *
 *     1. reversal.reversed_amount > 0
 *     2. reversal.reversed_amount ≤ effective_allocated(parent) before
 *        this reversal
 *     3. parent allocation is not itself a reversal (allocations are not
 *        reversals — structurally guaranteed, but restated for clarity)
 *     4. a reversal row is never itself reversed (no such operation exists
 *        in 2B)
 *
 * The reversal's `reason` and `reversed_by` are required by the service on
 * every call. The schema marks `reason` as NOT NULL; `reversed_by` is
 * nullable at the schema level for the same reason as `created_by` on the
 * other entities — a future system-initiated correction could have no
 * human actor — but the Phase 2B service requires a user.
 *
 * The row locks required to enforce these invariants follow the fixed
 * order documented in Architecture §4.7:
 *
 *     Wallet → Payment → Obligation → PaymentAllocation
 *
 * (The reversal acquires locks on the same four rows as an allocation;
 *  the parent allocation is the fourth in the sequence.)
 *
 * =============================================================================
 * No currency column (Q8)
 * =============================================================================
 * This model carries no `currency` column. The currency of a reversal is
 * unambiguous from its ancestry: the parent allocation resolves to a
 * payment and an obligation, both of which carry a currency, and the Q8
 * invariant ensures both equal the wallet's currency. Recording a fourth
 * copy on the reversal would add a multi-way equality that the schema
 * cannot enforce and that the service would have to maintain in lockstep.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   wallet()             — the owning Wallet (BelongsTo).
 *   paymentAllocation()  — the allocation being reversed (BelongsTo).
 *   reversedBy()         — the recording user (BelongsTo, nullable).
 *
 * The `paymentAllocation` relation resolves to the parent allocation. A
 * caller that needs the full attribution chain (payment → obligation →
 * reversal) traverses through the allocation's own relations.
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not produce a ledger entry. Attribution corrections are not
 *     monetary movements.
 *
 *   - It does not maintain `payment.unapplied_amount` or
 *     `obligation.outstanding_amount`. AllocationService owns both
 *     projections.
 *
 *   - It does not carry a currency column. The Q8 invariant derives the
 *     currency from the ancestry.
 *
 *   - It does not support "reversal of reversal". No such operation exists
 *     in 2B; a subsequent correction would be a new allocation with a new
 *     allocation record.
 *
 *   - It does not soft-delete. The 2B posture is that financial records
 *     are not deleted through application operations.
 *
 *   - It exposes no `effectiveAmount()` helper. The effective reduction
 *     contributed by this specific reversal is simply its
 *     `reversed_amount`; the aggregate effective amount of the parent
 *     allocation is a query concern over the parent's `reversals()`
 *     relation.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution decision
 *   Architecture v1.2.0 §3.6     — PaymentAllocationReversal entity (A1)
 *   Architecture v1.2.0 §4.7     — Deterministic lock acquisition order
 *   Architecture v1.2.0 §5.1     — Allocations do not produce ledger entries
 *   Architecture v1.2.0 §6.1     — Obligation projection
 *   Architecture v1.2.0 §6.2     — Payment projection
 *   Architecture v1.2.0 §6.5     — Allocation-reversal invariants
 *   Architecture v1.2.0 §19      — Q8 resolution
 *   Step 5 (manifest) 2B.9       — This file
 *   Step 6 v1.1.0 §3.6           — wallet_payment_allocation_reversals
 *                                  table specification
 *   Step 6 v1.1.0 §5.2           — Explicit short FK name for
 *                                  payment_allocation_id
 *
 * @property int                       $id
 * @property int                       $wallet_id
 * @property int                       $payment_allocation_id
 * @property string                    $reversed_amount
 * @property string                    $reason
 * @property Carbon $reversed_at
 * @property int|null                  $reversed_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Models
 * @version 1.0.0
 */
final class PaymentAllocationReversal extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'wallet_payment_allocation_reversals';

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
     * Order mirrors the migration's column order (2B.53). Every attribute
     * is set once, at insert time, by AllocationService (2B.18). There is
     * no update path.
     *
     * @var list<string>
     */
    protected $fillable = [
        'wallet_id',
        'payment_allocation_id',
        'reversed_amount',
        'reason',
        'reversed_at',
        'reversed_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * `reversed_amount` is cast as `decimal:4` so that reads return the
     * exact decimal string that bcmath operates on. Laravel's decimal cast
     * returns a string in Laravel 11+, avoiding float rounding for
     * monetary values.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'wallet_id'             => 'integer',
        'payment_allocation_id' => 'integer',
        'reversed_amount'       => 'decimal:4',
        'reversed_at'           => 'datetime',
        'reversed_by'           => 'integer',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
    ];

    /**
     * The attributes that should be hidden from array/JSON serialization.
     *
     * Empty. Nothing on the reversal row is secret in the ordinary sense.
     * The resource layer (2B.35) selects which fields to expose.
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
     * Rejects ANY update attempt. PaymentAllocationReversal is fully
     * immutable once recorded: it has no projection column, no mutable
     * metadata, and no lifecycle beyond creation. Every attribute is an
     * identity or an economic fact that must never change.
     *
     * There is no correction-of-correction operation in Phase 2B. If a
     * reversal itself needs to be undone, the correct remedy is a new
     * allocation with a new allocation record — not a mutation of this row
     * and not a "reversal of a reversal". See the class docblock
     * §"Full immutability" for the reasoning.
     *
     * Note on Eloquent mechanics: the `updating` event fires when `save()`
     * detects at least one dirty attribute. By the time the event fires,
     * Eloquent has already marked `updated_at` as dirty. This means the
     * guard below rejects every attempted update — including ones that
     * would change no user-visible attribute. That is the intended
     * behaviour.
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
        static::updating(static function (PaymentAllocationReversal $reversal): void {
            throw new LogicException(
                sprintf(
                    'PaymentAllocationReversal rows are immutable once recorded '
                    . '(Architecture §3.6). A reversal cannot be modified, and a '
                    . 'reversal of a reversal is not a supported operation in '
                    . 'Phase 2B. To correct a subsequent attribution error, '
                    . 'record a new allocation with a new allocation record. '
                    . 'Attempted update on '
                    . 'wallet_payment_allocation_reversals.id=%s.',
                    (string) $reversal->getKey(),
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
     * A reversal belongs to exactly one wallet. The wallet carries the
     * tenant scope; this reversal resolves tenant transitively through its
     * wallet.
     *
     * The `wallet_id` column is denormalized from the parent allocation
     * (which itself denormalizes from the payment/obligation). It is
     * retained as a direct column so that the wallet's full reversal
     * history is queryable without traversing the allocation.
     *
     * @return BelongsTo
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'id');
    }

    /**
     * The allocation being reversed.
     *
     * @return BelongsTo
     */
    public function paymentAllocation(): BelongsTo
    {
        return $this->belongsTo(
            PaymentAllocation::class,
            'payment_allocation_id',
            'id',
        );
    }

    /**
     * The user who recorded this reversal.
     *
     * Nullable at the schema level because a future system-initiated
     * correction could have no human actor. The Phase 2B service
     * (AllocationService::reverse()) requires a user on every call, so
     * null is not produced by any 2B code path. It is retained as
     * nullable for forward compatibility with future automated
     * correction workflows.
     *
     * @return BelongsTo
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by', 'id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific wallet.
     *
     * The primary wallet-scoping entry point for reversals, mirroring the
     * pattern used by the Wallet model's `scopeForTenant()` and by the
     * sibling Obligation/Payment/PaymentAllocation models'
     * `scopeForWallet()`. The AllocationRepository composes this scope
     * with the tenant-scoped wallet lookup to enforce the invariant
     * recorded in Step 7 v1.1.0 §C.6.
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
     * Scope a query to a specific payment allocation.
     *
     * Supports "all reversals of allocation A" queries. The AllocationResource
     * (2B.34) uses this scope when embedding the reversal history of an
     * allocation, and any reporting surface that needs the correction
     * history of a single allocation reads through the same scope.
     *
     * The sum of `reversed_amount` across the collection returned by this
     * scope is the effective reduction of the parent allocation's amount.
     *
     * @param Builder $query
     * @param int     $paymentAllocationId
     * @return Builder
     */
    public function scopeForAllocation(Builder $query, int $paymentAllocationId): Builder
    {
        return $query->where('payment_allocation_id', $paymentAllocationId);
    }

    // =========================================================================
    // Domain helpers
    // =========================================================================

    /**
     * Whether the reversal has a recorded actor.
     *
     * Phase 2B always records an actor. The helper exists so that a reader
     * can branch on the field without inspecting it directly, and so that
     * a future automated workflow that produces actor-less reversals has
     * a natural predicate.
     *
     * @return bool
     */
    public function hasActor(): bool
    {
        return $this->reversed_by !== null;
    }
}