<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Exceptions\FileException;
use MaaleArna\MASES\Core\Files\Models\File;
use MaaleArna\MASES\Core\Files\Services\FileService;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Security\Services\SecurityEventService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

final class PersonIdentityService
{
    public function __construct(
        private readonly SecurityEventService $securityEvents,
        private readonly FileService $files,
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
            $this->assertAccountCompatibleWithLinkedDomains($person, $userId);
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
            $this->assertAccountCanBeUnlinked($person);
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
            // Use the same domain-record-before-Person lock direction as the
            // link/unlink lifecycle methods. Lock in a fixed table order and
            // only then lock the Person, avoiding the previous reverse-order
            // cycle (Person -> domain row) against concurrent identity links.
            $employees = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->where('person_id', $personId)
                ->lockForUpdate()
                ->get();
            $shareholders = DB::table('shareholders')
                ->where('tenant_id', $tenantId)
                ->where('person_id', $personId)
                ->lockForUpdate()
                ->get();
            $memberships = DB::table('governance_memberships')
                ->where('tenant_id', $tenantId)
                ->where('person_id', $personId)
                ->lockForUpdate()
                ->get();

            $person = Person::query()->whereKey($personId)->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);

            if ($employees->isNotEmpty() || $shareholders->isNotEmpty() || $memberships->isNotEmpty()) {
                throw new DomainException('Unlink tenant domain records before revoking Person visibility.');
            }

