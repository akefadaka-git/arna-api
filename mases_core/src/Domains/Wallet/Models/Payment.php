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

/**
 * Payment
 *
 * Eloquent model for a wallet payment — a scoped business aggregate of the
 * Wallet domain.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * A payment is an amount received into a wallet. It records the money's
 * arrival; it does NOT record what the money is for. Attribution is
 * recorded separately on `wallet_payment_allocations` (2B.8), which links
 * each payment to specific obligations.
 *
 * The separation is deliberate (D110): a payment is recorded once, at
 * receipt, and may then be allocated (in whole or in part, possibly across
 * multiple obligations) at any later time. No automatic allocation is
 * performed on receipt. A payment with no allocations has an unapplied
 * amount equal to its full amount.
 *
 * =============================================================================
 * Projection vs. source of truth
 * =============================================================================
 * `amount` is the recorded amount at creation time. It is IMMUTABLE — a
 * correction is not an edit to an existing payment; it is a compensating
 * operation (a reversal of the corresponding entry, or a new payment) per
 * Architecture §4.3.
 *
 * `unapplied_amount` is a PROJECTION. It is derived from the recorded
 * amount and the effective allocations recorded against this payment:
 *
 *     unapplied_amount
 *         = amount
 *         − Σ(effective_allocated for all allocations of this payment)
 *
 * where:
 *
 *     effective_allocated(allocation)
 *         = allocation.amount
 *         − Σ(reversal.reversed_amount for all reversals of that allocation)
 *
 * The projection is materialized in the column for query performance and
 * is updated transactionally by AllocationService (2B.18) whenever an
 * allocation or allocation-reversal is recorded. The derivation rule is
 * authoritative; the column is a cache.
 *
 * The three projection states a consumer reads:
 *
 *     unapplied_amount = amount        → unapplied (nothing allocated)
 *     unapplied_amount = 0             → fully applied
 *     0 < unapplied < amount           → partially applied
 *
 * The helper methods `isUnapplied()`, `isPartiallyApplied()`, and
 * `isFullyApplied()` expose this classification.
 *
 * =============================================================================
 * Overpayment and unapplied amounts (D110)
 * =============================================================================
 * A payment's `unapplied_amount` may remain positive indefinitely. D110
 * requires that overpaid or unapplied amounts be explicitly recorded — not
 * silently absorbed. In this domain, the recording is the `unapplied_amount`
 * column itself: a first-class, queryable, resource-exposed value.
 *
 * Phase 2B has no "hold" or "reserve" state. An unapplied amount is a
 * positive wallet balance under the sign convention (Architecture §4.2);
 * its meaning for the member is expressed by the wallet's overall position,
 * not by a per-payment state machine.
 *
 * =============================================================================
 * Single-currency invariant (Q8)
 * =============================================================================
 * `currency` must equal the owning wallet's currency. The wallet's
 * currency is itself immutable (2B.5, A10). Therefore this payment's
 * currency is effectively fixed for the payment's lifetime.
 *
 * The equality is enforced at the service layer (PaymentService, 2B.17)
 * because the schema cannot express it portably without a trigger. The
 * same rule is restated at the allocation boundary (Architecture §6.3):
 * allocation requires `payment.currency == obligation.currency ==
 * wallet.currency`.
 *
 * =============================================================================
 * Immutability
 * =============================================================================
 * Four attributes are immutable after creation:
 *
 *     wallet_id   — aggregate scope; a payment is not re-parented.
 *     currency    — must equal the (immutable) wallet currency (Q8).
 *     amount      — the recorded amount. Corrections are new payments or
 *                   ledger-entry reversals (Architecture §4.3).
 *     received_at — the business time of receipt. Historical fact.
 *
 * These four are guarded at the model level by `booted()`; any attempted
 * update that leaves one of them dirty throws `LogicException`.
 *
 * The following attributes are NOT guarded and remain mutable through the
 * service layer:
 *
 *     unapplied_amount — the projection; updated by AllocationService.
 *     reference_type   — descriptive metadata.
 *     reference_id     — descriptive metadata.
 *     description      — descriptive metadata.
 *     created_by       — actor metadata.
 *
 * The distinction follows the 2B posture established by Wallet (2B.5) and
 * Obligation (2B.6): identity and economic facts are guarded; descriptive
 * and derived fields are not.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   wallet()        — the owning Wallet (BelongsTo).
 *   createdBy()     — the recording user (BelongsTo, nullable).
 *   allocations()   — the allocations recorded against this payment
 *                     (HasMany PaymentAllocation).
 *
 * The `allocations()` relation does not filter reversed portions. A caller
 * that needs the effective allocated total computes it from the relation
 * by summing `amount` and subtracting any associated reversals. For the
 * projection-cached value, read `unapplied_amount` directly.
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not carry a payment/schedule category. D109 places that
 *     vocabulary outside the Wallet domain; the consuming domain supplies
 *     it as the opaque pair `reference_type` + `reference_id`.
 *
 *   - It does not carry an auto-allocation policy. D110 declines to freeze
 *     a universal allocation priority order.
 *
 *   - It does not carry a payment-method field. Method metadata is a
 *     consuming-domain concern; if needed, it is encoded in the reference
 *     pair or added by a future CR.
 *
 *   - It does not maintain `unapplied_amount`. AllocationService owns that
 *     projection.
 *
 *   - It does not enforce currency equality with its wallet. That is a
 *     PaymentService concern.
 *
 *   - It does not soft-delete. The 2B posture is that financial records are
 *     not deleted through application operations.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution decision
 *   Architecture v1.2.0 §3.4     — Payment entity
 *   Architecture v1.2.0 §4.2     — Sign convention
 *   Architecture v1.2.0 §4.3     — Corrections
 *   Architecture v1.2.0 §6.2     — Payment projection
 *   Architecture v1.2.0 §6.3     — Allocation invariants (currency equality)
 *   Architecture v1.2.0 §6.4     — Overpayment and unapplied amounts
 *   Architecture v1.2.0 §18      — Explicitly deferred items
 *   Step 5 (manifest) 2B.7       — This file
 *   Step 6 v1.1.0 §3.4           — wallet_payments table specification
 *
 * @property int                       $id
 * @property int                       $wallet_id
 * @property string                    $currency
 * @property string                    $amount
 * @property string                    $unapplied_amount
 * @property Carbon $received_at
 * @property string|null               $reference_type
 * @property string|null               $reference_id
 * @property string|null               $description
 * @property int|null                  $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Models
 * @version 1.0.0
 */
