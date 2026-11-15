<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_election_manual_count_batches', function (Blueprint $table): void {
            $table->string('status', 24)->default('pending_witness');
            $table->dateTime('witnessed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('governance_election_manual_count_batches', function (Blueprint $table): void {
            $table->dropColumn(['status', 'witnessed_at']);
        });
    }
};
