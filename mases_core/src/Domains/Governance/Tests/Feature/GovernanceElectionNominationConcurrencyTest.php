<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCandidateService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionLifecycleService;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Tests\TestCase;

final class GovernanceElectionNominationConcurrencyTest extends TestCase
{
    public function test_two_independent_mysql_workers_submit_the_same_nomination_at_once(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('This regression requires MySQL and the pcntl extension.');
        }

        // This test runs in its own CI step and needs committed setup rows visible
        // to child processes. Avoid transactional test traits and avoid rollback:
        // the repository has unrelated MySQL migration rollback constraints.
        $this->artisan('migrate:fresh', ['--force' => true]);

        $tenant = Tenant::query()->create([
            'name_am' => 'የተመሳሳይ ጊዜ ምርጫ ሙከራ',
            'name_en' => 'Concurrent Election Test',
            'slug' => 'election-concurrent-' . uniqid(),
            'domain' => 'election-concurrent-' . uniqid() . '.example.test',
            'is_active' => true,
        ]);
        app(TenantContextService::class)->setTenantById((int) $tenant->id);

        $manager = $this->makeUser($tenant, 'manager');
        $this->grant($manager, 'governance.election.manage');
        $this->grant($manager, 'governance.election.authorize');

        $election = app(GovernanceElectionLifecycleService::class)->create((int) $tenant->id, $manager, [
            'code' => 'CONCURRENT-' . $tenant->id,
            'election_scope' => 'board',
            'election_type' => 'board',
            'title_en' => 'Concurrent Nomination Election',
        ]);
        $digest = str_repeat('c', 64);
        app(GovernanceElectionLifecycleService::class)->transition(
            (int) $tenant->id,
            (int) $election->id,
            $manager,
            'authorize',
            1,
            'Authority and nomination rules verified',
            ['authority:GA-RESOLUTION-CONCURRENT'],
            [
                'authority_reference' => 'GA-RESOLUTION-CONCURRENT',
                'authority_evidence_digest' => str_repeat('d', 64),
                'authority_version' => '1.0',
                'rule_set_version' => 'NOM-CONCURRENT-1.0',
                'rule_set_digest' => $digest,
            ],
        );

