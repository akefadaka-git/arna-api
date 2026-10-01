<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — Amendment 1 of 8
 * =============================================================================
 *
 * File:           2026_09_11_000004_create_share_holding_entries_table.php
 * Unit:           P2.2 — immutable timestamp corrections (source amendment)
 * Delivery type:  Full replacement of an existing file
 * Net lines:      +1 line in up(); +~15 lines in docblock
 *
 * Before (original source):
 *   $table->timestamp('occurred_at')
 *         ->comment('Business time the change occurred (NOT the row insert time)');
 *
 *   Under the deployed server's `explicit_defaults_for_timestamp = OFF`,
 *   MySQL added `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
 *   implicitly. Verified in the DB dump.
 *
 * After (amended source):
 *   $table->dateTime('occurred_at')
 *         ->comment('Business time the change occurred (NOT the row insert time)');
 *
 *   DATETIME is immune to MySQL's implicit DEFAULT / ON UPDATE behavior
 *   in every server mode. A `migrate:fresh` on any server produces the
 *   corrected column directly, in the original migration's run, with no
 *   intermediate TIMESTAMP state and no ALTER anywhere in the sequence.
 *
 * Every other line — every column, comment, foreign key action, and
 * index name — is byte-for-byte identical to the source the user
 * supplied.
 *
 * Operational consequence:
 *   · `migrate:fresh` produces the corrected schema.
 *   · The existing deployed database still has the old (defective)
 *     schema; it is corrected by re-running `migrate:fresh` on the
 *     target (which drops every table, including `audit_logs` and
 *     `personal_access_tokens` — see the deployment note).
 *   · The three P2.2a/b/c ALTER migrations previously delivered are
 *     discarded; do not deploy them.
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Migration filename:        unchanged
 * Migration timestamp:       unchanged
 * =============================================================================
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `share_holding_entries` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * The append-only ledger of every balance change in the Shareholder domain.
 * Every change to `share_holdings.current_quantity` produces exactly one
 * row here, written inside the same database transaction that updates the
 * projection.
 *
 * Each entry records:
 *
 *   quantity        — the SIGNED change applied to the holding. Positive
 *                     for increases, negative for decreases. The sign
 *                     encodes direction; there is no separate direction
 *                     column.
 *
 *   balance_before  — the value of `share_holdings.current_quantity`
 *                     immediately before this entry.
 *
 *   balance_after   — the value of `share_holdings.current_quantity`
 *                     immediately after this entry.
 *
 *   entry_type      — a controlled vocabulary value describing WHAT kind
 *                     of business event produced the change (D16-R8):
 *
 *                         subscription
 *                         transfer_in
 *                         transfer_out
 *                         allocation
 *                         adjustment
 *                         reversal
 *
 *   reference_type  — an optional free-form string identifying the source
 *   reference_id      object of the change (e.g. "ShareTransfer" / "42").
 *                     This is the primary audit link from the ledger back
 *                     to the business operation. The `description` field
 *                     is informational and MUST NOT be the only
 *                     explanation of what happened.
 *
 *   occurred_at     — the business time of the event. Distinct from
 *                     `created_at`: an administrative backfill may insert
 *                     an entry now for an event that occurred earlier.
 *                     The ledger's entries are ordered by this column.
 *
 *   created_by      — the user who recorded the entry. Nullable for
 *                     system-generated entries.
 *
 *   reversed_entry_id — set on a reversal entry. Points back to the
 *                       original entry being reversed.
 *
 * =============================================================================
 * The ledger is authoritative
 * =============================================================================
 * Decision D16-CORE:
 *
 *     ShareHolding       = current-state projection
 *     ShareHoldingEntry  = append-only ledger
 *
 * The invariant
 *
 *     balance_before + quantity = balance_after
 *
 * holds for every entry ever written. If the projection and the ledger
 * ever disagree, the ledger is authoritative and the projection is
 * rebuilt from it.
 *
 * =============================================================================
 * Immutability (D16-R1)
 * =============================================================================
 * A posted entry is immutable:
 *
 *   - This migration declares NO `updated_at` column. The model
 *     (2A.9) sets `$timestamps = false` so Eloquent does not attempt to
 *     touch a non-existent timestamp on save.
 *
 *   - The domain code provides no path that updates or deletes a posted
 *     entry. Corrections are new rows.
 *
 *   - The `created_at` column is written explicitly by
 *     ShareHoldingService::persistEntry() (2A.14) at insertion time.
 *
 * If a future business requirement genuinely needs to modify a posted
 * entry, the correct response is a CR that adds a new entry type or a new
 * reversal pattern — never a mutation of an existing row.
 *
 * =============================================================================
 * Business time immutability — occurred_at is DATETIME (v1.0.2)
 * =============================================================================
 * `occurred_at` is declared as DATETIME rather than TIMESTAMP.
 *
 * DATETIME is not subject to MySQL's server-mode-dependent implicit
 * default behavior. A TIMESTAMP NOT NULL column, on a server running
 * with `explicit_defaults_for_timestamp = OFF`, receives an implicit
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` — which would
 * contradict this table's append-only contract and silently rewrite the
 * business time of a ledger entry on any UPDATE.
 *
 * DATETIME carries no such behavior in any server mode. The column's
 * value is set explicitly by ShareHoldingService::persistEntry() at
 * insertion time and is never modified afterwards.
 *
 * The type change is a semantic no-op for the application: DATETIME
 * and TIMESTAMP both store a second-resolution instant interpreted in
 * the connection timezone. The model's `datetime` cast and every
 * service-layer write are unaffected.
 *
 * See also P2.2's companion amendments in the Wallet domain (six
 * columns across six tables) and the Policy capability
 * (`versioned_policies.effective_from`), which apply the same DATETIME
 * correction for the same reason.
 *
 * =============================================================================
 * Reversal linkage (D16-R2, D16-X5)
 * =============================================================================
 * The `reversed_entry_id` column is a nullable self-reference. It is
 * non-null only on a reversal entry, and it points at the original posted
 * entry that the reversal undoes.
 *
 * The link is ONE-DIRECTIONAL: only the reversal points at the original;
 * the original carries no "reversed by" column. A reader that needs to
 * know whether an entry has been reversed runs a reverse query against
 * `reversed_entry_id`.
 *
 *   - On delete: nullify. If a reversal row is hard-deleted (which the
 *     domain does not do), the original survives and the pointer
 *     nullifies. This is safer than `cascadeOnDelete`, which would
 *     silently remove the original row as a side effect.
 *
 * =============================================================================
 * No direction column (F-2A4-1)
 * =============================================================================
 * The direction of the change is encoded by the SIGN of `quantity`.
 * Positive is an increase (In), negative is a decrease (Out), zero is
 * neutral. Adding a `direction` column would duplicate state that is
 * already unambiguous from the sign and could drift out of agreement with
 * it. The model (2A.9) provides a `direction()` accessor that derives the
 * enumerated value from the sign on demand.
 *
 * =============================================================================
 * No unique constraint
 * =============================================================================
 * The ledger is append-only. Multiple entries can share the same
 * `reference_type` + `reference_id` (a single business operation could
 * conceivably produce more than one ledger line in future phases),
 * multiple entries reference the same holding (that is the normal case),
 * and — in principle — the domain's "no double reversal" rule is enforced
 * by ShareHoldingService (2A.14), not by a schema constraint.
 *
 * The absence of a UNIQUE on `reversed_entry_id` is deliberate. Adding one
 * would prevent a legitimate business operation that produced two
 * reversals from being recorded. A partial unique index (UNIQUE WHERE
 * reversed_entry_id IS NOT NULL AND entry_type = 'reversal') could
 * express the constraint, but Laravel's migration DSL does not expose
 * filtered indexes portably. The service-layer check suffices for Phase
 * 2A; see F-2A14-6 for the residual race condition.
 *
 * =============================================================================
 * Whole-number quantities (D6)
 * =============================================================================
 * The `quantity`, `balance_before`, and `balance_after` columns are
 * DECIMAL(20,4). The scale of 4 reserves four decimal places for future
 * business needs (D16-X2), but D6 forbids fractional shares: a share is
 * an indivisible unit.
 *
 * The service (ShareHoldingService, 2A.14) therefore accepts only
 * positive whole-number magnitudes and computes the new balances in
 * whole-number arithmetic. The schema does not enforce the whole-number
 * rule because DECIMAL is inherently fractional and a CHECK constraint
 * against `FLOOR(...)` would add coupling to a rule the service already
 * enforces with a clearer error. If the business later permits fractional
 * shares, only the service's validation rule is relaxed; the schema is
 * unchanged.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Five indexes support the query patterns this table must serve:
 *
 *   (share_holding_id, occurred_at)   — the primary read pattern: the
 *                                       ledger of a holding, in
 *                                       chronological order.
 *
 *   (entry_type)                      — audit queries filtering by type.
 *
 *   (reversed_entry_id)               — the reverse-lookup for "which
 *                                       entries point at this one?" and
 *                                       the double-reversal check in
 *                                       ShareHoldingService::reverseEntry().
 *
 *   (reference_type, reference_id)    — the "find ledger entries for
 *                                       business operation X" pattern.
 *
 *   (created_by)                      — "which entries did this user
 *                                       record?" queries.
 *
 * No index is declared on `quantity` or on either balance column. No
 * query pattern in Phase 2A filters or sorts by those values.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `share_holding_entries` table. The foreign key targets
     * the `share_holdings` table (2A.39) — which must already exist — and
     * the Phase 1 `users` table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('share_holding_entries', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Owning holding.
            //
            // On delete: cascade. A hard delete of the holding (unusual —
            // see F-2A39-1) removes its ledger entries. In normal
            // operation, holdings are never deleted; the row persists even
            // at zero quantity, so the ledger remains anchored.
            // -----------------------------------------------------------------
            $table->foreignId('share_holding_id')
                  ->constrained('share_holdings')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete()
                  ->comment('Owning share holding');

            // -----------------------------------------------------------------
            // Entry type — controlled vocabulary (D16-R8).
            //
            // VARCHAR(32) leaves headroom above the longest current value
            // (`transfer_out`, 12 characters). The set of valid values is
            // declared in config/shareholder.php (2A.1) and enforced by
            // ShareHoldingService (2A.14).
            // -----------------------------------------------------------------
            $table->string('entry_type', 32)
                  ->comment('Controlled entry-type vocabulary value (D16-R8)');

            // -----------------------------------------------------------------
            // Signed quantity — the change applied to the holding.
            //
            // Positive for increases, negative for decreases. The sign
            // encodes direction (F-2A4-1); there is no separate direction
            // column.
            // -----------------------------------------------------------------
            $table->decimal('quantity', 20, 4)
                  ->comment('Signed quantity change (positive = increase, negative = decrease)');

            // -----------------------------------------------------------------
            // Before / after balances — the ledger's audit invariant.
            //
            // Every entry satisfies: balance_before + quantity = balance_after.
            // These two values are stored (rather than derived on read)
            // because they are what makes the ledger self-diagnosing and
            // cheap to audit (D16-R4).
            // -----------------------------------------------------------------
            $table->decimal('balance_before', 20, 4)
                  ->comment('Holding quantity immediately before this entry');

            $table->decimal('balance_after', 20, 4)
                  ->comment('Holding quantity immediately after this entry');

            // -----------------------------------------------------------------
            // Reference metadata — the primary audit link back to the
            // business operation that caused this entry. Free-form strings
            // from the schema's perspective; the caller chooses the
            // vocabulary.
            // -----------------------------------------------------------------
            $table->string('reference_type', 64)
                  ->nullable()
                  ->comment('Optional label of the source operation (e.g. ShareTransfer)');

            $table->string('reference_id', 64)
                  ->nullable()
                  ->comment('Optional identifier of the source operation');

            // -----------------------------------------------------------------
            // Free-text description. Informational only; not a substitute
            // for reference_type / reference_id as the primary audit link.
            // -----------------------------------------------------------------
            $table->string('description', 500)
                  ->nullable()
                  ->comment('Optional human-readable note');

            // -----------------------------------------------------------------
            // Business time of the event.
            //
            // DATETIME, not TIMESTAMP. See the class docblock
            // §"Business time immutability — occurred_at is DATETIME".
            // Distinct from created_at; a backfill can insert an entry
            // now for an event that occurred earlier. The ledger's read
            // queries order by this column.
            // -----------------------------------------------------------------
            $table->dateTime('occurred_at')
                  ->comment('Business time the change occurred (NOT the row insert time)');

            // -----------------------------------------------------------------
            // Actor.
            //
            // On delete: nullify. A hard-deleted User detaches from the
            // ledger entry; the entry survives with no actor. Nullable at
            // the schema level because system-generated entries have no
            // user behind them.
            // -----------------------------------------------------------------
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who recorded the entry (nullable)');

            // -----------------------------------------------------------------
            // Reversal linkage (D16-R2, D16-X5).
            //
            // A nullable self-reference. Non-null only on a reversal entry;
            // points at the original entry that the reversal undoes. The
            // original never carries a "reversed by" column; the link is
            // one-directional from reversal to original.
            //
            // On delete: nullify. See the class-level docblock for the
            // rationale (deliberately NOT cascadeOnDelete).
            // -----------------------------------------------------------------
            $table->foreignId('reversed_entry_id')
                  ->nullable()
                  ->constrained('share_holding_entries')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('The entry this one reverses (nullable, reversal rows only)');

            // -----------------------------------------------------------------
            // Insert time. Written explicitly by ShareHoldingService (2A.14)
            // because the model sets $timestamps = false. There is no
            // updated_at — posted entries are immutable (D16-R1).
            // -----------------------------------------------------------------
            $table->timestamp('created_at')
                  ->nullable()
                  ->comment('Row insert timestamp (written explicitly, no updated_at)');

            // -----------------------------------------------------------------
            // Indexes. See the class-level docblock for the rationale
            // behind each.
            // -----------------------------------------------------------------
            $table->index(['share_holding_id', 'occurred_at'], 'she_holding_occurred_index');
            $table->index('entry_type', 'she_entry_type_index');
            $table->index('reversed_entry_id', 'she_reversed_entry_index');
            $table->index(['reference_type', 'reference_id'], 'she_reference_index');
            $table->index('created_by', 'she_created_by_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because the table self-references via
     * `reversed_entry_id`, MySQL may refuse to drop a table with a
     * self-referencing FK constraint while rows exist. Laravel's
     * `Schema::dropIfExists` issues a `DROP TABLE`, which MySQL processes
     * by first dropping the table's constraints; the self-reference is
     * handled in the same statement. No data is preserved on rollback,
     * which is consistent with the ledger's append-only posture in normal
     * operation — the operator must intend to destroy the ledger.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('share_holding_entries');
    }
};