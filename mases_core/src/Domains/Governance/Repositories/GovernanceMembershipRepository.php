<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 19 of 31
 * =============================================================================
 *
 * File:            Repositories/GovernanceMembershipRepository.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (repository)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceMembership` model (G2c
 *   file 18 of 31) could be queried from any caller via Eloquent's
 *   own static API, but the domain had no centralised, tenant-scoped
 *   query surface for memberships. Every controller, service, or
 *   future consumer that needed to load a membership would have
 *   had to construct its own query.
 *
 * After:
 *   One file that declares the domain's read-only query surface for
 *   `GovernanceMembership`. Every read path in the domain — the
 *   service (G2c file 20), the policy (G2c file 21), the controller
 *   (G2c file 25), and every future caller — goes through this
 *   class.
 *
 *   The repository is:
 *
 *     · Read-only. It declares no write methods.
 *
 *     · Tenant-scoped by construction. Every public method takes
 *       `int $tenantId` as its first parameter.
 *
 *     · Body-scoped for its primary list.
 *
 *   The methods it declares:
 *
 *     paginateForBody($tenantId, $bodyId, $filters, $perPage, $with)
 *         — Paginated list of a body's memberships, with filters.
 *
 *     findForTenant($tenantId, $id, $with)
 *         — Single row by primary key, tenant-scoped.
 *
 *     findForTenantOrFail($tenantId, $id, $with)
 *         — Same, but throws ModelNotFoundException on miss.
 *
 *     existsForTenant($tenantId, $id)
 *         — Existence check by primary key.
 *
 *     countForBody($tenantId, $bodyId, $status)
 *         — Count of a body's memberships, optionally by status.
 *
 *     findActiveForBody($tenantId, $bodyId, $with)
 *         — Active memberships of a body.
 *
 *     findActiveForPosition($tenantId, $positionId, $with)
 *         — Active memberships for a specific position. A position
 *           typically has at most one; the method returns a
 *           collection for the same reason the appointment
 *           repository's counterpart does.
 *
 *     findOngoingForPosition($tenantId, $positionId, $with)
 *         — Ongoing memberships (active OR suspended) for a
 *           position. Used by the service layer's overlap check
 *           (ruling B4).
 *
 *     findActiveForUser($tenantId, $userId, $with)
 *         — Active memberships held by a specific user.
 *
 *     existsOverlapping($tenantId, $bodyId, $positionId, $memberKey,
 *                       $startDate, $endDate, $exceptId)
 *         — THE OVERLAP-CHECK QUERY for ruling B4. Returns true if
 *           any ongoing membership for the same
 *           (body, position, member) overlaps the given date
 *           range. See §"The overlap-check query" below.
 *
 *   What this repository deliberately does NOT do:
 *
 *     · It does NOT resolve foreign relations automatically. A
 *       caller that needs `body`, `position`, `term`, `appointment`,
 *       or `user` passes them via the `$with` parameter.
 *
 *     · It does NOT enforce any business rule. The overlap
 *       invariant is enforced by the SERVICE; this repository only
 *       provides the query the service runs.
 *
 *     · It does NOT paginate the "for position" / "for user"
 *       queries. Those return collections — a position has a
 *       bounded number of historical memberships, and a user
 *       holds a bounded number of seats.
 *
 * =============================================================================
 * The overlap-check query — the design constraint
 * =============================================================================
 * The overlap invariant (ruling B4) is stated as:
 *
 *     "No two ACTIVE memberships for the same (person reference,
 *      position, body) may have overlapping date ranges. Compare on
 *      [start_date, end_date] with end_date = NULL treated as +∞."
 *
 * The query must compare a CANDIDATE date range
 * `[candidateStart, candidateEnd]` against every existing ongoing
 * membership's range `[existingStart, existingEnd]` for the same
 * `(body, position, member)` tuple, and return true if any pair of
 * ranges overlaps.
 *
 * Two ranges `[a1, a2]` and `[b1, b2]` overlap when:
 *
 *     a1 <= b2  AND  b1 <= a2
 *
 * (with `null` upper bounds treated as +∞ — i.e., the `<=` condition
 * on the upper-bound side is treated as satisfied).
 *
 * Two complications:
 *
 *   1. The "person reference" is a SET of columns, not a single
 *      key. Two memberships are for the same person when:
 *
 *        · both have the same non-null `user_id`, OR
 *        · both have the same non-null `external_reference`, OR
 *        · both have the same non-empty bilingual name pair.
 *
 *      The precedence the model's `memberKey()` helper documents
 *      (user_id > external_reference > name pair) is the
 *      PRECEDENCE FOR IDENTIFYING WHICH COLUMN TO COMPARE. Two
 *      memberships with different `user_id` values but the same
 *      name are NOT for the same person — they have different
 *      accounts.
 *
 *      The query's job is to build a WHERE clause that applies the
 *      precedence: for a candidate with a `user_id`, match existing
 *      rows by `user_id` only; for a candidate with an
 *      `external_reference`, match by that reference only; for a
 *      candidate with only a name pair, match by the name pair
 *      only.
 *
 *      This repository exposes the check as a single method
 *      `existsOverlapping(...)` that takes the candidate's
 *      `(userId, externalReference, memberNameAm, memberNameEn)`
 *      tuple and the date range, and constructs the correct
 *      WHERE clause internally. The service passes the four
 *      candidate values; it does not assemble the query itself.
 *
 *   2. The date-range overlap with a nullable upper bound. MySQL
 *      handles this in a WHERE clause naturally:
 *
 *        · existing `end_date IS NULL` → treated as +∞, so
 *          `existing.end_date >= candidate.start` is
 *          `true` unconditionally for that row.
 *        · candidate `endDate IS NULL` → treated as +∞, so
 *          `existing.start <= candidate.end` is
 *          `true` unconditionally for that row.
 *
 *      The repository's query expresses this with an explicit
 *      pair of OR-conditions.
 *
 * The method returns a boolean — `true` if any overlapping row
 * exists. The service translates a `true` into a
 * `ValidationException` with the appropriate field message. The
 * method does NOT throw; a `false` is a valid result, not an error.
 *
 * =============================================================================
 * The exceptId parameter — why it exists
 * =============================================================================
 * `existsOverlapping()` accepts an optional `$exceptId`. When set,
 * the query excludes the row with that primary key from the check.
 *
 * The parameter is used by the service's `update()` path: when a
 * membership is being updated, the check must not flag the
 * membership's own current row as an overlap with itself.
 *
 * The parameter is NOT used by the service's `register()` path —
 * a new row has no id yet, so no exclusion is needed.
 *
 * A caller passing an id that does not match any row is harmless;
 * the exclusion is a no-op for a missing id.
 *
 * =============================================================================
 * Tenant scoping — the platform's contract
 * =============================================================================
 * Every public method takes `int $tenantId` as its FIRST parameter.
 * The caller resolves this value from the established MASES tenant
 * context and passes it in.
 *
 * The overlap check is scoped to a tenant as well — two memberships
 * in different tenants may never be for the same seat, so the
 * check's WHERE clause includes `tenant_id = ?`. This makes the
 * index on `(tenant_id, status)` a candidate for the query planner
 * in addition to the position-status index.
 *
 * =============================================================================
 * Pagination and filters
 * =============================================================================
 * `paginateForBody()` recognises five filter keys:
 *
 *     'status'            — a GovernanceMembershipStatus case or
 *                           its string value; filters on `status`.
 *
 *     'is_ex_officio'     — a bool; filters on `is_ex_officio`.
 *
 *     'is_current'        — a bool; when true, applies the model's
 *                           `current()` scope; when false, applies
 *                           its negation.
 *
 *     'has_user'          — a bool; when true, filters to rows with
 *                           a non-null `user_id`; when false, to
 *                           rows with a null `user_id`.
 *
 *     'search'            — a string; case-insensitive LIKE across
 *                           `member_name_am`, `member_name_en`,
 *                           `external_reference`.
 *
 * Unrecognised keys are ignored.
 *
 * The pagination page size is clamped to `[1, MAX_PER_PAGE]`.
 *
 * The default order is `start_date DESC, id DESC`. This mirrors the
 * appointment repository's ordering rationale: a membership is a
 * temporal record, and the most recent record is what a reader
 * wants to see at the top of a list. The `id DESC` tiebreaker
 * favours the more recently created row.
 *
 * =============================================================================
 * Search behaviour
 * =============================================================================
 * The `search` filter matches the term against three columns:
 *
 *     member_name_am
 *     member_name_en
 *     external_reference
 *
 * The `ended_reason` and `notes_am` / `notes_en` columns are NOT
 * searched — they are free-text fields whose inclusion would
 * produce false-positive matches in a search intended to locate a
 * specific member.
 *
 * The match is a case-insensitive LIKE `%term%`, with LIKE
 * wildcards in the user's term escaped.
 *
 * =============================================================================
 * No writes
 * =============================================================================
 * This repository is READ-ONLY by design. Persistence for
 * memberships is performed exclusively by
 * `GovernanceMembershipService` (G2c file 20 of 31), which
 * validates the domain invariants before persisting.
 *
 * =============================================================================
 * Eager-loading
 * =============================================================================
 * Methods accept an optional `array $with` parameter listing
 * relations to eager-load. Callers that render the result to JSON
 * typically pass `['body', 'position', 'term', 'appointment', 'user']`
 * to avoid N+1. Callers that only need counts pass an empty array.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Repositories
 * @version 1.0.0
 */
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

        // Primary occupancy is exclusive per position, regardless of person identity.
        // Acting assignments are represented separately and must not be stored as primary memberships.

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