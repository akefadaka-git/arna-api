<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentNumberAllocator;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDriverName() !== 'mysql' || ! extension_loaded('pcntl')) {
    fwrite(STDERR, "MySQL and pcntl are required for this verification.\n");
    exit(2);
}

$tenant = Tenant::factory()->create(['is_active' => true]);
$entityId = DB::table('legal_entities')->insertGetId([
    'tenant_id' => $tenant->id,
    'code' => 'CONCURRENCY-ENTITY',
    'name_en' => 'Concurrency Test Entity',
    'base_currency' => 'ETB',
    'status' => 'active',
    'created_at' => now(),
    'updated_at' => now(),
]);
$unitId = DB::table('organization_units')->insertGetId([
    'tenant_id' => $tenant->id,
    'legal_entity_id' => $entityId,
    'code' => 'CONCURRENCY-UNIT',
    'unit_type' => 'department',
    'name_en' => 'Concurrency Test Department',
    'status' => 'active',
    'created_at' => now(),
    'updated_at' => now(),
]);

DB::table('document_entity_code_assignments')->insert([
    'tenant_id' => $tenant->id,
    'legal_entity_id' => $entityId,
    'entity_code' => '01',
    'is_active' => true,
    'created_at' => now(),
    'updated_at' => now(),
]);
DB::table('document_department_code_assignments')->insert([
    'tenant_id' => $tenant->id,
    'legal_entity_id' => $entityId,
    'organization_unit_id' => $unitId,
    'entity_code' => '01',
    'department_code' => '03',
    'is_active' => true,
    'created_at' => now(),
    'updated_at' => now(),
]);

$startFile = tempnam(sys_get_temp_dir(), 'arna-doc-start-');
$resultFiles = [
    tempnam(sys_get_temp_dir(), 'arna-doc-result-'),
    tempnam(sys_get_temp_dir(), 'arna-doc-result-'),
];
unlink($startFile);
foreach ($resultFiles as $resultFile) {
    unlink($resultFile);
}

$children = [];
try {
    foreach ($resultFiles as $resultFile) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a worker for the MySQL concurrency test.');
        }

        if ($pid === 0) {
            try {
                DB::purge();
                while (! file_exists($startFile)) {
                    usleep(1000);
                }

                DB::connection()->beginTransaction();
                $allocation = app(DocumentNumberAllocator::class)->allocate(
                    (int) $tenant->id,
                    $entityId,
                    $unitId,
                    'LET',
                    2019,
                );
                usleep(200000);
                DB::connection()->commit();
                file_put_contents($resultFile, $allocation['canonical_number']);
                exit(0);
            } catch (Throwable $exception) {
                if (DB::connection()->transactionLevel() > 0) {
                    DB::connection()->rollBack();
                }
                file_put_contents($resultFile, 'ERROR: '.$exception::class.': '.$exception->getMessage());
                exit(1);
            }
        }

        $children[] = $pid;
    }

    touch($startFile);

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('A concurrent numbering worker failed.');
        }
    }

    $numbers = array_map(static fn (string $file): string => (string) file_get_contents($file), $resultFiles);
    sort($numbers);

    $expected = [
        'ማዓ/01/03/01/2019/0001',
        'ማዓ/01/03/01/2019/0002',
    ];

    if ($numbers !== $expected) {
        throw new RuntimeException('Concurrent allocation did not produce the expected unique sequence: '.implode(', ', $numbers));
    }

    fwrite(STDOUT, "PASS: concurrent MySQL allocation produced unique 0001 and 0002 numbers for one EE/DD/TT/EC-year scope.\n");
} finally {
    @unlink($startFile);
    foreach ($resultFiles as $resultFile) {
        @unlink($resultFile);
    }
}
