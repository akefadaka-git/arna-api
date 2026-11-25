<?php

declare(strict_types=1);

// Centralized tenant-scoped membership queries.


namespace MaaleArna\MASES\Core\Domains\Governance\Repositories;

use DateTimeInterface;
use Throwable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceMembershipStatus;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;

/**
 * GovernanceMembershipRepository
 *
 * Read-only query surface for `GovernanceMembership` rows.
 *
 * See the file-level change-verification block for the repository's
 * shape, its tenant and body scoping, its overlap-check query for
 * ruling B4, its pagination and filter contract, and its deliberate
 * absence of write methods.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Repositories
 * @version 1.0.0
 */
final class GovernanceMembershipRepository
{
    /**
     * Default page size when the caller does not specify one.
     */
    private const DEFAULT_PER_PAGE = 25;

    /**
     * Maximum page size the caller may request.
     */
    private const MAX_PER_PAGE = 200;

    // =========================================================================
    // Listing
    // =========================================================================

    /**
     * Paginate memberships belonging to a body.
     *
     * The result is scoped to BOTH `$tenantId` and `$bodyId`.
     *
     * Recognised filter keys:
     *
     *     'status'        — a GovernanceMembershipStatus case or
     *                       its string value.
     *     'is_ex_officio' — a bool.
     *     'is_current'    — a bool; applies or negates the
     *                       current() scope.
     *     'has_user'      — a bool; filters on user_id presence.
     *     'search'        — a string searched across the name
     *                       columns and external_reference.
     *
     * Order: `start_date DESC, id DESC` — most recent first.
     *
     * @param  int                  $tenantId
     * @param  int                  $bodyId
     * @param  array<string, mixed> $filters
     * @param  int                  $perPage
     * @param  array<int, string>   $with
     * @return LengthAwarePaginator
     */
    public function paginateForBody(
        int $tenantId,
        int $bodyId,
        array $filters = [],
        int $perPage = self::DEFAULT_PER_PAGE,
        array $with = []
    ): LengthAwarePaginator {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = GovernanceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId);

        $this->applyFilters($query, $filters);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    // =========================================================================
    // Single-row lookups by primary key (tenant-scoped)
    // =========================================================================

    /**
     * Find a membership by primary key, scoped to a tenant.
     *
     * @param  int                $tenantId
     * @param  int                $id
     * @param  array<int, string> $with
     * @return GovernanceMembership|null
     */
    public function findForTenant(int $tenantId, int $id, array $with = []): ?GovernanceMembership
    {
        $query = GovernanceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id);

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /**
     * Find a membership by primary key, scoped to a tenant, or
     * throw.
     *
     * Throws `ModelNotFoundException` when no row is found in the
     * tenant, INCLUDING the case where the id exists in a different
     * tenant. The exception is constructed directly rather than via
     * a query, so a cross-tenant id cannot accidentally be returned.
     *
     * @param  int                $tenantId
     * @param  int                $id
     * @param  array<int, string> $with
     * @return GovernanceMembership
     *
     * @throws ModelNotFoundException
     */
    public function findForTenantOrFail(int $tenantId, int $id, array $with = []): GovernanceMembership
    {
        $membership = $this->findForTenant($tenantId, $id, $with);

        if ($membership === null) {
            throw (new ModelNotFoundException())->setModel(
                GovernanceMembership::class,
                [$id],
            );
        }

        return $membership;
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    /**
     * Whether a membership with the given primary key exists in the
     * tenant.
     *
     * @param  int  $tenantId
     * @param  int  $id
     * @return bool
     */
    public function existsForTenant(int $tenantId, int $id): bool
    {
        return GovernanceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->exists();
    }

    // =========================================================================
    // Counts
    // =========================================================================

    /**
     * Count memberships for a body in a tenant.
     *
     * @param  int                                       $tenantId
     * @param  int                                       $bodyId
     * @param  GovernanceMembershipStatus|string|null    $status
     * @return int
     */
    public function countForBody(
        int $tenantId,
        int $bodyId,
        GovernanceMembershipStatus|string|null $status = null
    ): int {
        $query = GovernanceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId);

        if ($status !== null) {
            $value = $status instanceof GovernanceMembershipStatus
                ? $status->value
                : $status;

            $query->where('status', $value);
        }

        return $query->count();
    }

    // =========================================================================
    // Active-scope queries
    // =========================================================================

