<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_meeting_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('subject_am', 190);
            $table->string('subject_en', 190)->nullable();
            $table->longText('body_am');
            $table->longText('body_en')->nullable();
            $table->char('content_sha256', 64);
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'meeting_id', 'revision'], 'gmnotice_tenant_meeting_rev_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gmnotice_tenant_meeting_status_ix');
        });

        Schema::create('governance_meeting_notice_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('notice_id')->constrained('governance_meeting_notices')->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 24)->default('portal');
            $table->string('destination', 255)->nullable();
            $table->string('status', 24)->default('queued');
            $table->string('delivery_reference', 255)->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['notice_id', 'shareholder_id', 'channel'], 'gmnotice_recipient_channel_uq');
            $table->index(['tenant_id', 'notice_id', 'status'], 'gmnotice_recipient_status_ix');
        });

        Schema::create('governance_meeting_agenda_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->unsignedInteger('sort_order');
            $table->string('title_am', 255);
            $table->string('title_en', 255)->nullable();
            $table->text('description_am')->nullable();
            $table->text('description_en')->nullable();
            $table->string('status', 24)->default('open');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['meeting_id', 'sort_order'], 'gmagenda_meeting_order_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gmagenda_tenant_meeting_status_ix');
        });

        Schema::create('governance_meeting_electorate_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->date('record_date');
            $table->string('source_reference', 2000);
            $table->char('source_sha256', 64);
            $table->decimal('total_shares', 20, 4)->default(0);
            $table->decimal('total_voting_power', 20, 4)->default(0);
            $table->string('status', 24)->default('pending_review');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'meeting_id', 'revision'], 'gmelectorate_tenant_meeting_rev_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gmelectorate_tenant_status_ix');
        });

        Schema::create('governance_meeting_electorate_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('snapshot_id')->constrained('governance_meeting_electorate_snapshots')->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->foreignId('share_class_id')->constrained('share_classes')->restrictOnDelete();
            $table->decimal('shares', 20, 4);
            $table->decimal('votes_per_share', 20, 4);
            $table->decimal('voting_power', 20, 4);
            $table->timestamps();
            $table->unique(['snapshot_id', 'shareholder_id', 'share_class_id'], 'gmelectorate_member_class_uq');
            $table->index(['tenant_id', 'snapshot_id', 'shareholder_id'], 'gmelectorate_member_lookup_ix');
        });

        Schema::create('governance_meeting_quorum_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('snapshot_id')->constrained('governance_meeting_electorate_snapshots')->restrictOnDelete();
            $table->decimal('eligible_units', 20, 4);
            $table->decimal('present_units', 20, 4);
            $table->decimal('required_units', 20, 4);
            $table->unsignedInteger('threshold_numerator');
            $table->unsignedInteger('threshold_denominator');
            $table->string('basis', 24);
            $table->boolean('quorum_met');
            $table->string('status', 24)->default('pending_review');
            $table->foreignId('calculated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gmquorum_tenant_meeting_status_ix');
        });

        Schema::create('governance_meeting_motions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('agenda_item_id')->nullable()->constrained('governance_meeting_agenda_items')->restrictOnDelete();
            $table->unsignedInteger('motion_number');
            $table->text('text_am');
            $table->text('text_en')->nullable();
            $table->string('status', 24)->default('proposed');
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('seconded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('voting_opened_at')->nullable();
            $table->dateTime('voting_closed_at')->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'motion_number'], 'gmmotion_meeting_number_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gmmotion_tenant_status_ix');
        });

        Schema::create('governance_meeting_ballots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('motion_id')->constrained('governance_meeting_motions')->restrictOnDelete();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->foreignId('cast_by')->constrained('users')->restrictOnDelete();
            $table->string('choice', 16);
            $table->decimal('voting_power', 20, 4);
            $table->dateTime('cast_at');
            $table->timestamps();
            $table->unique(['motion_id', 'shareholder_id'], 'gmballot_motion_shareholder_uq');
            $table->index(['tenant_id', 'motion_id', 'choice'], 'gmballot_tenant_motion_choice_ix');
        });

        Schema::create('governance_meeting_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->foreignId('motion_id')->unique()->constrained('governance_meeting_motions')->restrictOnDelete();
            $table->string('resolution_number', 64);
            $table->text('text_am');
            $table->text('text_en')->nullable();
            $table->decimal('votes_for', 20, 4)->default(0);
            $table->decimal('votes_against', 20, 4)->default(0);
            $table->decimal('votes_abstain', 20, 4)->default(0);
            $table->unsignedInteger('threshold_numerator');
            $table->unsignedInteger('threshold_denominator');
            $table->string('result', 16);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'resolution_number'], 'gmresolution_tenant_number_uq');
            $table->index(['tenant_id', 'meeting_id', 'result'], 'gmresolution_tenant_meeting_result_ix');
        });

        Schema::create('governance_meeting_minutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained('governance_meetings')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->longText('content_am');
            $table->longText('content_en')->nullable();
            $table->json('record_snapshot');
            $table->char('content_sha256', 64);
            $table->string('status', 24)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'meeting_id', 'version'], 'gmminutes_tenant_meeting_version_uq');
            $table->index(['tenant_id', 'meeting_id', 'status'], 'gmminutes_tenant_status_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_meeting_minutes');
        Schema::dropIfExists('governance_meeting_resolutions');
        Schema::dropIfExists('governance_meeting_ballots');
        Schema::dropIfExists('governance_meeting_motions');
        Schema::dropIfExists('governance_meeting_quorum_assessments');
        Schema::dropIfExists('governance_meeting_electorate_members');
        Schema::dropIfExists('governance_meeting_electorate_snapshots');
        Schema::dropIfExists('governance_meeting_agenda_items');
        Schema::dropIfExists('governance_meeting_notice_recipients');
        Schema::dropIfExists('governance_meeting_notices');
    }
};
