<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A3.3 — separate voter-register preparation, reconciliation, approval and freeze.
 * Existing snapshots are not silently upgraded to approved/frozen status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governance_election_register_snapshots', function (Blueprint $table): void {
            $table->string('register_status', 24)->default('draft')->after('voter_count')->index();
            $table->unsignedBigInteger('prepared_by')->nullable()->after('created_by');
            $table->unsignedBigInteger('reconciled_by')->nullable()->after('prepared_by');
            $table->dateTime('reconciled_at')->nullable()->after('reconciled_by');
            $table->unsignedBigInteger('approved_by')->nullable()->after('reconciled_at');
            $table->dateTime('approved_at')->nullable()->after('approved_by');
            $table->dateTime('integrity_verified_at')->nullable()->after('approved_at');
            $table->dateTime('frozen_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Draft/prepared registers legitimately have no frozen_at timestamp.
        // Refuse rollback rather than failing a NOT NULL conversion or fabricating a freeze time.
        if (DB::table('governance_election_register_snapshots')->whereNull('frozen_at')->exists()) {
            throw new RuntimeException('Cannot roll back voter-register lifecycle while draft or unfrozen snapshots exist; resolve them before rollback.');
        }

        Schema::table('governance_election_register_snapshots', function (Blueprint $table): void {
            $table->dateTime('frozen_at')->nullable(false)->change();
            $table->dropIndex(['register_status']);
            $table->dropColumn([
                'register_status', 'prepared_by', 'reconciled_by', 'reconciled_at',
                'approved_by', 'approved_at', 'integrity_verified_at',
            ]);
        });
    }
};
