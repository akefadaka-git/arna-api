<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Policy\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use Carbon\CarbonInterface;

/**
 * VersionedPolicyRegistry
 *
 * The single read/write façade over the `versioned_policies` table (CORE.2).
 *
 * =============================================================================
 * What this service is
 * =============================================================================
 * A domain-agnostic store of immutable, versioned policy definitions.
 * Consumers (a future KYC capability, a future interest capability, the
 * wallet allocation surface) call this service to:
 *
 *   - Create a new policy version.
 *   - Resolve the policy version that applies at a moment in time.
 *   - Retrieve a specific version.
 *   - Enumerate all versions of a type.
 *
 * The registry does not evaluate policies, does not validate payload
 * schemas, and does not attach meaning to any policy_type beyond accepting
 * that it is declared in `config('policy.types')` (CORE.1).
 *
 * =============================================================================
 * What this service is NOT
 * =============================================================================
 *   - It is NOT an interest calculator.
 *   - It is NOT an allocation engine.
 *   - It is NOT a KYC evaluation system.
 *   - It does NOT validate payloads — the payload is stored as-is.
 *   - It does NOT cache — consumers that need caching do so at their layer.
 *   - It does NOT enforce cross-version monotonicity of `effective_from`
 *     (Architecture §8.7). A caller may create version 5 with an earlier
 *     `effective_from` than version 4. The temporal-resolution query still
 *     resolves correctly by `effective_from DESC`; the version numbers are
 *     a labelling scheme, not a strictly-ordered timeline.
 *
 * =============================================================================
 * What this service DOES enforce
 * =============================================================================
 * Three per-row invariants at write time, all of which the schema does not
 * express (see CORE.2 §"Effective dates" for the rationale):
 *
 *   1. `policy_type` is declared in `config('policy.types')` (CORE.1).
 *
 *   2. `version` is a positive integer (>= 1).
 *
 *   3. When `effective_until` is non-null, it lies strictly after
 *      `effective_from`. An open-ended version (null `effective_until`) is
 *      always well-formed.
 *
 * Each violation throws `Illuminate\Validation\ValidationException` with a
 * field-keyed message, mirroring the discipline used throughout Phase 2A
 * for caller-visible errors.
 *
 * =============================================================================
 * Immutability (Architecture §8.3)
 * =============================================================================
 * This service exposes NO update and NO delete method. The registry is
 * append-only. Corrections are expressed as new versions:
 *
 *   - If a policy version should change at a future point in time, create a
 *     successor with the desired `effective_from`.
 *
 *   - If a policy version's `effective_until` needs to move, that is a
 *     CR-level concern that adds a supersession chain; it is not an
 *     in-place edit through this service.
 *
 * The `VersionedPolicy` model (CORE.3) provides a second defense layer: it
 * throws `LogicException` on `updating` and `deleting`. Even if a future
 * contributor accidentally calls `$policy->save()` on a loaded model, the
 * attempt fails loudly rather than silently mutating a historical policy.
 *
 * =============================================================================
 * Version numbering
 * =============================================================================
 * A caller may supply an explicit `version` (for migrations, seeded
 * policies, or imported registers) or omit it, in which case the service
 * computes the next number as `MAX(version) + 1` within the policy type.
 *
 * Two concurrent `create()` calls without an explicit `version` can race:
 * both read the same maximum and both attempt to insert the same next
 * number. The composite UNIQUE constraint on `(policy_type, version)`
 * (CORE.2) guarantees that one insert wins. The loser receives a
 * `QueryException` from the framework — not a friendly validation error —
 * because a unique-constraint violation is a rare administrative race, not
 * a caller input error. This matches the pattern used by Phase 2A's
 * `ShareHoldingService::findOrCreateHolding()` for its composite-unique
 * race window.
 *
 * Policy creation is a low-frequency administrative operation. The service
 * deliberately does not introduce a heavier concurrency mechanism (a
 * per-type lock, a serializable transaction) that would be justified only
 * by high-frequency writes.
 *
 * =============================================================================
 * Temporal resolution (Architecture §8.2)
 * =============================================================================
 * `findApplicable($policyType, $moment)` returns the version whose
 * applicability window contains `$moment`, selected by:
 *
 *     effective_from <= :moment
 *       AND
 *     (effective_until IS NULL OR effective_until > :moment)
 *     ORDER BY effective_from DESC
 *     LIMIT 1
 *
 * When two versions share the same `effective_from` (a legal but unusual
 * configuration), the service orders by `version DESC` as a deterministic
 * tiebreaker. The architecture does not specify a tie-breaker; this
 * document records the choice so that resolution is reproducible across
 * environments.
 *
 * Returns `null` when no version applies. The absence of an applicable
 * policy is a valid outcome; the consumer decides how to handle it
 * (typically: fail the operation, or proceed without policy).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §8      — Versioned-policy registry integration
 *   Architecture v1.2.0 §8.2    — Identity and temporal applicability
 *   Architecture v1.2.0 §8.3    — Immutability and deletion
 *   Architecture v1.2.0 §8.4    — Policy types recognised in Phase 2B
 *   Architecture v1.2.0 §8.5    — Wallet policy references
 *   Architecture v1.2.0 §8.7    — Registry boundary conditions
 *   Step 5 (manifest) CORE.5    — This file
 *   Step 6 v1.1.0 §3.1          — versioned_policies table specification
 *   Step 6 v1.1.0 S6-C          — Immutable-on-create, no updated_at
 *
 * @package MaaleArna\MASES\Core\Policy\Services
 * @version 1.0.0
 */
