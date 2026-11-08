<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT = 'organization_assignments_person_tenant_access_fk';

    public function up(): void
    {
        if (! Schema::hasTable('person_tenant_accesses')) {
            throw new RuntimeException('Cannot enforce Organization tenant access: person_tenant_accesses is missing.');
        }

        $alreadyExists = collect(Schema::getForeignKeys('organization_assignments'))
            ->contains(fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === self::CONSTRAINT);

        if ($alreadyExists) {
            return;
        }

        $orphanCount = DB::table('organization_assignments as assignments')
            ->leftJoin('person_tenant_accesses as accesses', function ($join): void {
                $join->on('accesses.person_id', '=', 'assignments.person_id')
                    ->on('accesses.tenant_id', '=', 'assignments.tenant_id');
            })
            ->whereNull('accesses.id')
            ->count();

        if ($orphanCount > 0) {
            throw new RuntimeException(
                "Cannot add Organization tenant-access constraint: {$orphanCount} assignment(s) lack a matching person/tenant access record. Reconcile them explicitly before retrying."
            );
        }

        Schema::table('organization_assignments', function (Blueprint $table): void {
            $table->foreign(['person_id', 'tenant_id'], self::CONSTRAINT)
                ->references(['person_id', 'tenant_id'])
                ->on('person_tenant_accesses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $exists = collect(Schema::getForeignKeys('organization_assignments'))
            ->contains(fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === self::CONSTRAINT);

        if ($exists) {
            Schema::table('organization_assignments', function (Blueprint $table): void {
                $table->dropForeign(self::CONSTRAINT);
            });
        }
    }
};
