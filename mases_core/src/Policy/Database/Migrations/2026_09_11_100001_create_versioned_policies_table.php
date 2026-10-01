<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — Amendment 2 of 8
 * =============================================================================
 *
 * File:           2026_09_11_100001_create_versioned_policies_table.php
 * Unit:           P2.2 — immutable timestamp corrections (source amendment)
 * Delivery type:  Full replacement of an existing file
 * Net lines:      +1 line in up(); +~25 lines in docblock
 *
 * Before (original source):
 *   $table->timestamp('effective_from')
 *         ->comment('Instant from which this version is applicable (inclusive)');
 *
 *   Under the deployed server's `explicit_defaults_for_timestamp = OFF`,
 *   MySQL added `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
 *   implicitly. Verified in the DB dump. This was the highest-severity
 *   defect of the eight: `effective_from` is both the ordering key and
 *   the lower bound of the temporal-resolution query in
 *   VersionedPolicyRegistry::findApplicable(). Any UPDATE to a policy
 *   row — even one unrelated to the policy's semantics — would
 *   retroactively move the column and change which version resolves at
 *   every past moment.
 *
 * After (amended source):
 *   $table->dateTime('effective_from')
 *         ->comment('Instant from which this version is applicable (inclusive)');
 *
 *   DATETIME is immune to MySQL's implicit DEFAULT / ON UPDATE behavior
 *   in every server mode. A `migrate:fresh` on any server produces the
 *   corrected column directly, in the original migration's run, with no
 *   intermediate TIMESTAMP state and no ALTER anywhere in the sequence.
 *
 * Every other line — every column, comment, index name, and the
 * composite UNIQUE — is byte-for-byte identical to the source.
 *
 * Other TIMESTAMP columns on this table, NOT amended:
 *   · effective_until — declared ->nullable()->timestamp(...), already
 *     applied as `timestamp NULL DEFAULT NULL`. Nullable TIMESTAMP
 *     columns are never given the ON UPDATE clause; no defect exists.
 *   · created_at      — declared ->nullable()->timestamp(...), already
 *     applied as `timestamp NULL DEFAULT NULL`. Same reasoning. The
 *     existing docblock §"created_at is nullable" documents why the
 *     column was made nullable in the first place.
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Migration filename:        unchanged
 * Migration timestamp:       unchanged
 * =============================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `versioned_policies` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * The immutable, versioned policy registry. Each row is one (policy_type,
 * version) pair — a single, permanent record of a policy definition that
 * applies to a defined window of time. Rows are written once and never
 * modified. Corrections require a new row.
 *
 * Three policy types are recognised in Phase 2B (Architecture §8.4):
 *
 *     kyc          — reserved for the future KYC capability (A7)
 *     interest     — reserved for the future interest capability (A8)
 *     allocation   — reserved for the future wallet allocation-policy
 *                    capability (D110)
 *
 * In Phase 2B all three are "slot only": no payload schema is frozen, no
 * row is seeded by the manifest, and no consumer evaluates them. The
 * vocabulary is declared in `config('policy.types')` (CORE.1) so that
 * future implementers attach to stable names.
 *
 * =============================================================================
 * Policy identity (Architecture §8.2)
 * =============================================================================
 * A policy version's identity is `(policy_type, version)`. The table
 * enforces this with a composite UNIQUE constraint. `effective_from` and
 * `effective_until` are TEMPORAL METADATA, not identity components — two
 * versions of the same policy_type coexist, distinguished by their version
 * numbers, and their applicability windows are resolved by query.
 *
 * Resolution of "which version applies at time T":
 *
 *     SELECT *
 *       FROM versioned_policies
 *      WHERE policy_type = :type
 *        AND effective_from <= :T
 *        AND (effective_until IS NULL OR effective_until > :T)
 *      ORDER BY effective_from DESC
 *      LIMIT 1
 *
 * The composite index `vp_policy_effective_index` supports this query.
 *
 * =============================================================================
 * Immutability (Architecture §8.3, Step 6 amendment S6-C)
 * =============================================================================
 * A policy row is immutable on creation. There is NO `updated_at` column.
 * The `created_at` column is written explicitly at insert time by the
 * registry service (CORE.5). The `VersionedPolicy` model (CORE.3) sets
 * `$timestamps = false`.
 *
 * A change to a policy definition is expressed as a NEW row:
 *
 *     - If the change applies at a future point in time, insert a new
 *       version with an `effective_from` in the future.
 *     - If the previous version should stop applying at a defined moment,
 *       insert the successor with the desired `effective_from`; the
 *       previous version's applicability ends naturally at that instant.
 *     - If the previous version's `effective_until` was explicitly set and
 *       needs to move, that is a CR-level concern that adds a supersession
 *       chain — not an in-place edit to this row.
 *
 * There is no delete path in the registry. The table is append-only.
 *
 * =============================================================================
 * `created_at` is nullable — a MySQL-necessitated correction
 * =============================================================================
 * Step 6 v1.1.0 amendment S6-C declared `created_at` as NOT NULL, on the
 * reasoning that every row is written by an explicit insert and the
 * registry always supplies the value. That reasoning is correct at the
 * application layer, but MySQL rejects a `TIMESTAMP NOT NULL` column that
 * has no `DEFAULT` clause when `explicit_defaults_for_timestamp` is ON
 * (or under some strict `sql_mode` combinations), producing:
 *
 *     SQLSTATE[42000]: 1067 Invalid default value for 'created_at'
 *
 * The correction is to make the column NULLABLE. This matches:
 *
 *   - Laravel's own `$table->timestamps()` convention (nullable timestamps
 *     for exactly this reason).
 *   - The 2A precedent on `share_holding_entries.created_at` (2A.40
 *     §"Insert time"), which is likewise nullable on the same grounds.
 *   - The equivalent column on `wallet_entries` (2B.54), which is nullable.
 *
 * No runtime behavior changes: the registry service (CORE.5) always writes
 * `created_at` explicitly at insert time, and the `VersionedPolicy` model
 * (CORE.3) sets `$timestamps = false` so Eloquent does not manage the
 * column. The column is NULL only if a row is written by an external
 * process that bypasses the registry — a case the schema does not need to
 * protect against with a NOT NULL constraint.
 *
 * =============================================================================
 * Business time immutability — effective_from is DATETIME (v1.0.2)
 * =============================================================================
 * `effective_from` is declared as DATETIME rather than TIMESTAMP.
 *
 * DATETIME is not subject to MySQL's server-mode-dependent implicit
 * default behavior. A TIMESTAMP NOT NULL column, on a server running
 * with `explicit_defaults_for_timestamp = OFF`, receives an implicit
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. That would
 * contradict the registry's immutable-on-creation contract: an UPDATE
 * to any policy row — such as a maintenance script correcting a typo in
 * `payload` — would silently move `effective_from` to the moment of
 * the update. Because the temporal-resolution query orders by
 * `effective_from DESC` and bounds it with `effective_from <= :moment`,
 * a moved value changes which version resolves at every past moment.
 * A historical lookup would return a different version than it did
 * before the update — a silent corruption of the registry's temporal
 * semantics.
 *
 * DATETIME carries no such behavior in any server mode. The column's
 * value is set explicitly by VersionedPolicyRegistry::create() at
 * insertion time and is never modified afterwards.
 *
 * The type change is a semantic no-op for the application: DATETIME
 * and TIMESTAMP both store a second-resolution instant interpreted in
 * the connection timezone. The model's `datetime` cast, the registry's
 * `findApplicable()` comparison, and every service-layer write are
 * unaffected.
 *
 * See also P2.2's companion amendments: the Shareholder domain
 * (`share_holding_entries.occurred_at`) and the Wallet domain (six
 * columns across six tables). All eight amendments apply the same
 * DATETIME correction for the same reason.
 *
 * =============================================================================
 * Payload opacity (Architecture §8.7)
 * =============================================================================
 * `payload` is JSON and is OPAQUE to this table and to the registry. The
 * registry stores the payload; the consumer (a future KYC capability, a
 * future interest capability, the wallet allocation surface) defines the
 * schema and validates on read. The database does not enforce payload
 * shape.
 *
 * MySQL 8 supports native JSON columns. The column type is chosen over TEXT
 * so that future consumers can use `JSON_EXTRACT`-style queries if their
 * access patterns require it. Phase 2B performs no such queries; the
 * registry reads whole payloads.
 *
 * =============================================================================
 * Effective dates (Architecture §8.2)
 * =============================================================================
 * `effective_from` is required. Every policy version has a defined start of
 * applicability.
 *
 * `effective_until` is nullable. A null value means the version is
 * open-ended: it remains applicable until a successor version supersedes it
 * by `effective_from` ordering. A non-null value places an explicit upper
 * bound on applicability — useful when a policy is known in advance to
 * expire at a defined moment.
 *
 * The table does not enforce `effective_until > effective_from`. That
 * invariant is a consumer-layer concern, because:
 *
 *   - The registry is domain-agnostic and does not assume any semantic of
 *     "effective" beyond the ordering used for temporal resolution.
 *   - A cross-column CHECK on timestamps is expressible in MySQL 8 but
 *     would couple the schema to a rule that, if ever relaxed (e.g. to
 *     allow retroactive corrections in a future CR), would require a
 *     schema migration rather than a service change.
 *
 * The registry service (CORE.5) is the enforcement point.
 *
 * =============================================================================
 * Version numbering (Architecture §8.2)
 * =============================================================================
 * `version` is an unsigned integer. Version numbers are monotonic within a
 * `policy_type`: version 2 supersedes version 1 of the same type. The
 * numbering is not required to be contiguous — a future CR may reserve
 * ranges or skip numbers — but it is required to be unique within a type,
 * which the composite UNIQUE enforces.
 *
 * The registry service is responsible for choosing the next version number
 * when creating a row. The schema does not auto-increment `version` (an
 * auto-increment would be global, not per-type).
 *
 * =============================================================================
 * Indexes (Step 6 amendment S6-D)
 * =============================================================================
 * Two indexes support this table's query patterns:
 *
 *   vp_policy_version_unique       — UNIQUE on (policy_type, version).
 *                                    Enforces identity.
 *
 *   vp_policy_effective_index      — COMPOSITE on (policy_type, effective_from).
 *                                    Supports the temporal-resolution query.
 *                                    The leading `policy_type` also serves
 *                                    as a supporting index for any query
 *                                    filtered by type alone.
 *
 * No FK-supporting indexes are required — this table has no foreign keys.
 * No standalone single-column index is declared; the two above cover the
 * access paths.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The `effective_until > effective_from` invariant — registry service.
 *   - Payload schema validation — consumer layer.
 *   - Cross-type version monotonicity — registry service (per type).
 *   - "Only one open-ended version per type" — NOT an invariant; the
 *     temporal-resolution query naturally selects the latest applicable
 *     version regardless of how many open-ended rows exist.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `versioned_policies` table. This migration has no
     * dependencies on any other table — it is the root of the Phase 2B
     * migration sequence and runs before `wallets` (2B.49).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('versioned_policies', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Policy type — the discriminator that partitions the version
            // space. Values are declared in config('policy.types') (CORE.1):
            //
            //     kyc, interest, allocation
            //
            // VARCHAR(64) leaves headroom above the longest declared type.
            // The column is NOT a MySQL ENUM: adding a type is a Change
            // Request that updates the config vocabulary; a MySQL ENUM would
            // require a schema migration for every value change and would
            // couple the migration to application-code terminology. This
            // mirrors the pattern used by 2A for `shareholder_type`,
            // `entry_type`, and `status`.
            // -----------------------------------------------------------------
            $table->string('policy_type', 64)
                  ->comment('Policy-type discriminator; see config(policy.types)');

            // -----------------------------------------------------------------
            // Version number — monotonic within a policy_type.
            //
            // Unsigned integer. The composite UNIQUE below enforces
            // uniqueness within a type; the registry service chooses the
            // next number when creating a row.
            // -----------------------------------------------------------------
            $table->unsignedInteger('version')
                  ->comment('Version number; unique within policy_type');

            // -----------------------------------------------------------------
            // Applicability window.
            //
            // `effective_from` is required and defines the start of
            // applicability. `effective_until` is nullable; a null value
            // means open-ended.
            //
            // `effective_from` is DATETIME, not TIMESTAMP. See the class
            // docblock §"Business time immutability — effective_from is
            // DATETIME". The type change eliminates the server-mode-
            // dependent implicit DEFAULT / ON UPDATE behavior that would
            // otherwise silently move the temporal anchor on any UPDATE
            // to a policy row.
            //
            // The temporal-resolution query orders by `effective_from`
            // descending to select the latest applicable version. See the
            // class docblock for the exact query.
            // -----------------------------------------------------------------
            $table->dateTime('effective_from')
                  ->comment('Instant from which this version is applicable (inclusive)');

            // -----------------------------------------------------------------
            // `effective_until` remains a nullable TIMESTAMP. Nullable
            // TIMESTAMP columns are not affected by MySQL's implicit
            // default behavior; this column was already declared nullable
            // and is applied as `timestamp NULL DEFAULT NULL`.
            // -----------------------------------------------------------------
            $table->timestamp('effective_until')
                  ->nullable()
                  ->comment('Instant after which this version is no longer applicable (exclusive; null = open-ended)');

            // -----------------------------------------------------------------
            // Payload — the policy definition itself.
            //
            // JSON and OPAQUE to this table and to the registry. The
            // consumer defines the schema and validates on read. MySQL 8
            // supports native JSON columns; the type is chosen over TEXT
            // so that future consumers can use JSON_EXTRACT if their access
            // patterns require it.
            // -----------------------------------------------------------------
            $table->json('payload')
                  ->comment('Opaque policy payload; consumer defines its schema');

            // -----------------------------------------------------------------
            // Insert timestamp.
            //
            // Written explicitly at insert time by the registry service
            // (CORE.5). There is NO updated_at — the table is immutable on
            // creation (Architecture §8.3, Step 6 amendment S6-C). The
            // VersionedPolicy model (CORE.3) sets $timestamps = false.
            //
            // NULLABLE — see the class-level docblock
            // §"`created_at` is nullable — a MySQL-necessitated correction"
            // for the rationale. Laravel's own `$table->timestamps()`
            // produces nullable timestamps for exactly this reason: MySQL
            // rejects a `TIMESTAMP NOT NULL` column without an explicit
            // `DEFAULT` when `explicit_defaults_for_timestamp` is ON.
            //
            // The registry always writes the value explicitly; the column
            // is NULL only if a row is written by an external process that
            // bypasses the registry — a case the schema does not need to
            // protect against with a NOT NULL constraint.
            //
            // This column remains a nullable TIMESTAMP. It is not affected
            // by the DATETIME correction applied to `effective_from`.
            // -----------------------------------------------------------------
            $table->timestamp('created_at')
                  ->nullable()
                  ->comment('Row insert timestamp; written explicitly, no updated_at');

            // -----------------------------------------------------------------
            // Identity — (policy_type, version).
            //
            // The composite UNIQUE enforces the policy-identity rule from
            // Architecture §8.2. Two versions of the same type cannot share
            // a version number; two different types may independently use
            // version 1.
            // -----------------------------------------------------------------
            $table->unique(
                ['policy_type', 'version'],
                'vp_policy_version_unique',
            );

            // -----------------------------------------------------------------
            // Temporal-resolution support.
            //
            // The composite index supports the "which version applies at
            // time T" query, which filters by policy_type and orders by
            // effective_from. The leading `policy_type` also serves as a
            // supporting index for any query filtered by type alone.
            //
            // The index remains valid across the type change from TIMESTAMP
            // to DATETIME.
            // -----------------------------------------------------------------
            $table->index(
                ['policy_type', 'effective_from'],
                'vp_policy_effective_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. No other table references this one by foreign key
     * in a way that would block a standalone drop at the point this
     * migration's `down()` runs — the Wallet domain's three policy-version
     * FKs (2B.49) are dropped by the Wallet migrations, which run earlier
     * in the reverse order of `php artisan migrate:rollback`.
     *
     * No data is preserved on rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('versioned_policies');
    }
};