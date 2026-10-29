<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Documents\Registries\DocumentTypeRegistry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceActingAssignment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAuthorityGrant;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceSeatOccupancy;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationUnit;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Resolves all issuance codes against their owning domains and enforces
 * document-type-specific authority. No caller-supplied code is authoritative.
 */
final class DocumentAuthorityService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContextService $tenantContext,
    ) {
    }

    /**
     * @return array{legal_entity_id:int, organization_unit_id:int, governance_body_id:int, governance_position_id:int, organization_code:string, department_code:string, issuing_role_code:string, document_type_code:string}
     */
    public function assertCanIssue(
        User $actor,
        int $tenantId,
        int $legalEntityId,
        string $organizationCode,
        string $departmentCode,
        string $roleCode,
        string $typeCode
    ): array {
        if ($tenantId < 1 || (int) $this->tenantContext->getTenantId() !== $tenantId) {
            throw new AuthorizationException('A matching server-validated tenant context is required.');
        }
        if (!$actor->is_active || !$this->authorization->hasPermission('documents.issue', $actor, $tenantId)) {
            throw new AuthorizationException('The actor lacks documents.issue in the validated tenant.');
        }

        $typeCode = strtoupper(trim($typeCode));
        if (!DocumentTypeRegistry::supports($typeCode)) {
            throw ValidationException::withMessages(['document_type_code' => ['The document type is not in the canonical Documents type catalogue.']]);
        }

        $entity = LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($legalEntityId)
            ->where('status', 'active')
            ->first();
        if (!$entity) {
            throw (new ModelNotFoundException())->setModel(LegalEntity::class, [$legalEntityId]);
        }

        $organizationCode = strtoupper(trim($organizationCode));
        if (!hash_equals(strtoupper((string) $entity->code), $organizationCode)) {
            throw ValidationException::withMessages(['organization_code' => ['Organization code must match the active Finance legal-entity registry.']]);
        }

        $departmentCode = strtoupper(trim($departmentCode));
        $unit = OrganizationUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entity->id)
            ->where('code', $departmentCode)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();
        if (!$unit) {
            throw ValidationException::withMessages(['department_code' => ['Department code must identify an active Organization unit belonging to the selected legal entity and tenant.']]);
        }

        $roleCode = strtoupper(trim($roleCode));
        // Governance position codes are unique per body, not tenant-wide.
        // Until a canonical body-qualified role code is part of the number
        // contract, reject ambiguous role-code matches rather than choosing
        // an arbitrary authority scope.
        $positions = GovernancePosition::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $roleCode)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->limit(2)
            ->get();
        if ($positions->count() !== 1) {
            throw ValidationException::withMessages(['issuing_role_code' => ['Issuing role code must resolve to exactly one active Governance position in this tenant; ambiguous or missing codes fail closed.']]);
        }
        $position = $positions->first();

        $body = GovernanceBody::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($position->governance_body_id)
            ->where('is_active', true)
            ->first();
        if (!$body) {
            throw ValidationException::withMessages(['issuing_role_code' => ['Issuing role must belong to an active Governance body in the validated tenant.']]);
        }

        $action = DocumentTypeRegistry::authorityAction($typeCode);
        if (!$this->hasSubstantiveOrExplicitActingAuthority($actor, $tenantId, (int) $body->id, (int) $position->id, $action)) {
            throw new AuthorizationException('No effective document-type-specific Governance authority grant or explicitly scoped acting assignment authorizes this issuance.');
        }

        return [
            'legal_entity_id' => (int) $entity->id,
            'organization_unit_id' => (int) $unit->id,
            'governance_body_id' => (int) $body->id,
            'governance_position_id' => (int) $position->id,
            'organization_code' => strtoupper((string) $entity->code),
            'department_code' => strtoupper((string) $unit->code),
            'issuing_role_code' => strtoupper((string) $position->code),
            'document_type_code' => $typeCode,
        ];
    }

    private function hasSubstantiveOrExplicitActingAuthority(
        User $actor,
        int $tenantId,
        int $bodyId,
        int $positionId,
        string $action
    ): bool {
        $today = CarbonImmutable::today()->toDateString();
        $actorPerson = Person::query()
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if (!$actorPerson) {
            return false;
        }

        $hasActiveTenantAccess = \MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess::query()
            ->where('person_id', $actorPerson->id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists();
        if (!$hasActiveTenantAccess) {
            return false;
        }

        // Direct authority requires a current membership and a matching,
        // unrevoked grant for this exact type-specific action.
        $directMembership = \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->where('governance_position_id', $positionId)
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $today)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->exists();

        if ($directMembership && $this->hasGrantForPerson($tenantId, $bodyId, $positionId, (int) $actorPerson->id, $action, $today)) {
            return true;
        }

        // Acting authority is never inherited implicitly. The acting assignment
        // must name this exact action and be effective now; the substantive
        // incumbent must separately hold the same effective type-specific grant.
        $assignments = GovernanceActingAssignment::query()
            ->where('acting_person_id', $actorPerson->id)
            ->whereNull('revoked_at')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->whereJsonContains('actions', $action)
            ->whereHas('seat', fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('governance_body_id', $bodyId)
                ->where('governance_position_id', $positionId)
                ->where('status', 'active'))
            ->with('substantiveOccupancy')
            ->get();

        foreach ($assignments as $assignment) {
            $occupancy = $assignment->substantiveOccupancy;
            if (!$occupancy || !$occupancy->isEffectiveOn($today)) {
                continue;
            }

            $incumbent = Person::query()
                ->whereKey($occupancy->person_id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->first();
            if (!$incumbent || !$incumbent->user_id) {
                continue;
            }

            $incumbentAccess = \MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess::query()
                ->where('person_id', $incumbent->id)
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->exists();
            if (!$incumbentAccess) {
                continue;
            }

            $incumbentMembership = \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership::query()
                ->whereKey($occupancy->governance_membership_id)
                ->where('tenant_id', $tenantId)
                ->where('governance_body_id', $bodyId)
                ->where('governance_position_id', $positionId)
                ->where('user_id', $incumbent->user_id)
                ->where('status', 'active')
                ->whereDate('start_date', '<=', $today)
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                ->exists();
            if (!$incumbentMembership) {
                continue;
            }

            if ($this->hasGrantForPerson($tenantId, $bodyId, $positionId, (int) $incumbent->id, $action, $today)) {
                return true;
            }
        }

        return false;
    }

    private function hasGrantForPerson(
        int $tenantId,
        int $bodyId,
        int $positionId,
        int $personId,
        string $action,
        string $today
    ): bool {
        return GovernanceAuthorityGrant::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->where('governance_position_id', $positionId)
            ->where('grantee_person_id', $personId)
            ->where('action', $action)
            ->whereNull('revoked_at')
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
            ->exists();
    }
}
