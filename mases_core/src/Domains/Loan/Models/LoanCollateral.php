<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Loan\Enums\CollateralStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\CollateralType;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;

/**
 * LoanCollateral
 *
 * Eloquent model for a single collateral registered against a loan.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * One row per collateral held as security for a loan. Each row records the
 * security interest the company holds: its type (external or
 * shareholding-pledge), its description, its attributes (external
 * reference, pledged shareholding linkage, or valuation triple as
 * applicable), its lifecycle status, and its release metadata where
 * applicable.
 *
 * Phase 2C implements exactly two collateral types (A.4, per the A10 and
 * B4 freezes):
 *
 *     CollateralType::External      — a collateral asset held outside the
 *                                     MASES ledger (real property,
 *                                     equipment, third-party instruments).
 *                                     Its valuation triple is required by
 *                                     the service.
 *
 *     CollateralType::Shareholding  — a pledge against a shareholder's
 *                                     shareholding. Its pledge linkage
 *                                     (pledged_shareholding_id and
 *                                     pledged_quantity) is required; its
 *                                     valuation triple is optional and
 *                                     product-policy-driven.
 *
 * Cash-deposit collateral is out of Phase 2C scope (B4). A guarantor is a
 * distinct security mechanism and is not modelled as a `CollateralType`
 * case (see `CollateralType`, A.4, for the rationale).
 *
 * =============================================================================
 * Partial mutability — the release transition
 * =============================================================================
 * This model is partially mutable. Most columns are set once at
 * registration and never change; the RELEASE TRIPLE changes exactly once,
 * when the collateral transitions from `registered` to `released`:
 *
 *   IMMUTABLE (set at insert, never modified):
 *       loan_id, collateral_type, description, external_reference,
 *       pledged_shareholding_id, pledged_quantity,
 *       valuation_amount, valuation_currency, valuation_date,
 *       registered_at, registered_by
 *
 *   MUTABLE (updated once, at release):
 *       status, released_at, release_reason, released_by
 *
 * The `status` field and the release triple are MASS ASSIGNABLE on
 * `create()` so that seeders, fixtures, and any data-migration path can
 * construct a collateral in a given state in a single call. TRANSITIONS
 * (from `registered` to `released` AFTER creation) are still performed
 * exclusively by `LoanCollateralService::release()` (A.33), inside the
 * transaction that also dispatches the after-commit
 * `LoanCollateralReleased` event (A.46). No controller, resource, or
 * repository path updates a collateral row, and the model exposes no
 * mutating methods.
 *
 * See the class docblock §"The fillable list" for the full rationale on
 * why `status` and the release triple are mass assignable.
 *
 * =============================================================================
 * Deferred pledge FK (B4, C3, CR-2C-COLLATERAL-PLEDGE-FK-001)
 * =============================================================================
 * The `pledged_shareholding_id` column is declared as a plain
 * `unsignedBigInteger` with no database-level foreign key (migration
 * A.11). The FK to the authoritative pledge relationship is deferred to
 * CR-2C-COLLATERAL-PLEDGE-FK-001, which will introduce the
 * `shareholding_pledges` table in the Shareholder domain and add the FK
 * from this column to it.
 *
 * In the interim, the model DECLARES a `BelongsTo` relation
 * `pledgedShareHolding()` targeting the Phase 2A `ShareHolding` model
 * (2A.8). The relation is the natural expression of the column's
 * semantics — a pledged shareholding is a shareholding — and it is what
 * `LoanCollateralService` (A.33) uses to validate the pledge when the
 * referenced holding is resolvable.
 *
 * The relation's integrity is enforced ONLY at the service layer in Phase
 * 2C. A database-level FK would prevent an administrative delete of a
 * shareholding from orphaning a pledge reference; the deferral means the
 * Loan domain trusts the service to prevent that outcome. When the Phase
 * 2A CR lands:
 *
 *   1. The FK is added at the database level, targeting either the
 *      `shareholding_pledges` table (if the CR chooses a two-level
 *      indirection) or the `share_holdings` table (if the CR chooses a
 *      direct FK, in which case the current relation is correct as-is).
 *
 *   2. The relation on this model is updated if the FK's target changes.
 *
 *   3. The service's validation logic is simplified — the FK becomes the
 *      primary integrity mechanism and the service's check becomes a
 *      defence-in-depth.
 *
 * Until then, the model's relation and the service's validation are the
 * interim integrity mechanism, and they are correct for Phase 2C.
 *
 * =============================================================================
 * Deletion semantics
 * =============================================================================
 * Collateral rows are NEVER deleted. When a collateral is released, its
 * `status` transitions to `released` and its release metadata is
 * recorded; the row remains in the table for audit.
 *
 * The migration A.11 declares no `deleted_at` column and no SoftDeletes
 * trait on the model. A collateral is a security-interest fact; it is
 * preserved indefinitely, even after release.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   loan()                — the owning loan (A.18).
 *   pledgedShareHolding() — the pledged shareholding, for shareholding-
 *                           typed collateral (Phase 2A `ShareHolding`,
 *                           with the deferred-FK caveat above).
 *   registeredBy()        — the user who registered the collateral.
 *   releasedBy()          — the user who released the collateral.
 *
 * =============================================================================
 * Casts
 * =============================================================================
 *   collateral_type         — CollateralType enum (A.4).
 *   pledged_quantity        — decimal:4 (string).
 *   valuation_amount        — decimal:4 (string).
 *   valuation_date          — date (Carbon at midnight).
 *   status                  — CollateralStatus enum (A.3).
 *   registered_at,
 *   released_at,
 *   created_at,
 *   updated_at              — datetime.
 *
 * All monetary values are strings. The platform-wide convention: financial
 * arithmetic uses bcmath on strings, never floats. Share quantities
 * (`pledged_quantity`) follow the same convention.
 *
 * The two enum casts (`collateral_type`, `status`) mirror the pattern
 * established by `Loan.status` (A.18) and `LoanInstallment.status`
 * (A.19): an unrecognised value is a defect and surfaces as an error.
 *
 * =============================================================================
 * Scopes
 * =============================================================================
 *   scopeForLoan($query, $loanId)         — filter by owning loan.
 *   scopeRegistered($query)               — status = registered.
 *   scopeReleased($query)                 — status = released.
 *   scopeActive($query)                   — status = registered
 *                                            (alias for the more common
 *                                            read pattern).
 *   scopeOfType($query, $type)            — filter by collateral type.
 *   scopeExternal($query)                 — collateral_type = external.
 *   scopeShareholding($query)             — collateral_type = shareholding.
 *   scopePledgeBacked($query)             — collateral_type ∈ pledgeBackedTypes.
 *   scopeValuated($query)                 — valuation_amount IS NOT NULL.
 *   scopeUnvalued($query)                 — valuation_amount IS NULL.
 *
 * =============================================================================
 * Domain helpers (read-only)
 * =============================================================================
 *   status()                     — the CollateralStatus enum (via cast).
 *   type()                       — the CollateralType enum (via cast).
 *   isRegistered(), isReleased() — one predicate per status.
 *   isTerminal()                 — delegates to CollateralStatus.
 *   canTransitionTo(CollateralStatus) — structural check only.
 *   isExternal(), isShareholding()    — delegate to CollateralType.
 *   isPledgeBacked()             — delegates to CollateralType.
 *   hasPledge()                  — the pledge linkage columns are set.
 *   hasValuation()               — the valuation triple is fully set.
 *   hasReleaseMetadata()         — the release triple is fully set.
 *   valuationConsistency()       — reports whether the valuation triple
 *                                  is all-null or all-non-null.
 *   pledgeConsistency()          — reports whether the pledge columns
 *                                  match the type (non-null for
 *                                  shareholding, null for external).
 *
 * The three consistency helpers are read-only predicates used by the
 * reconciliation service and by the completion gate's data-integrity
 * checks. They report `true` for a well-formed row; a `false` return
 * surfaces an anomaly that the service layer should not have produced.
 *
 * =============================================================================
 * The fillable list
 * =============================================================================
 * The `$fillable` list is authoritative for every call site that
 * constructs or modifies a collateral row via mass assignment:
 *
 *   - `LoanCollateralService::register()` (A.33) — creates the row with
 *     the registration triple. Does not supply `status` or the release
 *     triple; the migration defaults apply.
 *
 *   - Test fixtures and seeders that need to construct a collateral in a
 *     specific state for setup. This includes fixtures that build a
 *     RELEASED collateral directly (used by the double-release
 *     regression suite), which require `status`, `released_at`,
 *     `release_reason`, and `released_by` to be mass-assignable.
 *
 * The following columns are deliberately NOT mass-assignable:
 *
 *   id                     — auto-assigned.
 *   registered_at          — initialized to `CURRENT_TIMESTAMP` by the
 *                            migration's default; never overridden.
 *   created_at, updated_at — managed by Eloquent.
 *
 * The `status` column and the three release-metadata columns (`released_at`,
 * `release_reason`, `released_by`) ARE fillable. Their presence on this
 * list is a deliberate correction: without it, a `create()` call that
 * supplies any of them — as fixtures do — silently drops the value, and
 * the migration's `registered` default applies regardless of what the
 * caller intended.
 *
 * The design rule that governs the release triple is unaffected by its
 * presence here: a `status` TRANSITION (from `registered` to `released`
 * after the row exists) still flows exclusively through
 * `LoanCollateralService::release()` (A.33), which composes the
 * transition with its own permission gate, its loan-state guard, and
 * its after-commit event dispatch. `$fillable` governs only the
 * construction call, not the transition path.
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not mutate the loan, the Wallet, or the Shareholder ledger.
 *     The only mutable group on this row is the release triple, and it is
 *     updated exclusively by `LoanCollateralService::release()` (A.33).
 *
 *   - It does not dispatch events. The `LoanCollateralReleased` event
 *     (A.46) is dispatched by the service that performs the release.
 *
 *   - It does not evaluate the product's collateral policy. The policy is
 *     read by the service; the model stores the resulting registration.
 *
 *   - It does not use SoftDeletes. Collateral records are permanent.
 *
 *   - It does not expose a mutating method. Every mutation flows through
 *     the service. See the class docblock §"Partial mutability".
 *
 *   - It does not attempt a `release()` method. The release is a business
 *     operation with a permission check, a loan-state guard, an
 *     after-commit event, and a status-history effect; it is the
 *     service's concern, not the model's.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A10 (collateral rules),
 *                                                  B4 (collateral
 *                                                  integration)
 *   Phase 2C Clarification Response, 2026-09-12  — C2 (release endpoint),
 *                                                  C3 (Loan-side pledge
 *                                                  sequencing)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §3
 *   Phase 2A migration 2A.39                     — `share_holdings`
 *   Phase 2B migration 2B.50                     — `wallet_obligations`
 *   Step 5 (manifest) A.20                       — This file
 *   Step 6 v1.1.0 §3.11                          — `loan_collaterals`
 *                                                  table specification
 *
 * @property int                         $id
 * @property int                         $loan_id
 * @property CollateralType              $collateral_type
 * @property string                      $description
 * @property string|null                 $external_reference
 * @property int|null                    $pledged_shareholding_id
 * @property string|null                 $pledged_quantity
 * @property string|null                 $valuation_amount
 * @property string|null                 $valuation_currency
 * @property Carbon|null $valuation_date
 * @property CollateralStatus            $status
 * @property Carbon $registered_at
 * @property Carbon|null $released_at
 * @property string|null                 $release_reason
 * @property int|null                    $registered_by
 * @property int|null                    $released_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Loan|null              $loan
 * @property-read ShareHolding|null      $pledgedShareHolding
 * @property-read User|null              $registeredBy
 * @property-read User|null              $releasedBy
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Models
 * @version 1.1.0
 */
