<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_seats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('governance_body_id')->constrained('governance_bodies')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('governance_position_id')->constrained('governance_positions')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('seat_code', 64);
            $table->string('label_am', 150)->nullable();
            $table->string('label_en', 150)->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'governance_body_id', 'governance_position_id', 'seat_code'], 'governance_seat_identity_unique');
            $table->index(['tenant_id', 'status'], 'governance_seat_tenant_status_index');
        });

        Schema::create('governance_seat_occupancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('governance_seat_id')->constrained('governance_seats')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('governance_membership_id')->nullable()->constrained('governance_memberships')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 24)->default('active');
            $table->string('ended_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['governance_seat_id', 'status', 'start_date'], 'governance_occupancy_seat_dates_index');
            $table->index(['person_id', 'start_date'], 'governance_occupancy_person_dates_index');
        });

        Schema::create('governance_acting_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('governance_seat_id')->constrained('governance_seats')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('substantive_occupancy_id')->constrained('governance_seat_occupancies')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('acting_person_id')->constrained('people')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('grantor_person_id')->constrained('people')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('source_decision_reference', 191);
            $table->json('actions');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason', 500);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['governance_seat_id', 'start_date', 'end_date'], 'governance_acting_seat_dates_index');
            $table->index(['acting_person_id', 'start_date', 'end_date'], 'governance_acting_person_dates_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_acting_assignments');
        Schema::dropIfExists('governance_seat_occupancies');
        Schema::dropIfExists('governance_seats');
    }
};
