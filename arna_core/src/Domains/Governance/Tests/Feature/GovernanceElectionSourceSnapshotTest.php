<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Governance\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Authorization\Models\Permission;
use MaaleArna\Arna\Core\Files\Models\File;
use MaaleArna\Arna\Core\Domains\Governance\Services\GovernanceElectionSourceSnapshotService;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;
use MaaleArna\Arna\Core\Tenancy\Services\TenantContextService;
use Tests\TestCase;

final class GovernanceElectionSourceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('ARNA_MIGRATIONS_PRELOADED') === 'true') {
            RefreshDatabaseState::$migrated = true;
        }
        parent::setUp();
        Storage::fake('local');
    }

    public function test_source_snapshot_routes_use_dedicated_least_privilege_permissions(): void
    {
        $routes = [
            'api.governance.elections.shareholder-source-snapshots.index' => 'auth.permission:governance.election.register.view',
            'api.governance.elections.shareholder-source-snapshots.store' => 'auth.permission:governance.election.register.prepare',
            'api.governance.elections.shareholder-source-snapshots.show' => 'auth.permission:governance.election.register.view',
            'api.governance.elections.shareholder-source-snapshots.reconcile' => 'auth.permission:governance.election.register.reconcile',
        ];

        foreach ($routes as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, "Missing route {$name}");
            self::assertContains($permission, $route->gatherMiddleware());
        }
    }

    public function test_source_snapshot_is_immutable_evidence_only_and_reconciliation_requires_a_distinct_actor(): void
    {
        [$tenant, $preparer] = $this->makeActor('prepare', [
            'governance.election.register.view',
            'governance.election.register.prepare',
        ]);
        [, $reconciler] = $this->makeActor('reconcile', [
            'governance.election.register.view',
            'governance.election.register.reconcile',
        ], $tenant);

        $electionId = $this->makeAuthorizedElection($tenant, $preparer, $reconciler);
        $shareholderId = $this->makeShareholder($tenant);

        $service = app(GovernanceElectionSourceSnapshotService::class);
        $snapshot = $service->capture(
            (int) $tenant->id,
            $electionId,
            '2026-01-01T00:00:00+03:00',
            $preparer,
        );

        self::assertSame('captured', $snapshot['snapshot_state']);
        self::assertSame('blocked_unverified', $snapshot['electorate_state']);
        self::assertSame(1, (int) $snapshot['shareholder_count']);
        self::assertSame(0, (int) $snapshot['holding_count']);
        self::assertSame(0, (int) $snapshot['ledger_entry_count']);
        self::assertDatabaseHas('governance_election_source_snapshot_holders', [
            'snapshot_id' => $snapshot['id'],
            'shareholder_id' => $shareholderId,
            'identity_temporal_state' => 'unresolved_no_effective_dated_history',
        ]);

        $matched = $service->reconcile(
            (int) $tenant->id,
            $electionId,
            (int) $snapshot['id'],
            'Independent source digest comparison.',
            $reconciler,
        );
        self::assertSame('matched', $matched['result']);
        self::assertSame('blocked_unverified', $matched['electorate_state']);

        DB::table('shareholders')->where('id', $shareholderId)->update(['is_active' => false, 'updated_at' => now()]);
        $changed = $service->reconcile(
            (int) $tenant->id,
            $electionId,
            (int) $snapshot['id'],
            'Recheck after source identity status changed.',
            $reconciler,
        );
        self::assertSame('changed', $changed['result']);

        self::assertDatabaseHas('governance_election_source_snapshots', [
            'id' => $snapshot['id'],
            'snapshot_state' => 'captured',
            'electorate_state' => 'blocked_unverified',
            'source_digest' => $snapshot['source_digest'],
        ]);
        self::assertSame(2, DB::table('governance_election_source_snapshot_reconciliations')
            ->where('snapshot_id', $snapshot['id'])->count());

        try {
            DB::table('governance_election_source_snapshot_reconciliations')
                ->where('id', $matched['reconciliation_id'])
                ->update(['reason' => 'tampered']);
            self::fail('Direct SQL updates to reconciliation evidence must be rejected.');
        } catch (QueryException) {
            self::assertDatabaseHas('governance_election_source_snapshot_reconciliations', [
                'id' => $matched['reconciliation_id'],
                'reason' => 'Independent source digest comparison.',
            ]);
        }
        self::assertFalse(Schema::hasColumn('governance_election_source_snapshot_holders', 'voting_weight'));
        self::assertFalse(Schema::hasColumn('governance_election_source_snapshot_holders', 'voter_eligible'));

        try {
            DB::table('governance_election_source_snapshots')
                ->where('id', $snapshot['id'])
                ->update(['source_digest' => str_repeat('f', 64)]);
            self::fail('Direct SQL updates to a captured source snapshot must be rejected.');
        } catch (QueryException) {
            self::assertDatabaseHas('governance_election_source_snapshots', [
                'id' => $snapshot['id'],
                'source_digest' => $snapshot['source_digest'],
            ]);
        }

        try {
            DB::table('governance_election_source_snapshot_holders')
                ->where('snapshot_id', $snapshot['id'])
                ->delete();
            self::fail('Direct SQL deletes from captured holder rows must be rejected.');
        } catch (QueryException) {
            self::assertDatabaseHas('governance_election_source_snapshot_holders', [
                'snapshot_id' => $snapshot['id'],
                'shareholder_id' => $shareholderId,
            ]);
        }
    }

    public function test_snapshot_preparer_cannot_reconcile_own_source_snapshot(): void
    {
        [$tenant, $preparer] = $this->makeActor('self-review', [
            'governance.election.register.view',
            'governance.election.register.prepare',
            'governance.election.register.reconcile',
        ]);
        $electionId = $this->makeAuthorizedElection($tenant, $preparer, $preparer);
        $this->makeShareholder($tenant);

        $service = app(GovernanceElectionSourceSnapshotService::class);
        $snapshot = $service->capture(
            (int) $tenant->id,
            $electionId,
            '2026-01-01T00:00:00+03:00',
            $preparer,
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->reconcile(
            (int) $tenant->id,
            $electionId,
            (int) $snapshot['id'],
            'Self-review must be rejected.',
            $preparer,
        );
    }

    private function makeActor(string $suffix, array $permissions, ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::query()->create([
            'name_am' => 'የምርጫ ሙከራ',
            'name_en' => 'Election Source Snapshot Test',
            'slug' => 'election-source-' . $suffix . '-' . uniqid(),
            'domain' => 'election-source-' . $suffix . '-' . uniqid() . '.example.test',
            'is_active' => true,
        ]);
        app(TenantContextService::class)->setTenantById((int) $tenant->id);

        $user = User::query()->create([
            'first_name_en' => 'Snapshot',
            'father_name_en' => ucfirst($suffix),
            'email' => 'snapshot-' . $suffix . '-' . uniqid() . '@example.test',
            'password' => bcrypt('not-used-in-test'),
            'locale' => 'am',
            'is_active' => true,
            'default_tenant_id' => $tenant->id,
        ]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now()]);

        foreach ($permissions as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name_am' => $slug,
                    'name_en' => $slug,
                    'description_am' => $slug,
                    'description_en' => $slug,
                    'category' => 'governance',
                    'scope' => 'tenant',
                    'tenant_id' => null,
                    'application_id' => null,
                    'is_active' => true,
                    'is_system' => true,
                ],
            );
            $user->permissions()->attach($permission->id, ['assigned_at' => now()]);
        }

        return [$tenant, $user];
    }

    private function makeAuthorizedElection(Tenant $tenant, User $preparer, User $reviewer): int
    {
        $bytes = "%PDF-1.4\nElection authority and rule evidence\n";
        $path = 'tenant_' . $tenant->id . '/snapshot-authority-' . uniqid() . '.pdf';
        Storage::disk('local')->put($path, $bytes);
        $file = File::query()->create([
            'user_id' => $preparer->id,
            'tenant_id' => $tenant->id,
            'original_name' => 'authority.pdf',
            'name' => basename($path),
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => strlen($bytes),
            'path' => $path,
            'disk' => 'local',
            'version' => 1,
            'status' => 'published',
            'is_public' => false,
        ]);

        $electionId = DB::table('governance_elections')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'SNAP-' . strtoupper(substr(uniqid(), -8)),
            'title_en' => 'Source snapshot test',
            'election_scope' => 'board',
            'election_type' => 'board',
            'governance_model' => 'share_company',
            'electorate_basis' => 'shareholders_on_record_date',
            'vote_weight_basis' => 'eligible_shares',
            'ballot_secrecy' => 'secret',
            'status' => 'authorized',
            'authority_reference' => 'TEST-AUTHORITY',
            'authority_document_id' => $file->id,
            'authority_evidence_digest' => hash('sha256', $bytes),
            'authority_version' => 'test-v1',
            'authority_snapshot' => json_encode(['test' => true]),
            'rule_set_version' => 'test-rule-v1',
            'rule_set_digest' => hash('sha256', 'test-rule'),
            'rule_set_document_id' => $file->id,
            'timezone' => 'Africa/Addis_Ababa',
            'authorized_at' => now(),
            'authorized_by' => $reviewer->id,
            'version' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $evidenceId = DB::table('governance_election_authority_evidence')->insertGetId([
            'tenant_id' => $tenant->id,
            'election_id' => $electionId,
            'instrument_type' => 'test_resolution',
            'instrument_reference' => 'TEST-AUTHORITY',
            'document_id' => $file->id,
            'storage_reference' => 'file-management:' . $file->id,
            'content_sha256' => hash('sha256', $bytes),
            'status' => 'verified',
            'recorded_by' => $preparer->id,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_reason' => 'Independent test evidence review completed.',
            'verified_by' => $reviewer->id,
            'verified_at' => now(),
            'verification_reason' => 'Test evidence verified.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('governance_elections')->where('id', $electionId)->update([
            'authority_evidence_id' => $evidenceId,
            'authority_verified_by' => $reviewer->id,
            'authority_verified_at' => now(),
        ]);

        return $electionId;
    }

    private function makeShareholder(Tenant $tenant): int
    {
        return DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenant->id,
            'shareholder_number' => 'SH-' . strtoupper(substr(uniqid(), -8)),
            'shareholder_type' => 'individual',
            'first_name_en' => 'Test Holder',
            'father_name_en' => 'Source',
            'grandfather_name_en' => 'Snapshot',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
