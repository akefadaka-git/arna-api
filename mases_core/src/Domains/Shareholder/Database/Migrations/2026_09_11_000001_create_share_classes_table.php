<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates share_classes schema; reference rows are inserted by ShareClassSeeder.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `share_classes` table with the column set declared by
     * the ShareClass model (2A.6, F-2A6-1) and the domain's decisions
     * D3, D4, D5, and D7.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('share_classes', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Canonical code — the immutable, uppercase identifier that
            // configuration and application logic use to reference a class.
            // -----------------------------------------------------------------
            $table->string('code', 32)
                  ->unique()
                  ->comment('Canonical uppercase class code (FOUNDERS, STANDARD, ...)');

            // -----------------------------------------------------------------
            // Bilingual name columns. Amharic first, per the Amharic-first
            // policy; see the class-level docblock.
            // -----------------------------------------------------------------
            $table->string('name_am', 150)
                  ->nullable()
                  ->comment('Share class name in Amharic');

            $table->string('name_en', 150)
                  ->nullable()
                  ->comment('Share class name in English');

            // -----------------------------------------------------------------
            // Monetary attributes. See the class-level docblock for the
            // rationale behind DECIMAL(20,4) and the separate currency
            // column.
            // -----------------------------------------------------------------
            $table->decimal('par_value', 20, 4)
                  ->comment('Par value per share, in the class currency');

            $table->char('currency', 3)
                  ->default('ETB')
                  ->comment('ISO 4217 currency code; ETB in Phase 2A');

            // -----------------------------------------------------------------
            // Flags.
            // -----------------------------------------------------------------
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the class is available for new issuance and holdings');

            $table->boolean('is_treasury_eligible')
                  ->default(false)
                  ->comment('Whether the class supports treasury / buyback operations (D7)');

            // -----------------------------------------------------------------
            // Soft deletes and timestamps.
            // -----------------------------------------------------------------
            $table->softDeletes()
                  ->comment('Soft-delete timestamp; preserves historical references');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because the table is the reference root for
     * share_holdings and share_certificates, the migration's `down()` will
     * fail if those tables still exist and reference this one. In normal
     * rollback order (reverse of `up()`), those tables are dropped before
     * this one. If a partial rollback is performed manually, the operator
     * must drop dependent tables first.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('share_classes');
    }
};