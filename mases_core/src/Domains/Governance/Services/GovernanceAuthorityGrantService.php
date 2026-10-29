<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAuthorityGrant;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;

final class GovernanceAuthorityGrantService
{
    /**
     * Explicit action catalogue for this bounded slice. Financial actions,
     * monetary limits, cross-body grants and chained delegation are excluded.
     *
     * @var list<string>
     */
    public const ACTIONS = [
        'governance.appointment.manage',
        'governance.membership.manage',
        'documents.issue.LET',
        'documents.issue.MEM',
        'documents.issue.LEG',
        'documents.issue.INV',
        'documents.issue.CER',
        'documents.issue.RES',
        'documents.issue.CIR',
        'documents.issue.RPT',
        'documents.issue.CON',
        'documents.issue.NOT',
        'documents.issue.POL',
    ];

    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {
    }

    /**
     * Create a non-self, position-scoped grant. The grantor must hold the
     * existing tenant-scoped governance.body.manage RBAC permission.
     *
     * @param array{governance_position_id:int, grantee_person_id:int, action:string, effective_from:string, effective_until?:string|null, reason:string} $attributes
     */
    public function grant(User $actor, int $tenantId, array $attributes): GovernanceAuthorityGrant
    {
        $this->assertTenantContext($tenantId);
        $this->assertCanAdminister($actor, $tenantId);

        $positionId = (int) ($attributes['governance_position_id'] ?? 0);
        $granteeId = (int) ($attributes['grantee_person_id'] ?? 0);
        $action = (string) ($attributes['action'] ?? '');
        $from = $this->date($attributes['effective_from'] ?? null, 'effective_from');
        $until = isset($attributes['effective_until']) && $attributes['effective_until'] !== ''
            ? $this->date($attributes['effective_until'], 'effective_until')
            : null;
        $reason = trim((string) ($attributes['reason'] ?? ''));

        if (!in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => ['The requested authority action is not in the approved catalogue.']]);
        }
        if ($until !== null && $until->lt($from)) {
            throw ValidationException::withMessages(['effective_until' => ['The end date must be on or after the start date.']]);
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => ['A reason of 1–500 characters is required.']]);
        }

        return DB::transaction(function () use ($actor, $tenantId, $positionId, $granteeId, $action, $from, $until, $reason): GovernanceAuthorityGrant {
            $position = GovernancePosition::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($positionId)
                ->lockForUpdate()
                ->first();

            if (!$position) {
                throw (new ModelNotFoundException())->setModel(GovernancePosition::class, [$positionId]);
            }

            $body = GovernanceBody::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($position->governance_body_id)
                ->first();

            if (!$body || !$body->is_active) {
                throw ValidationException::withMessages(['governance_position_id' => ['The position must belong to an active governance body in the validated tenant.']]);
            }

            $grantee = Person::query()->whereKey($granteeId)->where('status', 'active')->whereNull('deleted_at')->first();
            if (!$grantee || !$grantee->user_id || !User::query()->whereKey($grantee->user_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['grantee_person_id' => ['The grantee must be a canonical Person linked to an active account.']]);
            }
            if (!PersonTenantAccess::query()->where('person_id', $grantee->id)->where('tenant_id', $tenantId)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['grantee_person_id' => ['The grantee must have an active explicit Person access grant for this tenant.']]);
            }

            $grantor = Person::query()->where('user_id', $actor->getKey())->where('status', 'active')->whereNull('deleted_at')->first();
            if (!$grantor) {
                throw ValidationException::withMessages(['grantor' => ['The granting account must be linked to a canonical Person.']]);
            }
            if (!PersonTenantAccess::query()->where('person_id', $grantor->id)->where('tenant_id', $tenantId)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['grantor' => ['The grantor must have an active explicit Person access grant for this tenant.']]);
            }
            if ((int) $grantor->id === $granteeId) {
                throw ValidationException::withMessages(['grantee_person_id' => ['Self-approval and self-delegation are prohibited.']]);
            }

            $today = CarbonImmutable::today();
            $membership = GovernanceMembership::query()
                ->where('tenant_id', $tenantId)
                ->where('governance_body_id', $body->id)
                ->where('governance_position_id', $position->id)
                ->where('user_id', $grantee->user_id)
                ->where('status', 'active')
                ->whereDate('start_date', '<=', $from->toDateString())
                ->where(function ($query) use ($until): void {
                    if ($until === null) {
                        $query->whereNull('end_date');
                    } else {
                        $query->whereNull('end_date')->orWhereDate('end_date', '>=', $until->toDateString());
                    }
                })
                ->first();

            if (!$membership || $from->lt($today)) {
                throw ValidationException::withMessages(['grantee_person_id' => ['The grantee needs an active, same-tenant membership in this exact position covering the entire grant period; grants cannot be backdated.']]);
            }

            return GovernanceAuthorityGrant::query()->create([
                'tenant_id' => $tenantId,
                'governance_body_id' => $body->id,
                'governance_position_id' => $position->id,
                'grantee_person_id' => $granteeId,
                'grantor_person_id' => $grantor->id,
                'created_by_user_id' => $actor->getKey(),
                'action' => $action,
                'effective_from' => $from->toDateString(),
                'effective_until' => $until?->toDateString(),
                'reason' => $reason,
            ]);
        }, 3);
    }

    public function list(User $actor, int $tenantId)
    {
        $this->assertTenantContext($tenantId);
        $this->assertCanAdminister($actor, $tenantId);

        return GovernanceAuthorityGrant::query()
            ->where('tenant_id', $tenantId)
            ->with(['body:id,code,name_am,name_en', 'position:id,code,name_am,name_en', 'grantee:id,public_id,first_name_am,first_name_en,father_name_am,father_name_en'])
            ->orderByDesc('created_at')
            ->paginate(25);
    }

    public function revoke(User $actor, int $tenantId, int $grantId, string $reason): GovernanceAuthorityGrant
    {
        $this->assertTenantContext($tenantId);
        $this->assertCanAdminister($actor, $tenantId);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => ['A reason of 1–500 characters is required.']]);
        }

        return DB::transaction(function () use ($actor, $tenantId, $grantId, $reason): GovernanceAuthorityGrant {
            $grant = GovernanceAuthorityGrant::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($grantId)
                ->lockForUpdate()
                ->first();

            if (!$grant) {
                throw (new ModelNotFoundException())->setModel(GovernanceAuthorityGrant::class, [$grantId]);
            }
            if ($grant->revoked_at !== null) {
                throw ValidationException::withMessages(['grant' => ['The authority grant has already been revoked.']]);
            }

            $grant->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->getKey(),
                'revocation_reason' => $reason,
            ])->save();

            return $grant->refresh();
        }, 3);
    }

    /**
     * Check a grant in addition to (never instead of) route-level RBAC.
     * It requires an active canonical Person account and matching active
     * membership; the grant cannot expand tenant/body/position scope.
     */
    public function allows(User $user, int $tenantId, int $bodyId, int $positionId, string $action): bool
    {
        if (!in_array($action, self::ACTIONS, true) || $tenantId < 1 || $bodyId < 1 || $positionId < 1) {
            return false;
        }
        if ($this->resolveTenantId() !== $tenantId) {
            return false;
        }

        $person = Person::query()->where('user_id', $user->getKey())->where('status', 'active')->whereNull('deleted_at')->first();
        if (!$person || !$user->is_active) {
            return false;
        }

        $today = CarbonImmutable::today()->toDateString();

        $membership = GovernanceMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->where('governance_position_id', $positionId)
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->exists();

        if (!$membership) {
            return false;
        }

        return GovernanceAuthorityGrant::query()
            ->where('tenant_id', $tenantId)
            ->where('governance_body_id', $bodyId)
            ->where('governance_position_id', $positionId)
            ->where('grantee_person_id', $person->id)
            ->where('action', $action)
            ->whereNull('revoked_at')
            ->whereDate('effective_from', '<=', $today)
            ->where(function ($query) use ($today): void {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today);
            })
            ->exists();
    }

    private function assertCanAdminister(User $actor, int $tenantId): void
    {
        if (!$this->authorization->hasPermission('governance.body.manage', $actor, $tenantId)) {
            throw new AuthorizationException('The actor lacks governance.body.manage in the validated tenant.');
        }
    }

    private function assertTenantContext(int $tenantId): void
    {
        if ($tenantId < 1 || $this->resolveTenantId() !== $tenantId) {
            throw ValidationException::withMessages(['tenant_id' => ['A matching server-validated tenant context is required.']]);
        }
    }

    private function resolveTenantId(): ?int
    {
        if (!function_exists('mases_tenant_id')) {
            return null;
        }
        $tenantId = mases_tenant_id();
        return $tenantId === null ? null : (int) $tenantId;
    }

    private function date(mixed $value, string $field): CarbonImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ValidationException::withMessages([$field => ['Use an ISO calendar date in YYYY-MM-DD format.']]);
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = false;
        }
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => ['The date is invalid.']]);
        }
        return $date;
    }
}
