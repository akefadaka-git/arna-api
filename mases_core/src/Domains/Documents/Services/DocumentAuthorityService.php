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
use MaaleArna\MASES\Core\Domains\Organization\Services\OrganizationNumberingRegistryService;
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
        private readonly OrganizationNumberingRegistryService $numberingRegistry,
    ) {
    }

    /**
     * Resolve BB through Organization and enforce Governance authority separately.
     *
     * @return array{legal_entity_id:int, organization_unit_id:int, governance_body_id:int, governance_position_id:int, organization_code:string, bb_code:string, branch_code:string, department_code:string, issuing_role_code:string, document_type_code:string}
     */
    public function assertCanIssue(
        User $actor,
        int $tenantId,
        int $legalEntityId,
        string $bbCode,
        string $roleCode,
        string $typeCode,
        bool $lockRows = false
    ): array {
        if ($tenantId < 1 || (int) $this->tenantContext->getTenantId() !== $tenantId) {
            throw new AuthorizationException('A matching server-validated tenant context is required.');
        }

        $authorityActor = $lockRows
            ? User::query()->whereKey($actor->getKey())->lockForUpdate()->first()
            : $actor;
        if (!$authorityActor || !$authorityActor->is_active
            || !$this->authorization->hasPermission('documents.issue', $authorityActor, $tenantId)) {
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
            ->when($lockRows, fn ($query) => $query->lockForUpdate())
            ->first();
        if (!$entity) {
            throw (new ModelNotFoundException())->setModel(LegalEntity::class, [$legalEntityId]);
        }

        $bb = $this->numberingRegistry->resolveForIssuance($tenantId, $legalEntityId, trim($bbCode), $lockRows);
        $roleCode = strtoupper(trim($roleCode));
        $positions = GovernancePosition::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $roleCode)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->when($lockRows, fn ($query) => $query->lockForUpdate())
            ->limit(2)
            ->get();
        if ($positions->count() !== 1) {
            throw ValidationException::withMessages(['issuing_role_code' => ['Issuing role code must resolve to exactly one active Governance position in this tenant.']]);
        }
        $position = $positions->first();

        $body = GovernanceBody::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($position->governance_body_id)
            ->where('is_active', true)
            ->when($lockRows, fn ($query) => $query->lockForUpdate())
            ->first();
        if (!$body) {
            throw ValidationException::withMessages(['issuing_role_code' => ['Issuing role must belong to an active Governance body in this tenant.']]);
        }

        $action = DocumentTypeRegistry::authorityAction($typeCode);
        if (!$this->hasSubstantiveOrExplicitActingAuthority(
            $actor, $tenantId, (int) $body->id, (int) $position->id, $action, $lockRows
        )) {
            throw new AuthorizationException('No effective document-type-specific Governance authority grant or explicitly scoped acting assignment authorizes this issuance.');
        }

        return [
            'legal_entity_id' => (int) $entity->id,
            'organization_unit_id' => $bb['organization_unit_id'],
            'governance_body_id' => (int) $body->id,
            'governance_position_id' => (int) $position->id,
            'organization_code' => strtoupper((string) $entity->code),
            'bb_code' => $bb['bb_code'],
            'branch_code' => $bb['branch_code'],
            'department_code' => $bb['department_code'],
            'issuing_role_code' => strtoupper((string) $position->code),
            'document_type_code' => $typeCode,
        ];
    }

    private function hasSubstantiveOrExplicitActingAuthority(
        User $actor,
        int $tenantId,
        int $bodyId,
        int $positionId,
        string $action,
        bool $lockRows = false
    ): bool {
        $today = CarbonImmutable::today()->toDateString();
        $actorPerson = Person::query()
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->when($lockRows, fn ($query) => $query->lockForUpdate())
            ->first();

        if (!$actorPerson) {
            return false;
        }

        $hasActiveTenantAccess = \MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess::query()
            ->where('person_id', $actorPerson->id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->when($lockRows, fn ($query) => $query->lockForUpdate())
            ->first();
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
            ->when($lockRows, fn ($query) => $query->lockForUpdate())
            ->first() !== null;

        if ($directMembership && $this->hasGrantForPerson($tenantId, $bodyId, $positionId, (int) $actorPerson->id, $action, $today, $lockRows)) {
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
            ->with([
                'seat' => fn ($query) => $query->when($lockRows, fn ($locked) => $locked->lockForUpdate()),
                'substantiveOccupancy' => fn ($query) => $query->when($lockRows, fn ($locked) => $locked->lockForUpdate()),
            ])
            ->when($lockRows, fn ($query) => $query->lockForUpdate())
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
                ->when($lockRows, fn ($query) => $query->lockForUpdate())
                ->first();
            if (!$incumbent || !$incumbent->user_id) {
                continue;
            }

            $incumbentAccess = \MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess::query()
                ->where('person_id', $incumbent->id)
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->when($lockRows, fn ($query) => $query->lockForUpdate())
                ->first();
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
                ->when($lockRows, fn ($query) => $query->lockForUpdate())
                ->first() !== null;
            if (!$incumbentMembership) {
                continue;
            }

            if ($this->hasGrantForPerson($tenantId, $bodyId, $positionId, (int) $incumbent->id, $action, $today, $lockRows)) {
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
        string $today,
        bool $lockRows = false
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
            ->when($lockRows, fn ($query) => $query->lockForUpdate())
            ->first() !== null;
    }
}