final class LoanCollateral extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'loan_collaterals';

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
     * See the class docblock §"The fillable list" for the full rationale.
     *
     * @var list<string>
     */
    protected $fillable = [
        'loan_id',
        'collateral_type',
        'description',
        'external_reference',
        'pledged_shareholding_id',
        'pledged_quantity',
        'valuation_amount',
        'valuation_currency',
        'valuation_date',
        'registered_by',
        'status',
        'released_at',
        'release_reason',
        'released_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * See the class docblock §"Casts" for the rationale on each group.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'loan_id'                => 'integer',
        'collateral_type'        => CollateralType::class,
        'pledged_shareholding_id' => 'integer',
        'pledged_quantity'       => 'decimal:4',
        'valuation_amount'       => 'decimal:4',
        'valuation_date'         => 'date',
        'status'                 => CollateralStatus::class,
        'registered_at'          => 'datetime',
        'released_at'            => 'datetime',
        'registered_by'          => 'integer',
        'released_by'            => 'integer',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. The `LoanCollateralResource` (A.67) selects
     * fields explicitly. The consistency and state predicates are
     * available on demand but are not force-serialized.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The loan this collateral is registered against.
     *
     * @return BelongsTo
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id', 'id');
    }

    /**
     * The pledged shareholding, for a `shareholding`-typed collateral.
     *
     * Targets the Phase 2A `ShareHolding` model (2A.8). Null for an
     * `external`-typed collateral.
     *
     * The relation's integrity is enforced at the SERVICE layer in Phase
     * 2C, not by a database FK. See the class docblock §"Deferred pledge
     * FK" for the full rationale and for the CR that will add the FK.
     *
     * A consumer that reads this relation on a row whose
     * `pledged_shareholding_id` points at a now-deleted shareholding
     * receives `null` from the relation. That state is prevented by the
     * service in Phase 2C; when the FK lands, the database prevents it as
     * well.
     *
     * @return BelongsTo
     */
    public function pledgedShareHolding(): BelongsTo
    {
        return $this->belongsTo(
            ShareHolding::class,
            'pledged_shareholding_id',
            'id',
        );
    }

    /**
     * The user who registered the collateral.
     *
     * Set at creation by `LoanCollateralService::register()` (A.33).
     * Nullable and `nullOnDelete()`: a hard-deleted user detaches from the
     * row, which survives with a null actor (migration A.11).
     *
     * @return BelongsTo
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by', 'id');
    }

    /**
     * The user who released the collateral.
     *
     * Set at release by `LoanCollateralService::release()` (A.33). Nullable
     * for every collateral that has not been released.
     *
     * @return BelongsTo
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by', 'id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific loan.
     *
     * @param Builder $query
     * @param int     $loanId
     * @return Builder
     */
    public function scopeForLoan(Builder $query, int $loanId): Builder
    {
        return $query->where('loan_id', $loanId);
    }

    /**
     * Scope a query to collaterals currently held as security.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeRegistered(Builder $query): Builder
    {
        return $query->where('status', CollateralStatus::Registered->value);
    }

    /**
     * Scope a query to released collaterals.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeReleased(Builder $query): Builder
    {
        return $query->where('status', CollateralStatus::Released->value);
    }

    /**
     * Scope a query to active collaterals.
     *
     * Alias for `scopeRegistered()`. Provided so that call sites that
     * express the concept as "active" (rather than "registered") read
     * naturally. If a future CR introduces a non-terminal state beyond
     * `registered` (e.g. a `pending` review state), the scope's definition
     * is the single place to widen.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CollateralStatus::Registered->value);
    }

    /**
     * Scope a query by collateral type.
     *
     * Accepts either a `CollateralType` case or its string value, so
     * callers do not need to normalise before invoking the scope.
     *
     * @param Builder              $query
     * @param CollateralType|string $type
     * @return Builder
     */
    public function scopeOfType(Builder $query, CollateralType|string $type): Builder
    {
        $value = $type instanceof CollateralType ? $type->value : $type;

        return $query->where('collateral_type', $value);
    }

    /**
     * Scope a query to external collaterals.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeExternal(Builder $query): Builder
    {
        return $query->where('collateral_type', CollateralType::External->value);
    }

    /**
     * Scope a query to shareholding-pledge collaterals.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeShareholding(Builder $query): Builder
    {
        return $query->where('collateral_type', CollateralType::Shareholding->value);
    }

    /**
     * Scope a query to pledge-backed collaterals.
     *
     * Currently equivalent to `scopeShareholding()`, because
     * `CollateralType::pledgeBackedTypes()` (A.4) returns only
     * `['shareholding']`. The scope uses the helper rather than the
     * hard-coded string so that a future CR that adds a second
     * pledge-backed type widens this scope automatically.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePledgeBacked(Builder $query): Builder
    {
        return $query->whereIn(
            'collateral_type',
            CollateralType::pledgeBackedTypes(),
        );
    }

    /**
     * Scope a query to collaterals that carry a valuation.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeValuated(Builder $query): Builder
    {
        return $query->whereNotNull('valuation_amount');
    }

    /**
     * Scope a query to collaterals that carry no valuation.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeUnvalued(Builder $query): Builder
    {
        return $query->whereNull('valuation_amount');
    }

    // =========================================================================
    // Domain helpers — lifecycle state
    // =========================================================================

    /**
     * The collateral's current status as a `CollateralStatus` case.
     *
     * Because the `status` column is cast to the enum (see the class
     * docblock §"Casts"), this helper is a convenience wrapper for
     * `$collateral->status`.
     *
     * @return CollateralStatus
     */
    public function status(): CollateralStatus
    {
        return $this->status;
    }

    /**
     * The collateral's type as a `CollateralType` case.
     *
     * Because the `collateral_type` column is cast to the enum, this
     * helper is a convenience wrapper for `$collateral->collateral_type`.
     *
     * @return CollateralType
     */
    public function type(): CollateralType
    {
        return $this->collateral_type;
    }

    /**
     * Whether the collateral is currently held as security.
     *
     * @return bool
     */
    public function isRegistered(): bool
    {
        return $this->status === CollateralStatus::Registered;
    }

    /**
     * Whether the collateral has been released.
     *
     * @return bool
     */
    public function isReleased(): bool
    {
        return $this->status === CollateralStatus::Released;
    }

    /**
     * Whether the collateral is in a terminal state — one from which
     * there is no outgoing transition.
     *
     * Delegates to `CollateralStatus::isTerminal()` (A.3). Terminal is
     * true only for `released`.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Whether a structural transition from the collateral's current
     * status to the given status is legal.
     *
     * Delegates to `CollateralStatus::canTransitionTo()` (A.3). The check
     * is STRUCTURAL only. The service that drives the transition
     * (`LoanCollateralService::release()`, A.33) composes this check with
     * its permission gate, its loan-state guard, and its event dispatch.
     *
     * Direct use of this helper outside the service is intended for tests
     * and for read-only diagnostics, not for production writes.
     *
     * @param CollateralStatus $next
     * @return bool
     */
    public function canTransitionTo(CollateralStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    // =========================================================================
    // Domain helpers — type classification
    // =========================================================================

    /**
     * Whether this collateral is an external asset.
     *
     * Delegates to `CollateralType::isExternal()` (A.4).
     *
     * @return bool
     */
    public function isExternal(): bool
    {
        return $this->collateral_type->isExternal();
    }

    /**
     * Whether this collateral is a shareholding pledge.
     *
     * Delegates to `CollateralType::isShareholding()` (A.4).
     *
     * @return bool
     */
    public function isShareholding(): bool
    {
        return $this->collateral_type->isShareholding();
    }

    /**
     * Whether this collateral requires a pledge relationship to be valid.
     *
     * Delegates to `CollateralType::isPledgeBacked()` (A.4). True for
     * shareholding-typed collateral; false for external.
     *
     * @return bool
     */
    public function isPledgeBacked(): bool
    {
        return $this->collateral_type->isPledgeBacked();
    }

    // =========================================================================
    // Domain helpers — data consistency
    // =========================================================================

    /**
     * Whether the collateral carries a full pledge linkage.
     *
     * True when both `pledged_shareholding_id` and `pledged_quantity` are
     * non-null. Required for a `shareholding`-typed collateral; forbidden
     * for an `external`-typed collateral.
     *
     * This is a shape check on the current row. It does not verify that
     * the referenced shareholding exists; that validation is the service's
     * concern (A.33) and is performed at registration time.
     *
     * @return bool
     */
    public function hasPledge(): bool
    {
        return $this->pledged_shareholding_id !== null
            && $this->pledged_quantity !== null;
    }

    /**
     * Whether the collateral carries a full valuation triple.
     *
     * True when `valuation_amount`, `valuation_currency`, and
     * `valuation_date` are all non-null. The service layer requires the
     * triple for an `external`-typed collateral; for a `shareholding`-
     * typed collateral, the triple is optional and product-policy-driven.
     *
     * @return bool
     */
    public function hasValuation(): bool
    {
        return $this->valuation_amount !== null
            && $this->valuation_currency !== null
            && $this->valuation_date !== null;
    }

    /**
     * Whether the collateral carries a full release metadata triple.
     *
     * True when `released_at`, `release_reason`, and `released_by` are all
     * non-null. Required for a `released` collateral; forbidden for a
     * `registered` one.
     *
     * Note that `released_by` is nullable at the schema level, so a
     * released collateral in a defensive scenario (e.g. a future CR that
     * introduces system-initiated releases) could have a null
     * `released_by`. The helper reports `false` in that case; a caller
     * that needs to distinguish "released with no actor" from "not
     * released" reads the `status` column directly.
     *
     * @return bool
     */
    public function hasReleaseMetadata(): bool
    {
        return $this->released_at !== null
            && $this->release_reason !== null
            && $this->released_by !== null;
    }

    /**
     * Whether the valuation triple is internally consistent.
     *
     * A valuation is either fully present (all three columns non-null) or
     * fully absent (all three null). A partial triple — for example, an
     * amount without a currency — is a data anomaly that the service layer
     * should never produce.
     *
     * The helper is read-only; it reports the state of the current row.
     * It is used by the reconciliation service (A.32) as a defensive check
     * and by tests that assert the service's validation discipline.
     *
     * @return bool
     */
    public function valuationConsistency(): bool
    {
        $present = 0;
        $present += $this->valuation_amount   !== null ? 1 : 0;
        $present += $this->valuation_currency !== null ? 1 : 0;
        $present += $this->valuation_date     !== null ? 1 : 0;

        return $present === 0 || $present === 3;
    }

    /**
     * Whether the pledge columns are consistent with the collateral type.
     *
     * For a `shareholding`-typed collateral, both `pledged_shareholding_id`
     * and `pledged_quantity` must be non-null. For an `external`-typed
     * collateral, both must be null.
     *
     * The helper reports `true` when the state matches the type's
     * requirement; `false` when it does not. A `false` return is a data
     * anomaly that the service layer should never produce.
     *
     * @return bool
     */
    public function pledgeConsistency(): bool
    {
        if ($this->isPledgeBacked()) {
            return $this->hasPledge();
        }

        // External collateral: both pledge columns must be null.
        return $this->pledged_shareholding_id === null
            && $this->pledged_quantity === null;
    }
}