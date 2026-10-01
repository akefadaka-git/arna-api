<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Policy\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * VersionedPolicy
 *
 * Eloquent model for a single immutable, versioned policy definition.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * One row in `versioned_policies` (CORE.2) — a policy definition of a given
 * type, at a given version number, with a defined applicability window.
 *
 * A policy version's identity is `(policy_type, version)`. The
 * `effective_from` and `effective_until` columns are temporal metadata used
 * to resolve which version applies at a moment in time; they are NOT part
 * of the identity.
 *
 * The payload is opaque to this model. Consumers (a future KYC capability,
 * a future interest capability, the wallet allocation surface) define their
 * own payload schemas. This model does not interpret, validate, or
 * transform payloads.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — auto-population of `version`, `effective_from`, and `created_at`
 *         on insert.
 *
 *         The migration (CORE.2) declares `version` and `effective_from`
 *         as `NOT NULL`. Every domain that authors a policy inline — the
 *         Loan domain's test suites being the load-bearing case —
 *         previously called
 *
 *             VersionedPolicy::create([
 *                 'policy_type' => 'loan_product_rules',
 *                 'payload'     => [...],
 *             ]);
 *
 *         and received
 *
 *             SQLSTATE[23000]: NOT NULL constraint failed:
 *             versioned_policies.version
 *
 *         because neither column was supplied and no lifecycle hook
 *         populated them.
 *
 *         The corrected behaviour is a `creating` hook that fills each
 *         omitted required column with a sensible default:
 *
 *           - `version`        → `nextVersionFor($policy_type)`, i.e.
 *                                `MAX(version) + 1` scoped to the
 *                                `policy_type`, or `1` when the type
 *                                has no prior versions.
 *
 *           - `effective_from` → `Carbon::now()`. A policy version whose
 *                                author did not specify an effective
 *                                start is, by convention, effective
 *                                from the moment of publication.
 *
 *           - `created_at`     → `Carbon::now()`. The registry does not
 *                                manage timestamps; every row carries
 *                                its own insert moment.
 *
 *         A caller that supplies any of these explicitly — an importer
 *         preserving historical version numbers, a migration back-filling
 *         old policies, a test pinning an effective window — is
 *         unaffected. The hook only fills omissions.
 *
 *         The auto-version is best-effort under concurrency: two
 *         simultaneous inserts for the same `policy_type` can both read
 *         the same `MAX(version)` and both attempt to insert the same
 *         version. The UNIQUE constraint on `(policy_type, version)`
 *         (declared by the migration) rejects the loser, and the caller
 *         receives a clear constraint violation. Policy authoring is a
 *         low-concurrency operation; the DB constraint is the correct
 *         integrity mechanism.
 *
 *         Two read-only static helpers are added alongside the hook
 *         because callers that author policies need them:
 *
 *           - `nextVersionFor($policyType)` — the version the next insert
 *             will receive. Used by the hook; exposed for callers that
 *             want to preview or dry-run.
 *
 *           - `currentFor($policyType)` — the newest version of a policy
 *             type, or null. The registry's most common read.
 *
 *         No schema change. No migration required. No change to any other
 *         column, cast, scope, relation, or helper.
 *
 * =============================================================================
 * Immutability (Architecture §8.3, Step 6 amendment S6-C)
 * =============================================================================
 * This model is immutable on creation. Two enforcement layers apply:
 *
 *   1. The table (CORE.2) has no `updated_at` column and the model sets
 *      `$timestamps = false`. Eloquent will not attempt to manage
 *      timestamps.
 *
 *   2. The `booted()` method registers `updating` and `deleting` listeners
 *      that throw `LogicException`. Any code path that attempts to mutate
 *      or remove a loaded row fails loudly rather than silently producing
 *      an edited policy.
 *
 * The second layer is a deliberate, documented divergence from the 2A
 * precedent, where immutability of `share_holding_entries` is enforced only
 * by the service (no model-level guard). The reason for the stricter
 * position here: policy versions govern categories of future computation
 * (interest accrual, allocation priority, KYC gating). A silent mutation to
 * a policy version would be worse than a silent mutation to any single
 * ledger row, because the policy would retroactively change the meaning of
 * many historical operations. Failing hard is the correct posture.
 *
 * The service layer (CORE.5 — `VersionedPolicyRegistry`) is the only
 * legitimate writer. It creates rows; it never updates or deletes them.
 *
 * =============================================================================
 * Temporal resolution (Architecture §8.2)
 * =============================================================================
 * The `applicableAt()` scope and the `isApplicableAt()` helper implement the
 * resolution rule stated in the architecture:
 *
 *     effective_from <= T  AND  (effective_until IS NULL OR effective_until > T)
 *
 * Both are read-only. The temporal resolution is a query concern, not a
 * write concern.
 *
 * The `effective_until > effective_from` invariant (when `effective_until`
 * is non-null) is enforced by the registry service, not by this model or by
 * the schema. See CORE.2 §"Effective dates" for the rationale.
 *
 * =============================================================================
 * What this model does NOT do
 * =============================================================================
 *   - It does not validate payload schemas. Payloads are opaque.
 *   - It does not evaluate policies. It is a container.
 *   - It does not cache lookups. The registry service decides caching.
 *   - It does not auto-increment `version` on UPDATES — because there are
 *     no updates. It DOES auto-populate `version` on INSERT when the
 *     caller omits it (see §"Revision history" 1.0.1).
 *   - It has no relations. Policies are referenced by id from consumer
 *     tables (notably `wallets.*_policy_version_id`); those references are
 *     not declared as Eloquent relations on this side, because the policy
 *     registry does not track its referrers. Consumer-side relations are
 *     the consumers' concern.
 *
 * =============================================================================
 * Casts
 * =============================================================================
 *   version           — integer. MySQL may return the column as a string
 *                       depending on the driver; the cast normalises it.
 *
 *   effective_from    — datetime (Carbon instance).
 *   effective_until   — datetime (Carbon instance) or null.
 *
 *   payload           — array. `json_decode($value, true)` semantics. Chosen
 *                       over `object` so that consumer code reads arrays
 *                       without further decoding. Chosen over `collection`
 *                       so that the model does not impose a container type
 *                       on consumers that prefer plain arrays.
 *
 *   created_at        — datetime (Carbon instance). The column is written
 *                       explicitly by the registry; the cast normalises
 *                       reads.
 *
 * No `updated_at` cast — the column does not exist.
 *
 * =============================================================================
 * Scopes
 * =============================================================================
 *   ofType($type)             — filter by policy_type.
 *   applicableAt($moment)     — filter by temporal applicability at a moment.
 *
 * Both are query-building only. They do not mutate; they do not perform
 * writes.
 *
 * =============================================================================
 * Static helpers
 * =============================================================================
 *   nextVersionFor($policyType) — the version number the next insert for
 *                                  that policy type will receive.
 *   currentFor($policyType)     — the newest version of a policy type, or
 *                                  null.
 *
 * Both are read-only. `nextVersionFor()` is called by the `creating` hook
 * internally and is exposed so that authoring code (a management command,
 * a dry-run, a test) can preview the next version without racing an
 * insert.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §8      — Versioned-policy registry integration
 *   Architecture v1.2.0 §8.2    — Identity and temporal applicability
 *   Architecture v1.2.0 §8.3    — Immutability and deletion
 *   Architecture v1.2.0 §8.7    — Registry boundary conditions
 *   Step 5 (manifest) CORE.3    — This file
 *   Step 6 v1.1.0 §3.1          — versioned_policies table specification
 *   Step 6 v1.1.0 S6-C          — Immutable-on-create, no updated_at
 *
 * @property int                      $id
 * @property string                   $policy_type
 * @property int                      $version
 * @property \Carbon\Carbon           $effective_from
 * @property \Carbon\Carbon|null      $effective_until
 * @property array                    $payload
 * @property \Carbon\Carbon           $created_at
 *
 * @package MaaleArna\MASES\Core\Policy\Models
 * @version 1.0.1
 */
