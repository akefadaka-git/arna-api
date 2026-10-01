<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `loan_collaterals` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * One row per collateral registered against a loan. Each row records the
 * security interest the company holds as protection against the borrower's
 * default, its type, its valuation (where applicable), and its lifecycle
 * status (registered or released).
 *
 * Phase 2C implements two collateral types (A.4, per A10 and B4):
 *
 *     external      — a collateral asset held outside the MASES ledger
 *                     (real property, equipment, third-party instruments,
 *                     and so on). Its ownership and valuation are recorded
 *                     directly on this row.
 *
 *     shareholding  — a pledge against a shareholder's shareholding. The
 *                     row carries the linkage columns
 *                     (`pledged_shareholding_id`, `pledged_quantity`); the
 *                     actual FK and mutation workflow are deferred to a
 *                     Phase 2A Change Request
 *                     (CR-2C-COLLATERAL-PLEDGE-FK-001). See
 *                     §"Deferred pledge FK" below.
 *
 * Cash-deposit collateral is explicitly out of Phase 2C scope (B4). A
 * guarantor is a distinct security mechanism and is not modelled as a
 * `CollateralType` case (see `CollateralType`, A.4, for the rationale).
 *
 * =============================================================================
 * What this table is NOT
 * =============================================================================
 *   - It is NOT a monetary ledger. Collateral is a security interest, not
 *     a Wallet entry. Registering a collateral does NOT record a Wallet
 *     obligation, payment, or adjustment. The Wallet's ledger is
 *     untouched by every operation on this table.
 *
 *   - It is NOT an enforcement mechanism. A10 explicitly excludes
 *     automatic enforcement and seizure from Phase 2C. There is no
 *     `forfeited` state (see `CollateralStatus`, A.3) and no workflow
 *     that realizes collateral value. When a future Change Request
 *     introduces enforcement, that CR adds the workflow and any new
 *     state.
 *
 *   - It is NOT a Shareholder-ledger mutation. For a shareholding
 *     pledge, the Loan domain does NOT modify
 *     `share_holdings.current_quantity`, `share_holding_entries`, or any
 *     other Shareholder-domain row. It records the pledge on this table
 *     and, once the Phase 2A Change Request lands, references the
 *     authoritative pledge relationship (see §"Deferred pledge FK").
 *
 * =============================================================================
 * Deferred pledge FK (B4, C3, CR-2C-COLLATERAL-PLEDGE-FK-001)
 * =============================================================================
 * The `pledged_shareholding_id` column is declared as a plain
 * `unsignedBigInteger` with `->nullable()` and NO `->constrained()` call.
 * This is a deliberate, documented deferral, not an oversight:
 *
 *   1. The authoritative pledge relationship — the `shareholding_pledges`
 *      table — does not exist in Phase 2A. Creating it is a Phase 2A
 *      Change Request (CR-2C-COLLATERAL-PLEDGE-FK-001). Until that CR
 *      lands, there is no target table for the FK.
 *
 *   2. Fabricating a `shareholding_pledges` table here would be a Phase
 *      2A change disguised as a Phase 2C migration. It would violate the
 *      domain-boundary discipline (Phase 2C's mandate is the Loan
 *      domain, not the Shareholder domain) and would preempt the Phase 2A
 *      CR's design decisions about the pledge relationship's shape.
 *
 *   3. The column exists so that the Loan-side schema does not need a
 *      second migration when the Phase 2A CR lands. The FK addition will
 *      be a small, additive migration that references
 *      `shareholding_pledges.id` (or, if the Phase 2A CR chooses a
 *      different target, `share_holdings.id`), plus a backfill step for
 *      any non-null `pledged_shareholding_id` values already present.
 *
 * Until the FK is added, the value in this column is enforced by
 * `LoanCollateralService` (A.33), not by the database:
 *
 *   - When registering a `shareholding`-typed collateral, the service
 *     validates that the referenced shareholding exists and belongs to
 *     the loan's borrower. A value that does not satisfy these conditions
 *     causes a `ValidationException` (422).
 *
 *   - When the FK is later added, the migration that adds it must:
 *       1. Verify no orphaned values exist (rows whose
 *          `pledged_shareholding_id` does not resolve).
 *       2. Add the FK with `RESTRICT` on delete (matching the platform's
 *          Q6 posture for financial FKs).
 *       3. Remove the "deferred" note from this file's docblock.
 *
 * The migration's column comment records the deferral so that a reader of
 * the schema is informed without having to consult this docblock.
 *
 * =============================================================================
 * Status (A.3)
 * =============================================================================
 * `status` stores the `CollateralStatus` enum value:
 *
 *     registered   — the collateral is held. Non-terminal.
 *     released     — the collateral has been released. Terminal.
 *
 * The default is `'registered'`. The column is VARCHAR(16) — the longest
 * current value is `registered` (10 characters) — not a MySQL ENUM,
 * matching the platform-wide convention.
 *
 * Transition: `registered → released`, performed exclusively by
 * `LoanCollateralService::release()` (A.33). The service composes the
 * structural check (`CollateralStatus::canTransitionTo()`, A.3) with the
 * `loan.collateral.release` permission gate, the loan-state guard, and
 * the after-commit `LoanCollateralReleased` event dispatch.
 *
 * =============================================================================
 * Type-specific attribute requirements (A.4)
 * =============================================================================
 * The two collateral types carry different attribute requirements, enforced
 * by `LoanCollateralService` (A.33):
 *
 *   external:
 *       description              — required
 *       valuation_amount         — required (positive)
 *       valuation_currency       — required (ISO 4217 shape)
 *       valuation_date           — required
 *       external_reference       — optional
 *       pledged_shareholding_id  — must be null
 *       pledged_quantity         — must be null
 *
 *   shareholding:
 *       description              — required
 *       pledged_shareholding_id  — required
 *       pledged_quantity         — required (positive)
 *       external_reference       — optional
 *       valuation_amount         — optional (required only if the product
 *                                   policy requires pledged-share valuation)
 *       valuation_currency       — paired with valuation_amount (required
 *                                   iff valuation_amount is non-null)
 *       valuation_date           — paired with valuation_amount
 *
 * The schema declares every attribute column nullable because MySQL cannot
 * portably express "column X is required iff column Y has value Z" via
 * Laravel's migration DSL, and because a service-layer rejection produces
 * a structured 422 with a per-field error message — materially better than
 * a database CHECK violation. This mirrors the Phase 2A `shareholders`
 * table's type-conditional name rules and the Phase 2B `wallets` table's
 * cross-column invariants.
 *
 * =============================================================================
 * Valuation
 * =============================================================================
 * `valuation_amount`, `valuation_currency`, and `valuation_date` record the
 * collateral's valuation. For an external collateral, all three are
 * required by the service. For a shareholding, all three are optional —
 * the product's collateral policy decides whether pledged shares must be
 * valued, and if the policy is silent, the service records no valuation.
 *
 * The currency is stored as a separate column rather than folded into the
 * amount string so that reports can filter or group by currency without
 * parsing the amount. The `CHAR(3)` shape matches every other currency
 * column on the platform.
 *
 * The valuation is a POINT-IN-TIME fact. If the collateral's valuation
 * changes, a future Change Request may introduce a `loan_collateral_
 * valuations` history table (or equivalent); Phase 2C does not, because no
 * decision in the register requires periodic revaluation.
 *
 * =============================================================================
 * Release
 * =============================================================================
 * When a collateral is released (via
 * `POST /loans/{loan}/collateral/{collateral}/release`), the service:
 *
 *   1. Verifies the `loan.collateral.release` permission.
 *   2. Verifies the loan is in a state that permits release (not in a
 *      terminal lifecycle state).
 *   3. Verifies the collateral is currently `registered`.
 *   4. Sets `status = 'released'`, `released_at = now()`,
 *      `release_reason = <caller-supplied>`, `released_by = <actor>`.
 *   5. Dispatches `LoanCollateralReleased` after commit.
 *
 * The row is NEVER deleted. A released collateral remains in the table for
 * audit. If a subsequent security interest is required for the same loan,
 * a new row is registered.
 *
 * =============================================================================
 * Foreign keys
 * =============================================================================
 * Three foreign keys, plus the deferred pledge linkage:
 *
 *     loan_id        → loans.id   RESTRICT
 *     registered_by  → users.id   SET NULL (nullable)
 *     released_by    → users.id   SET NULL (nullable)
 *     pledged_shareholding_id  → (none — deferred; see §"Deferred pledge FK")
 *
 * The `loan_id` FK is RESTRICT per Q6 and the Phase 2B precedent: a hard
 * delete of a loan must not cascade into its collateral history. The two
 * actor FKs use `nullOnDelete()` because the actor is metadata, not an
 * aggregate fact; a hard-deleted user detaches from the collateral, which
 * survives with a null actor. This mirrors the pattern on `wallets` (Phase
 * 2B) and `loans` (A.9).
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * Two composite indexes support the Phase 2C query patterns:
 *
 *   (loan_id, status)       — "list a loan's active collateral", the
 *                             primary read pattern for the
 *                             `GET /loans/{loan}/collateral` endpoint and
 *                             for any reconciliation or reporting path
 *                             that enumerates active collateral.
 *
 *   (pledged_shareholding_id) — "which loans have pledged this
 *                             shareholding?", used by the future Phase 2A
 *                             CR's backfill step and by any future report
 *                             that cross-references a shareholding
 *                             against its pledges. The index exists in
 *                             Phase 2C as defensive support for that
 *                             future CR; no Phase 2C code path issues the
 *                             query today.
 *
 * The `loan_id` FK's implicit index is satisfied by the composite
 * `(loan_id, status)`'s leading column; no separate single-column index on
 * `loan_id` is declared.
 *
 * No index on `status` alone. Every 2C query that filters by status is
 * per-loan; the composite `(loan_id, status)` serves those queries
 * efficiently.
 *
 * No index on `valuation_currency`. No 2C query groups or filters
 * collateral by currency. A future report that requires it can be added by
 * its own CR.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The presence of a `pledged_shareholding_id` for shareholding-typed
 *     collateral. Service-layer concern (see §"Deferred pledge FK").
 *
 *   - The absence of a `pledged_shareholding_id` for external-typed
 *     collateral. Service-layer concern.
 *
 *   - The type-specific attribute requirements documented above.
 *     Service-layer concern.
 *
 *   - The `release_reason` presence at release. The column is nullable at
 *     the schema level because it is only meaningful in the `released`
 *     state; the service requires a non-empty reason on every release.
 *
 *   - The `registered → released` transition matrix. Enforced by
 *     `CollateralStatus::canTransitionTo()` (A.3) and by
 *     `LoanCollateralService::release()` (A.33).
 *
 *   - Any linkage to a Shareholder-domain pledge workflow. Deferred to
 *     the Phase 2A CR.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `loan_collaterals` table. Foreign keys target the
     * `loans` table (A.9) and the Phase 1 `users` table, both of which
     * must already exist when this migration runs.
     *
     * Migration ordering: A.9 (`loans`, prefix `200020`) runs before this
     * file (prefix `200040`).
     *
     * The `pledged_shareholding_id` column does NOT declare an FK; see
     * the class docblock §"Deferred pledge FK" for the rationale and for
     * the Change Request that will add it.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('loan_collaterals', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Owning loan.
            //
            // Every collateral belongs to exactly one loan. The composite
            // index (loan_id, status) declared below provides the FK's
            // implicit index on `loan_id` — no separate single-column
            // index is declared.
            //
            // On delete: restrict. A hard delete of the loan must not
            // cascade into its collateral history (Q6, Architecture §17.3).
            // -----------------------------------------------------------------
            $table->foreignId('loan_id')
                  ->constrained('loans')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning loan');

            // -----------------------------------------------------------------
            // Collateral type.
            //
            // Stores a `CollateralType` enum value (A.4):
            //
            //     external | shareholding
            //
            // The default is not set: every row must declare its type at
            // creation time. A NULL or empty type would be a data defect
            // that the service layer would reject; making the column NOT
            // NULL without a default enforces the "must be supplied"
            // requirement at the schema level as well.
            //
            // VARCHAR(32) accommodates every current value with headroom
            // (the longest is `shareholding`, 12 characters). The column
            // is not a MySQL ENUM; adding a type updates the enum, not
            // the schema.
            // -----------------------------------------------------------------
            $table->string('collateral_type', 32)
                  ->comment('CollateralType enum value: external | shareholding');

            // -----------------------------------------------------------------
            // Description.
            //
            // Required free-text identification of the collateral. For an
            // external collateral, this describes the asset (e.g. "2019
            // Toyota Hilux, plate AA-12345"). For a shareholding pledge,
            // it describes the pledge arrangement.
            //
            // The column is NOT NULL. The service enforces a non-empty
            // trimmed value; the schema enforces only that the column
            // has some value at insert time. NULL is forbidden at the
            // schema level so that a caller cannot accidentally leave
            // the collateral undescribed.
            //
            // VARCHAR(500) matches the description columns on the Phase
            // 2B wallet tables and on `loans` (A.9).
            // -----------------------------------------------------------------
            $table->string('description', 500)
                  ->comment('Required free-text description of the collateral');

            // -----------------------------------------------------------------
            // External reference.
            //
            // An optional identifier for the collateral in an external
            // registry: a land title number, a vehicle registration, a
            // third-party instrument's serial number, or any other
            // externally-issued identifier.
            //
            // Opaque to the Loan domain. The consuming workflow (Loan or
            // an administrative process) owns the vocabulary.
            //
            // VARCHAR(64) matches the reference-id width used on the
            // Phase 2B wallet reference pairs.
            // -----------------------------------------------------------------
            $table->string('external_reference', 64)
                  ->nullable()
                  ->comment('Optional external identifier (title number, registration, etc.)');

            // -----------------------------------------------------------------
            // Pledged shareholding linkage (deferred FK).
            //
            // For a `shareholding`-typed collateral, this column records
            // the id of the pledged shareholding. For an `external`-
            // typed collateral, it MUST be null (enforced by the service).
            //
            // The column is declared as a plain `unsignedBigInteger` with
            // `->nullable()` and NO `->constrained()` call. The FK to the
            // authoritative pledge table (`shareholding_pledges`, or
            // whichever target the Phase 2A CR selects) is deferred to
            // CR-2C-COLLATERAL-PLEDGE-FK-001. See the class docblock
            // §"Deferred pledge FK" for the rationale.
            //
            // Until that CR lands, the value is validated by
            // `LoanCollateralService` (A.33), not by the database.
            // -----------------------------------------------------------------
            $table->unsignedBigInteger('pledged_shareholding_id')
                  ->nullable()
                  ->comment(
                      'Pledged shareholding id; FK deferred to '
                      . 'CR-2C-COLLATERAL-PLEDGE-FK-001 until the Phase 2A '
                      . 'pledge schema exists'
                  );

            // -----------------------------------------------------------------
            // Pledged quantity.
            //
            // For a `shareholding`-typed collateral, the quantity of
            // shares subject to the pledge. A positive DECIMAL(20,4),
            // matching the precision of `share_holdings.current_quantity`
            // on the Shareholder domain (Phase 2A).
            //
            // The service validates the quantity is positive and (if the
            // relevant pledge table exists) does not exceed the
            // shareholder's current holding. Until the Phase 2A CR lands,
            // the service validates only the shape and the positivity.
            //
            // MUST be null for an `external`-typed collateral (enforced
            // by the service).
            // -----------------------------------------------------------------
            $table->decimal('pledged_quantity', 20, 4)
                  ->nullable()
                  ->comment('Quantity of pledged shares (shareholding type only)');

            // -----------------------------------------------------------------
            // Valuation triple.
            //
            // The collateral's valuation amount, currency, and date.
            // Required for external collateral; optional for shareholding
            // (per the product's collateral policy).
            //
            // All three columns are nullable at the schema level; the
            // service enforces the type-specific requirements documented
            // in the class docblock §"Type-specific attribute
            // requirements".
            //
            // The `CHAR(3)` currency shape matches every other currency
            // column on the platform. The DATE (not timestamp) valuation
            // date matches the schedule's `due_date` granularity
            // (A.10) — a valuation is a business day, not an instant.
            // -----------------------------------------------------------------
            $table->decimal('valuation_amount', 20, 4)
                  ->nullable()
                  ->comment('Collateral valuation amount');

            $table->char('valuation_currency', 3)
                  ->nullable()
                  ->comment('ISO 4217 currency of the valuation; paired with valuation_amount');

            $table->date('valuation_date')
                  ->nullable()
                  ->comment('Date of the collateral valuation; paired with valuation_amount');

            // -----------------------------------------------------------------
            // Status.
            //
            // Stores a `CollateralStatus` enum value (A.3):
            //
            //     registered | released
            //
            // The default is `'registered'`, the state a collateral is
            // created in. VARCHAR(16) accommodates every current value
            // with headroom (the longest is `registered`, 10 characters).
            // Not a MySQL ENUM; adding a state updates the enum, not the
            // schema.
            // -----------------------------------------------------------------
            $table->string('status', 16)
                  ->default('registered')
                  ->comment('CollateralStatus enum value: registered | released');

            // -----------------------------------------------------------------
            // Lifecycle timestamps.
            //
            // `registered_at` is NOT NULL and defaults to
            // `CURRENT_TIMESTAMP` at row creation. It records the moment
            // the collateral was registered — the same moment the row is
            // inserted.
            //
            // `released_at`, `release_reason`, and `released_by` are
            // nullable. They are set only when the collateral transitions
            // to `released` via `LoanCollateralService::release()` (A.33).
            //
            // The three "release" columns are grouped together, mirroring
            // the grouping on `wallet_payment_allocation_reversals`
            // (Phase 2B): the release is a distinct fact, and its
            // metadata is kept alongside its timestamp.
            // -----------------------------------------------------------------
            $table->timestamp('registered_at')
                  ->useCurrent()
                  ->comment('Moment the collateral was registered');

            $table->timestamp('released_at')
                  ->nullable()
                  ->comment('Moment the collateral was released; nullable');

            $table->string('release_reason', 500)
                  ->nullable()
                  ->comment('Reason supplied by the releasing actor; nullable until release');

            // -----------------------------------------------------------------
            // Actor columns.
            //
            // `registered_by` records the user who registered the
            // collateral (set at insert). `released_by` records the user
            // who released it (set at release). Both are nullable and use
            // `nullOnDelete()`: a hard-deleted user detaches from the
            // collateral, which survives with a null actor.
            // -----------------------------------------------------------------
            $table->foreignId('registered_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who registered the collateral; nullable');

            $table->foreignId('released_by')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete()
                  ->comment('User who released the collateral; nullable');

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            // -----------------------------------------------------------------
            $table->timestamps();

            // -----------------------------------------------------------------
            // Composite indexes.
            //
            // The composite `(loan_id, status)` provides the FK's implicit
            // index on `loan_id`. The plain index on
            // `pledged_shareholding_id` supports the future Phase 2A CR's
            // backfill and any cross-reference reports.
            // -----------------------------------------------------------------

            // "List a loan's collateral filtered by state" — the primary
            // read pattern for the collateral endpoints and for any
            // reporting surface.
            $table->index(
                ['loan_id', 'status'],
                'loan_collaterals_loan_status_index',
            );

            // "Which loans pledge this shareholding?" — future Phase 2A CR
            // and cross-reference reports. No Phase 2C code path issues
            // this query; the index is defensive for the future CR.
            $table->index(
                'pledged_shareholding_id',
                'loan_collaterals_pledged_shareholding_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. No other Phase 2C migration references this table
     * via a foreign key, so a rollback of this migration does not require
     * any dependent table to be dropped first (beyond the standard reverse
     * order of `php artisan migrate:rollback`, which drops this table
     * before the `loans` table it depends on).
     *
     * When the Phase 2A CR (CR-2C-COLLATERAL-PLEDGE-FK-001) adds the FK
     * on `pledged_shareholding_id`, this `down()` method remains correct:
     * the FK is dropped along with the table.
     *
     * No data is preserved on rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_collaterals');
    }
};