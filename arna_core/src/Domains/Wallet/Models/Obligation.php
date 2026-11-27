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
use MaaleArna\Arna\Core\Domains\Wallet\Enums\ObligationComponent;

/**
 * Tenant-scoped debt or credit item with one economic component. Original amount is immutable; outstanding amount is derived from effective allocations.
 *
 * @property int                       $id
 * @property int                       $wallet_id
 * @property ObligationComponent       $component
 * @property string                    $currency
 * @property string                    $original_amount
 * @property string                    $outstanding_amount
 * @property Carbon $occurred_at
 * @property Carbon|null $due_at
 * @property string|null               $reference_type
 * @property string|null               $reference_id
 * @property string|null               $description
 * @property int|null                  $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @package MaaleArna\Arna\Core\Domains\Wallet\Models
 * @version 1.0.0
 */
final class Obligation extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'wallet_obligations';

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
     * Order mirrors the migration's column order (2B.50). The two amount
     * columns are fillable so that ObligationService can supply them at
     * creation via `create([...])`. Their subsequent immutability
     * (`original_amount`) and service-owned projection update
     * (`outstanding_amount`) are enforced by the model's `booted()` guard
     * and by the service layer, not by exclusion from `$fillable`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'wallet_id',
        'component',
        'currency',
        'original_amount',
        'outstanding_amount',
        'occurred_at',
        'due_at',
        'reference_type',
        'reference_id',
        'description',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * `component` is cast to the `ObligationComponent` enum. See the class
     * docblock §"Enum cast" for the rationale and the documented
     * divergence from the 2A precedent on `ShareholderType`.
     *
     * The two amount columns are cast as `decimal:4` so that reads return
     * the exact decimal string that bcmath operates on. Laravel's decimal
     * cast returns a string in Laravel 11+, avoiding float rounding for
     * monetary values.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'wallet_id'          => 'integer',
        'component'          => ObligationComponent::class,
        'original_amount'    => 'decimal:4',
        'outstanding_amount' => 'decimal:4',
        'occurred_at'        => 'datetime',
        'due_at'             => 'datetime',
        'created_by'         => 'integer',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    /**
     * The attributes that should be hidden from array/JSON serialization.
     *
     * Empty. Nothing on the obligation row is secret in the ordinary
     * sense. The resource layer (2B.32) selects which fields to expose.
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
     * Guards five attributes against mutation after creation:
     *
     *   - `wallet_id`        — aggregate scope.
     *   - `component`        — the economic component is fixed.
     *   - `currency`         — must equal the (immutable) wallet currency.
     *   - `original_amount`  — the recorded amount. Corrections are new
     *                          obligations plus reversals.
     *   - `occurred_at`      — historical fact.
     *
     * Each of these is set once at creation and never legitimately changes.
     * The guard fails loudly rather than silently permitting an update that
     * would falsify history, break the currency invariant, or change the
     * economic meaning of an existing ledger-referenced obligation.
     *
     * The following attributes are deliberately NOT guarded:
     *
     *   - `outstanding_amount` — the projection; AllocationService updates
     *                            it inside its transaction.
     *   - `due_at`             — a future CR may add a reschedule operation.
     *   - `reference_type`     — descriptive metadata.
     *   - `reference_id`       — descriptive metadata.
     *   - `description`        — descriptive metadata.
     *   - `created_by`         — actor metadata.
     *
     * See the class docblock §"Immutability" for the distinction.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(static function (Obligation $obligation): void {
            $guarded = [
                'wallet_id',
                'component',
                'currency',
                'original_amount',
                'occurred_at',
            ];

            foreach ($guarded as $attribute) {
                if (!$obligation->isDirty($attribute)) {
                    continue;
                }

                throw new LogicException(
                    sprintf(
                        'Obligation.%s is immutable after creation. '
                        . 'Changing it would falsify the obligation\'s recorded '
                        . 'economic meaning or breach a wallet invariant '
                        . '(aggregate scope, currency equality, or historical '
                        . 'anchor). If a different obligation is required, '
                        . 'record a new one and reverse the original. '
                        . 'Attempted change on wallet_obligations.id=%s.',
                        $attribute,
                        (string) $obligation->getKey(),
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
     * An obligation belongs to exactly one wallet. The wallet carries the
     * tenant scope; this obligation resolves tenant transitively through
     * its wallet.
     *
     * @return BelongsTo
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'id');
    }

    /**
     * The user who recorded this obligation.
     *
     * Nullable at the schema level because system-generated obligations
     * (e.g. a future scheduled accrual) have no human actor. Null is a
     * legitimate state, not an error.
     *
     * @return BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * The allocations recorded against this obligation.
     *
     * The relation does not filter reversed portions. A caller that needs
     * the effective allocation total computes it by summing `amount` and
     * subtracting any reversals reachable through each allocation's
     * `reversals()` relation. For the projection-cached value, read
     * `outstanding_amount` directly.
     *
     * @return HasMany
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(
            PaymentAllocation::class,
            'obligation_id',
            'id',
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific wallet.
     *
     * The primary wallet-scoping entry point for obligations, mirroring
     * the pattern used by the Wallet model's `scopeForTenant()`. The
     * ObligationRepository composes this scope with the tenant-scoped
     * wallet lookup to enforce the invariant recorded in Step 7 v1.1.0
     * §C.6 — cross-tenant and cross-wallet references produce the same
     * 404 a non-existent id would produce.
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
     * Scope a query to a specific component.
     *
     * Accepts either the enum case or its string value, so callers do not
     * need to normalise before invoking the scope. Mirrors the pattern
     * used by `Shareholder::scopeOfType()`.
     *
     * @param Builder                    $query
     * @param ObligationComponent|string $component
     * @return Builder
     */
    public function scopeOfComponent(Builder $query, ObligationComponent|string $component): Builder
    {
        $value = $component instanceof ObligationComponent
            ? $component->value
            : $component;

        return $query->where('component', $value);
    }

    /**
     * Scope a query to obligations with a non-zero outstanding amount.
     *
     * "Outstanding" is defined by the projection: `outstanding_amount > 0`.
     * This is the primary filter for reporting and for selecting
     * obligations that can still receive an allocation.
     *
     * The comparison uses a strict-greater-than against zero at the
     * database level. The column is `DECIMAL(20,4)`; comparing against the
     * literal `0` is exact under MySQL's decimal semantics.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('outstanding_amount', '>', 0);
    }

    // =========================================================================
    // Domain helpers
    // =========================================================================

    /**
     * Whether the obligation is fully outstanding — no allocation has been
     * recorded against it.
     *
     * Uses bcmath to compare the two decimal strings, avoiding float
     * rounding.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return bccomp((string) $this->outstanding_amount, (string) $this->original_amount, 4) === 0;
    }

    /**
     * Whether the obligation is settled — its outstanding amount is zero.
     *
     * @return bool
     */
    public function isSettled(): bool
    {
        return bccomp((string) $this->outstanding_amount, '0', 4) === 0;
    }

    /**
     * Whether the obligation is partially settled — a positive outstanding
     * amount that is strictly less than the original.
     *
     * @return bool
     */
    public function isPartiallySettled(): bool
    {
        $outstanding = (string) $this->outstanding_amount;
        $original    = (string) $this->original_amount;

        $isPositive        = bccomp($outstanding, '0', 4) > 0;
        $isBelowOriginal   = bccomp($outstanding, $original, 4) < 0;

        return $isPositive && $isBelowOriginal;
    }

    /**
     * Whether the obligation has a specified due date.
     *
     * @return bool
     */
    public function hasDueDate(): bool
    {
        return $this->due_at !== null;
    }

    /**
     * Whether the obligation carries a reference to a consuming-domain
     * business object.
     *
     * The obligation stores the reference as the pair
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