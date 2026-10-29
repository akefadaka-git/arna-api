<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enforce one active canonical Person link per tenant for employment and
     * individual shareholding. NULL generated keys deliberately permit any
     * number of inactive, soft-deleted, unlinked, or non-individual records.
     *
     * MySQL 8 is the supported production engine for this migration.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            // SQLite is used by hermetic unit tests; MySQL-specific constraints
            // are exercised by the dedicated MySQL migration/concurrency gate.
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE employees
              ADD COLUMN active_person_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                  CASE
                    WHEN is_active = 1 AND deleted_at IS NULL AND person_id IS NOT NULL
                    THEN person_id
                    ELSE NULL
                  END
                ) STORED,
              ADD UNIQUE KEY employees_tenant_active_person_unique (tenant_id, active_person_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE shareholders
              ADD COLUMN active_individual_person_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                  CASE
                    WHEN is_active = 1
                      AND deleted_at IS NULL
                      AND shareholder_type = 'individual'
                      AND person_id IS NOT NULL
                    THEN person_id
                    ELSE NULL
                  END
                ) STORED,
              ADD UNIQUE KEY shareholders_tenant_active_individual_person_unique
                (tenant_id, active_individual_person_id)
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE shareholders DROP INDEX shareholders_tenant_active_individual_person_unique, DROP COLUMN active_individual_person_id');
        DB::statement('ALTER TABLE employees DROP INDEX employees_tenant_active_person_unique, DROP COLUMN active_person_id');
    }
};