            DB::table('person_tenant_accesses')->where('tenant_id', $tenantId)->where('person_id', $personId)
                ->where('status', 'active')
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
            $holder = DB::table('shareholders')->where('tenant_id', $tenantId)->where('id', $shareholderId)
                ->whereNull('deleted_at')->where('is_active', true)->lockForUpdate()->first();
            if ($holder === null) {
                throw new DomainException('Shareholder was not found in the authorized tenant.');
            }
            if (($holder->shareholder_type ?? null) !== 'individual') {
                throw new DomainException('Legal-entity shareholders cannot link to a natural-person profile.');
            }
            if (($holder->user_id ?? null) === null) {
                throw new DomainException('An individual shareholder must have its required direct user account before Person linking.');
            }
            $person = Person::query()->whereKey($personId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            $this->assertDomainLinkAvailable($holder->person_id ?? null, $personId);
            $this->assertDomainUserConsistency($person, $holder->user_id ?? null);
            $otherShareholder = DB::table('shareholders')->where('tenant_id', $tenantId)
                ->where('person_id', $personId)->where('id', '<>', $shareholderId)->whereNull('deleted_at')->first();
            if ($otherShareholder !== null) {
                throw new DomainException('Person is already associated with an individual shareholder record in this tenant.');
            }
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
            $employee = DB::table('employees')->where('tenant_id', $tenantId)->where('id', $employeeId)
                ->whereNull('deleted_at')->where('is_active', true)->lockForUpdate()->first();
            if ($employee === null) {
                throw new DomainException('Employee was not found in the authorized tenant.');
            }
            $person = Person::query()->whereKey($personId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            $this->assertDomainLinkAvailable($employee->person_id ?? null, $personId);
            $this->assertDomainUserConsistency($person, $employee->user_id ?? null);
            if ((bool) ($employee->is_active ?? false)) {
                $otherActiveEmployee = DB::table('employees')->where('tenant_id', $tenantId)
                    ->where('person_id', $personId)->where('is_active', true)->whereNull('deleted_at')
                    ->where('id', '<>', $employeeId)->exists();
                if ($otherActiveEmployee) {
                    throw new DomainException('Person already has an active employee record in this tenant.');
                }
            }
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
            // Match GovernanceMembershipService lock ordering: position first,
            // then membership, then Person. This serializes identity-link overlap
            // checks with concurrent membership registration/update operations.
            $reference = DB::table('governance_memberships')->where('tenant_id', $tenantId)
                ->where('id', $membershipId)->whereNull('deleted_at')->where('status', 'active')->first();
            if ($reference === null) {
                throw new DomainException('Governance membership was not found in the authorized tenant.');
            }
            $position = DB::table('governance_positions')->where('tenant_id', $tenantId)
                ->where('id', $reference->governance_position_id)->lockForUpdate()->first();
            if ($position === null
                || (int) $position->governance_body_id !== (int) $reference->governance_body_id) {
                throw new DomainException('Governance position and membership must belong to the same authorized body and tenant.');
            }
            $membership = DB::table('governance_memberships')->where('tenant_id', $tenantId)
                ->where('id', $membershipId)->whereNull('deleted_at')->where('status', 'active')->lockForUpdate()->first();
            if ($membership === null
                || (int) $membership->governance_position_id !== (int) $reference->governance_position_id
                || (int) $membership->governance_body_id !== (int) $reference->governance_body_id) {
                throw new DomainException('Governance membership changed while its identity link was being validated.');
            }
            $person = Person::query()->whereKey($personId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            $this->assertDomainLinkAvailable($membership->person_id ?? null, $personId);
            $this->assertDomainUserConsistency($person, $membership->user_id ?? null);

            if (in_array($membership->status, ['active', 'suspended'], true)) {
                $overlap = DB::table('governance_memberships')->where('tenant_id', $tenantId)
                    ->where('governance_body_id', $membership->governance_body_id)
                    ->where('governance_position_id', $membership->governance_position_id)
                    ->where('person_id', $personId)
                    ->whereIn('status', ['active', 'suspended'])->whereNull('deleted_at')
                    ->where('id', '<>', $membershipId)
                    ->where('start_date', '<=', $membership->end_date ?? '9999-12-31')
                    ->where(function ($query) use ($membership): void {
                        $query->whereNull('end_date')->orWhere('end_date', '>=', $membership->start_date);
                    })
                    // This is a current/locking read: a plain exists() can use a
                    // REPEATABLE READ snapshot taken before waiting on the position lock.
                    ->lockForUpdate()->first() !== null;
                if ($overlap) {
                    throw new DomainException('Person already has an overlapping ongoing membership in this governance position.');
                }
            }

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
            // Locking the Person serializes grants for this identity. Preserve
            // the original grant provenance when reactivating a revoked row:
            // created_by and created_at describe the first grant, not the latest
            // operator. The unique (person_id, tenant_id) key is the final guard.
            $access = DB::table('person_tenant_accesses')
                ->where('person_id', $personId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($access === null) {
                DB::table('person_tenant_accesses')->insert([
                    'person_id' => $personId,
                    'tenant_id' => $tenantId,
                    'created_by' => $actor->getKey(),
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($access->status !== 'active') {
                DB::table('person_tenant_accesses')
                    ->where('person_id', $personId)
                    ->where('tenant_id', $tenantId)
                    ->update(['status' => 'active', 'updated_at' => now()]);
            }

            $this->recordEvent('person.tenant_access_granted', $tenantId, $actor, $person, [
                'reason' => $reason,
                'reactivated' => $access !== null && $access->status !== 'active',
            ]);
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

    /**
     * List only documents that the caller can also view through FileService.
     * Storage paths, disk names, and ownership identifiers are never returned.
     *
     * @return list<array<string, int|string|null>>
     */
    public function listDocuments(int $tenantId, int $personId, User $actor): array
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertSensitiveReadPermission($tenantId, $actor);
        $person = Person::query()->whereKey($personId)->firstOrFail();
        $this->assertPersonTenantAccess($tenantId, $person);

        $links = DB::table('person_document_links')
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->orderByDesc('created_at')
            ->get();

        $documents = [];
        foreach ($links as $link) {
            try {
                $file = $this->files->find((int) $link->file_id);
            } catch (FileException) {
                // Do not reveal inaccessible file metadata or even its existence.
                continue;
            }
            if ($file === null || (int) $file->tenant_id !== $tenantId || $file->trashed()) {
                continue;
            }

            $documents[] = [
                'file_id' => (int) $file->getKey(),
                'display_name_am' => $file->display_name_am,
                'display_name_en' => $file->display_name_en,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size' => (int) $file->size,
                'version' => (int) $file->version,
                'status' => $file->status,
                'linked_at' => (string) $link->created_at,
            ];
        }

        return $documents;
    }

    public function linkDocument(int $tenantId, int $personId, int $fileId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);

        // FileService is the authoritative file-level permission boundary.
        $authorizedFile = $this->files->find($fileId);
        if ($authorizedFile === null || (int) $authorizedFile->tenant_id !== $tenantId || $authorizedFile->trashed()) {
            throw new DomainException('The file is unavailable in the authorized tenant.');
        }
        if ($authorizedFile->is_public) {
            throw new DomainException('Public files cannot be linked to a Person record; make the file private first.');
        }

        DB::transaction(function () use ($tenantId, $personId, $fileId, $actor, $reason): void {
            $person = Person::query()->whereKey($personId)->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            $file = File::query()->whereKey($fileId)->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            if ($file === null || $file->status === 'archived') {
                throw new DomainException('Only an available, non-archived tenant file can be linked.');
            }
            if ($file->is_public) {
                throw new DomainException('Public files cannot be linked to a Person record; make the file private first.');
            }

            $existing = DB::table('person_document_links')
                ->where('tenant_id', $tenantId)->where('person_id', $personId)->where('file_id', $fileId)
                ->lockForUpdate()->exists();
            if ($existing) {
                return;
            }

            DB::table('person_document_links')->insert([
                'tenant_id' => $tenantId,
                'person_id' => $personId,
                'file_id' => $fileId,
                'linked_by' => $actor->getKey(),
                'link_reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recordEvent('person.document_linked', $tenantId, $actor, $person, [
                'file_id' => $fileId,
                'reason' => $reason,
            ]);
        });
    }

    public function unlinkDocument(int $tenantId, int $personId, int $fileId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);

        // Require current file-level access before modifying the association.
        $authorizedFile = $this->files->find($fileId);
        if ($authorizedFile === null || (int) $authorizedFile->tenant_id !== $tenantId) {
            throw new DomainException('The file is unavailable in the authorized tenant.');
        }

        DB::transaction(function () use ($tenantId, $personId, $fileId, $actor, $reason): void {
            $person = Person::query()->whereKey($personId)->lockForUpdate()->firstOrFail();
            $this->assertPersonTenantAccess($tenantId, $person);
            $link = DB::table('person_document_links')->where('tenant_id', $tenantId)
                ->where('person_id', $personId)->where('file_id', $fileId)->lockForUpdate()->first();
            if ($link === null) {
                return;
            }

            DB::table('person_document_links')->where('id', $link->id)->delete();
            $this->recordEvent('person.document_unlinked', $tenantId, $actor, $person, [
                'file_id' => $fileId,
                'reason' => $reason,
            ]);
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

    public function unlinkShareholder(int $tenantId, int $shareholderId, int $expectedPersonId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);

        DB::transaction(function () use ($tenantId, $shareholderId, $expectedPersonId, $actor, $reason): void {
            $holder = DB::table('shareholders')->where('tenant_id', $tenantId)->where('id', $shareholderId)->lockForUpdate()->first();
            if ($holder === null) {
                throw new DomainException('Shareholder was not found in the authorized tenant.');
            }
            if ($holder->person_id === null) {
                return;
            }
            if ((int) $holder->person_id !== $expectedPersonId) {
                throw new DomainException('The shareholder is not linked to the Person specified by the request.');
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

    public function unlinkEmployee(int $tenantId, int $employeeId, int $expectedPersonId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);

        DB::transaction(function () use ($tenantId, $employeeId, $expectedPersonId, $actor, $reason): void {
            $employee = DB::table('employees')->where('tenant_id', $tenantId)->where('id', $employeeId)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            if ($employee === null) {
                throw new DomainException('Employee was not found in the authorized tenant.');
            }
            if ($employee->person_id === null) {
                return;
            }
            if ((int) $employee->person_id !== $expectedPersonId) {
                throw new DomainException('The employee is not linked to the Person specified by the request.');
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

    public function unlinkGovernanceMembership(int $tenantId, int $membershipId, int $expectedPersonId, User $actor, string $reason): void
    {
        $this->assertTenantAccess($tenantId, $actor);
        $this->assertManagePermission($tenantId, $actor);
        $this->assertReason($reason);

        DB::transaction(function () use ($tenantId, $membershipId, $expectedPersonId, $actor, $reason): void {
            $membership = DB::table('governance_memberships')->where('tenant_id', $tenantId)
                ->where('id', $membershipId)->lockForUpdate()->first();
            if ($membership === null) {
                throw new DomainException('Governance membership was not found in the authorized tenant.');
            }
            if ($membership->person_id === null) {
                return;
            }
            if ((int) $membership->person_id !== $expectedPersonId) {
                throw new DomainException('The governance membership is not linked to the Person specified by the request.');
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

    /**
     * Require sensitive-read access for tenant-scoped Person document metadata.
     *
     * The permission must be granted through a role bound to the active tenant.
     * The existing global people.manage exception is preserved for platform-level
     * operators; a tenant-scoped people.sensitive.view grant is never accepted
     * as a global or cross-tenant direct user grant.
     */
    private function assertSensitiveReadPermission(int $tenantId, User $actor): void
    {
        $directGlobalManage = $actor->permissions()
            ->where('slug', 'people.manage')
            ->where('scope', 'global')
            ->whereNull('permissions.tenant_id')
            ->where('is_active', true)
            ->exists();

        $viaTenantRole = $actor->roles()
            ->where('tenant_id', $tenantId)
            ->whereHas('permissions', function ($query): void {
                // Permission definitions are shared catalogue rows; the role
                // supplies the tenant boundary for this sensitive read.
                $query->whereIn('slug', ['people.sensitive.view', 'people.manage'])
                    ->whereIn('scope', ['tenant', 'global'])
                    ->whereNull('permissions.tenant_id')
                    ->where('is_active', true);
            })
            ->exists();

        if (! $directGlobalManage && ! $viaTenantRole) {
            throw new DomainException(
                'The tenant-scoped people.sensitive.view or people.manage permission is required to list Person documents.'
            );
        }
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

    /**
     * Preserve account consistency for domain rows already linked to this Person.
     * Employee and governance records may be account-less, but a populated
     * account reference must match the canonical Person account. Individual
     * shareholders require a direct account by the Shareholder domain contract.
     */
    private function assertAccountCompatibleWithLinkedDomains(Person $person, int $userId): void
    {
        foreach (['employees', 'governance_memberships'] as $table) {
            $conflictingAccount = DB::table($table)->where('person_id', $person->getKey())
                ->whereNotNull('user_id')->where('user_id', '<>', $userId)->exists();
            if ($conflictingAccount) {
                throw new DomainException('A linked domain record references a different account from the proposed Person account.');
            }
        }

        $invalidShareholder = DB::table('shareholders')->where('person_id', $person->getKey())
            ->where(function ($query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', '<>', $userId);
            })->exists();
        if ($invalidShareholder) {
            throw new DomainException('Every linked individual shareholder must reference the same direct account as the Person.');
        }
    }

    /**
     * Do not detach the canonical account while linked domain records still
     * depend on an account-backed identity association.
     */
    private function assertAccountCanBeUnlinked(Person $person): void
    {
        $hasAccountBackedEmployee = DB::table('employees')->where('person_id', $person->getKey())
            ->whereNotNull('user_id')->exists();
        $hasLinkedShareholder = DB::table('shareholders')->where('person_id', $person->getKey())->exists();
        $hasAccountBackedGovernanceMembership = DB::table('governance_memberships')->where('person_id', $person->getKey())
            ->whereNotNull('user_id')->exists();

        if ($hasAccountBackedEmployee || $hasLinkedShareholder || $hasAccountBackedGovernanceMembership) {
            throw new DomainException('Unlink account-backed domain relationships before unlinking the Person account.');
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
