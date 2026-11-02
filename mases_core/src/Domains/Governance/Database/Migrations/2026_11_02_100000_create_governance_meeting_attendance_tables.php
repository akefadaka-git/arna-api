<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_meetings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('governance_body_id')->constrained('governance_bodies')->restrictOnDelete();
            $table->string('meeting_kind', 16);
            $table->string('title_am', 180)->nullable();
            $table->string('title_en', 180)->nullable();
            $table->dateTime('scheduled_at');
            $table->string('timezone', 64)->default('Africa/Addis_Ababa');
            $table->string('status', 24)->default('scheduled');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'governance_body_id', 'scheduled_at'], 'gmeet_tenant_body_date_idx');
            $table->index(['tenant_id', 'status', 'scheduled_at'], 'gmeet_tenant_status_date_idx');
        });

        Schema::create('governance_meeting_proxies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->unsignedBigInteger('principal_membership_id');
            $table->unsignedBigInteger('representative_membership_id')->nullable();
            $table->string('representative_name', 180);
            $table->string('representative_contact', 100)->nullable();
            $table->string('instrument_reference', 120);
            $table->string('document_path', 500)->nullable();
            $table->dateTime('appointed_at');
            $table->dateTime('received_at');
            $table->string('status', 24)->default('submitted');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_reason', 1000)->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'principal_membership_id'], 'gproxy_meeting_principal_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gproxy_tenant_meeting_status_idx');
            $table->index(['meeting_id', 'representative_membership_id'], 'gproxy_meeting_rep_idx');
        });

        Schema::create('governance_meeting_attendance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->unsignedBigInteger('membership_id');
            $table->foreignId('proxy_id')->nullable()->constrained('governance_meeting_proxies')->nullOnDelete();
            $table->string('status', 20);
            $table->dateTime('arrived_at')->nullable();
            $table->boolean('absence_authorized')->default(false);
            $table->string('authorization_reference', 180)->nullable();
            $table->string('authorization_reason', 1000)->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'membership_id'], 'gattendance_meeting_member_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gattendance_tenant_meeting_status_idx');
        });

        Schema::create('governance_meeting_penalties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->unsignedBigInteger('membership_id');
            $table->string('penalty_type', 24);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('ETB');
            $table->string('status', 28)->default('pending_review');
            $table->string('reason', 1000);
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('assessed_at')->nullable();
            $table->string('decision_reason', 1000)->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'membership_id', 'penalty_type'], 'gpenalty_meeting_member_type_uq');
            $table->index(['tenant_id', 'membership_id', 'status'], 'gpenalty_tenant_member_status_idx');
        });

        Schema::create('governance_meeting_penalty_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('penalty_id')->constrained('governance_meeting_penalties')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('ETB');
            $table->string('payment_method', 32);
            $table->string('receipt_reference', 120);
            $table->dateTime('paid_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('finance_reconciliation_status', 24)->default('pending');
            $table->string('finance_reference', 160)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'receipt_reference'], 'gpayment_tenant_receipt_uq');
            $table->index(['tenant_id', 'finance_reconciliation_status'], 'gpayment_tenant_recon_idx');
        });

        Schema::create('governance_meeting_dividend_settlement_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('penalty_id')->constrained('governance_meeting_penalties')->restrictOnDelete();
            $table->unsignedBigInteger('shareholder_id');
            $table->string('dividend_reference', 160);
            $table->decimal('requested_amount', 12, 2);
            $table->char('currency', 3)->default('ETB');
            $table->string('status', 28)->default('pending_finance_legal_review');
            $table->string('authority_reference', 180)->nullable();
            $table->string('finance_reference', 180)->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_reason', 1000)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status'], 'gdividend_settlement_tenant_status_idx');
        });

        Schema::create('governance_meeting_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->dateTime('occurred_at');
            $table->index(['tenant_id', 'meeting_id', 'occurred_at'], 'gmeet_audit_tenant_meeting_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_meeting_audit_events');
        Schema::dropIfExists('governance_meeting_dividend_settlement_requests');
        Schema::dropIfExists('governance_meeting_penalty_payments');
        Schema::dropIfExists('governance_meeting_penalties');
        Schema::dropIfExists('governance_meeting_attendance');
        Schema::dropIfExists('governance_meeting_proxies');
        Schema::dropIfExists('governance_meetings');
    }
};
