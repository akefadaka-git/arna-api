<?php

declare(strict_types=1);



namespace MaaleArna\MASES\Core\Domains\Governance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAuthorityGrantService;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;

/**
 * GovernanceAppointmentPolicy
 *
 * Authorization rules for governance appointment operations.
 *
 * See the file-level change-verification block for the policy's
 * two-layer authorization model, its tenant-context resolution, its
 * deliberate omission of the membership-based grant path (deferred
 * to the memberships half of G2c), and its reasoning for reusing
 * the `governance.body.*` permission slugs.
 *
 * =============================================================================
 * Ability map
 * =============================================================================
 * The policy exposes five abilities, mapped by Laravel to the
 * following controller calls (delivered as G2c file 12 of 31):
 *
 *   viewAny(User, GovernanceBody)        ← GovernanceAppointmentController::index
 *   view(User, GovernanceAppointment)    ← GovernanceAppointmentController::show
 *   create(User, GovernanceBody)         ← GovernanceAppointmentController::store
 *   update(User, GovernanceAppointment)  ← GovernanceAppointmentController::update
 *   delete(User, GovernanceAppointment)  ← (no route in G2c; reserved for
 *                                           a future CR that adds a
 *                                           soft-delete endpoint)
 *
 * The `delete` ability is declared for symmetry with the platform's
 * standard five-ability policy shape. It is not invoked by any
 * route in G2c.
 *
 * =============================================================================
 * Grant rules
 * =============================================================================
 *   viewAny   grants  if the caller holds `governance.body.view`
 *                     in the resolved tenant, AND the body belongs
 *                     to that tenant.
 *
 *   view      grants  if the caller holds `governance.body.view`
 *                     in the resolved tenant, AND the appointment
 *                     belongs to that tenant.
 *
 *   create    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the body belongs
 *                     to that tenant.
 *
 *   update    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the appointment
 *                     belongs to that tenant.
 *
 *   delete    grants  if the caller holds `governance.body.manage`
 *                     in the resolved tenant, AND the appointment
 *                     belongs to that tenant.
 *
 * Every ability fails closed when the tenant cannot be resolved.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Policies
 * @version 1.0.0
 */
final class GovernanceAppointmentPolicy
{
    /**
     * The permission slug required for read operations on
     * appointments.
     *
     * Reused from the GovernanceBody permission set.
     */
    private const PERMISSION_VIEW = 'governance.body.view';

    /**
     * The permission slug required for write operations on
     * appointments.
     *
     * Reused from the GovernanceBody permission set.
     */
    private const PERMISSION_MANAGE = 'governance.body.manage';

    /**
     * Construct the policy with its authorization dependency.
     *
     * @param AuthorizationService $authorization
     */
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly GovernanceAuthorityGrantService $authorityGrants,
    ) {
    }

    // =========================================================================
    // viewAny — list appointments of a body
    // =========================================================================

    /**
     * Determine whether the user may list the appointments of a body.
     *
     * @param  User           $user
     * @param  GovernanceBody $body
     * @return bool
     */
    public function viewAny(User $user, GovernanceBody $body): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $body->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_VIEW,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // view — read a single appointment
    // =========================================================================

    /**
     * Determine whether the user may view a specific appointment.
     *
     * @param  User                  $user
     * @param  GovernanceAppointment $appointment
     * @return bool
     */
    public function view(User $user, GovernanceAppointment $appointment): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $appointment->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_VIEW,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // create — register a new appointment under a body
    // =========================================================================

    /**
     * Determine whether the user may create an appointment under a
     * body.
     *
     * @param  User           $user
     * @param  GovernanceBody $body
     * @return bool
     */
    public function create(User $user, GovernanceBody $body): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $body->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_MANAGE,
            user: $user,
            tenantId: $tenantId,
        ) || ($appointment->governance_position_id !== null && $this->authorityGrants->allows(
            $user, $tenantId, (int) $appointment->governance_body_id, (int) $appointment->governance_position_id, 'governance.appointment.manage',
        ));
    }

    // =========================================================================
    // update — modify an existing appointment
    // =========================================================================

    /**
     * Determine whether the user may modify a specific appointment.
     *
     * This ability governs BOTH field updates (through the service's
     * `update()` method) and lifecycle transitions (through the
     * service's `activate()`, `revoke()`, and `supersede()` methods).
     * The controller that invokes any of those methods calls this
     * ability first; the policy does not distinguish between the
     * field-level and lifecycle cases.
     *
     * A future CR that decides some lifecycle transitions warrant a
     * stricter permission than field edits would introduce a
     * separate ability — e.g. `revoke(User, GovernanceAppointment)`
     * — and the controller would call it instead of `update` for
     * that path. The current design does not anticipate that CR.
     *
     * @param  User                  $user
     * @param  GovernanceAppointment $appointment
     * @return bool
     */
    public function update(User $user, GovernanceAppointment $appointment): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $appointment->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_MANAGE,
            user: $user,
            tenantId: $tenantId,
        ) || ($appointment->governance_position_id !== null && $this->authorityGrants->allows(
            $user, $tenantId, (int) $appointment->governance_body_id, (int) $appointment->governance_position_id, 'governance.appointment.manage',
        ));
    }

    // =========================================================================
    // delete — soft-delete an appointment
    // =========================================================================

    /**
     * Determine whether the user may soft-delete a specific
     * appointment.
     *
     * No HTTP endpoint invokes this ability in G2c. The
     * `GovernanceAppointmentService` (G2c file 7 of 31) deliberately
     * does not expose a `delete()` or `softDelete()` method —
     * appointments end through `revoked` or `superseded`, not
     * through deletion. This ability is declared for symmetry with
     * the platform's standard five-ability policy shape.
     *
     * A future CR that decides an administrative-recovery scenario
     * warrants a soft-delete endpoint would add the endpoint, the
     * service method, and the `Gate::authorize('delete', ...)`
     * call in the controller. The policy's grant rule for `delete`
     * is already in place for that CR to consume.
     *
     * @param  User                  $user
     * @param  GovernanceAppointment $appointment
     * @return bool
     */
    public function delete(User $user, GovernanceAppointment $appointment): bool
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return false;
        }

        if ((int) $appointment->tenant_id !== $tenantId) {
            return false;
        }

        return $this->authorization->hasPermission(
            permissionSlug: self::PERMISSION_MANAGE,
            user: $user,
            tenantId: $tenantId,
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the tenant id from the server-side request context.
     *
     * Returns null when the helper is unavailable or no tenant is
     * resolved. Callers treat null as "fail closed".
     *
     * @return int|null
     */
    private function resolveTenantId(): ?int
    {
        if (!function_exists('mases_tenant_id')) {
            return null;
        }

        $tenantId = mases_tenant_id();

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}