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
use MaaleArna\Arna\Core\Policy\Models\VersionedPolicy;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;

/**
 * LoanProduct
 *
 * Eloquent model for a loan-product catalogue entry.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * A loan product is the tenant's catalogue entry for a class of loans the
 * company offers: its identity (code, names, description, purpose), its
 * structural envelope (currency, principal bounds, term bounds, default
 * repayment frequency), and its policy reference (the versioned
 * `loan_product_rules` policy that governs its financial rules).
 *
 * The model is a MUTABLE REFERENCE-DATA object, not a financial fact. A
 * product's names, bounds, description, and policy reference may
 * legitimately be updated by an operator over the product's lifetime. The
 * table carries standard `created_at` / `updated_at` timestamps (migration
 * A.8) and the model uses Eloquent's default timestamp management.
 *
 * This contrasts with the loan's own models (`Loan`, `LoanInstallment`,
 * `LoanCollateral`, `LoanRestructuring`, `LoanWriteOff`,
 * `LoanStatusHistory`, `LoanInterestAccrual`, `LoanPenaltyAccrual`) — those
 * are financial-fact models with strict immutability or transaction-scoped
 * mutability. `LoanProduct` is the one mutable reference-data model in the
 * Loan domain.
 *
 * =============================================================================
 * Historical changes are recorded in Git.
 * @property int                        $id
 * @property int                        $tenant_id
 * @property string                     $code
 * @property string|null                $name_am
 * @property string|null                $name_en
 * @property string|null                $description_am
 * @property string|null                $description_en
 * @property string|null                $purpose
 * @property string                     $currency
 * @property string                     $min_principal
 * @property string                     $max_principal
 * @property int                        $min_term_months
 * @property int                        $max_term_months
 * @property string                     $default_installment_frequency
 * @property bool                       $is_active
 * @property int|null                   $product_rules_policy_version_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Tenant|null               $tenant
 * @property-read VersionedPolicy|null      $policyVersion
 * @property-read Collection<int, Loan> $loans
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Models
 * @version 1.0.1
 */
