<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('person_id')->nullable()->after('user_id')
                ->constrained('people')->restrictOnDelete();
            $table->index(['tenant_id', 'person_id'], 'employees_tenant_person_index');
        });

        Schema::table('shareholders', function (Blueprint $table): void {
            $table->foreignId('person_id')->nullable()->after('representative_user_id')
                ->constrained('people')->restrictOnDelete();
            $table->index(['tenant_id', 'person_id'], 'shareholders_tenant_person_index');
        });

        Schema::table('governance_memberships', function (Blueprint $table): void {
            $table->foreignId('person_id')->nullable()->after('user_id')
                ->constrained('people')->restrictOnDelete();
            $table->index(['tenant_id', 'person_id'], 'governance_memberships_tenant_person_index');
        });
    }

    public function down(): void
    {
        Schema::table('governance_memberships', function (Blueprint $table): void {
            $table->dropForeign(['person_id']);
            $table->dropIndex('governance_memberships_tenant_person_index');
            $table->dropColumn('person_id');
        });

        Schema::table('shareholders', function (Blueprint $table): void {
            $table->dropForeign(['person_id']);
            $table->dropIndex('shareholders_tenant_person_index');
            $table->dropColumn('person_id');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropForeign(['person_id']);
            $table->dropIndex('employees_tenant_person_index');
            $table->dropColumn('person_id');
        });
    }
};
