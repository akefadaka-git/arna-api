<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_authority_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('governance_body_id')->constrained('governance_bodies')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('governance_position_id')->constrained('governance_positions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('grantee_person_id')->constrained('people')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('grantor_person_id')->constrained('people')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('action', 100);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('reason', 500);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'governance_body_id', 'governance_position_id', 'action'], 'gag_scope_action_idx');
            $table->index(['grantee_person_id', 'action', 'revoked_at'], 'gag_grantee_action_idx');
            $table->index(['effective_from', 'effective_until'], 'gag_effective_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_authority_grants');
    }
};
