<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAppointmentService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceMembershipService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceTermService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;
use Tests\TestCase;

final class GovernanceInvariantsMySqlConcurrencyTest extends TestCase
{
    #[Test]
    public function independent_mysql_connections_cannot_race_governance_invariants(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || ! function_exists('pcntl_fork')
            || getenv('ARNA_RUN_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires the dedicated MySQL concurrency workflow and pcntl.');
        }

        config(['governance.audit.emit_events' => false]);
        $suffix = bin2hex(random_bytes(5));
        $tenant = Tenant::query()->create([
            'name_am' => 'የተመሳሰለ ሙከራ',
            'name_en' => 'Concurrency Test',
            'slug' => 'gov-concurrency-'.$suffix,
            'domain' => $suffix.'.gov.test',
            'is_active' => true,
        ]);
        $body = GovernanceBody::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'BODY-'.$suffix,
            'body_type' => 'board',
            'name_am' => 'ቦርድ',
            'name_en' => 'Concurrency Board',
            'is_active' => true,
        ]);
        $position = GovernancePosition::query()->create([
            'tenant_id' => $tenant->id,
            'governance_body_id' => $body->id,
            'code' => 'POS-'.$suffix,
            'name_am' => 'ሰብሳቢ',
            'name_en' => 'Chairperson',
            'is_voting' => true,
            'is_officer' => true,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        try {
            $termOutcomes = $this->raceIndependentConnections(
                fn () => app(GovernanceTermService::class)->register([
                    'governance_body_id' => $body->id,
                    'label_en' => 'Concurrent term '.$suffix,
                    'effective_from' => '2030-01-01',
                    'effective_until' => '2032-12-31',
                    'is_active' => true,
                ], (int) $tenant->id),
            );
            $this->assertOneSuccessAndOneConflict($termOutcomes, 'active term overlap');

            $appointmentIds = [];
            foreach ([1, 2] as $n) {
                $appointmentIds[] = GovernanceAppointment::query()->create([
                    'tenant_id' => $tenant->id,
                    'governance_body_id' => $body->id,
                    'governance_position_id' => $position->id,
                    'instrument_type' => 'resolution',
                    'instrument_reference' => 'RES-'.$suffix.'-'.$n,
                    'instrument_date' => '2029-12-01',
                    'effective_date' => '2030-01-01',
                    'appointment_mode' => 'elected',
                    'status' => 'draft',
                    'title_en' => 'Concurrent appointment '.$n,
                ])->id;
            }
            $appointmentOutcomes = $this->raceIndependentConnections(
                fn (int $index) => app(GovernanceAppointmentService::class)->activate(
                    GovernanceAppointment::query()->findOrFail($appointmentIds[$index]),
                ),
            );
            $this->assertSame(
                2,
                count(array_filter($appointmentOutcomes, static fn (string $value): bool => $value === 'success')),
                'Appointment lifecycle activation is independent of primary-seat occupancy; both instruments may be activated.',
            );

            $membershipOutcomes = $this->raceIndependentConnections(
                fn () => app(GovernanceMembershipService::class)->register([
                    'governance_body_id' => $body->id,
                    'governance_position_id' => $position->id,
                    'member_name_en' => 'Concurrent Member '.$suffix,
                    'start_date' => '2030-01-01',
                    'end_date' => '2032-12-31',
                    'status' => 'active',
                ], (int) $tenant->id),
            );
            $this->assertOneSuccessAndOneConflict($membershipOutcomes, 'membership overlap');
        } finally {
            DB::purge();
            DB::table('governance_memberships')->where('governance_body_id', $body->id)->delete();
            DB::table('governance_appointments')->where('governance_body_id', $body->id)->delete();
            DB::table('governance_terms')->where('governance_body_id', $body->id)->delete();
            DB::table('governance_positions')->where('governance_body_id', $body->id)->delete();
            DB::table('governance_bodies')->where('id', $body->id)->delete();
            DB::table('tenants')->where('id', $tenant->id)->delete();
        }
    }

    #[Test]
    public function independent_mysql_connections_cannot_overlap_primary_seat_occupancy(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || ! function_exists('pcntl_fork')
            || getenv('ARNA_RUN_MYSQL_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Requires the dedicated MySQL concurrency workflow and pcntl.');
        }

        $suffix = bin2hex(random_bytes(5));
        $tenant = Tenant::query()->create([
            'name_am' => 'የመቀመጫ ሙከራ',
            'name_en' => 'Seat Concurrency Test',
            'slug' => 'seat-concurrency-'.$suffix,
            'domain' => $suffix.'.seat.test',
            'is_active' => true,
        ]);
        $body = GovernanceBody::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'SEAT-BODY-'.$suffix,
            'body_type' => 'board',
            'name_am' => 'ቦርድ',
            'name_en' => 'Seat Concurrency Board',
            'is_active' => true,
        ]);
        $position = GovernancePosition::query()->create([
            'tenant_id' => $tenant->id,
            'governance_body_id' => $body->id,
            'code' => 'SEAT-POS-'.$suffix,
            'name_am' => 'ሰብሳቢ',
            'name_en' => 'Seat Chairperson',
            'is_voting' => true,
            'is_officer' => true,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $actor = User::query()->create([
            'first_name_en' => 'Seat',
            'father_name_en' => 'Concurrency',
            'email' => 'seat-admin-'.$suffix.'@example.test',
            'password' => bcrypt('not-used-in-test'),
            'locale' => 'am',
            'is_active' => true,
            'default_tenant_id' => $tenant->id,
        ]);
        $actor->tenants()->attach($tenant->id, ['joined_at' => now()]);
        $permission = Permission::query()->create([
            'slug' => 'governance.body.manage',
            'name_am' => 'የአስተዳደር አካል ማስተዳደር',
            'name_en' => 'Manage governance bodies',
            'description_am' => 'የመቀመጫ የሙከራ ፈቃድ',
            'description_en' => 'Seat concurrency test permission',
            'category' => 'governance',
            'scope' => 'tenant',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => true,
            'is_system' => true,
        ]);
        $actor->permissions()->attach($permission->id, ['assigned_at' => now()]);

        $people = [];
        foreach ([1, 2] as $n) {
            $person = new Person([
                'first_name_en' => 'Seat Person '.$n,
                'father_name_en' => 'Concurrency',
            ]);
            $person->status = 'active';
            $person->save();
            PersonTenantAccess::query()->create([
                'person_id' => $person->id,
                'tenant_id' => $tenant->id,
                'created_by' => $actor->id,
                'status' => 'active',
            ]);
            $people[] = (int) $person->id;
        }

        $seat = app(GovernanceSeatService::class)->createSeat(
            $actor,
            (int) $tenant->id,
            (int) $body->id,
            (int) $position->id,
            'SEAT-'.$suffix,
        );

        try {
            $outcomes = $this->raceIndependentConnections(
                fn (int $index) => app(GovernanceSeatService::class)->occupy(
                    $actor,
                    (int) $tenant->id,
                    (int) $seat->id,
                    $people[$index],
                    '2035-01-01',
                    '2035-12-31',
                ),
            );
            $this->assertOneSuccessAndOneConflict($outcomes, 'primary seat occupancy overlap');
        } finally {
            DB::purge();
            DB::table('governance_seat_occupancies')->where('governance_seat_id', $seat->id)->delete();
            DB::table('governance_seats')->where('id', $seat->id)->delete();
            DB::table('person_tenant_accesses')->whereIn('person_id', $people)->delete();
            DB::table('people')->whereIn('id', $people)->delete();
            DB::table('user_has_permissions')->where('user_id', $actor->id)->delete();
            DB::table('tenant_memberships')->where('user_id', $actor->id)->delete();
            DB::table('users')->where('id', $actor->id)->delete();
            DB::table('governance_positions')->where('id', $position->id)->delete();
            DB::table('governance_bodies')->where('id', $body->id)->delete();
            DB::table('permissions')->where('id', $permission->id)->delete();
            DB::table('tenants')->where('id', $tenant->id)->delete();
        }
    }

    /**
     * Start two workers together; each worker purges inherited connection
     * state and opens its own PDO connection after the fork.
     *
     * @param callable(int): mixed $operation
     * @return list<string>
     */
    private function raceIndependentConnections(callable $operation): array
    {
        $release = tempnam(sys_get_temp_dir(), 'arna-gov-release-');
        $resultPaths = [
            tempnam(sys_get_temp_dir(), 'arna-gov-result-'),
            tempnam(sys_get_temp_dir(), 'arna-gov-result-'),
        ];
        @unlink($release);
        foreach ($resultPaths as $resultPath) {
            @unlink($resultPath);
        }

        DB::disconnect();
        $children = [];

        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork governance MySQL concurrency worker.');
                }

                if ($pid === 0) {
                    DB::purge();
                    $deadline = microtime(true) + 10;
                    while (! file_exists($release) && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    if (! file_exists($release)) {
                        file_put_contents($resultPaths[$index], 'error:release-timeout');
                        exit(2);
                    }

                    try {
                        $operation($index);
                        file_put_contents($resultPaths[$index], 'success');
                        exit(0);
                    } catch (ValidationException $exception) {
                        file_put_contents($resultPaths[$index], 'conflict');
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($resultPaths[$index], 'error:'.get_class($exception));
                        exit(3);
                    }
                }

                $children[] = $pid;
            }

            file_put_contents($release, 'go');
            $exitCodes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
            }

            $outcomes = array_map(
                static fn (string $resultPath): string => is_file($resultPath)
                    ? trim((string) file_get_contents($resultPath))
                    : 'missing-result',
                $resultPaths,
            );
            $this->assertSame([0, 0], $exitCodes, 'Both concurrency workers must finish without infrastructure errors.');

            return $outcomes;
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            DB::purge();
            @unlink($release);
            foreach ($resultPaths as $resultPath) {
                @unlink($resultPath);
            }
        }
    }

    /** @param list<string> $outcomes */
    private function assertOneSuccessAndOneConflict(array $outcomes, string $invariant): void
    {
        $this->assertCount(1, array_filter($outcomes, static fn (string $value): bool => $value === 'success'), $invariant);
        $this->assertCount(
            1,
            array_filter($outcomes, static fn (string $value): bool => in_array($value, ['conflict', 'constraint'], true)),
            $invariant,
        );
    }
}
