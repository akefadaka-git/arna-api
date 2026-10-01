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
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * Wallet
 *
 * Eloquent model for a member's wallet — the primary aggregate root of the
 * Wallet domain.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * A wallet is a member's statement-of-account ledger with Maale Arna. It
 * records obligations the member owes (or is owed), payments received, and
 * the explicit allocation of payments to obligations — under policy slots
 * whose contents are deferred (A7, A8, D110).
 *
 * A wallet is scoped to exactly one (tenant, shareholder) pair. The
 * composite UNIQUE constraint on `(tenant_id, shareholder_id)` in the
 * migration (2B.49) guarantees at most one wallet per shareholder per
 * tenant.
 *
 * The wallet is NOT a custody banking system, a payment rail, or an
 * AML/CFT reporting subsystem. It is a ledger.
 *
 * =============================================================================
 * Single-currency invariant (Q8)
 * =============================================================================
 * Every wallet is single-currency. The `currency` column is required and
 * carries an ISO 4217 three-letter code. Every obligation and every
 * payment belonging to this wallet must use the same currency; the
 * equality is enforced at the service layer (the schema cannot express it
 * portably).
 *
 * Wallet.current_balance, WalletEntry.signed_quantity, and every other
 * monetary value in the domain represents exactly the currency of its
 * owning wallet. There is no per-currency sub-balance.
 *
 * Multi-currency wallets are explicitly out of scope for Phase 2B. A
 * future phase requiring them would redesign the balance model; the 2B
 * schema does not anticipate that redesign.
 *
 * =============================================================================
 * Currency immutability (A10)
 * =============================================================================
 * The wallet's currency is immutable for the wallet's lifetime. Changing
 * currency would invalidate every ledger entry, every projection, and
 * every allocation — every historical monetary value was expressed in the
 * original currency, and reinterpreting it in a different currency is not
 * a legitimate operation.
 *
 * If a member's account must move between currencies, that is a NEW
 * wallet. This model enforces the immutability at the model level: the
 * `booted()` method throws `LogicException` on any attempted update that
 * leaves `currency` dirty. This is a defence-in-depth layer; the service
 * layer (WalletService, 2B.15) exposes no update path for currency.
 *
 * The same guard protects `tenant_id`, `shareholder_id`, and `opened_at` —
 * the aggregate's identity and its historical anchor. See `booted()`.
 *
 * =============================================================================
 * Balance projection (Architecture §4.6)
 * =============================================================================
 * `current_balance` is a PROJECTION, not the source of truth. The
 * append-only ledger (`wallet_entries`, 2B.54) is authoritative. Two
 * invariants govern:
 *
 *     Entry invariant:
 *         balance_before + signed_quantity = balance_after
 *
 *     Projection invariant:
 *         wallet.current_balance = latest committed wallet_entries.balance_after
 *
 * For a wallet with no entries, `current_balance = 0`.
 *
 * The ONLY writer of `current_balance` is WalletService (2B.15), through
 * its transactional write path. This model exposes no mutation method for
 * the column.
 *
 * =============================================================================
 * Sign convention (Architecture §4.2)
 * =============================================================================
 * The sign of `current_balance` reflects the member's position:
 *
 *     positive  — the organization holds credit for the member
 *     negative  — the member owes the organization
 *     zero      — no net position
 *
 * The convention is documented here so that consumers reading the field
 * do not need to consult the ledger.
 *
 * =============================================================================
 * Policy references (Architecture §8.4–§8.6)
 * =============================================================================
 * Three nullable foreign keys reference the Core versioned-policy registry
 * (`versioned_policies`, CORE.2):
 *
 *     interest_policy_version_id      — A8 seam
 *     allocation_policy_version_id    — D110 seam
 *     kyc_policy_version_id           — A7 seam
 *
 * When null, the wallet is governed by no policy of that type. Phase 2B
 * declares the slots but does NOT evaluate them: no service reads, parses,
 * or enforces a policy payload. The slots exist so that A7, A8, and D110
 * can attach without schema rework when their rules are supplied.
 *
 * The DB-level FK proves only existence of the policy row. It does NOT
 * prove type compatibility — i.e., that `interest_policy_version_id`
 * resolves to a policy whose `policy_type = 'interest'`. Type
 * compatibility is the future policy-attachment service's concern
 * (Architecture §8.6).
 *
 * =============================================================================
 * Lifecycle
 * =============================================================================
 *   opened_at   — required; set at creation. Immutable (see `booted()`).
 *   is_active   — boolean; true by default.
 *   closed_at   — nullable; retained for future closure workflows. Phase
 *                 2B exposes no closure/reopen operation (Q5). The field
 *                 exists so that the aggregate's shape anticipates
 *                 lifecycle state; a future CR adds the operation.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   tenant()                    — the tenant this wallet belongs to.
 *   shareholder()               — the member; referenced by id only.
 *   interestPolicy()            — nullable VersionedPolicy reference.
 *   allocationPolicy()          — nullable VersionedPolicy reference.
 *   kycPolicy()                 — nullable VersionedPolicy reference.
 *   obligations()               — HasMany Obligation.
 *   payments()                  — HasMany Payment.
 *   paymentAllocations()        — HasMany PaymentAllocation (direct via wallet_id).
 *   paymentAllocationReversals() — HasMany PaymentAllocationReversal (direct via wallet_id).
 *   entries()                   — HasMany WalletEntry (the ledger).
 *
 * The three policy relations use `belongsTo()` with the explicit FK
 * column. They resolve the same target model (`VersionedPolicy`) under
 * three distinct relation names; the type compatibility of each reference
 * is a service-layer concern, not a relation-layer one.
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not maintain `current_balance`. WalletService owns that.
 *
 *   - It does not validate currency equality with obligations/payments.
 *     That is a service-layer invariant (Architecture §6.3).
 *
 *   - It does not evaluate policies. It stores three nullable references.
 *
 *   - It does not provide a wallet identifier. D107-C places identifier
 *     concerns outside the Wallet domain; there is no `wallet_number` or
 *     similar column.
 *
 *   - It does not carry KYC fields. A7 leaves the KYC field matrix open;
 *     no KYC column is present.
 *
 *   - It does not cascade-delete children. All wallet-owned financial FKs
 *     use RESTRICT / NO ACTION (Q6); deletion of a wallet and its history
 *     is not an application operation.
 *
 *   - It does not soft-delete. The 2B domain has no soft-delete posture;
 *     `is_active` covers lifecycle, and the ledger is append-only.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.2     — Wallet aggregate root
 *   Architecture v1.2.0 §4.2     — Sign convention
 *   Architecture v1.2.0 §4.4     — Precision, currency, immutability (A10)
 *   Architecture v1.2.0 §4.6     — Balance projection invariant
 *   Architecture v1.2.0 §6.3     — Allocation invariants (currency equality)
 *   Architecture v1.2.0 §8.4     — Policy types recognised in Phase 2B
 *   Architecture v1.2.0 §8.6     — Policy reference integrity
 *   Architecture v1.2.0 §12      — Tenant isolation
 *   Architecture v1.2.0 §18      — Explicitly deferred items
 *   Architecture v1.2.0 §19      — Q4, Q5, Q8 resolutions
 *   Step 5 (manifest) 2B.5       — This file
 *   Step 6 v1.1.0 §3.2           — wallets table specification
 *
 * @property int                       $id
 * @property int                       $tenant_id
 * @property int                       $shareholder_id
 * @property string                    $currency
 * @property string                    $current_balance
 * @property int|null                  $interest_policy_version_id
 * @property int|null                  $allocation_policy_version_id
 * @property int|null                  $kyc_policy_version_id
 * @property bool                      $is_active
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Models
 * @version 1.0.0
 */
