<?php

declare(strict_types=1);

// Centralized tenant-scoped appointment queries.


namespace MaaleArna\Arna\Core\Domains\Governance\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MaaleArna\Arna\Core\Domains\Governance\Enums\GovernanceAppointmentInstrumentType;
use MaaleArna\Arna\Core\Domains\Governance\Enums\GovernanceAppointmentMode;
use MaaleArna\Arna\Core\Domains\Governance\Enums\GovernanceAppointmentStatus;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceAppointment;

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
 * @package MaaleArna\Arna\Core\Domains\Governance\Repositories
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