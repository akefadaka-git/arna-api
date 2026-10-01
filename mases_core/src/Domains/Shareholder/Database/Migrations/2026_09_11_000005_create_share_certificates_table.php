<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `share_certificates` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * A share certificate is an official document attesting that a specific
 * shareholder holds a specific quantity of a specific share class. Each
 * row records one certificate's identity, its lifecycle state, and its
 * administrative metadata.
 *
 * The table stores NO quantity column. The certified quantity is what the
 * holding's balance was at the time of issue; it is derivable from the
 * ledger (`share_holding_entries`) as of `issued_at`. Phase 2A does not
 * snapshot it — see F-2A10-1 for the rationale and the future CR path.
 *
 * The table stores NO monetary value. Monetary fields, when they matter,
 * belong to future transaction objects (subscriptions, transfers,
 * issuances) — see D16-X6.
 *
 * =============================================================================
 * Lifecycle (CertificateStatus, 2A.5)
 * =============================================================================
 * `status` holds one of four values from the CertificateStatus enum:
 *
 *     draft      — prepared but not yet issued. Non-terminal.
 *     issued     — in force. Non-terminal.
 *     cancelled  — withdrawn. Terminal.
 *     replaced   — superseded by another certificate. Terminal.
 *
 * The column is VARCHAR(16) — the longest value is `cancelled`
 * (9 characters), with headroom. It is NOT NULL. The default is `issued`
 * because Phase 2A issues certificates directly; `draft` is reserved for a
 * future prepare-then-issue workflow.
 *
 * The column is NOT a MySQL ENUM. Adding a state is a Change Request that
 * updates the CertificateStatus enum (2A.5) and the config vocabulary
 * (2A.1); a MySQL ENUM would require a schema migration for every state
 * change and would couple the migration to application-code terminology.
 *
 * =============================================================================
 * Certificate number
 * =============================================================================
 * `certificate_number` is nullable (pre-flight answer). In Phase 2A, no
 * automatic allocation is performed by the domain — the operator supplies
 * the value or leaves it null. Two certificates cannot share a non-null
 * number: the UNIQUE index enforces this.
 *
 * The UNIQUE is GLOBAL (not per-tenant). Phase 2A operates with a single
 * tenant. If MASES later becomes genuinely multi-tenant, the uniqueness
 * contract must become `(tenant_id, certificate_number)` — which requires
 * adding a `tenant_id` column and a follow-up CR. See F-2A10-4.
 *
 * The column is VARCHAR(64) — 64 characters, matching the
 * StoreCertificateRequest validation (2A.20).
 *
 * =============================================================================
 * Timestamps
 * =============================================================================
 * Four timestamp columns:
 *
 *   issued_at      — when the certificate entered `issued` state. Set by
 *                    CertificateService::issue() and ::replace() (2A.15).
 *                    Nullable: a hypothetical `draft` certificate has no
 *                    issuance time.
 *
 *   cancelled_at   — when the certificate entered `cancelled` state. Set
 *                    by CertificateService::cancel() (2A.15). Nullable:
 *                    certificates that have not been cancelled have no
 *                    value.
 *
 *   created_at /   — Eloquent's standard timestamps, maintained
 *   updated_at       automatically by the model (2A.10).
 *
 * The distinction between `issued_at` and `created_at` is intentional:
 * a certificate created in a `draft` state may be issued days later; the
 * two timestamps record different facts.
 *
 * =============================================================================
 * Replacement chain (F-2A10-3)
 * =============================================================================
 * `replaced_by_id` is a nullable self-reference. It is non-null only on a
 * certificate whose status is `replaced`, and it points at the NEW
 * certificate that supersedes it.
 *
 * The link is ONE-DIRECTIONAL: only the replaced (older) certificate points
 * at the replacing (newer) one. A reader that needs "what did this
 * certificate replace?" runs a reverse query against `replaced_by_id`.
 * This avoids the two-way consistency problem that arises when both
 * directions are stored and can disagree.
 *
 * On delete: nullify. If the replacement certificate is hard-deleted
 * (which the domain does not do), the replaced certificate survives with
 * its pointer cleared. This is safer than cascadeOnDelete, which would
 * silently remove the replaced certificate as a side effect.
 *
 * =============================================================================
 * Soft deletes
 * =============================================================================
 * `deleted_at` supports Laravel's SoftDeletes trait, which the
 * ShareCertificate model uses (2A.10). The normal certificate lifecycle is
 * state transitions — cancellation or replacement — not deletion. Soft
 * deletion is provided as an emergency-recovery mechanism for
 * mistakenly-created rows, not as a routine operation (F-2A10-6).
 *
 * A soft-deleted certificate that was previously `issued` is a data
 * anomaly, not a normal state. The domain does not produce such rows; a
 * manual DB edit could.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Five indexes support the query patterns this table must serve:
 *
 *   (shareholder_id)     — "list all certificates for this shareholder".
 *   (share_class_id)     — "which certificates exist for this class?".
 *   (share_holding_id)   — "which certificates attest to this holding?".
 *   (status)             — audit and reporting queries filtered by state.
 *   (replaced_by_id)     — reverse-lookup for "what did this certificate
 *                          replace?" and the replacement-chain traversal.
 *
 * Plus the UNIQUE on `certificate_number` — with MySQL's semantics, NULL
 * values are treated as distinct, so many certificates can have a NULL
 * number without conflicting.
 *
 * The foreign-key columns receive implicit indexes from MySQL when their
 * FK constraints are created; the explicit declarations below are declared
 * for non-FK query patterns and for readability.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The "at most one active certificate per holding" invariant. That
 *     invariant depends on the `status` value, which MySQL cannot express
 *     in a portable partial unique index via Laravel's migration DSL. It
 *     is enforced by CertificateService::issue() (2A.15) inside its
 *     issuance transaction. See F-2A10-5.
 *
 *   - The "replacement target exists and is in the right state" invariant.
 *     That is CertificateService::replace()'s responsibility (2A.15).
 *
 *   - The "cancellation requires a prior issued/draft state" transition
 *     matrix. That is enforced by CertificateStatus::canTransitionTo()
 *     (2A.5) inside CertificateService::cancel() (2A.15).
 *
 *   - The existence of the shareholder, class, and (optional) holding
 *     referenced by the FKs is enforced by the FK constraints themselves.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `share_certificates` table. Foreign keys target
     * `shareholders` (2A.38), `share_classes` (2A.37), `share_holdings`
     * (2A.39), and the same table (self-reference for `replaced_by_id`).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('share_certificates', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Owning shareholder.
            //
            // On delete: cascade. A hard delete of the shareholder removes
            // its certificates. In normal operation, shareholders use
            // SoftDeletes and this cascade never fires.
            // -----------------------------------------------------------------
            $table->foreignId('shareholder_id')
                  ->constrained('shareholders')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Owning shareholder');

            // -----------------------------------------------------------------
            // Share class.
            //
            // On delete: restrict. A hard delete of a share class is
            // blocked while any certificate references it.
            // -----------------------------------------------------------------
            $table->foreignId('share_class_id')
                  ->constrained('share_classes')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Share class the certificate is denominated in');

            // -----------------------------------------------------------------
            // Linked holding.
            //
            // Nullable. A certificate attests to a holding, but is not
            // owned by it. If the holding is hard-deleted (unusual), the
            // certificate survives with its pointer cleared.
            // -----------------------------------------------------------------
            $table->foreignId('share_holding_id')
                  ->nullable()
                  ->constrained('share_holdings')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Holding the certificate attests to (nullable)');

            // -----------------------------------------------------------------
            // Certificate number.
            //
            // Nullable — Phase 2A does not auto-generate numbers. UNIQUE
            // across non-null values; MySQL treats NULL as distinct in a
            // unique index, so many certificates can have no number.
            // -----------------------------------------------------------------
            $table->string('certificate_number', 64)
                  ->nullable()
                  ->unique()
                  ->comment('Manually assigned certificate number (nullable, unique)');

            // -----------------------------------------------------------------
            // Lifecycle status.
            //
            // Values from CertificateStatus (2A.5):
            //     draft | issued | cancelled | replaced
            //
            // Default `issued` reflects Phase 2A's direct-issuance model.
            // -----------------------------------------------------------------
            $table->string('status', 16)
                  ->default('issued')
                  ->comment('Lifecycle status: draft | issued | cancelled | replaced');

            // -----------------------------------------------------------------
            // Lifecycle timestamps.
            //
            // `issued_at` is set on issuance; `cancelled_at` on
            // cancellation. Both nullable — a hypothetical draft has no
            // issuance time; a non-cancelled certificate has no
            // cancellation time.
            // -----------------------------------------------------------------
            $table->timestamp('issued_at')
                  ->nullable()
                  ->comment('When the certificate entered the issued state (nullable)');

            $table->timestamp('cancelled_at')
                  ->nullable()
                  ->comment('When the certificate was cancelled (nullable)');

            // -----------------------------------------------------------------
            // Replacement chain.
            //
            // On delete: nullify (see class docblock). One-directional:
            // only the replaced certificate points at the replacement.
            // -----------------------------------------------------------------
            $table->foreignId('replaced_by_id')
                  ->nullable()
                  ->constrained('share_certificates')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('Certificate that replaced this one (nullable, self-reference)');

            // -----------------------------------------------------------------
            // Notes.
            //
            // Free-text field. CertificateService::cancel() appends the
            // cancellation reason here, separated by a `---` line and a
            // `Cancellation reason:` label. TEXT because the column has no
            // practical length ceiling.
            // -----------------------------------------------------------------
            $table->text('notes')
                  ->nullable()
                  ->comment('Optional free-text notes; cancellation reason is appended here');

            // -----------------------------------------------------------------
            // Timestamps and soft deletes.
            // -----------------------------------------------------------------
            $table->timestamps();

            $table->softDeletes()
                  ->comment('Soft-delete timestamp; emergency-recovery mechanism only');

            // -----------------------------------------------------------------
            // Indexes. See the class-level docblock for the rationale
            // behind each.
            // -----------------------------------------------------------------
            $table->index('shareholder_id', 'sc_shareholder_index');
            $table->index('share_class_id', 'sc_share_class_index');
            $table->index('share_holding_id', 'sc_share_holding_index');
            $table->index('status', 'sc_status_index');
            $table->index('replaced_by_id', 'sc_replaced_by_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because the table self-references via
     * `replaced_by_id`, MySQL processes the self-referencing FK constraint
     * as part of the `DROP TABLE` statement. No data is preserved on
     * rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('share_certificates');
    }
};