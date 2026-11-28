<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 01 entity accounting profile.
 *
 * Defaults preserve the current ETB-only posting baseline. Fiscal-year
 * boundaries and tax profiles remain unset until formally configured.
 * This migration does not seed, open periods, or create balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_entity_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('legal_entity_id');
            $table->char('functional_currency', 3)->default('ETB');
            $table->char('reporting_currency', 3)->default('ETB');
            $table->string('transaction_currency_mode', 24)->default('base_only');
            $table->string('display_calendar', 2)->default('EC');
            $table->string('timezone', 64)->default('Africa/Addis_Ababa');
            $table->unsignedTinyInteger('fiscal_year_start_month_ec')->nullable();
            $table->unsignedTinyInteger('fiscal_year_start_day_ec')->nullable();
            $table->string('tax_profile_status', 24)->default('unconfigured');
            $table->string('accounting_policy_status', 32)->default('owner_review_required');
            $table->string('document_numbering_policy_version', 32)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'legal_entity_id'], 'finance_entity_profiles_entity_unique');
            $table->foreign(['tenant_id', 'legal_entity_id'], 'finance_entity_profiles_entity_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->index(['tenant_id', 'transaction_currency_mode'], 'finance_entity_profiles_currency_mode_idx');
        });

        DB::table('legal_entities')->orderBy('id')->chunkById(500, function ($entities): void {
            foreach ($entities as $entity) {
                DB::table('finance_entity_profiles')->insertOrIgnore([
                    'tenant_id' => $entity->tenant_id,
                    'legal_entity_id' => $entity->id,
                    'functional_currency' => 'ETB',
                    'reporting_currency' => 'ETB',
                    'transaction_currency_mode' => 'base_only',
                    'display_calendar' => 'EC',
                    'timezone' => 'Africa/Addis_Ababa',
                    'fiscal_year_start_month_ec' => null,
                    'fiscal_year_start_day_ec' => null,
                    'tax_profile_status' => 'unconfigured',
                    'accounting_policy_status' => 'owner_review_required',
                    'document_numbering_policy_version' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE finance_entity_profiles ADD CONSTRAINT finance_entity_profiles_currency_mode_chk CHECK (transaction_currency_mode = 'base_only')");
            DB::statement("ALTER TABLE finance_entity_profiles ADD CONSTRAINT finance_entity_profiles_calendar_chk CHECK (display_calendar IN ('EC', 'GC'))");
            DB::statement("ALTER TABLE finance_entity_profiles ADD CONSTRAINT finance_entity_profiles_fiscal_start_pair_chk CHECK ((fiscal_year_start_month_ec IS NULL AND fiscal_year_start_day_ec IS NULL) OR (fiscal_year_start_month_ec BETWEEN 1 AND 12 AND fiscal_year_start_day_ec BETWEEN 1 AND 30) OR (fiscal_year_start_month_ec = 13 AND fiscal_year_start_day_ec BETWEEN 1 AND 6))");
            DB::statement("ALTER TABLE finance_entity_profiles ADD CONSTRAINT finance_entity_profiles_currency_etb_chk CHECK (functional_currency = 'ETB' AND reporting_currency = 'ETB')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_entity_profiles');
    }
};