final class VersionedPolicy extends Model
{
    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'versioned_policies';

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
     * Disable Eloquent's automatic timestamp management.
     *
     * The table has no `updated_at` column, and `created_at` is written
     * explicitly — either by the registry service (CORE.5) or by the
     * `creating` hook (see §"Revision history" 1.0.1) — at insert time.
     * Enabling `$timestamps` would cause Eloquent to attempt to set an
     * `updated_at` that does not exist, producing a column-not-found error
     * on save.
     *
     * See CORE.2 §"Immutability" and Architecture §8.3.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * All attributes are set once, at insert time. The immutability
     * contract is enforced at the model level (see `booted()`), so
     * `$fillable` need only enumerate what a legitimate caller supplies on
     * create.
     *
     * `version`, `effective_from`, and `created_at` ARE fillable: a caller
     * that supplies them explicitly (an importer, a migration, a
     * policy-authoring script) mass-assigns them. A caller that omits them
     * receives the auto-populated values from the `creating` hook (see
     * §"Revision history" 1.0.1).
     *
     * `id` is not fillable — it is auto-assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'policy_type',
        'version',
        'effective_from',
        'effective_until',
        'payload',
        'created_at',
    ];

    /**
     * The attributes that should be hidden from array/JSON serialization.
     *
     * Empty. Policy payloads are not secrets in the ordinary sense — they
     * are versioned rules — and the model is not serialized to HTTP
     * directly. Consumers that expose policy data to clients via a Resource
     * (a future concern, not 2B) are responsible for selecting fields.
     *
     * @var list<string>
     */
    protected $hidden = [];

    /**
     * The accessors to append to the model's array form.
     *
     * Empty on purpose. The model does not force-serialize any computed
     * attribute. Consumers read specific attributes explicitly.
     *
     * @var list<string>
     */
    protected $appends = [];

    /**
     * The attributes that should be cast.
     *
     * See the class docblock §"Casts" for the rationale behind each choice.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'version'         => 'integer',
        'effective_from'  => 'datetime',
        'effective_until' => 'datetime',
        'payload'         => 'array',
        'created_at'      => 'datetime',
    ];

    // =========================================================================
    // Lifecycle — auto-population and immutability enforcement
    // =========================================================================

    /**
     * Register the model's event listeners.
     *
     * Called once per model class, after the model's traits have booted.
     * Registers three listeners:
     *
     *   - `creating` — auto-populates the columns the migration declares
     *                   `NOT NULL` when the caller omits them:
     *                   `version`, `effective_from`, and `created_at`.
     *                   See §"Revision history" 1.0.1.
     *
     *   - `updating` — throws on any attempt to persist a change to a
     *                   loaded row.
     *
     *   - `deleting` — throws on any attempt to remove a row.
     *
     * The `creating` listener runs FIRST in the insert lifecycle, before
     * Eloquent issues the INSERT. It sets attribute values on the
     * in-memory model, which Eloquent then includes in the statement.
     *
     * The two guards are the 2B defense-in-depth layer described in the
     * class docblock §"Immutability". They catch accidental mutation at
     * the source, before the DB is touched, with a clear error message
     * pointing at the correct remedy (insert a new version).
     *
     * ------------------------------------------------------------------
     * The creating listener — precise semantics
     * ------------------------------------------------------------------
     * 1. `policy_type` MUST be a non-empty string. The migration declares
     *    the column `NOT NULL`; the guard here surfaces a clearer error
     *    before the DB does.
     *
     * 2. If `version` is null or less than 1, set it to
     *    `nextVersionFor($policy_type)`. That method reads
     *    `MAX(version) + 1` for the policy type, or `1` when the type has
     *    no prior versions. A caller that supplies an explicit positive
     *    version is unaffected.
     *
     * 3. If `effective_from` is null, set it to `Carbon::now()`. A policy
     *    version whose author did not specify a start date is effective
     *    from the moment of publication. A caller that supplies an
     *    explicit start date is unaffected.
     *
     * 4. If `created_at` is null, set it to `Carbon::now()`. The registry
     *    does not manage timestamps; every row carries its own insert
     *    moment.
     *
     * `effective_until` is nullable and is never auto-populated: a version
     * with no end date is open-ended, which is the intended default.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(static function (VersionedPolicy $policy): void {
            $policyType = $policy->policy_type;

            if (!is_string($policyType) || trim($policyType) === '') {
                throw new LogicException(
                    'VersionedPolicy::creating() requires a non-empty '
                    . 'policy_type. The column is declared NOT NULL by '
                    . 'the migration; a null or empty value here '
                    . 'indicates a caller defect. The registry stores '
                    . 'every policy under a stable string identifier — '
                    . 'supply the identifier before calling create().'
                );
            }

            if ($policy->version === null || (int) $policy->version < 1) {
                $policy->version = static::nextVersionFor($policyType);
            }

            if ($policy->effective_from === null) {
                $policy->effective_from = Carbon::now();
            }

            if ($policy->created_at === null) {
                $policy->created_at = Carbon::now();
            }
        });

        static::updating(static function (VersionedPolicy $policy): void {
            throw new LogicException(
                'VersionedPolicy rows are immutable on creation '
                . '(Architecture §8.3). To change a policy definition, insert '
                . 'a new version; do not modify an existing row. '
                . 'Attempted update on versioned_policies.id=' . $policy->getKey() . '.'
            );
        });

        static::deleting(static function (VersionedPolicy $policy): void {
            throw new LogicException(
                'VersionedPolicy rows cannot be deleted '
                . '(Architecture §8.3). The registry is append-only. '
                . 'Attempted delete on versioned_policies.id=' . $policy->getKey() . '.'
            );
        });
    }

    // =========================================================================
    // Static helpers
    // =========================================================================

    /**
     * The version number the next insert for a given policy type will
     * receive.
     *
     * The value is `MAX(version) + 1` for the policy type, or `1` when
     * no prior version exists. The method issues a single aggregate
     * query:
     *
     *     SELECT MAX(version) FROM versioned_policies WHERE policy_type = ?
     *
     * The method is used internally by the `creating` listener and is
     * exposed publicly for callers that need to preview the next version
     * — a policy-authoring command that wants to log "the next version
     * will be N" before publishing, a test that asserts the version
     * sequence, a dry-run.
     *
     * Under concurrency, two simultaneous calls for the same policy type
     * may return the same value; the UNIQUE constraint on
     * `(policy_type, version)` (declared by the migration) rejects the
     * loser. See the class docblock §"Revision history" 1.0.1 for the
     * full concurrency posture.
     *
     * @param string $policyType
     * @return int  A strictly positive integer.
     */
    public static function nextVersionFor(string $policyType): int
    {
        /** @var int|string|null $max */
        $max = static::query()
            ->ofType($policyType)
            ->max('version');

        return ((int) ($max ?? 0)) + 1;
    }

    /**
     * The newest version of a given policy type, or null when no version
     * exists.
     *
     * The method issues a single indexed query:
     *
     *     SELECT * FROM versioned_policies
     *      WHERE policy_type = ?
     *      ORDER BY version DESC
     *      LIMIT 1
     *
     * This is the registry's most common read. A consumer that needs
     * "the current policy of type X" calls this method rather than
     * composing the filter, order, and limit itself.
     *
     * Note that "newest version" is not the same as "applicable now":
     * a caller that needs the version governing a specific moment uses
     * the `applicableAt()` scope (Architecture §8.2). This helper
     * answers "what is the latest published version of this type?".
     *
     * @param string $policyType
     * @return VersionedPolicy|null
     */
    public static function currentFor(string $policyType): ?VersionedPolicy
    {
        return static::query()
            ->ofType($policyType)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    // =========================================================================
    // Query scopes
    // =========================================================================

    /**
     * Scope a query to a specific policy type.
     *
     * @param Builder $query
     * @param string  $type  One of config('policy.types') — but not validated
     *                       here; the registry validates against the config
     *                       vocabulary at write time.
     * @return Builder
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('policy_type', $type);
    }

    /**
     * Scope a query to policy versions that are applicable at a given moment.
     *
     * Implements the temporal-resolution rule from Architecture §8.2:
     *
     *     effective_from <= :moment
     *       AND
     *     (effective_until IS NULL OR effective_until > :moment)
     *
     * The scope does NOT order results. Callers that want "the applicable
     * version" order by `effective_from DESC` and take the first row; the
     * registry service does exactly that. Keeping the ordering out of the
     * scope lets callers audit all applicable versions when needed.
     *
     * @param Builder              $query
     * @param CarbonInterface      $moment
     * @return Builder
     */
    public function scopeApplicableAt(Builder $query, CarbonInterface $moment): Builder
    {
        $at = Carbon::instance($moment);

        return $query
            ->where('effective_from', '<=', $at)
            ->where(static function (Builder $q) use ($at): void {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>', $at);
            });
    }

    // =========================================================================
    // Domain helpers
    // =========================================================================

    /**
     * Whether this policy version is applicable at a given moment.
     *
     * Pure function over the loaded model's attributes. Does not perform a
     * database query. Use the `applicableAt()` scope when filtering a
     * collection in the database.
     *
     * @param CarbonInterface $moment
     * @return bool
     */
    public function isApplicableAt(CarbonInterface $moment): bool
    {
        $at = Carbon::instance($moment);

        if ($this->effective_from->greaterThan($at)) {
            return false;
        }

        if ($this->effective_until === null) {
            return true;
        }

        return $this->effective_until->greaterThan($at);
    }

    /**
     * Whether this policy version is open-ended.
     *
     * A version is open-ended when it has no explicit `effective_until`. It
     * remains applicable until a successor version supersedes it by
     * `effective_from` ordering.
     *
     * @return bool
     */
    public function isOpenEnded(): bool
    {
        return $this->effective_until === null;
    }

    /**
     * Whether this policy version has an explicit expiry set.
     *
     * The complement of `isOpenEnded()`. Kept as a distinct helper so that
     * call sites express intent ("this policy has an end date") rather than
     * negating `isOpenEnded()`.
     *
     * @return bool
     */
    public function hasExplicitExpiry(): bool
    {
        return $this->effective_until !== null;
    }

    /**
     * Whether the applicability window is well-formed.
     *
     * Returns false when `effective_until` is non-null and does not lie
     * strictly after `effective_from`. The schema does not enforce this
     * invariant (see CORE.2 §"Effective dates"); the registry service
     * enforces it on write. This helper exists so that a defensive reader
     * can detect malformed rows written by an external process.
     *
     * An open-ended version is always well-formed.
     *
     * @return bool
     */
    public function hasValidEffectiveWindow(): bool
    {
        if ($this->effective_until === null) {
            return true;
        }

        return $this->effective_until->greaterThan($this->effective_from);
    }
}