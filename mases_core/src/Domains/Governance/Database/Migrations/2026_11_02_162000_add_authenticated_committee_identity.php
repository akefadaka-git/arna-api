<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_election_committee_members', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->after('person_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('governance_election_committee_members', function (Blueprint $table): void {
            $table->dropColumn('user_id');
        });
    }
};