final class Payment extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'wallet_payments';

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
     * Order mirrors the migration's column order (2B.51). The two amount
     * columns are fillable so that PaymentService can supply them at
     * creation via `create([...])`. Their subsequent immutability
     * (`amount`) and service-owned projection update (`unapplied_amount`)
     * are enforced by the model's `booted()` guard and by the service
     * layer, not by exclusion from `$fillable`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'wallet_id',
        'currency',
        'amount',
        'unapplied_amount',
        'received_at',
        'reference_type',
        'reference_id',
        'description',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * The two amount columns are cast as `decimal:4` so that reads return
     * the exact decimal string that bcmath operates on. Laravel's decimal
     * cast returns a string in Laravel 11+, avoiding float rounding for
     * monetary values.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'wallet_id'        => 'integer',
        'amount'           => 'decimal:4',
        'unapplied_amount' => 'decimal:4',
        'received_at'      => 'datetime',
        'created_by'       => 'integer',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    /**
     * The attributes that should be hidden from array/JSON serialization.
     *
     * Empty. Nothing on the payment row is secret in the ordinary sense.
     * The resource layer (2B.33) selects which fields to expose.
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
    // Lifecycle — identity and economic-fact immutability
    // =========================================================================

    /**
     * Register the model's event listeners.
     *
     * Guards four attributes against mutation after creation:
     *
     *   - `wallet_id`   — aggregate scope.
     *   - `currency`    — must equal the (immutable) wallet currency.
     *   - `amount`      — the recorded amount. Corrections are new payments
     *                     or ledger-entry reversals.
     *   - `received_at` — historical fact.
     *
     * Each of these is set once at creation and never legitimately changes.
     * The guard fails loudly rather than silently permitting an update that
     * would falsify history, break the currency invariant, or change the
     * monetary fact of a payment that may already be partially allocated.
     *
     * The following attributes are deliberately NOT guarded:
     *
     *   - `unapplied_amount` — the projection; AllocationService updates it
     *                          inside its transaction.
     *   - `reference_type`   — descriptive metadata.
     *   - `reference_id`     — descriptive metadata.
     *   - `description`      — descriptive metadata.
     *   - `created_by`       — actor metadata.
     *
     * See the class docblock §"Immutability" for the distinction.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(static function (Payment $payment): void {
            $guarded = [
                'wallet_id',
                'currency',
                'amount',
                'received_at',
            ];

            foreach ($guarded as $attribute) {
                if (!$payment->isDirty($attribute)) {
                    continue;
                }

                throw new LogicException(
                    sprintf(
                        'Payment.%s is immutable after creation. '
                        . 'Changing it would falsify the recorded monetary fact, '
                        . 'breach the wallet currency invariant, or alter the '
                        . 'aggregate scope of a payment that may already be '
                        . 'partially allocated. If a different payment is '
                        . 'required, record a new one and correct the ledger '
                        . 'through a reversal. '
                        . 'Attempted change on wallet_payments.id=%s.',
                        $attribute,
                        (string) $payment->getKey(),
                    )
                );
            }
        });
    }

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The owning wallet.
     *
     * A payment belongs to exactly one wallet. The wallet carries the tenant
     * scope; this payment resolves tenant transitively through its wallet.
     *
     * @return BelongsTo
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'id');
    }

    /**
     * The user who recorded this payment.
     *
     * Nullable at the schema level because system-generated payments (e.g.
     * a future automated receipt) have no human actor. Null is a legitimate
     * state, not an error.
     *
     * @return BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * The allocations recorded against this payment.
     *
     * The relation does not filter reversed portions. A caller that needs
     * the effective allocated total computes it by summing `amount` and
     * subtracting any reversals reachable through each allocation's
     * `reversals()` relation. For the projection-cached value, read
     * `unapplied_amount` directly.
     *
     * @return HasMany
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(
            PaymentAllocation::class,
            'payment_id',
            'id',
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific wallet.
     *
     * The primary wallet-scoping entry point for payments, mirroring the
     * pattern used by the Wallet model's `scopeForTenant()`. The
     * PaymentRepository composes this scope with the tenant-scoped wallet
     * lookup to enforce the invariant recorded in Step 7 v1.1.0 §C.6.
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
     * Scope a query to payments with a positive unapplied amount.
     *
     * "Unapplied" is defined by the projection: `unapplied_amount > 0`.
     * This is the primary filter for selecting payments that can still
     * receive an allocation.
     *
     * The comparison uses a strict-greater-than against zero at the
     * database level. The column is `DECIMAL(20,4)`; comparing against the
     * literal `0` is exact under MySQL's decimal semantics.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeUnapplied(Builder $query): Builder
    {
        return $query->where('unapplied_amount', '>', 0);
    }

    /**
     * Scope a query to fully applied payments.
     *
     * "Fully applied" is defined by the projection: `unapplied_amount = 0`.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFullyApplied(Builder $query): Builder
    {
        return $query->where('unapplied_amount', '=', 0);
    }

    // =========================================================================
    // Domain helpers
    // =========================================================================

    /**
     * Whether the payment is fully unapplied — no allocation has been
     * recorded against it.
     *
     * Uses bcmath to compare the two decimal strings, avoiding float
     * rounding.
     *
     * @return bool
     */
    public function isUnapplied(): bool
    {
        return bccomp((string) $this->unapplied_amount, (string) $this->amount, 4) === 0;
    }

    /**
     * Whether the payment is fully applied — its unapplied amount is zero.
     *
     * @return bool
     */
    public function isFullyApplied(): bool
    {
        return bccomp((string) $this->unapplied_amount, '0', 4) === 0;
    }

    /**
     * Whether the payment is partially applied — a positive unapplied
     * amount that is strictly less than the original amount.
     *
     * @return bool
     */
    public function isPartiallyApplied(): bool
    {
        $unapplied = (string) $this->unapplied_amount;
        $amount    = (string) $this->amount;

        $isPositive       = bccomp($unapplied, '0', 4) > 0;
        $isBelowAmount    = bccomp($unapplied, $amount, 4) < 0;

        return $isPositive && $isBelowAmount;
    }

    /**
     * Whether the payment carries a reference to a consuming-domain
     * business object.
     *
     * The payment stores the reference as the pair
     * `reference_type` + `reference_id`. Both must be non-null for the
     * pair to be meaningful.
     *
     * @return bool
     */
    public function hasReference(): bool
    {
        return $this->reference_type !== null && $this->reference_id !== null;
    }
}