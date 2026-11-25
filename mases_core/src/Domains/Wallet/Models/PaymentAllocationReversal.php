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
 * Immutable correction to a payment allocation. The original allocation remains unchanged; reversals require a positive amount, reason and actor.
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