final class VersionedPolicyRegistry
{
    // =========================================================================
    // Public API — writes
    // =========================================================================

    /**
     * Create a new policy version.
     *
     * This is the registry's only write operation. It is append-only: the
     * method inserts a new row and returns it; it never modifies an
     * existing row.
     *
     * The method validates three per-row invariants (see the class
     * docblock) before touching the database. It then runs inside a
     * database transaction that:
     *
     *   1. Computes the version number if not supplied.
     *   2. Checks for uniqueness of `(policy_type, version)` explicitly,
     *      producing a friendly ValidationException rather than a raw
     *      integrity violation for the common case.
     *   3. Inserts the row with `created_at` set explicitly (the model
     *      disables Eloquent's timestamp management; see CORE.3).
     *
     * The explicit uniqueness check does not eliminate the concurrent-race
     * window; the composite UNIQUE constraint on the table is the ultimate
     * guarantee. See the class docblock §"Version numbering".
     *
     * @param string               $policyType       One of config('policy.types').
     * @param array<string, mixed> $payload          Opaque policy payload.
     * @param CarbonInterface      $effectiveFrom    Start of applicability (inclusive).
     * @param CarbonInterface|null $effectiveUntil   End of applicability (exclusive); null = open-ended.
     * @param int|null             $version          Explicit version; null = auto-compute.
     * @return VersionedPolicy
     *
     * @throws ValidationException
     */
    public function create(
        string $policyType,
        array $payload,
        CarbonInterface $effectiveFrom,
        ?CarbonInterface $effectiveUntil = null,
        ?int $version = null,
    ): VersionedPolicy {
        $this->assertKnownType($policyType);
        $this->assertValidEffectiveWindow($effectiveFrom, $effectiveUntil);

        if ($version !== null) {
            $this->assertPositiveVersion($version);
        }

        return DB::transaction(function () use (
            $policyType,
            $payload,
            $effectiveFrom,
            $effectiveUntil,
            $version,
        ): VersionedPolicy {
            $versionToUse = $version ?? $this->computeNextVersionNumber($policyType);

            $this->assertPositiveVersion($versionToUse);

            $exists = VersionedPolicy::query()
                ->ofType($policyType)
                ->where('version', $versionToUse)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'version' => [
                        sprintf(
                            'Version %d already exists for policy type "%s". '
                            . 'Choose a different version number or omit the '
                            . 'version to auto-compute the next one.',
                            $versionToUse,
                            $policyType,
                        ),
                    ],
                ]);
            }

            /** @var VersionedPolicy $policy */
            $policy = VersionedPolicy::create([
                'policy_type'     => $policyType,
                'version'         => $versionToUse,
                'effective_from'  => Carbon::instance($effectiveFrom),
                'effective_until' => $effectiveUntil !== null
                    ? Carbon::instance($effectiveUntil)
                    : null,
                'payload'         => $payload,
                'created_at'      => Carbon::now(),
            ]);

