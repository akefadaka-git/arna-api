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
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;

/**
 * Obligation
 *
 * Eloquent model for a wallet obligation — a scoped business aggregate of
 * the Wallet domain.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * An obligation is an amount the member owes (or is owed) that is tracked
 * against a specific wallet. Each obligation carries exactly one economic
 * component from the closed `ObligationComponent` vocabulary (2B.3):
 *
 *     principal, interest, fee, penalty, tax, other
 *
 * The obligation does NOT carry a payment/schedule category — categories
 * such as `loan_installment` or `subscription_payment` belong to the
 * consuming domain and are stored as the opaque pair
 * `reference_type` + `reference_id` (D109, Architecture §18).
 *
 * =============================================================================
 * Projection vs. source of truth
 * =============================================================================
 * `original_amount` is the recorded amount at creation time. It is
 * IMMUTABLE — a correction is not an edit to an existing obligation; it is
 * a new obligation plus a reversal of the original (Architecture §6.1).
 *
 * `outstanding_amount` is a PROJECTION. It is derived from the original
 * amount and the effective allocations recorded against this obligation:
 *
 *     outstanding_amount
 *         = original_amount
 *         − Σ(effective_allocated for all allocations of this obligation)
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
 * The two projection states a consumer reads:
 *
 *     outstanding_amount = original_amount   → open
 *     outstanding_amount = 0                 → settled
 *     0 < outstanding < original             → partially settled
 *
 * The helper methods `isOpen()`, `isPartiallySettled()`, and `isSettled()`
 * expose this classification.
 *
 * =============================================================================
 * Single-currency invariant (Q8)
 * =============================================================================
 * `currency` must equal the owning wallet's currency. The wallet's
 * currency is itself immutable (2B.5, A10). Therefore this obligation's
 * currency is effectively fixed for the obligation's lifetime — the
 * obligation cannot drift to a different currency while the wallet it
 * belongs to stays in the original one.
 *
 * The equality is enforced at the service layer (ObligationService, 2B.16)
 * because the schema cannot express it portably without a trigger. See
 * Architecture §6.3 for the allocation-time invariant, which restates the
 * same rule at the attribution boundary.
 *
 * =============================================================================
 * Immutability
 * =============================================================================
 * Five attributes are immutable after creation:
 *
 *     wallet_id        — aggregate scope; an obligation is not re-parented.
 *     component        — the economic component is a fixed property of the
 *                        obligation. A different component is a different
 *                        obligation.
 *     currency         — must equal the (immutable) wallet currency (Q8).
 *     original_amount  — the recorded amount. Corrections are new
 *                        obligations plus reversals (Architecture §6.1).
 *     occurred_at      — the business time of recording. Historical fact.
 *
 * These five are guarded at the model level by `booted()`; any attempted
 * update that leaves one of them dirty throws `LogicException`.
 *
 * The following attributes are NOT guarded and remain mutable through the
 * service layer:
 *
 *     outstanding_amount — the projection; updated by AllocationService.
 *     due_at             — a future CR may add a reschedule operation.
 *     reference_type     — descriptive metadata.
 *     reference_id       — descriptive metadata.
 *     description        — descriptive metadata.
 *     created_by         — actor metadata.
 *
 * The distinction follows the 2B posture established by Wallet (2B.5):
 * identity and economic facts are guarded; descriptive and derived fields
 * are not. Phase 2B exposes no update operation for the mutable fields;
 * the posture exists so that a future CR can add one without schema
 * rework.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   wallet()          — the owning Wallet (BelongsTo).
 *   createdBy()       — the recording user (BelongsTo, nullable).
 *   allocations()     — the allocations recorded against this obligation
 *                       (HasMany PaymentAllocation).
 *
 * The `allocations()` relation does not filter out reversed portions. A
 * caller that needs the effective allocation total computes it from the
 * relation by summing `amount` and subtracting any associated reversals.
 * For the projection-cached value, read `outstanding_amount` directly.
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not carry a payment/schedule category. D109 places that
 *     vocabulary outside the Wallet domain.
 *
 *   - It does not carry a priority field. D110 declines to freeze a
 *     universal allocation priority order.
 *
 *   - It does not maintain `outstanding_amount`. AllocationService owns
 *     that projection.
 *
 *   - It does not enforce currency equality with its wallet. That is an
 *     ObligationService concern.
 *
 *   - It does not soft-delete. The 2B posture is that financial records
 *     are not deleted through application operations; corrections flow
 *     through reversals.
 *
 * =============================================================================
 * Enum cast
 * =============================================================================
 * `component` is cast to the `ObligationComponent` enum. This is a
 * deliberate choice, and a documented divergence from the 2A precedent
 * on `Shareholder::shareholder_type` (which is not enum-cast; see
 * `mases_core/src/Domains/Shareholder/Models/Shareholder.php`).
 *
 * The rationale: the obligation component is a CLOSED vocabulary enforced
 * at write time by ObligationService; an unrecognised value in the column
 * indicates a deployment defect or a manual DB edit, both of which should
 * fail loudly rather than silently pass through. The 2A decision on
 * `shareholder_type` was made specifically to allow reading unrecognised
 * values for diagnostics; that rationale does not apply to the wallet's
 * economic-component vocabulary, whose closed nature is what makes the
 * ledger auditable.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D109                         — Obligation types decision
 *   Architecture v1.2.0 §3.3     — Obligation entity
 *   Architecture v1.2.0 §6.1     — Obligation projection
 *   Architecture v1.2.0 §6.3     — Allocation invariants (currency equality)
 *   Architecture v1.2.0 §9       — Interest-policy contract boundary
 *   Architecture v1.2.0 §10      — Allocation-policy contract boundary
 *   Architecture v1.2.0 §18      — Explicitly deferred items
 *   Step 5 (manifest) 2B.6       — This file
 *   Step 6 v1.1.0 §3.3           — wallet_obligations table specification
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
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Models
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