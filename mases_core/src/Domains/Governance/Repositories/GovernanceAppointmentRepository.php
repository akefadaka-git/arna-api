<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 6 of 31
 * =============================================================================
 *
 * File:            Repositories/GovernanceAppointmentRepository.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (repository)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `GovernanceAppointment` model (G2c
 *   file 5 of 31) could be queried from any caller via Eloquent's
 *   own static API, but the domain had no centralised, tenant-scoped
 *   query surface for appointments. Every controller, service, or
 *   future consumer that needed to load an appointment would have
 *   had to construct its own query — with the risk that a caller
 *   would forget the tenant scope, that two callers would implement
 *   the same filter differently, or that a future schema change
 *   would require edits scattered across the domain.
 *
 * After:
 *   One file that declares the domain's read-only query surface for
 *   `GovernanceAppointment`. Every read path in the domain — the
 *   service (G2c file 7), the policy (G2c file 8), the controller
 *   (G2c file 12), and every future caller — goes through this class.
 *
 *   The repository is:
 *
 *     · Read-only. It declares no write methods. Every mutation is
 *       performed by `GovernanceAppointmentService` (G2c file 7 of
 *       31), which validates invariants, wraps the write in a
 *       transaction, and dispatches an event after commit.
 *
 *     · Tenant-scoped by construction. Every public method takes
 *       `int $tenantId` as its first parameter and applies it as a
 *       WHERE clause.
 *
 *     · Body-scoped for its primary list. `paginateForBody()` takes
 *       a `bodyId` in addition to the `tenantId`, because the
 *       natural list query for appointments is "appointments of
 *       this body".
 *
 *   The methods it declares:
 *
 *     paginateForBody($tenantId, $bodyId, $filters, $perPage, $with)
 *         — Paginated list of a body's appointments, with optional
 *           filters.
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
 *         — Total count of appointments for a body, optionally
 *           filtered by status.
 *
 *     findActiveForBody($tenantId, $bodyId, $with)
 *         — The active appointments of a body, as a collection.
 *
 *     findActiveForPosition($tenantId, $positionId, $with)
 *         — The active appointments that target a specific position.
 *
 *     findActiveForTerm($tenantId, $termId, $with)
 *         — The active appointments scoped to a specific term.
 *
 *     existsActiveForPosition($tenantId, $positionId)
 *         — Existence check for "does this position have any
 *           active appointments?". Used by the service layer's
 *           cross-entity validation.
 *
 *     existsActiveForTerm($tenantId, $termId)
 *         — Existence check for "does this term have any active
 *           appointments?".
 *
 *   What this repository deliberately does NOT do:
 *
 *     · It does NOT resolve foreign relations automatically. A
 *       caller that needs `body`, `position`, `term`, or
 *       `memberships` passes them via the `$with` parameter.
 *
 *     · It does NOT declare a `search()` method separate from
 *       `paginateForBody()`. The `search` filter is applied inside
 *       the paginated list path.
 *
 *     · It does NOT return typed DTOs.
 *
 *     · It does NOT enforce any business rule about appointments.
 *       Whether an appointment is valid, whether its instrument
 *       type is compatible with its mode, whether its effective
 *       date precedes its instrument date — those are service-layer
 *       concerns.
 *
 *     · It does NOT paginate the "for position" and "for term"
 *       queries. Those return collections, because a position or a
 *       term has a bounded number of appointments in practice — a
 *       position is occupied by one person at a time (historically,
 *       over the body's lifetime, perhaps a handful), and a term
 *       has as many appointments as it has seats (a Board term
 *       might have five to fifteen). A caller that needs
 *       pagination on one of these is signalling a data model
 *       defect, not a legitimate query pattern.
 *
 * =============================================================================
 * Why this class exists
 * =============================================================================
 * Appointment lookups are performed from several places:
 *
 *   1. The HTTP controller layer (G2c file 12) — for listing and
 *      viewing.
 *   2. The service layer (G2c file 7) — for existence checks
 *      before writes, and for cross-entity validation (for
 *      example, "is this position already filled by an active
 *      appointment?").
 *   3. The policy layer (G2c file 8) — for tenant ownership
 *      resolution during authorization.
 *   4. Future cross-cutting callers — a membership validator in
 *      the second half of G2c that checks whether a proposed
 *      membership rests on a valid active appointment; a dashboard
 *      that shows the currently-active appointments of each body.
 *
 * Centralising the queries serves the same purposes as the
 * equivalent repositories for `GovernanceBody`,
 * `GovernancePosition`, and `GovernanceTerm`.
 *
 * =============================================================================
 * Tenant scoping — the platform's contract
 * =============================================================================
 * Every public method takes `int $tenantId` as its FIRST parameter.
 * The caller resolves this value from the established MASES tenant
 * context and passes it in.
 *
 * This class does NOT read `mases_tenant_id()` or any request
 * header. Requiring the caller to supply the value makes the trust
 * boundary explicit.
 *
 * The body-scoped methods (`paginateForBody`, `countForBody`,
 * `findActiveForBody`) take both `$tenantId` and `$bodyId`. Both
 * scopes are applied. A caller that supplies a `$bodyId` belonging
 * to a different tenant receives an empty result.
 *
 * The position- and term-scoped methods
 * (`findActiveForPosition`, `findActiveForTerm`,
 * `existsActiveForPosition`, `existsActiveForTerm`) also take both
 * `$tenantId` and the scope id. The tenant filter excludes any
 * cross-tenant scope's appointments.
 *
 * =============================================================================
 * Pagination and filters
 * =============================================================================
 * `paginateForBody()` recognises four filter keys:
 *
 *     'status'           — a GovernanceAppointmentStatus case or
 *                          its string value; filters on `status`.
 *
 *     'appointment_mode' — a GovernanceAppointmentMode case or its
 *                          string value; filters on
 *                          `appointment_mode`.
 *
 *     'instrument_type'  — a GovernanceAppointmentInstrumentType
 *                          case or its string value; filters on
 *                          `instrument_type`.
 *
 *     'search'           — a string; case-insensitive LIKE across
 *                          `instrument_reference`, `title_am`,
 *                          `title_en`.
 *
 * Unrecognised keys are ignored. A null or empty-string value for
 * any filter is treated as "filter not present".
 *
 * The pagination page size is clamped to `[1, MAX_PER_PAGE]`.
 *
 * The default order is `effective_date DESC, id DESC`. This is the
 * appointment's natural chronological order, LATEST FIRST: an
 * appointment is an event — the most recent one is what a reader
 * usually wants to see at the top of a list. This differs from
 * `GovernanceTermRepository`'s `effective_from ASC, id ASC`
 * ordering (earliest first), because a term is a chronological
 * container whose beginning a reader wants to see first, while an
 * appointment is an event whose latest instance a reader wants to
 * see first.
 *
 * The `id DESC` tiebreaker for equal `effective_date` values
 * favours the more recently created row — an appointment recorded
 * later, for the same effective date, is presumed to supersede
 * the earlier one. This is the correct posture for the common case
 * in which a corrective appointment is recorded to replace an
 * earlier record of the same instrument.
 *
 * =============================================================================
 * Search behaviour
 * =============================================================================
 * The `search` filter matches the term against three columns:
 *
 *     instrument_reference  — the free-text identifier
 *     title_am              — the Amharic title
 *     title_en              — the English title
 *
 * The match is a case-insensitive LIKE `%term%`, with LIKE
 * wildcards in the user's term escaped. The database's default
 * collation (`utf8mb4_unicode_ci`) handles the case-insensitivity.
 *
 * The `notes_am` and `notes_en` columns are deliberately NOT
 * searched. Both are free-text fields; including them would
 * produce too many false-positive matches in a search intended to
 * locate a specific appointment by its identity. A future CR that
 * needs full-text search across notes is a separate feature.
 *
 * =============================================================================
 * No writes
 * =============================================================================
 * This repository is READ-ONLY by design. It exposes no `create`,
 * `update`, `delete`, `restore`, or `forceDelete` methods.
 * Persistence for appointments is performed exclusively by
 * `GovernanceAppointmentService` (G2c file 7), which validates the
 * domain invariants before persisting.
 *
 * =============================================================================
 * Eager-loading
 * =============================================================================
 * Methods accept an optional `array $with` parameter listing
 * relations to eager-load. Callers that render the result to JSON
 * typically pass `['body', 'position', 'term']` to avoid N+1 on
 * the resource's optional embedded relations. Callers that only
 * need counts pass an empty array. The default is empty — the
 * repository never eager-loads relations the caller did not
 * request.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Repositories
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentInstrumentType;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentMode;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentStatus;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;

/**
 * GovernanceAppointmentRepository
 *
 * Read-only query surface for `GovernanceAppointment` rows.
 *
 * See the file-level change-verification block for the repository's
 * shape, its tenant and body scoping, its pagination and filter
 * contract, its active-scope queries, and its deliberate absence of
 * write methods.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Repositories
 * @version 1.0.0
 */
final class GovernanceAppointmentRepository
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
     * Paginate appointments belonging to a body.
     *
     * The result is scoped to BOTH `$tenantId` and `$bodyId`.
     *
     * Recognised filter keys:
     *
     *     'status'           — a GovernanceAppointmentStatus case or
     *                          its string value.
     *     'appointment_mode' — a GovernanceAppointmentMode case or
     *                          its string value.
     *     'instrument_type'  — a GovernanceAppointmentInstrumentType
     *                          case or its string value.
     *     'search'           — a string searched across
     *                          `instrument_reference`, `title_am`,
     *                          `title_en`.
     *
     * Order: `effective_date DESC, id DESC` — latest first. See the
     * file-level change-verification block §"Pagination and filters".
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

        $query = GovernanceAppointment::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId);

        $this->applyFilters($query, $filters);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    // =========================================================================
    // Single-row lookups by primary key (tenant-scoped)
    // =========================================================================

    /**
     * Find an appointment by primary key, scoped to a tenant.
     *
     * @param  int                $tenantId
     * @param  int                $id
     * @param  array<int, string> $with
     * @return GovernanceAppointment|null
     */
    public function findForTenant(int $tenantId, int $id, array $with = []): ?GovernanceAppointment
    {
        $query = GovernanceAppointment::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id);

        if ($with !== []) {
            $query->with($with);
        }

        return $query->first();
    }

    /**
     * Find an appointment by primary key, scoped to a tenant, or throw.
     *
     * Throws `ModelNotFoundException` when no row is found in the
     * tenant, INCLUDING the case where the id exists in a different
     * tenant. The exception is constructed directly rather than via
     * a query, so a cross-tenant id cannot accidentally be returned.
     *
     * @param  int                $tenantId
     * @param  int                $id
     * @param  array<int, string> $with
     * @return GovernanceAppointment
     *
     * @throws ModelNotFoundException
     */
    public function findForTenantOrFail(int $tenantId, int $id, array $with = []): GovernanceAppointment
    {
        $appointment = $this->findForTenant($tenantId, $id, $with);

        if ($appointment === null) {
            throw (new ModelNotFoundException())->setModel(
                GovernanceAppointment::class,
                [$id],
            );
        }

        return $appointment;
    }

    // =========================================================================
    // Existence checks
    // =========================================================================

    /**
     * Whether an appointment with the given primary key exists in the
     * tenant.
     *
     * @param  int  $tenantId
     * @param  int  $id
     * @return bool
     */
    public function existsForTenant(int $tenantId, int $id): bool
    {
        return GovernanceAppointment::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->exists();
    }

    // =========================================================================
    // Counts
    // =========================================================================

    /**
     * Count appointments for a body in a tenant.
     *
     * When `$status` is a `GovernanceAppointmentStatus` case (or its
     * string value), only appointments in that state are counted.
     * When null, every non-soft-deleted appointment is counted.
     *
     * @param  int                                      $tenantId
     * @param  int                                      $bodyId
     * @param  GovernanceAppointmentStatus|string|null  $status
     * @return int
     */
    public function countForBody(
        int $tenantId,
        int $bodyId,
        GovernanceAppointmentStatus|string|null $status = null
    ): int {
        $query = GovernanceAppointment::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId);

        if ($status !== null) {
            $value = $status instanceof GovernanceAppointmentStatus
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
     * Find the active appointments of a body.
     *
     * "Active" is the `GovernanceAppointmentStatus::Active` state.
     * Draft appointments are excluded — they are not yet in force.
     * Revoked and superseded appointments are excluded — they are no
     * longer in force.
     *
     * The collection is ordered by `effective_date DESC, id DESC`,
     * matching the paginated list's order.
     *
     * @param  int                $tenantId
     * @param  int                $bodyId
     * @param  array<int, string> $with
     * @return Collection<int, GovernanceAppointment>
     */
    public function findActiveForBody(
        int $tenantId,
        int $bodyId,
        array $with = []
    ): Collection {
        $query = GovernanceAppointment::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->where('status', GovernanceAppointmentStatus::Active->value);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Find the active appointments that target a specific position.
     *
     * A position may have zero, one, or (historically) several
     * active appointments. In normal governance practice, a
     * position has at most one active appointment at a time — the
     * service layer enforces this where appropriate. The repository
     * returns whatever the schema holds; enforcing the uniqueness
     * is not its concern.
     *
     * The collection is ordered by `effective_date DESC, id DESC`.
     *
     * @param  int                $tenantId
     * @param  int                $positionId
     * @param  array<int, string> $with
     * @return Collection<int, GovernanceAppointment>
     */
    public function findActiveForPosition(
        int $tenantId,
        int $positionId,
        array $with = []
    ): Collection {
        $query = GovernanceAppointment::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_position_id', $positionId)
            ->where('status', GovernanceAppointmentStatus::Active->value);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Find the active appointments scoped to a specific term.
     *
     * A term typically has many active appointments (one per seat).
     * The collection is ordered by `effective_date DESC, id DESC`.
     *
     * @param  int                $tenantId
     * @param  int                $termId
     * @param  array<int, string> $with
     * @return Collection<int, GovernanceAppointment>
     */
    public function findActiveForTerm(
        int $tenantId,
        int $termId,
        array $with = []
    ): Collection {
        $query = GovernanceAppointment::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_term_id', $termId)
            ->where('status', GovernanceAppointmentStatus::Active->value);

        if ($with !== []) {
            $query->with($with);
        }

        return $query
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get();
    }

    // =========================================================================
    // Active-scope existence checks
    // =========================================================================

    /**
     * Whether a position has any active appointment.
     *
     * Used by the service layer's cross-entity validation — for
     * example, a future service method that refuses to deactivate a
     * position while it has an active appointment without an
     * explicit override.
     *
     * @param  int  $tenantId
     * @param  int  $positionId
     * @return bool
     */
    public function existsActiveForPosition(int $tenantId, int $positionId): bool
    {
        return GovernanceAppointment::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_position_id', $positionId)
            ->where('status', GovernanceAppointmentStatus::Active->value)
            ->exists();
    }

    /**
     * Whether a term has any active appointment.
     *
     * Used by the service layer's cross-entity validation.
     *
     * @param  int  $tenantId
     * @param  int  $termId
     * @return bool
     */
    public function existsActiveForTerm(int $tenantId, int $termId): bool
    {
        return GovernanceAppointment::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_term_id', $termId)
            ->where('status', GovernanceAppointmentStatus::Active->value)
            ->exists();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Apply the recognised filter keys to the query.
     *
     * Unknown keys are silently ignored. Empty or null values are
     * also ignored.
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

            $value = $status instanceof GovernanceAppointmentStatus
                ? $status->value
                : (string) $status;

            $query->where('status', $value);
        }

        // Appointment-mode filter — accepts an enum case or its string value.
        if (array_key_exists('appointment_mode', $filters)
            && $filters['appointment_mode'] !== null
            && $filters['appointment_mode'] !== '') {
            $mode = $filters['appointment_mode'];

            $value = $mode instanceof GovernanceAppointmentMode
                ? $mode->value
                : (string) $mode;

            $query->where('appointment_mode', $value);
        }

        // Instrument-type filter — accepts an enum case or its string value.
        if (array_key_exists('instrument_type', $filters)
            && $filters['instrument_type'] !== null
            && $filters['instrument_type'] !== '') {
            $instrumentType = $filters['instrument_type'];

            $value = $instrumentType instanceof GovernanceAppointmentInstrumentType
                ? $instrumentType->value
                : (string) $instrumentType;

            $query->where('instrument_type', $value);
        }

        // Search across reference + bilingual titles.
        if (array_key_exists('search', $filters)) {
            $term = is_string($filters['search']) ? trim($filters['search']) : '';

            if ($term !== '') {
                $this->applyIdentitySearch($query, $term);
            }
        }
    }

    /**
     * Apply an OR-chain LIKE condition across the three identity
     * columns: `instrument_reference`, `title_am`, `title_en`.
     *
     * LIKE wildcards in the user's term are escaped.
     *
     * @param  Builder $query
     * @param  string  $term
     * @return void
     */
    private function applyIdentitySearch(Builder $query, string $term): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $pattern = '%' . $escaped . '%';

        $columns = [
            'instrument_reference',
            'title_am',
            'title_en',
        ];

        $query->where(function (Builder $q) use ($columns, $pattern): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $q->{$method}($column, 'like', $pattern);
            }
        });
    }
}