        $nominator = $this->makeUser($tenant, 'nominator');
        $nominatorPerson = $this->makePerson($nominator, $tenant);
        DB::table('shareholders')->insert([
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'user_id' => $nominator->id,
            'representative_user_id' => null,
            'person_id' => $nominatorPerson->id,
            'is_active' => true,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $candidate = $this->makePerson($this->makeUser($tenant, 'candidate'), $tenant);

        $service = app(GovernanceElectionCandidateService::class);
        $service->configureNominationWindow(
            (int) $tenant->id,
            (int) $election->id,
            $manager,
            now()->subMinute()->toIso8601String(),
            now()->addDay()->toIso8601String(),
            'NOM-CONCURRENT-1.0',
            $digest,
            'GA-RESOLUTION-CONCURRENT',
        );
        $service->openNominationWindow((int) $tenant->id, (int) $election->id, $manager, 1);

        $gate = tempnam(sys_get_temp_dir(), 'arna-nom-gate-');
        $results = [
            tempnam(sys_get_temp_dir(), 'arna-nom-result-'),
            tempnam(sys_get_temp_dir(), 'arna-nom-result-'),
        ];
        $readyPaths = [
            tempnam(sys_get_temp_dir(), 'arna-nom-ready-'),
            tempnam(sys_get_temp_dir(), 'arna-nom-ready-'),
        ];
        if ($gate === false || in_array(false, $results, true) || in_array(false, $readyPaths, true)) {
            $this->fail('Unable to allocate IPC files for the concurrent nomination regression.');
        }
        unlink($gate);
        foreach ($readyPaths as $readyPath) {
            unlink($readyPath);
        }

        // Commit fixture setup and discard the inherited PDO handle before forking.
        DB::disconnect();
        $children = [];
        foreach ($results as $workerIndex => $resultPath) {
            $readyPath = $readyPaths[$workerIndex];
            $pid = pcntl_fork();
            if ($pid === -1) {
                file_put_contents($gate, 'abort');
                $this->fail('Unable to start an independent nomination worker.');
            }
            if ($pid === 0) {
                try {
                    DB::purge();
                    DB::reconnect();
                    app(TenantContextService::class)->setTenantById((int) $tenant->id);
                    $actor = User::query()->findOrFail((int) $nominator->id);
                    Auth::login($actor);
                    file_put_contents($readyPath, 'ready');

                    $deadline = microtime(true) + 15;
                    while (!file_exists($gate) && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    if (!file_exists($gate) || file_get_contents($gate) !== 'go') {
                        throw new \RuntimeException('Concurrent worker did not receive the shared start signal.');
                    }

                    $receipt = app(GovernanceElectionCandidateService::class)->submitNomination(
                        (int) $tenant->id,
                        (int) $election->id,
                        $actor,
                        (int) $candidate->id,
                        'parallel-nomination-worker-' . $workerIndex,
                        'Same nomination payload submitted by independent workers.',
                    );
                    file_put_contents($resultPath, json_encode([
                        'ok' => true,
                        'receipt_id' => (int) $receipt->id,
                    ], JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($resultPath, json_encode([
                        'ok' => false,
                        'error' => get_class($exception) . ': ' . $exception->getMessage(),
                    ], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }
            $children[] = $pid;
        }

        // Do not release the race until both independent workers have connected,
        // authenticated, and confirmed they are waiting at the barrier.
        $readyDeadline = microtime(true) + 15;
        $bothReady = file_exists($readyPaths[0]) && file_exists($readyPaths[1]);
        while (!$bothReady && microtime(true) < $readyDeadline) {
            usleep(1000);
            $bothReady = file_exists($readyPaths[0]) && file_exists($readyPaths[1]);
        }
        file_put_contents($gate, $bothReady ? 'go' : 'abort');
        $workerStatuses = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $workerStatuses[] = [
                'exited' => pcntl_wifexited($status),
                'exit_code' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null,
            ];
        }

        DB::reconnect();
        $workerResults = array_map(
            static fn (string $path): array => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
            $results,
        );
        foreach ([$gate, ...$results, ...$readyPaths] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        $this->assertTrue($bothReady, 'Both independent workers must be waiting before the race is released.');
        $this->assertSame([['exited' => true, 'exit_code' => 0], ['exited' => true, 'exit_code' => 0]], $workerStatuses);
        $outcomes = array_column($workerResults, 'outcome');
        sort($outcomes);
        $this->assertSame(['accepted', 'conflict'], $outcomes, 'Distinct idempotency keys must still collide on the shareholder/candidate uniqueness rule.');
        $this->assertSame(1, DB::table('governance_election_nomination_submissions')
            ->where('tenant_id', $tenant->id)
            ->where('election_id', $election->id)
            ->where('nominated_by_shareholder_id', function ($query) use ($tenant, $nominator): void {
                $query->select('id')->from('shareholders')->where('tenant_id', $tenant->id)->where('user_id', $nominator->id);
            })
            ->count());
        $this->assertSame(1, DB::table('governance_election_candidates')
            ->where('tenant_id', $tenant->id)
            ->where('election_id', $election->id)
            ->where('person_id', $candidate->id)
            ->count());
    }

    private function makeUser(Tenant $tenant, string $suffix): User
    {
        $user = User::query()->create([
            'first_name_en' => 'Election',
            'father_name_en' => 'Concurrency',
            'email' => $suffix . '-' . uniqid() . '@example.test',
            'password' => bcrypt('not-used-in-test'),
            'locale' => 'am',
            'is_active' => true,
            'default_tenant_id' => $tenant->id,
        ]);
        $user->tenants()->attach($tenant->id, ['joined_at' => now()]);
        return $user;
    }

    private function makePerson(User $user, Tenant $tenant): Person
    {
        $person = new Person(['first_name_en' => 'Election', 'father_name_en' => 'Concurrency']);
        $person->user_id = $user->id;
        $person->status = 'active';
        $person->save();
        PersonTenantAccess::query()->create([
            'person_id' => $person->id,
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'status' => 'active',
        ]);
        return $person;
    }

    private function grant(User $user, string $slug): void
    {
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
}