            return $policy->refresh();
        });
    }

    // =========================================================================
    // Public API — reads
    // =========================================================================

    /**
     * Resolve the policy version applicable at a given moment.
     *
     * Implements the temporal-resolution rule from Architecture §8.2. See
     * the class docblock §"Temporal resolution" for the exact query and for
     * the tie-breaking rule when two versions share an `effective_from`.
     *
     * Returns `null` when no version applies. Does not throw on absence —
     * a missing applicable policy is a valid state that consumers handle
     * explicitly.
     *
     * @param string          $policyType
     * @param CarbonInterface $moment
     * @return VersionedPolicy|null
     *
     * @throws ValidationException  When the policy type is not recognised.
     */
    public function findApplicable(
        string $policyType,
        CarbonInterface $moment,
    ): ?VersionedPolicy {
        $this->assertKnownType($policyType);

        /** @var VersionedPolicy|null $policy */
        $policy = VersionedPolicy::query()
            ->ofType($policyType)
            ->applicableAt($moment)
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();

        return $policy;
    }

    /**
     * Retrieve a specific version of a policy type.
     *
     * Direct lookup by `(policy_type, version)`. Returns `null` when the
     * version does not exist.
     *
     * @param string $policyType
     * @param int    $version
     * @return VersionedPolicy|null
     *
     * @throws ValidationException  When the policy type is not recognised
     *                              or the version is not positive.
     */
    public function findByVersion(string $policyType, int $version): ?VersionedPolicy
    {
        $this->assertKnownType($policyType);
        $this->assertPositiveVersion($version);

        /** @var VersionedPolicy|null $policy */
        $policy = VersionedPolicy::query()
            ->ofType($policyType)
            ->where('version', $version)
            ->first();

        return $policy;
    }

    /**
     * List every version of a policy type, ordered by version ascending.
     *
     * The returned collection is fully hydrated. Consumers that need to
     * filter by applicability should use `findApplicable()` for the single
     * applicable version, or filter the collection in memory.
     *
     * @param string $policyType
     * @return Collection<int, VersionedPolicy>
     *
     * @throws ValidationException  When the policy type is not recognised.
     */
    public function allVersions(string $policyType): Collection
    {
        $this->assertKnownType($policyType);

        /** @var Collection<int, VersionedPolicy> $versions */
        $versions = VersionedPolicy::query()
            ->ofType($policyType)
            ->orderBy('version')
            ->get();

        return $versions;
    }

    /**
     * Compute the next version number that a fresh `create()` would use.
     *
     * Returns `MAX(version) + 1` within the policy type, or `1` if no
     * version of the type exists. Read-only; does not reserve the number.
     *
     * This method is exposed so that consumers (or administrative UIs) can
     * display the next version number without attempting a create. It is
     * NOT a locking primitive; two concurrent callers may receive the same
     * number, in which case the second `create()` fails at the UNIQUE
     * constraint. See the class docblock §"Version numbering".
     *
     * @param string $policyType
     * @return int
     *
     * @throws ValidationException  When the policy type is not recognised.
     */
    public function nextVersionNumber(string $policyType): int
    {
        $this->assertKnownType($policyType);

        return $this->computeNextVersionNumber($policyType);
    }

    // =========================================================================
    // Public API — vocabulary
    // =========================================================================

    /**
     * Whether a policy type is declared in the recognised vocabulary.
     *
     * Checks `config('policy.types')` (CORE.1). This is the same check the
     * write path performs; exposing it lets consumers validate a policy
     * type before calling `create()` or `findApplicable()`.
     *
     * @param string $policyType
     * @return bool
     */
    public function isKnownType(string $policyType): bool
    {
        $types = (array) config('policy.types', []);

        return in_array($policyType, $types, true);
    }

    /**
     * Return the list of recognised policy types.
     *
     * Read-only accessor over the config vocabulary. The order matches the
     * order declared in `Config/policy.php` (CORE.1); it carries no runtime
     * meaning.
     *
     * @return list<string>
     */
    public function knownTypes(): array
    {
        /** @var list<string> $types */
        $types = array_values((array) config('policy.types', []));

        return $types;
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that the policy type is recognised.
     *
     * @param string $policyType
     * @return void
     *
     * @throws ValidationException
     */
    private function assertKnownType(string $policyType): void
    {
        if ($this->isKnownType($policyType)) {
            return;
        }

        $known = implode(', ', $this->knownTypes());

        throw ValidationException::withMessages([
            'policy_type' => [
                sprintf(
                    'Policy type "%s" is not recognised. Known types: %s.',
                    $policyType,
                    $known === '' ? '(none configured)' : $known,
                ),
            ],
        ]);
    }

    /**
     * Assert that a version number is a positive integer.
     *
     * @param int $version
     * @return void
     *
     * @throws ValidationException
     */
    private function assertPositiveVersion(int $version): void
    {
        if ($version >= 1) {
            return;
        }

        throw ValidationException::withMessages([
            'version' => [
                'Version must be a positive integer (>= 1).',
            ],
        ]);
    }

    /**
     * Assert that the effective window is well-formed.
     *
     * The rule applies only when `effective_until` is non-null: it must lie
     * strictly after `effective_from`. An open-ended version (null
     * `effective_until`) is always well-formed.
     *
     * The schema does not enforce this invariant (CORE.2 §"Effective
     * dates"); this method is the enforcement point.
     *
     * @param CarbonInterface      $from
     * @param CarbonInterface|null $until
     * @return void
     *
     * @throws ValidationException
     */
    private function assertValidEffectiveWindow(
        CarbonInterface $from,
        ?CarbonInterface $until,
    ): void {
        if ($until === null) {
            return;
        }

        if ($until->greaterThan($from)) {
            return;
        }

        throw ValidationException::withMessages([
            'effective_until' => [
                'The effective_until timestamp must lie strictly after effective_from.',
            ],
        ]);
    }

    // =========================================================================
    // Private — version computation
    // =========================================================================

    /**
     * Compute the next version number for a policy type.
     *
     * Returns `MAX(version) + 1` within the type, or `1` when no version
     * exists. Assumes the type has already been validated by the caller;
     * this method performs no assertion of its own.
     *
     * The result is not reserved. Two concurrent callers may receive the
     * same number; the UNIQUE constraint on `(policy_type, version)` is
     * the ultimate guard. See the class docblock §"Version numbering".
     *
     * @param string $policyType
     * @return int
     */
    private function computeNextVersionNumber(string $policyType): int
    {
        /** @var int|string|null $max */
        $max = VersionedPolicy::query()
            ->ofType($policyType)
            ->max('version');

        return ((int) ($max ?? 0)) + 1;
    }
}