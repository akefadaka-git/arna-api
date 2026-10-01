<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 8 of 31
 * =============================================================================
 *
 * File:            Policies/GovernanceAppointmentPolicy.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (policy)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No `Gate` policy governed access to
 *   `GovernanceAppointment` rows. Any caller with a
 *   `governance.body.view` or `governance.body.manage` permission
 *   could in principle read or modify any governance appointment —
 *   including appointments belonging to a body in a tenant other
 *   than the one resolved for their request — because the
 *   permission check does not itself enforce the tenant boundary.
 *
 *   (The model's global scopes and the repository's tenant-scoped
 *   methods make the cross-tenant leak unlikely in practice, but
 *   "unlikely in practice" is not a security guarantee. The policy
 *   is the enforcement point that makes the guarantee structural.)
 *
 * After:
 *   One file that declares the domain's authorization policy for
 *   `GovernanceAppointment`. The policy enforces two layers — the
 *   RBAC permission and the tenant boundary — and nothing more. The
 *   three additional authorization layers that later phases will
 *   add (active membership of the body during the current term,
 *   position-based authority, confidentiality classification) are
 *   NOT part of this policy; they belong to policies introduced by
 *   the phases that create the entities they govern.
 *
 *   The policy exposes five abilities:
 *
 *     viewAny(User, GovernanceBody):             bool
 *         — list every appointment of a body.
 *
 *     view(User, GovernanceAppointment):         bool
 *         — read a specific appointment.
 *
 *     create(User, GovernanceBody):              bool
 *         — register a new appointment under a body.
 *
 *     update(User, GovernanceAppointment):       bool
 *         — modify a specific appointment (including its lifecycle
 *           transitions through the service's activate / revoke /
 *           supersede methods).
 *
 *     delete(User, GovernanceAppointment):       bool
 *         — soft-delete a specific appointment.
 *
 *   Every ability grants through `AuthorizationService::hasPermission()`,
 *   fails closed on missing tenant context, and enforces the tenant
 *   boundary on every model-bearing argument. The grant rules are:
 *
 *     viewAny  →  `governance.body.view`   in the resolved tenant
 *                    AND  `$body->tenant_id === $tenantId`
 *
 *     view     →  `governance.body.view`   in the resolved tenant
 *                    AND  `$appointment->tenant_id === $tenantId`
 *
 *     create   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$body->tenant_id === $tenantId`
 *
 *     update   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$appointment->tenant_id === $tenantId`
 *
 *     delete   →  `governance.body.manage` in the resolved tenant
 *                    AND  `$appointment->tenant_id === $tenantId`
 *
 *   What the policy deliberately does NOT do:
 *
 *     · It does NOT reference any role name. Authorization is
 *       permission-based, not role-based.
 *
 *     · It does NOT delegate to a sibling policy. An appointment
 *       has no "own record" concept — no user "owns" an appointment
 *       the way a shareholder owns their own record. The policy has
 *       one grant rule per ability.
 *
 *     · It does NOT walk the membership table. Whether a user is an
 *       active member of a body during a specific term is a decision
 *       that depends on `GovernanceMembership` — which is delivered
 *       later in this unit (files 16–28 of 31). A future CR may
 *       extend `view` (and possibly `update`) to grant via an
 *       active membership in addition to the RBAC permission. The
 *       extension will be additive.
 *
 *     · It does NOT enforce confidentiality. Confidentiality is a
 *       property of governance RECORDS (minutes, resolutions,
 *       declarations), not of appointments. An appointment's
 *       existence, instrument type, and title are not confidential.
 *
 *     · It does NOT distinguish between read operations that show
 *       the appointment's lifecycle status and those that show its
 *       history. The `view` ability is binary; a caller either sees
 *       the appointment's full detail or sees nothing. A future CR
 *       that needs a read-trimmed view of appointments (e.g., a
 *       "list" version that omits the instrument reference) would
 *       introduce a second ability or a scope on the resource —
 *       not a change to this policy's grant rules.
 *
 *     · It does NOT throw. It returns `bool` on every ability; the
 *       controller is responsible for calling `Gate::authorize()`,
 *       which converts a `false` return into an
 *       `AuthorizationException` that the canonical renderer maps
 *       to a 403 `AUTH_PERMISSION_DENIED` response.
 *
 * =============================================================================
 * Why `create()` and `viewAny()` take a GovernanceBody, not a
 * GovernanceAppointment
 * =============================================================================
 * Two abilities operate on a collection or on a yet-to-be-created
 * row, not on an existing appointment:
 *
 *   `viewAny(User, GovernanceBody)` — the caller is asking to see
 *   the list of appointments for a specific body. There is no
 *   appointment instance to authorize against; the body is the
 *   relevant scope.
 *
 *   `create(User, GovernanceBody)` — the caller is asking to create
 *   a new appointment under a specific body. The body is the
 *   authorization scope; the not-yet-existing appointment has no
 *   tenant_id of its own.
 *
 * Laravel resolves the second argument of these abilities from the
 * route's model binding (the `{governanceBody}` parameter) or from
 * the controller's explicit call. The two abilities check the
 * body's tenant, not the appointment's.
 *
 * The three model-bearing abilities (`view`, `update`, `delete`)
 * take a `GovernanceAppointment` and check its `tenant_id` directly.
 *
 * =============================================================================
 * Two-layer authorization model
 * =============================================================================
 * For a model-bearing ability (`view`, `update`, `delete`), the
 * policy enforces TWO layers, in this order:
 *
 *   Layer 1 — the tenant boundary.
 *       The appointment's `tenant_id` must equal the tenant
 *       resolved from the server-side request context. A mismatch
 *       fails immediately, before any permission is consulted.
 *
 *   Layer 2 — the RBAC permission.
 *       The caller must hold the required permission in the
 *       resolved tenant.
 *
 * For a body-scoped ability (`viewAny`, `create`), the same two
 * layers apply, but Layer 1 checks the BODY's `tenant_id` instead
 * of the appointment's.
 *
 * =============================================================================
 * The permission slugs
 * =============================================================================
 * As approved in the G2c manifest, appointments reuse the two
 * `governance.body.*` slugs seeded by G1's
 * `GovernancePermissionsSeeder`:
 *
 *   Read operations  →  `governance.body.view`
 *   Write operations →  `governance.body.manage`
 *
 * No new slugs are introduced. An appointment is an aspect of a
 * body's structure and staffing; a caller authorized to read or
 * manage a body's structure is authorized to read or manage its
 * appointments. The `governance.member.*` slugs reserved by G1
 * remain for the memberships that will arrive later in this unit —
 * memberships are the entities that represent "who holds the seat"
 * (a membership-class fact), while appointments represent "the
 * instrument that put them there" (a body-structural fact).
 *
 * A future CR that decides appointments should have their own slugs
 * (e.g. because a role should be able to view the body's structure
 * without seeing sensitive instrument references) would amend the
 * seeder, the roles seeder, and this policy together.
 *
 * =============================================================================
 * Tenant-context resolution
 * =============================================================================
 * The tenant id is resolved from `mases_tenant_id()` — the same
 * server-side helper every policy in the platform uses.
 *
 * When the helper is unavailable or returns null, `resolveTenantId()`
 * returns null, and every ability fails closed by returning `false`.
 *
 * =============================================================================
 * No database queries beyond the injected service
 * =============================================================================
 * The policy performs no direct database queries. Permission
 * lookups go through `AuthorizationService`, which caches results.
 * The tenant-boundary checks are integer comparisons against
 * already-loaded model attributes.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Policies/GovernanceAppointmentPolicy.php
 *
 * The directory already exists.
 *
 * The policy is registered with the `Gate` facade by the amended
 * `GovernanceServiceProvider` (G2c file 30 of 31). Until that
 * amendment is deployed, this policy file is inert.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Abilities declared:        5 (viewAny, view, create, update, delete)
 * Services injected:         1 (AuthorizationService)
 * Direct database queries:   none
 * Role references:           none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
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
        );
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
        );
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