    /**
     * Find the active memberships of a body.
     *
     * @param  int                $tenantId
     * @param  int                $bodyId
     * @param  array<int, string> $with
     * @return Collection<int, GovernanceMembership>
     */
    public function findActiveForBody(
        int $tenantId,
        int $bodyId,
        array $with = []
    ): Collection {
        $query = GovernanceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->where('status', GovernanceMembershipStatus::Active->value);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Find the active memberships for a specific position.
     *
     * In normal governance practice, a position has at most one
     * active membership at a time — the service layer enforces this
     * where the overlap invariant applies. The method returns a
     * collection for the same reason the appointment repository's
     * counterpart does: a data-entry defect may leave more than one,
     * and returning a collection rather than a single model lets
     * a caller notice and handle the case.
     *
     * @param  int                $tenantId
     * @param  int                $positionId
     * @param  array<int, string> $with
     * @return Collection<int, GovernanceMembership>
     */
    public function findActiveForPosition(
        int $tenantId,
        int $positionId,
        array $with = []
    ): Collection {
        $query = GovernanceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_position_id', $positionId)
            ->where('status', GovernanceMembershipStatus::Active->value);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Find the ONGOING memberships for a specific position.
     *
     * "Ongoing" is the enum's `isOngoing()` partition — active OR
     * suspended. Used by the service layer's overlap check (ruling
     * B4), which treats a suspended membership as still reserving
     * its seat.
     *
     * @param  int                $tenantId
     * @param  int                $positionId
     * @param  array<int, string> $with
     * @return Collection<int, GovernanceMembership>
     */
    public function findOngoingForPosition(
        int $tenantId,
        int $positionId,
        array $with = []
    ): Collection {
        $query = GovernanceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_position_id', $positionId)
            ->whereIn('status', [
                GovernanceMembershipStatus::Active->value,
                GovernanceMembershipStatus::Suspended->value,
            ]);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Find the active memberships held by a specific user.
     *
     * @param  int                $tenantId
     * @param  int                $userId
     * @param  array<int, string> $with
     * @return Collection<int, GovernanceMembership>
     */
    public function findActiveForUser(
        int $tenantId,
        int $userId,
        array $with = []
    ): Collection {
        $query = GovernanceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('status', GovernanceMembershipStatus::Active->value);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }

    // =========================================================================
    // The overlap-check query (ruling B4)
    // =========================================================================

    /**
     * Whether an ongoing membership exists for the same
     * (body, position, member) whose date range overlaps the given
     * candidate range.
     *
     * The check is scoped to the tenant and the body. It matches
     * the candidate's member reference by the same precedence the
     * model's `memberKey()` helper documents:
     *
     *   · If the candidate has a `$userId`, existing rows are
     *     matched by `user_id = $userId`.
     *   · Else, if the candidate has a non-empty
     *     `$externalReference`, existing rows are matched by
     *     `external_reference = $externalReference`.
     *   · Else, existing rows are matched by the pair
     *     (`member_name_am`, `member_name_en`) — both sides
     *     trimmed and compared case-sensitively, matching the way
     *     the columns are stored.
     *
     * When NONE of the four reference values is supplied (all
     * null / empty), the method returns false immediately — no
     * member identity, no overlap possible. This is a defensive
     * short-circuit; the service layer requires at least one
     * reference to be present before reaching this method.
     *
     * The date-range overlap is the standard two-condition test:
     *
     *     existing.start <= candidate.end   (or candidate.end is null)
     *     AND
     *     existing.end >= candidate.start   (or existing.end is null)
     *
     * The check filters existing rows to the ONGOING statuses
     * (active OR suspended), matching the enum's `isOngoing()`
     * partition. The service layer's ruling B4 states "no two
     * ACTIVE memberships"; the domain interprets this as "no two
     * ONGOING memberships" because a suspended membership still
     * reserves its seat.
     *
     * @param  int         $tenantId
     * @param  int         $bodyId
     * @param  int         $positionId
     * @param  int|null    $userId
     * @param  string|null $externalReference
     * @param  string|null $memberNameAm
     * @param  string|null $memberNameEn
     * @param  mixed       $startDate        Carbon, DateTimeInterface, or string.
     * @param  mixed       $endDate          Same, or null for open-ended.
     * @param  int|null    $exceptId         Row to exclude (for update path).
     * @return bool
     */
    public function existsOverlapping(
        int $tenantId,
        int $bodyId,
        int $positionId,
        ?int $userId,
        ?string $externalReference,
        ?string $memberNameAm,
        ?string $memberNameEn,
        mixed $startDate,
        mixed $endDate,
        ?int $personId = null,
        ?int $exceptId = null
    ): bool {
        $query = GovernanceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->where('governance_position_id', $positionId)
            ->whereIn('status', [
                GovernanceMembershipStatus::Active->value,
                GovernanceMembershipStatus::Suspended->value,
            ]);

        // ---------------------------------------------------------------------
        // Member-reference matching — apply the precedence.
        // ---------------------------------------------------------------------
        $appliedReference = false;

        // Canonical Person identity supersedes legacy account/reference/name
        // keys whenever a relationship has been explicitly established.
        if ($personId !== null) {
            $query->where('person_id', $personId);
            $appliedReference = true;
        } elseif ($userId !== null) {
            $query->where('user_id', $userId);
            $appliedReference = true;
        } elseif (is_string($externalReference) && trim($externalReference) !== '') {
            $query->where('external_reference', trim($externalReference));
            $appliedReference = true;
        } else {
            $nameAm = is_string($memberNameAm) ? trim($memberNameAm) : '';
            $nameEn = is_string($memberNameEn) ? trim($memberNameEn) : '';

            if ($nameAm !== '' || $nameEn !== '') {
                $query->where(function (Builder $q) use ($nameAm, $nameEn): void {
                    $q->where('member_name_am', $nameAm === '' ? null : $nameAm)
                      ->where('member_name_en', $nameEn === '' ? null : $nameEn);
                });
                $appliedReference = true;
            }
        }

        if (!$appliedReference) {
            // No member identity — no overlap possible.
            return false;
        }

        // ---------------------------------------------------------------------
        // Date-range overlap.
        // ---------------------------------------------------------------------
        $from = $this->toCarbon($startDate);
        $to   = $this->toCarbon($endDate);

        if ($from === null) {
            // A candidate with no start date cannot be tested for
            // overlap; the service layer requires a start date
            // before calling this method.
            return false;
        }

        // existing.start <= candidate.end (or candidate.end is null → +∞)
        if ($to !== null) {
            $query->where('start_date', '<=', $to->toDateString());
        }

        // existing.end >= candidate.start (or existing.end is null → +∞)
        $query->where(function (Builder $q) use ($from): void {
            $q->whereNull('end_date')
              ->orWhere('end_date', '>=', $from->toDateString());
        });

        // ---------------------------------------------------------------------
        // Optional exclusion (update path).
        // ---------------------------------------------------------------------
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Apply the recognised filter keys to the query.
     *
     * Unknown keys are silently ignored.
     *
     * @param  Builder              $query
     * @param  array<string, mixed> $filters
     * @return void
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // Status filter — accepts an enum case or its string value.
        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $status = $filters['status'];

            $value = $status instanceof GovernanceMembershipStatus
                ? $status->value
                : (string) $status;

            $query->where('status', $value);
        }

        // Ex-officio flag.
        if (array_key_exists('is_ex_officio', $filters) && $filters['is_ex_officio'] !== null) {
            $query->where('is_ex_officio', (bool) $filters['is_ex_officio']);
        }

        // Currentness flag.
        if (array_key_exists('is_current', $filters) && $filters['is_current'] !== null) {
            $isCurrent = (bool) $filters['is_current'];

            if ($isCurrent) {
                $query->current();
            } else {
                $today = Carbon::today()->toDateString();

                $query->where(function (Builder $q) use ($today): void {
                    $q->where('start_date', '>', $today)
                      ->orWhere(function (Builder $q2) use ($today): void {
                          $q2->whereNotNull('end_date')
                             ->where('end_date', '<', $today);
                      });
                });
            }
        }

        // User-presence flag.
        if (array_key_exists('has_user', $filters) && $filters['has_user'] !== null) {
            if ((bool) $filters['has_user']) {
                $query->whereNotNull('user_id');
            } else {
                $query->whereNull('user_id');
            }
        }

        // Search across name columns + external reference.
        if (array_key_exists('search', $filters)) {
            $term = is_string($filters['search']) ? trim($filters['search']) : '';

            if ($term !== '') {
                $this->applyMemberSearch($query, $term);
            }
        }
    }

    /**
     * Apply an OR-chain LIKE condition across the three identity
     * columns: `member_name_am`, `member_name_en`,
     * `external_reference`.
     *
     * @param  Builder $query
     * @param  string  $term
     * @return void
     */
    private function applyMemberSearch(Builder $query, string $term): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $pattern = '%' . $escaped . '%';

        $columns = [
            'member_name_am',
            'member_name_en',
            'external_reference',
        ];

        $query->where(function (Builder $q) use ($columns, $pattern): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $q->{$method}($column, 'like', $pattern);
            }
        });
    }

    /**
     * Normalise a date-like value to a `Carbon` instance, or return
     * null when the value cannot be parsed.
     *
     * @param  mixed $value
     * @return Carbon|null
     */
    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}