final class LoanProduct extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'loan_products';

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
     * The list is authoritative for the model's `create()` and `update()`
     * call sites, both of which are the `LoanProductService` (a future
     * administrative service; the current Phase 2C freeze does not include
     * a product-management endpoint — products are seeded or created by
     * administrative scripts). A controller that attempts to mass-assign
     * an unlisted column receives no error and no effect; the missing key
     * simply is not written.
     *
     * `tenant_id` IS mass-assignable (since v1.0.1 — see the class
     * docblock §"Revision history"). The column is NOT NULL by the
     * migration A.8 and is a mandatory part of every product's identity
     * under the Extra 1 tenant-scope freeze. The creating service
     * (or the test fixture, or the seeder) supplies it from the request
     * context; Eloquent's `create()` method then writes it.
     *
     * `id` is NOT mass-assignable: it is auto-assigned by the database.
     * `created_at` and `updated_at` are NOT mass-assignable: Eloquent
     * manages them via the `$timestamps` mechanism.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'code',
        'name_am',
        'name_en',
        'description_am',
        'description_en',
        'purpose',
        'currency',
        'min_principal',
        'max_principal',
        'min_term_months',
        'max_term_months',
        'default_installment_frequency',
        'is_active',
        'product_rules_policy_version_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * Monetary values are cast as `decimal:4`, which yields STRINGS with
     * four decimal places. This is deliberate: financial values must not
     * be handled as PHP floats, because floats cannot represent every
     * decimal fraction exactly, and financial arithmetic requires decimal
     * semantics. Callers that need arithmetic use bcmath on the string
     * values, never PHP's native operators.
     *
     * This mirrors the casting policy on Phase 2A's `ShareClass.par_value`
     * and Phase 2B's `Wallet.current_balance`.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tenant_id'                      => 'integer',
        'min_principal'                  => 'decimal:4',
        'max_principal'                  => 'decimal:4',
        'min_term_months'                => 'integer',
        'max_term_months'                => 'integer',
        'is_active'                      => 'boolean',
        'product_rules_policy_version_id' => 'integer',
        'created_at'                     => 'datetime',
        'updated_at'                     => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. Resources (A.65 `LoanProductResource`) select
     * fields explicitly, and the `displayName()` helper is available on
     * demand but is not force-serialized.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The tenant that owns this product.
     *
     * Every product belongs to exactly one tenant (Extra 1). The relation
     * is used by resources and by administrative reporting; the
     * repository enforces tenant scoping at the query level.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * The versioned `loan_product_rules` policy that governs this product's
     * financial rules.
     *
     * Nullable: a product may be created before its first policy version is
     * authored. A product that is enabled for applications
     * (`is_active = true`) is expected to have a non-null policy version;
     * the service layer enforces that conjunction. See the migration A.8
     * for the rationale.
     *
     * The relation targets `VersionedPolicy` (CORE.3) — the Core Policy
     * Registry's model. The Loan domain does not define a local policy
     * model; it uses the shared registry, per the B6/Extra-2 freeze.
     *
     * @return BelongsTo
     */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(
            VersionedPolicy::class,
            'product_rules_policy_version_id',
            'id',
        );
    }

    /**
     * The loans originated under this product.
     *
     * One-to-many: a product may be the source of many loans. The relation
     * is used by administrative reports and by the product catalogue's
     * own deactivation logic (an operator may want to see the loan count
     * before deactivating a product). No Phase 2C endpoint exposes a "list
     * loans for this product" query.
     *
     * @return HasMany
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'loan_product_id', 'id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific tenant.
     *
     * Every read through the LoanProductRepository is composed with this
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
     * Scope a query to active products.
     *
     * Active means `is_active = true`. The filter is used by the
     * application service (A.24) when validating a product reference on a
     * new application, and by the product listing endpoint (which
     * optionally filters to active products).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query by product code, case-insensitively.
     *
     * The code is normalised to uppercase before the comparison, matching
     * the platform convention (Phase 2A's `ShareClass.scopeByCode` uses
     * the same pattern). A stored code is expected to be uppercase; a
     * caller who supplies a lowercase code still matches.
     *
     * The scope is used by `LoanProductRepository::findByCode()` (A.52).
     *
     * @param Builder $query
     * @param string  $code
     * @return Builder
     */
    public function scopeOfCode(Builder $query, string $code): Builder
    {
        return $query->where('code', strtoupper(trim($code)));
    }

    // =========================================================================
    // Domain helpers (read-only)
    // =========================================================================

    /**
     * Whether this product is currently active.
     *
     * The `is_active` flag controls whether the product is offered for
     * new applications. Deactivating a product does not affect loans
     * already originated under it.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Whether this product has a `loan_product_rules` policy version
     * attached.
     *
     * A product without a policy version cannot be enabled for
     * applications; the service layer rejects an application against such
     * a product with a 422. The helper is used by the application
     * service's pre-flight validation and by administrative diagnostics.
     *
     * @return bool
     */
    public function hasPolicyVersion(): bool
    {
        return $this->product_rules_policy_version_id !== null;
    }

    /**
     * Whether this product declares both principal bounds.
     *
     * The migration (A.8) declares both columns NOT NULL, so the helper
     * always returns true in practice. It exists for expressiveness at
     * call sites and to make the "bounds are required" invariant visible
     * in the model's API.
     *
     * @return bool
     */
    public function hasPrincipalBounds(): bool
    {
        return $this->min_principal !== null && $this->max_principal !== null;
    }

    /**
     * Whether this product declares both term bounds.
     *
     * Same reasoning as `hasPrincipalBounds()`.
     *
     * @return bool
     */
    public function hasTermBounds(): bool
    {
        return $this->min_term_months !== null && $this->max_term_months !== null;
    }

    /**
     * Whether a proposed principal falls within this product's declared
     * bounds.
     *
     * The comparison uses bcmath at scale 4. The caller supplies a
     * decimal-string amount; passing a float or an integer is a defect
     * the caller is responsible for avoiding.
     *
     * Returns false when either bound is null — an unset bound is treated
     * as "no value can be within the range", not as "any value is
     * acceptable". The service layer treats a false return as a
     * validation failure.
     *
     * @param string $amount  Positive decimal string, at scale 4.
     * @return bool
     */
    public function isPrincipalWithinBounds(string $amount): bool
    {
        if (!$this->hasPrincipalBounds()) {
            return false;
        }

        if (bccomp($amount, (string) $this->min_principal, 4) < 0) {
            return false;
        }

        if (bccomp($amount, (string) $this->max_principal, 4) > 0) {
            return false;
        }

        return true;
    }

    /**
     * Whether a proposed term falls within this product's declared
     * bounds.
     *
     * Integer comparison; no bcmath needed. Same "false on unset bound"
     * policy as `isPrincipalWithinBounds()`.
     *
     * @param int $months
     * @return bool
     */
    public function isTermWithinBounds(int $months): bool
    {
        if (!$this->hasTermBounds()) {
            return false;
        }

        if ($months < $this->min_term_months) {
            return false;
        }

        if ($months > $this->max_term_months) {
            return false;
        }

        return true;
    }

    /**
     * The product's locale-aware display name.
     *
     * Resolution order:
     *
     *   1. Amharic (`name_am`) when the active locale is `am`.
     *   2. English (`name_en`) when the active locale is not `am`.
     *   3. The other language as a fallback when the preferred one is null.
     *   4. The product's `code` as a last resort.
     *
     * The accessor NEVER returns an empty string. This matches the pattern
     * established by Phase 2A's `ShareClass::getDisplayNameAttribute`
     * (2A.6).
     *
     * The helper is intentionally named `displayName()` (a method) rather
     * than `getDisplayNameAttribute()` (an attribute accessor). Two
     * reasons:
     *
     *   1. The product model does not force-serialize a display name
     *      (the `$appends` array is empty). Resources select the fields
     *      they need.
     *
     *   2. A method call is explicit at every call site; an attribute
     *      accessor is invisible unless the reader knows to expect it.
     *      For a bilingual fallback chain, explicitness is preferable.
     *
     * @return string
     */
    public function displayName(): string
    {
        $locale = app()->getLocale();

        if ($locale === 'am') {
            return (string) ($this->name_am ?? $this->name_en ?? $this->code);
        }

        return (string) ($this->name_en ?? $this->name_am ?? $this->code);
    }
}