final class Wallet extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'wallets';

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
     * Order mirrors the migration's column order (2B.49): identity and
     * scope first, then monetary, then policy references, then lifecycle.
     *
     * `currency` is fillable so that `WalletService::open()` can supply it
     * via `create([...])`. Its immutability AFTER creation is enforced by
     * the `booted()` guard, not by exclusion from `$fillable` (Eloquent
     * has no temporal fillable concept).
     *
     * `current_balance` is fillable for symmetry with the migration's
     * default but is not mass-assigned by the service on create; the DB
     * default of `0` applies. Subsequent updates go through the
     * transactional write path in WalletService, not through mass
     * assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'shareholder_id',
        'currency',
        'current_balance',
        'interest_policy_version_id',
        'allocation_policy_version_id',
        'kyc_policy_version_id',
        'is_active',
        'opened_at',
        'closed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * `current_balance` is cast as `decimal:4` so that reads return the
     * exact decimal representation that bcmath operates on. Laravel's
     * decimal cast returns a string in Laravel 11+, avoiding float
     * rounding for monetary values.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tenant_id'                      => 'integer',
        'shareholder_id'                 => 'integer',
        'current_balance'                => 'decimal:4',
        'interest_policy_version_id'     => 'integer',
        'allocation_policy_version_id'   => 'integer',
        'kyc_policy_version_id'          => 'integer',
        'is_active'                      => 'boolean',
        'opened_at'                      => 'datetime',
        'closed_at'                      => 'datetime',
        'created_at'                     => 'datetime',
        'updated_at'                     => 'datetime',
    ];

    /**
     * The attributes that should be hidden from array/JSON serialization.
     *
     * Empty. Nothing on the wallet row is secret in the ordinary sense.
     * The resource layer (2B.31) selects which fields to expose.
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
    // Lifecycle — identity and monetary immutability
    // =========================================================================

    /**
     * Register the model's event listeners.
     *
     * Guards four attributes against mutation after creation:
     *
     *   - `tenant_id`      — the tenant scope is fixed for the wallet's life.
     *   - `shareholder_id` — the aggregate identity; a wallet is not
     *                        re-parented.
     *   - `currency`       — A10 immutability.
     *   - `opened_at`      — historical fact; the wallet was opened when it
     *                        was opened.
     *
     * Each of these attributes is set once at creation and never legitimately
     * changes. A change would either falsify history (`opened_at`), breach
     * tenant isolation (`tenant_id`), invalidate every ledger entry and
     * allocation (`currency`), or corrupt the aggregate identity
     * (`shareholder_id`).
     *
     * `is_active` and `closed_at` are NOT guarded: a future CR (Q5) may add
     * closure/reopen operations that legitimately update them.
     *
     * `current_balance` is NOT guarded by this observer: the projection is
     * updated by WalletService's transactional write path. The service is
     * responsible for updating it inside the same transaction that writes
     * the corresponding ledger entry. Guarding it here would block the
     * legitimate write path.
     *
     * See Architecture §4.4 (A10), §12 (tenant isolation), and §19 (Q8).
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(static function (Wallet $wallet): void {
            $guarded = ['tenant_id', 'shareholder_id', 'currency', 'opened_at'];

            foreach ($guarded as $attribute) {
                if (!$wallet->isDirty($attribute)) {
                    continue;
                }

                throw new LogicException(
                    sprintf(
                        'Wallet.%s is immutable after creation. '
                        . 'Changing it would breach an architectural invariant '
                        . '(tenant scope, aggregate identity, currency immutability, '
                        . 'or historical anchor). If a new scope or currency is '
                        . 'required, open a new wallet; do not mutate this one. '
                        . 'Attempted change on wallets.id=%s.',
                        $attribute,
                        (string) $wallet->getKey(),
                    )
                );
            }
        });
    }

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The tenant this wallet belongs to.
     *
     * Every wallet belongs to exactly one tenant. The tenant FK is the
     * enforcement point for tenant isolation: the wallet carries the
     * tenant_id; child entities carry the wallet_id and resolve tenant
     * transitively.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * The shareholder (member) this wallet belongs to.
     *
     * The relationship is a read-only reference: the Wallet domain
     * references the shareholder by id and does not modify any Shareholder
     * row. The dependency direction is `Wallet → Shareholder`; the
     * Shareholder domain has no reverse relation to Wallet.
     *
     * @return BelongsTo
     */
    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class, 'shareholder_id', 'id');
    }

    /**
     * The interest policy version attached to this wallet (A8 seam).
     *
     * Nullable. When null, no interest policy governs this wallet. When
     * non-null, the reference resolves to a `VersionedPolicy` row.
     *
     * Phase 2B does NOT evaluate the policy. Reading this relation returns
     * the policy row; interpretation is the consumer's concern.
     *
     * Type compatibility (that the referenced policy's `policy_type` is
     * `'interest'`) is NOT enforced by the FK; see Architecture §8.6.
     *
     * @return BelongsTo
     */
    public function interestPolicy(): BelongsTo
    {
        return $this->belongsTo(
            VersionedPolicy::class,
            'interest_policy_version_id',
            'id',
        );
    }

    /**
     * The allocation policy version attached to this wallet (D110 seam).
     *
     * Nullable. Same semantics as `interestPolicy()`; type compatibility
     * with `policy_type = 'allocation'` is a service-layer concern.
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
     * The KYC policy version attached to this wallet (A7 seam).
     *
     * Nullable. Same semantics as `interestPolicy()`; type compatibility
     * with `policy_type = 'kyc'` is a service-layer concern.
     *
     * @return BelongsTo
     */
    public function kycPolicy(): BelongsTo
    {
        return $this->belongsTo(
            VersionedPolicy::class,
            'kyc_policy_version_id',
            'id',
        );
    }

    /**
     * The obligations recorded against this wallet.
     *
     * @return HasMany
     */
    public function obligations(): HasMany
    {
        return $this->hasMany(
            Obligation::class,
            'wallet_id',
            'id',
        );
    }

    /**
     * The payments received against this wallet.
     *
     * @return HasMany
     */
    public function payments(): HasMany
    {
        return $this->hasMany(
            Payment::class,
            'wallet_id',
            'id',
        );
    }

    /**
     * The payment allocations belonging to this wallet.
     *
     * Payment allocations are normally reached through a specific Payment
     * or Obligation; this direct relation exists for reporting queries
     * that need the full attribution history of a wallet without
     * traversing either parent.
     *
     * @return HasMany
     */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(
            PaymentAllocation::class,
            'wallet_id',
            'id',
        );
    }

    /**
     * The payment-allocation reversals belonging to this wallet.
     *
     * Same rationale as `paymentAllocations()`: a direct relation for
     * reporting queries that need the full reversal history of a wallet.
     *
     * @return HasMany
     */
    public function paymentAllocationReversals(): HasMany
    {
        return $this->hasMany(
            PaymentAllocationReversal::class,
            'wallet_id',
            'id',
        );
    }

    /**
     * The ledger entries of this wallet.
     *
     * The relation does not impose an ordering. Callers that need
     * chronological ordering use `entries()->orderBy('occurred_at')`;
     * callers that need the current balance read the `current_balance`
     * column (which is the projection maintained by the ledger).
     *
     * The ledger is append-only. No method on this relation's target
     * model accepts updates or deletes.
     *
     * @return HasMany
     */
    public function entries(): HasMany
    {
        return $this->hasMany(
            WalletEntry::class,
            'wallet_id',
            'id',
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific tenant.
     *
     * The primary tenant-scoping entry point for the Wallet domain,
     * mirroring the pattern established by `Shareholder::scopeForTenant()`.
     * Repositories call this through their `findForTenant()` methods to
     * enforce the invariant recorded in Step 7 v1.1.0 §C.6.
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
     * Scope a query to active wallets only.
     *
     * A wallet is active when `is_active = true`. The scope is a
     * convenience; the `is_active` flag is not currently used to
     * constrain operations in Phase 2B (closure/reopen is deferred, Q5).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // =========================================================================
    // Domain helpers
    // =========================================================================

    /**
     * Whether the wallet is currently active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Whether the wallet has been closed.
     *
     * A wallet is considered closed when `closed_at` is non-null. Phase 2B
     * does not produce closed wallets; the helper exists so that a reader
     * can branch on the field without inspecting it directly.
     *
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Whether the wallet has an interest policy attached.
     *
     * Checks the reference only; does not load or evaluate the policy.
     *
     * @return bool
     */
    public function hasInterestPolicy(): bool
    {
        return $this->interest_policy_version_id !== null;
    }

    /**
     * Whether the wallet has an allocation policy attached.
     *
     * Checks the reference only; does not load or evaluate the policy.
     *
     * @return bool
     */
    public function hasAllocationPolicy(): bool
    {
        return $this->allocation_policy_version_id !== null;
    }

    /**
     * Whether the wallet has a KYC policy attached.
     *
     * Checks the reference only; does not load or evaluate the policy.
     *
     * @return bool
     */
    public function hasKycPolicy(): bool
    {
        return $this->kyc_policy_version_id !== null;
    }
}