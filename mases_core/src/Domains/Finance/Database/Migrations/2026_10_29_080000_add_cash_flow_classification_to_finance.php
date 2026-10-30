<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit cash-flow classification contract.
 *
 * Cash/equivalent accounts default to false (fail closed). Cash movement
 * lines remain unclassified until an authorized source supplies an activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ledger_accounts') && ! Schema::hasColumn('ledger_accounts', 'is_cash_equivalent')) {
            Schema::table('ledger_accounts', static function (Blueprint $table): void {
                $table->boolean('is_cash_equivalent')->default(false)->index();
            });
        }

        if (Schema::hasTable('journal_lines')) {
            Schema::table('journal_lines', static function (Blueprint $table): void {
                if (! Schema::hasColumn('journal_lines', 'cash_flow_activity')) {
                    $table->string('cash_flow_activity', 24)->nullable()->index();
                }
                if (! Schema::hasColumn('journal_lines', 'cash_flow_classification_note')) {
                    $table->string('cash_flow_classification_note', 1000)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('journal_lines')) {
            Schema::table('journal_lines', static function (Blueprint $table): void {
                if (Schema::hasColumn('journal_lines', 'cash_flow_classification_note')) {
                    $table->dropColumn('cash_flow_classification_note');
                }
                if (Schema::hasColumn('journal_lines', 'cash_flow_activity')) {
                    $table->dropIndex(['cash_flow_activity']);
                    $table->dropColumn('cash_flow_activity');
                }
            });
        }

        if (Schema::hasTable('ledger_accounts') && Schema::hasColumn('ledger_accounts', 'is_cash_equivalent')) {
            Schema::table('ledger_accounts', static function (Blueprint $table): void {
                $table->dropIndex(['is_cash_equivalent']);
                $table->dropColumn('is_cash_equivalent');
            });
        }
    }
};
