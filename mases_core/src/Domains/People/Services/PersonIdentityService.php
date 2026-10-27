<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Security\Services\SecurityEventService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class PersonIdentityService
{
    public function __construct(
        private readonly SecurityEventService $securityEvents,
    ) {
    }

    public function create(int $tenantId, User $actor, array $attributes): Person
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);

        return DB::transaction(function () use ($tenantId, $actor, $attributes): Person {
            $person = new Person();
            $person->fill([
                'first_name_am' => $attributes['first_name_am'] ?? null,
                'father_name_am' => $attributes['father_name_am'] ?? null,
                'grandfather_name_am' => $attributes['grandfather_name_am'] ?? null,
                'first_name_en' => $attributes['first_name_en'] ?? null,
                'father_name_en' => $attributes['father_name_en'] ?? null,
                'grandfather_name_en' => $attributes['grandfather_name_en'] ?? null,
                'status' => 'active',
            ]);
            if (collect($person->only([
                'first_name_am', 'father_name_am', 'grandfather_name_am',
                'first_name_en', 'father_name_en', 'grandfather_name_en',
            ]))->filter(fn ($value) => is_string($value) && trim($value) !== '')->isEmpty()) {
                throw ValidationException::withMessages([
                    'name' => 'At least one Amharic or English name component is required.',
                ]);
            }
            $person->save();
            DB::table('person_tenant_accesses')->insert([
                'person_id' => $person->getKey(),
                'tenant_id' => $tenantId,
                'created_by' => $actor->getKey(),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->recordEvent('person.created', $tenantId, $actor, $person, null);
            return $person;
        });
    }

    public function linkAccount(int $tenantId, int $personId, int $userId, User $actor, string $reason): Person
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertGlobalAccountLinkPermission($actor);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for account linking.']);
        }

        return DB::transaction(function () use ($tenantId, $personId, $userId, $actor, $reason): Person {
            $person = Person::query()->whereKey($personId)->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            $user = User::query()->whereKey($userId)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $this->assertTenantAccess($tenantId, $user);

            if ($person->status !== 'active' || $person->trashed()) {
                throw new DomainException('Only an active Person can be linked to an account.');
            }
            if ($person->user_id !== null && (int) $person->user_id !== $userId) {
                throw new DomainException('Person already has a current account association.');
            }
            $otherPerson = Person::query()->where('user_id', $userId)->where('id', '<>', $personId)->lockForUpdate()->first();
            if ($otherPerson !== null) {
                throw new DomainException('Account is already associated with another Person.');
            }
            if ($person->user_id === $userId) {
                return $person;
            }

            $person->user_id = $userId;
            $person->save();
            $this->recordEvent('person.account_linked', $tenantId, $actor, $person, [
                'linked_user_id' => $userId,
                'reason' => $reason,
            ]);
            return $person->refresh();
        });
    }

    public function unlinkAccount(int $tenantId, int $personId, User $actor, string $reason): Person
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertGlobalAccountLinkPermission($actor);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for account unlinking.']);
        }

        return DB::transaction(function () use ($tenantId, $personId, $actor, $reason): Person {
            $person = Person::query()->whereKey($personId)->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            if ($person->user_id === null) {
                return $person;
            }
            $previousUserId = (int) $person->user_id;
            $person->user_id = null;
            $person->save();
            $this->recordEvent('person.account_unlinked', $tenantId, $actor, $person, [
                'previous_user_id' => $previousUserId,
                'reason' => $reason,
            ]);
            return $person->refresh();
        });
    }

    public function revokeTenantAccess(int $tenantId, int $personId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertGlobalTenantAccessPermission($actor);
        $this->assertReason($reason);

        DB::transaction(function () use ($tenantId, $personId, $actor, $reason): void {
            $person = Person::query()->whereKey($personId)->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);

            $linked = DB::table('employees')->where('tenant_id', $tenantId)->where('person_id', $personId)->exists()
                || DB::table('shareholders')->where('tenant_id', $tenantId)->where('person_id', $personId)->exists()
                || DB::table('governance_memberships')->where('tenant_id', $tenantId)->where('person_id', $personId)->exists();

            if ($linked) {
                throw new DomainException('Unlink tenant domain records before revoking Person visibility.');
            }

            DB::table('person_tenant_accesses')->where('tenant_id', $tenantId)->where('person_id', $personId)
                ->update(['status' => 'revoked', 'updated_at' => now()]);
            $this->recordEvent('person.tenant_access_revoked', $tenantId, $actor, $person, ['reason' => $reason]);
        });
    }

    public function linkShareholder(int $tenantId, int $shareholderId, int $personId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);
        DB::transaction(function () use ($tenantId, $shareholderId, $personId, $actor, $reason): void {
            $holder = DB::table('shareholders')->where('tenant_id', $tenantId)->where('id', $shareholderId)->lockForUpdate()->first();
            if ($holder === null) {
                throw new DomainException('Shareholder was not found in the authorized tenant.');
            }
            if (($holder->shareholder_type ?? null) !== 'individual') {
                throw new DomainException('Legal-entity shareholders cannot link to a natural-person profile.');
            }
            $person = Person::query()->whereKey($personId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            $this->assertDomainLinkAvailable($holder->person_id ?? null, $personId);
            $this->assertDomainUserConsistency($person, $holder->user_id ?? null);
            DB::table('shareholders')->where('id', $shareholderId)->where('tenant_id', $tenantId)->update(['person_id' => $personId, 'updated_at' => now()]);
            $this->recordEvent('person.shareholder_linked', $tenantId, $actor, $person, ['shareholder_id' => $shareholderId, 'reason' => $reason]);
        });
    }

    public function linkEmployee(int $tenantId, int $employeeId, int $personId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);
        DB::transaction(function () use ($tenantId, $employeeId, $personId, $actor, $reason): void {
            $employee = DB::table('employees')->where('tenant_id', $tenantId)->where('id', $employeeId)->whereNull('deleted_at')->lockForUpdate()->first();
            if ($employee === null) {
                throw new DomainException('Employee was not found in the authorized tenant.');
            }
            $person = Person::query()->whereKey($personId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            $this->assertDomainLinkAvailable($employee->person_id ?? null, $personId);
            $this->assertDomainUserConsistency($person, $employee->user_id ?? null);
            DB::table('employees')->where('id', $employeeId)->where('tenant_id', $tenantId)->update(['person_id' => $personId, 'updated_at' => now()]);
            $this->recordEvent('person.employee_linked', $tenantId, $actor, $person, ['employee_id' => $employeeId, 'reason' => $reason]);
        });
    }

    public function linkGovernanceMembership(int $tenantId, int $membershipId, int $personId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);
        DB::transaction(function () use ($tenantId, $membershipId, $personId, $actor, $reason): void {
            $membership = DB::table('governance_memberships')->where('tenant_id', $tenantId)->where('id', $membershipId)->lockForUpdate()->first();
            if ($membership === null) {
                throw new DomainException('Governance membership was not found in the authorized tenant.');
            }
            $person = Person::query()->whereKey($personId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            $this->assertDomainLinkAvailable($membership->person_id ?? null, $personId);
            $this->assertDomainUserConsistency($person, $membership->user_id ?? null);
            DB::table('governance_memberships')->where('id', $membershipId)->where('tenant_id', $tenantId)->update(['person_id' => $personId, 'updated_at' => now()]);
            $this->recordEvent('person.governance_membership_linked', $tenantId, $actor, $person, ['membership_id' => $membershipId, 'reason' => $reason]);
        });
    }

    public function grantTenantAccess(int $tenantId, int $personId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertGlobalTenantAccessPermission($actor);
        $this->assertReason($reason);

        DB::transaction(function () use ($tenantId, $personId, $actor, $reason): void {
            $person = Person::query()->whereKey($personId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            DB::table('person_tenant_accesses')->updateOrInsert(
                ['person_id' => $personId, 'tenant_id' => $tenantId],
                ['created_by' => $actor->getKey(), 'status' => 'active', 'updated_at' => now(), 'created_at' => now()],
            );
            $this->recordEvent('person.tenant_access_granted', $tenantId, $actor, $person, ['reason' => $reason]);
        });
    }

    public function updateProfile(int $tenantId, string $publicId, User $actor, array $attributes, string $reason): Person
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);

        return DB::transaction(function () use ($tenantId, $publicId, $actor, $attributes, $reason): Person {
            $person = Person::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);

            if ($person->status !== 'active' || $person->trashed()) {
                throw new DomainException('Only an active Person profile can be updated.');
            }

            $fields = [
                'first_name_am', 'father_name_am', 'grandfather_name_am',
                'first_name_en', 'father_name_en', 'grandfather_name_en',
            ];
            $before = $person->only($fields);
            foreach ($fields as $field) {
                if (array_key_exists($field, $attributes)) {
                    $person->{$field} = $attributes[$field];
                }
            }
            if (collect($person->only($fields))->filter(
                fn ($value) => is_string($value) && trim($value) !== ''
            )->isEmpty()) {
                throw ValidationException::withMessages([
                    'name' => 'At least one Amharic or English name component is required.',
                ]);
            }
            $person->save();
            $this->recordEvent('person.profile_updated', $tenantId, $actor, $person, [
                'before' => $before,
                'after' => $person->only($fields),
                'reason' => $reason,
            ]);

            return $person->refresh();
        });
    }

    private function assertPersonTenantAccess(int $tenantId, Person $person): void
    {
        $allowed = DB::table('person_tenant_accesses')
            ->where('tenant_id', $tenantId)
            ->where('person_id', $person->getKey())
            ->where('status', 'active')
            ->exists();

        if (! $allowed) {
            throw new DomainException('Person profile is not visible in the validated tenant context.');
        }
    }

    private function assertGlobalTenantAccessPermission(User $actor): void
    {
        $direct = $actor->permissions()
            ->where('slug', 'people.tenant-access.manage')
            ->where('scope', 'global')
            ->where('is_active', true)
            ->exists();

        $viaRole = $actor->roles()
            ->whereNull('tenant_id')
            ->whereHas('permissions', function ($query): void {
                $query->where('slug', 'people.tenant-access.manage')
                    ->where('scope', 'global')
                    ->where('is_active', true);
            })
            ->exists();

        if (! $direct && ! $viaRole) {
            throw new DomainException('The global people.tenant-access.manage permission is required.');
        }
    }

    public function unlinkShareholder(int $tenantId, int $shareholderId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);

        DB::transaction(function () use ($tenantId, $shareholderId, $actor, $reason): void {
            $holder = DB::table('shareholders')->where('tenant_id', $tenantId)->where('id', $shareholderId)->lockForUpdate()->first();
            if ($holder === null) {
                throw new DomainException('Shareholder was not found in the authorized tenant.');
            }
            if ($holder->person_id === null) {
                return;
            }
            $person = Person::query()->whereKey((int) $holder->person_id)->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            DB::table('shareholders')->where('tenant_id', $tenantId)->where('id', $shareholderId)
                ->update(['person_id' => null, 'updated_at' => now()]);
            $this->recordEvent('person.shareholder_unlinked', $tenantId, $actor, $person, [
                'shareholder_id' => $shareholderId,
                'reason' => $reason,
            ]);
        });
    }

    public function unlinkEmployee(int $tenantId, int $employeeId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);

        DB::transaction(function () use ($tenantId, $employeeId, $actor, $reason): void {
            $employee = DB::table('employees')->where('tenant_id', $tenantId)->where('id', $employeeId)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            if ($employee === null) {
                throw new DomainException('Employee was not found in the authorized tenant.');
            }
            if ($employee->person_id === null) {
                return;
            }
            $person = Person::query()->whereKey((int) $employee->person_id)->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            DB::table('employees')->where('tenant_id', $tenantId)->where('id', $employeeId)
                ->update(['person_id' => null, 'updated_at' => now()]);
            $this->recordEvent('person.employee_unlinked', $tenantId, $actor, $person, [
                'employee_id' => $employeeId,
                'reason' => $reason,
            ]);
        });
    }

    public function unlinkGovernanceMembership(int $tenantId, int $membershipId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);

        DB::transaction(function () use ($tenantId, $membershipId, $actor, $reason): void {
            $membership = DB::table('governance_memberships')->where('tenant_id', $tenantId)
                ->where('id', $membershipId)->lockForUpdate()->first();
            if ($membership === null) {
                throw new DomainException('Governance membership was not found in the authorized tenant.');
            }
            if ($membership->person_id === null) {
                return;
            }
            $person = Person::query()->whereKey((int) $membership->person_id)->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            DB::table('governance_memberships')->where('tenant_id', $tenantId)->where('id', $membershipId)
                ->update(['person_id' => null, 'updated_at' => now()]);
            $this->recordEvent('person.governance_membership_unlinked', $tenantId, $actor, $person, [
                'membership_id' => $membershipId,
                'reason' => $reason,
            ]);
        });
    }

    private function assertManagePermission(int $tenantId, User $actor): void
    {
        // Direct user grants have no tenant dimension in the current pivot.
        // Accept them only for a genuinely global permission; tenant-scoped
        // people.manage must flow through a role scoped to this tenant.
        $direct = $actor->permissions()
            ->where('slug', 'people.manage')
            ->where('scope', 'global')
            ->whereNull('permissions.tenant_id')
            ->where('is_active', true)
            ->exists();

        $viaRole = $actor->roles()
            ->where('tenant_id', $tenantId)
            ->whereHas('permissions', function ($query) use ($tenantId): void {
                // Permission definitions are shared catalogue rows with a
                // null tenant_id; the assigned Role carries the tenant scope.
                $query->where('slug', 'people.manage')
                    ->whereIn('scope', ['global', 'tenant'])
                    ->whereNull('permissions.tenant_id')
                    ->where('is_active', true);
            })
            ->exists();

        if (! $direct && ! $viaRole) {
            throw new DomainException('The tenant-scoped people.manage permission is required for identity operations.');
        }
    }

    private function assertGlobalAccountLinkPermission(User $actor): void
    {
        $direct = $actor->permissions()
            ->where('slug', 'people.account-link.manage')
            ->where('scope', 'global')
            ->where('is_active', true)
            ->exists();

        $viaRole = $actor->roles()
            ->whereNull('tenant_id')
            ->whereHas('permissions', function ($query): void {
                $query->where('slug', 'people.account-link.manage')
                    ->where('scope', 'global')
                    ->where('is_active', true);
            })
            ->exists();

        if (! $direct && ! $viaRole) {
            throw new DomainException('The global people.account-link.manage permission is required for account linking changes.');
        }
    }

    private function assertTenantAccess(int $tenantId, User $user): void
    {
        $activeTenant = Tenant::query()->whereKey($tenantId)->where('is_active', true)->exists();
        $membership = DB::table('tenant_memberships')->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())->where('status', 'active')->exists();
        if (! $activeTenant || ! $user->is_active || ! $membership) {
            throw new DomainException('A valid active tenant membership is required.');
        }
    }

    private function assertDomainLinkAvailable(mixed $currentPersonId, int $targetPersonId): void
    {
        if ($currentPersonId !== null && (int) $currentPersonId !== $targetPersonId) {
            throw new DomainException('The domain record is already linked to another Person; unlink it through an audited lifecycle operation first.');
        }
    }

    private function assertDomainUserConsistency(Person $person, mixed $domainUserId): void
    {
        if ($domainUserId !== null && ($person->user_id === null || (int) $person->user_id !== (int) $domainUserId)) {
            throw new DomainException('The Person must be explicitly linked to the same User as the domain record before association.');
        }
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for identity linkage.']);
        }
    }

    private function recordEvent(string $eventType, int $tenantId, User $actor, Person $person, ?array $context): void
    {
        $event = $this->securityEvents->log($eventType, array_merge([
            'tenant_id' => $tenantId,
            'user_id' => $actor->getKey(),
            'actor_user_id' => $actor->getKey(),
            'person_id' => $person->getKey(),
            'person_public_id' => $person->public_id,
        ], $context ?? []), 'info', 'Person identity lifecycle event.');
        if ($event === null) {
            throw new \RuntimeException('Mandatory identity audit event could not be persisted; transaction rolled back.');
        }
    }
}
