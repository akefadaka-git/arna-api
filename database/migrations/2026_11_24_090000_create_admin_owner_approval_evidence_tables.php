<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_owner_approval_trust_anchors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('signing_key_id');
            $table->string('authority_reference', 255);
            $table->char('evidence_sha256', 64);
            $table->foreignId('trusted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('trusted_at');
            $table->timestamp('valid_until')->nullable();
            $table->unique(['tenant_id', 'id'], 'aoata_tenant_id_unique');
            $table->unique(['tenant_id', 'owner_user_id', 'signing_key_id'], 'aoata_owner_key_unique');
            $table->foreign(['tenant_id', 'signing_key_id'], 'aoata_signing_key_fk')
                ->references(['tenant_id', 'id'])->on('document_signing_keys')->restrictOnDelete();
            $table->index(['tenant_id', 'owner_user_id', 'valid_until'], 'aoata_owner_validity_idx');
        });

        Schema::create('admin_owner_approval_trust_anchor_revocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('trust_anchor_id');
            $table->unsignedBigInteger('revoked_by_user_id');
            $table->foreign('revoked_by_user_id', 'aoatar_revoked_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->timestamp('revoked_at');
            $table->unique(['tenant_id', 'trust_anchor_id'], 'aoatar_once_unique');
            $table->foreign(['tenant_id', 'trust_anchor_id'], 'aoatar_anchor_fk')
                ->references(['tenant_id', 'id'])->on('admin_owner_approval_trust_anchors')->restrictOnDelete();
        });

        Schema::create('admin_owner_approval_challenges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('challenge_uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->unsignedBigInteger('signing_key_id');
            $table->string('key_id', 100);
            $table->string('action', 80);
            $table->string('role_slug', 150);
            $table->json('permission_slugs');
            $table->char('permission_set_sha256', 64);
            $table->string('reason', 500);
            $table->char('nonce', 64);
            $table->longText('canonical_payload');
            $table->char('payload_sha256', 64);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->unique(['tenant_id', 'id'], 'aoac_tenant_id_unique');
            $table->foreign(['tenant_id', 'signing_key_id'], 'aoac_signing_key_fk')
                ->references(['tenant_id', 'id'])->on('document_signing_keys')->restrictOnDelete();
            $table->index(['tenant_id', 'requested_by_user_id', 'expires_at'], 'aoac_requester_expiry_idx');
            $table->index(['tenant_id', 'role_id', 'action'], 'aoac_role_action_idx');
        });

        Schema::create('admin_owner_approval_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('challenge_id');
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('signing_key_id');
            $table->string('action', 80);
            $table->text('signature_base64');
            $table->timestamp('verified_at');
            $table->char('payload_sha256', 64);
            $table->unique(['tenant_id', 'challenge_id'], 'aoac_consume_once_unique');
            $table->foreign(['tenant_id', 'challenge_id'], 'aoac_consumption_challenge_fk')
                ->references(['tenant_id', 'id'])->on('admin_owner_approval_challenges')->restrictOnDelete();
            $table->foreign(['tenant_id', 'signing_key_id'], 'aoac_consumption_key_fk')
                ->references(['tenant_id', 'id'])->on('document_signing_keys')->restrictOnDelete();
        });

        foreach ([
            'admin_owner_approval_trust_anchors',
            'admin_owner_approval_trust_anchor_revocations',
            'admin_owner_approval_challenges',
            'admin_owner_approval_consumptions',
        ] as $table) {
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Admin Owner approval evidence is append-only'");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Admin Owner approval evidence is append-only'");
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'Admin Owner approval evidence is append-only'); END");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Admin Owner approval evidence is append-only'); END");
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'admin_owner_approval_trust_anchors',
            'admin_owner_approval_trust_anchor_revocations',
            'admin_owner_approval_challenges',
            'admin_owner_approval_consumptions',
        ] as $table) {
            if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'sqlite') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
        }

        Schema::dropIfExists('admin_owner_approval_consumptions');
        Schema::dropIfExists('admin_owner_approval_challenges');
        Schema::dropIfExists('admin_owner_approval_trust_anchor_revocations');
        Schema::dropIfExists('admin_owner_approval_trust_anchors');
    }
};
