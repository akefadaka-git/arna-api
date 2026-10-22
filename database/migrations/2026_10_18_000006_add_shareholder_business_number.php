<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shareholders', function (Blueprint $table): void {
            $table->string('shareholder_number', 32)
                ->nullable()
                ->comment('Immutable tenant-scoped business identifier');

            $table->unique(
                ['tenant_id', 'shareholder_number'],
                'shareholders_tenant_business_number_unique',
            );
        });

        Schema::create(
            'shareholder_number_sequences',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('tenant_id')
                    ->constrained('tenants')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->unsignedBigInteger('next_number')
                    ->default(1)
                    ->comment('Next numeric identifier to allocate');

                $table->timestamps();

                $table->unique(
                    'tenant_id',
                    'shareholder_number_sequences_tenant_unique',
                );
            },
        );

        Schema::create(
            'shareholder_number_reservations',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('tenant_id')
                    ->constrained('tenants')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->unsignedBigInteger('number_sequence')
                    ->comment('Numeric portion of the issued identifier');

                $table->string('shareholder_number', 32)
                    ->comment('Permanent record of the issued identifier');

                /*
                 * Intentionally no FK: the reservation must survive
                 * deletion of its shareholder. A null value means the
                 * original shareholder row no longer exists or is unknown.
                 */
                $table->unsignedBigInteger('shareholder_id')->nullable();

                $table->timestamp('reserved_at');

                $table->timestamps();

                $table->unique(
                    ['tenant_id', 'number_sequence'],
                    'shareholder_reservations_tenant_sequence_unique',
                );

                $table->unique(
                    ['tenant_id', 'shareholder_number'],
                    'shareholder_reservations_tenant_number_unique',
                );

                $table->index(
                    ['tenant_id', 'shareholder_id'],
                    'shareholder_reservations_tenant_holder_index',
                );
            },
        );

        Schema::create(
            'shareholder_legacy_aliases',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('tenant_id')
                    ->constrained('tenants')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->string('legacy_number', 128)
                    ->comment('Original identifier, preserved as supplied');

                /*
                 * Application code must normalize this lookup key
                 * consistently before insertion and exact-match lookup.
                 * The unique constraint prevents ambiguous resolution
                 * within a tenant, even across different source systems.
                 */
                $table->string('legacy_number_key', 191);

                $table->string('source_system', 100)
                    ->comment('System or register from which the alias came');

                $table->string('source_record_key', 191)->nullable();

                $table->foreignId('shareholder_id')
                    ->constrained('shareholders')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->timestamps();

                $table->unique(
                    ['tenant_id', 'legacy_number_key'],
                    'shareholder_legacy_alias_tenant_key_unique',
                );

                $table->index(
                    ['tenant_id', 'shareholder_id'],
                    'shareholder_legacy_alias_tenant_holder_index',
                );

                $table->index(
                    ['tenant_id', 'source_system', 'source_record_key'],
                    'shareholder_legacy_alias_source_record_index',
                );
            },
        );
    }

    public function down(): void
    {
        /*
         * A normal rollback must not silently destroy issued identifiers,
         * sequence state, or legacy mappings. Deliberate development
         * database rebuilding with migrate:fresh is a separate operation.
         */
        if (
            DB::table('shareholders')
                ->whereNotNull('shareholder_number')
                ->exists()
            || DB::table('shareholder_number_reservations')->exists()
            || DB::table('shareholder_number_sequences')->exists()
            || DB::table('shareholder_legacy_aliases')->exists()
        ) {
            throw new RuntimeException(
                'Cannot roll back shareholder numbering: identifier '
                . 'history, sequence state, or legacy mappings exist. '
                . 'Use an explicitly reviewed recovery procedure.',
            );
        }

        Schema::dropIfExists('shareholder_legacy_aliases');
        Schema::dropIfExists('shareholder_number_reservations');
        Schema::dropIfExists('shareholder_number_sequences');

        Schema::table('shareholders', function (Blueprint $table): void {
            $table->dropUnique(
                'shareholders_tenant_business_number_unique',
            );

            $table->dropColumn('shareholder_number');
        });
    }
};
