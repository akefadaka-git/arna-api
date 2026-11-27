<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Policy\Models\VersionedPolicy;

/**
 * Explicit attribution from one payment to one obligation. Allocations are not ledger entries and never change wallet.current_balance.
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
 * @package MaaleArna\Arna\Core\Domains\Wallet\Models
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