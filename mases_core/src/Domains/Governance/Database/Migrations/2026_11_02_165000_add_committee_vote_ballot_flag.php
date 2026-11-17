<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_election_committee_decisions', function (Blueprint $table): void {
            $table->boolean('final_ballot_approved')->default(false)->after('decision');
        });
    }

    public function down(): void
    {
        Schema::table('governance_election_committee_decisions', function (Blueprint $table): void {
            $table->dropColumn('final_ballot_approved');
        });
    }
};
