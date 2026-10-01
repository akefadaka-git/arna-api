<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `loan_products` table.
 *
 * =============================================================================
 * What this table stores
 * =============================================================================
 * The loan-product catalogue: the identity and basic metadata of each loan
 * product the tenant offers. A `loan_products` row records WHAT a product
 * IS (its code, its names, its basic bounds); it does NOT record the
 * product's financial RULES. Those live in the Core Versioned Policy
 * Registry under the single `loan_product_rules` policy type (see
 * `product_rules_policy_version_id` below).
 *
 * The separation is the Phase 2C implementation of decision B6 (hybrid
 * product storage):
 *
 *     loan_products         — product identity and basic metadata
 *     versioned_policies    — product financial rules, versioned
 *
 * Product IDENTITY is not stored as arbitrary configuration; it is a
 * database row. Product RULES are not copied into every loan; they are
 * referenced by version and frozen onto the loan at approval time (see
 * `loans.frozen_policy_version_id`, migration A.9).
 *
 * =============================================================================
 * Tenant scoping (Extra 1)
 * =============================================================================
 * `loan_products` is tenant-scoped. Every row carries a `tenant_id` and
 * every product lookup is scoped to the tenant that owns it. The
 * `UNIQUE (tenant_id, code)` constraint allows the same product code to
 * exist under different tenants — which is correct, because different
 * tenants may independently define products with the same short
 * identifier.
 *
 * A cross-tenant product reference resolves to `null` at the repository
 * layer, which the controller translates into the same 404 a non-existent
 * product id would produce. The caller cannot distinguish "the product
 * does not exist" from "the product belongs to another tenant".
 *
 * This mirrors the tenant-isolation pattern established by Phase 2A's
 * `shareholders` table and Phase 2B's `wallets` table, and it satisfies
 * the frozen Stage 5 tenant-isolation matrix (T9).
 *
 * =============================================================================
 * Policy reference (B6, Extra 2)
 * =============================================================================
 * `product_rules_policy_version_id` is a nullable FK to
 * `versioned_policies.id`. When non-null, it points at the specific
 * version of the `loan_product_rules` policy that governs this product's
 * financial rules.
 *
 * The column is NULLABLE because:
 *
 *   1. A product may be created in a "no rules yet" state during
 *      administrative setup, before its first `loan_product_rules`
 *      version is authored. The domain requires the reference to be
 *      non-null at the point a product is enabled for applications
 *      (`is_active = true` AND a non-null policy version); the schema
 *      does not enforce that conjunction because it is a service-level
 *      invariant.
 *
 *   2. Enforcing NOT NULL at the schema level would couple product
 *      creation to policy authoring in a way that makes the initial
 *      seeding sequence awkward without any compensating benefit. The
 *      service that reads a product's rules for a loan operation
 *      validates the presence of the reference explicitly.
 *
 * The FK's `ON DELETE RESTRICT` behavior is dictated by Q6 and by the
 * Phase 2B precedent: versioned policies are immutable-on-create, and a
 * manual DB deletion of a policy row that a product references must not
 * silently null the reference. The RESTRICT makes such a deletion fail
 * loudly.
 *
 * Type compatibility (that the referenced policy's `policy_type` is
 * `'loan_product_rules'`) is NOT enforced by the FK; the FK proves only
 * that the id refers to SOME policy. Type compatibility is the
 * responsibility of the service that authors the product, which reads
 * the policy row through the Core VersionedPolicyRegistry and validates
 * its type before assigning the FK. See Architecture §8.6.
 *
 * =============================================================================
 * Currency
 * =============================================================================
 * `default_currency` is the product's default currency: the currency
 * that a loan of this product will use unless the borrower's Wallet
 * currency differs. The Phase 2C implementation enforces Q8 (the loan's
 * currency equals the borrower's Wallet currency) at the service layer;
 * `default_currency` is informational, used to seed the loan's currency
 * column when the Wallet currency is available, and to validate that a
 * product is not offered to a Wallet whose currency the product does not
 * support.
 *
 * The column is `CHAR(3)` — the ISO 4217 shape. It is NOT NULL. It
 * matches the `currency` column shape on `wallets`, `wallet_obligations`,
 * and `wallet_payments` from Phase 2B.
 *
 * =============================================================================
 * Principal and term bounds
 * =============================================================================
 * The four bound columns (`min_principal`, `max_principal`,
 * `min_term_months`, `max_term_months`) are the product's structural
 * envelope: the range within which an application's requested principal
 * and term must fall. They are PRODUCT POLICY DATA, not domain
 * constants — the domain does not impose a global minimum or maximum
 * principal or term; it only requires that a product declares its own.
 *
 * The service layer (`LoanApplicationService`, A.24) enforces the
 * bounds at application-submission time. The schema does not express the
 * `min <= max` cross-column invariants because:
 *
 *   - MySQL 8 can express them via CHECK constraints, but Laravel's
 *     migration DSL does not expose a portable syntax that is
 *     driver-agnostic. The Phase 2A and Phase 2B precedents consistently
 *     defer such invariants to the service layer.
 *
 *   - A service-layer rejection produces a structured 422 with a
 *     per-field error message; a database CHECK violation produces a 500
 *     with a SQL error. The user-facing outcome is materially better when
 *     the check lives in the service.
 *
 * The columns are `DECIMAL(20,4)` for the monetary bounds (matching the
 * platform-wide monetary precision) and `INTEGER` for the term bounds.
 *
 * =============================================================================
 * Installment frequency
 * =============================================================================
 * `default_installment_frequency` declares the product's default
 * repayment frequency. The Phase 2C freeze (D132) requires the value
 * `'monthly'`; the column's default is `'monthly'`. The column exists as
 * a VARCHAR(16) rather than as a boolean or a fixed constant so that a
 * future Change Request that introduces bi-weekly, quarterly, or other
 * frequencies has a stable place to record the value without a schema
 * change.
 *
 * The service layer validates that the value is one the amortization
 * engine supports (`'monthly'` in Phase 2C). An unsupported value is a
 * data defect, not a code defect; the reader throws a
 * `ValidationException` pointing at the column.
 *
 * =============================================================================
 * `is_active`
 * =============================================================================
 * A boolean flag indicating whether the product is currently offered for
 * new loan applications. Deactivating a product does NOT delete it and
 * does NOT affect loans already originated under it — the loans retain
 * their frozen policy version (see A.9) and continue to be serviced.
 *
 * This mirrors the `is_active` semantics of `share_classes` (Phase 2A)
 * and `wallets` (Phase 2B): deactivation is a business action, not a
 * delete.
 *
 * The default is `true`. A product is created `active` unless an operator
 * explicitly deactivates it.
 *
 * =============================================================================
 * Foreign keys
 * =============================================================================
 * Two foreign keys, both with `restrictOnDelete()` per Q6:
 *
 *     tenant_id                        → tenants.id            RESTRICT
 *     product_rules_policy_version_id  → versioned_policies.id RESTRICT
 *
 * The `tenant_id` FK is RESTRICT: a hard delete of a tenant must not
 * cascade into its loan products. Since `tenants` itself is protected by
 * SoftDeletes in Phase 1, a hard delete is an administrative action; the
 * RESTRICT makes it fail loudly if any product still references the
 * tenant.
 *
 * The `product_rules_policy_version_id` FK is RESTRICT for the reason
 * documented above: policies are immutable, and a hard delete of a
 * referenced policy must fail rather than silently null the reference.
 *
 * Both FKs use `cascadeOnUpdate()` for consistency with the Phase 2A and
 * Phase 2B conventions. Updates to the referenced primary keys are not a
 * runtime operation in Phase 2C (all primary keys are auto-incrementing
 * bigints), but the cascade declaration reflects the platform-wide
 * posture.
 *
 * =============================================================================
 * Indexes
 * =============================================================================
 * The migration declares exactly the indexes required by the Phase 2C
 * query patterns:
 *
 *   UNIQUE  (tenant_id, code)                 — identity; also serves as
 *                                                the FK-supporting index
 *                                                for `tenant_id`
 *   PLAIN   is_active                         — filter for "list active
 *                                                products", the primary
 *                                                read pattern for the
 *                                                `/loans/products`
 *                                                endpoint
 *   FK-implied product_rules_policy_version_id — provides the index for
 *                                                "which products use
 *                                                this policy version?",
 *                                                though no Phase 2C code
 *                                                path issues that query;
 *                                                the index is present as
 *                                                defence and for future
 *                                                reports
 *
 * The `UNIQUE` on `(tenant_id, code)` has `tenant_id` as its leading
 * column, so MySQL reuses it for the FK's implicit index on `tenant_id`.
 * No separate single-column index on `tenant_id` is declared. This
 * mirrors the pattern established by `wallets` (Phase 2B) and
 * `share_holdings` (Phase 2A).
 *
 * The `is_active` index is a plain single-column index, matching the
 * Phase 2A `share_classes.is_active` precedent and the Phase 2B
 * `wallets.is_active` precedent.
 *
 * =============================================================================
 * What this table does NOT enforce
 * =============================================================================
 *   - The `min <= max` invariants on the principal and term bounds.
 *     Service-layer concern; see the section above.
 *
 *   - The presence of a `product_rules_policy_version_id` for active
 *     products. Service-layer concern.
 *
 *   - The type compatibility of the referenced policy
 *     (`policy_type = 'loan_product_rules'`). Service-layer concern.
 *
 *   - The accepted value of `default_installment_frequency`. The column
 *     is VARCHAR(16); the service validates that the value is one the
 *     amortization engine supports. A MySQL ENUM would couple the schema
 *     to a vocabulary the phase does not yet consider frozen.
 *
 *   - The shape of `default_currency` (three ASCII letters). The service
 *     validates the shape at write time; a MySQL `CHECK` on a character
 *     class would couple the schema to a rule the service already
 *     enforces with a clearer error.
 *
 * @return void
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `loan_products` table. Foreign keys target the Phase 1
     * `tenants` table and the Core Policy capability's `versioned_policies`
     * table (CORE.2) — both of which must already exist when this
     * migration runs. The migration ordering frozen in Step 6 v1.1.0 §1
     * guarantees that `versioned_policies` is created first (filename
     * prefix `100001` vs. this file's `200010`).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table): void {
            // -----------------------------------------------------------------
            // Primary key.
            // -----------------------------------------------------------------
            $table->id()
                  ->comment('Primary identifier');

            // -----------------------------------------------------------------
            // Tenant ownership.
            //
            // Every product belongs to exactly one tenant. Product codes
            // are unique within a tenant; the same code may exist under
            // different tenants (see the class docblock, §"Tenant scoping").
            //
            // On delete: restrict. A tenant hard-delete must not silently
            // remove its products; an explicit orchestration step is
            // required (Q6, Architecture §17.3).
            //
            // The FK's backing index is the composite UNIQUE's leading
            // column — no separate single-column index is declared. See
            // the class docblock, §"Indexes".
            // -----------------------------------------------------------------
            $table->foreignId('tenant_id')
                  ->constrained('tenants')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('Owning tenant (mandatory)');

            // -----------------------------------------------------------------
            // Product code.
            //
            // A stable, short, human-readable identifier. Uppercase
            // convention (not enforced by the schema). Unique within the
            // tenant.
            //
            // The VARCHAR(32) width matches the `share_classes.code` and
            // `certificate_number` widths from Phase 2A. It accommodates
            // any plausible product code with headroom.
            // -----------------------------------------------------------------
            $table->string('code', 32)
                  ->comment('Stable product code, unique within the tenant');

            // -----------------------------------------------------------------
            // Bilingual names. Amharic first, per the platform policy.
            //
            // Both columns are nullable at the schema level; the service
            // layer requires at least one to be non-empty, mirroring the
            // `share_classes` pattern (Phase 2A).
            // -----------------------------------------------------------------
            $table->string('name_am', 150)
                  ->nullable()
                  ->comment('Product name in Amharic');

            $table->string('name_en', 150)
                  ->nullable()
                  ->comment('Product name in English');

            // -----------------------------------------------------------------
            // Bilingual descriptions. Amharic first.
            //
            // The VARCHAR(500) width matches the `description` columns on
            // the Phase 2B wallet-payment, wallet-obligation, and
            // wallet-allocation tables.
            // -----------------------------------------------------------------
            $table->string('description_am', 500)
                  ->nullable()
                  ->comment('Product description in Amharic');

            $table->string('description_en', 500)
                  ->nullable()
                  ->comment('Product description in English');

            // -----------------------------------------------------------------
            // Purpose.
            //
            // A free-text statement of the product's intended use. Stored
            // as a single column (not bilingual) because the purpose is
            // operational metadata, not user-facing display text; a
            // bilingual product catalogue would declare a second column
            // at that point.
            // -----------------------------------------------------------------
            $table->string('purpose', 500)
                  ->nullable()
                  ->comment('Free-text purpose of the product');

            // -----------------------------------------------------------------
            // Default currency.
            //
            // The ISO 4217 code the product is offered in. Informational
            // in Phase 2C: the loan's currency is inherited from the
            // borrower's Wallet (Q8), and the product's currency is
            // validated against the Wallet's currency at application
            // time by the service layer.
            //
            // CHAR(3) rather than VARCHAR(3): the value is always exactly
            // three characters, and the fixed-width type expresses that
            // contract. This matches the `currency` columns on `wallets`,
            // `wallet_obligations`, and `wallet_payments` (Phase 2B).
            // -----------------------------------------------------------------
            $table->char('currency', 3)
                  ->comment('ISO 4217 three-letter code');

            // -----------------------------------------------------------------
            // Principal bounds.
            //
            // The minimum and maximum principal a loan of this product
            // may carry. Enforced by the service layer at application
            // time. The `min <= max` invariant is a service-layer concern.
            //
            // DECIMAL(20,4) matches every monetary column in the platform.
            // -----------------------------------------------------------------
            $table->decimal('min_principal', 20, 4)
                  ->comment('Minimum principal a loan of this product may carry');

            $table->decimal('max_principal', 20, 4)
                  ->comment('Maximum principal a loan of this product may carry');

            // -----------------------------------------------------------------
            // Term bounds.
            //
            // The minimum and maximum term, in months, a loan of this
            // product may carry. Enforced by the service layer at
            // application time. The `min <= max` invariant is a
            // service-layer concern.
            //
            // INTEGER (not unsigned): a defensive negative check at the
            // service layer is preferable to relying on MySQL's strict
            // mode to reject a negative value, and matches the
            // `term_months` column on `loans` (migration A.9).
            // -----------------------------------------------------------------
            $table->integer('min_term_months')
                  ->comment('Minimum term, in months, for a loan of this product');

            $table->integer('max_term_months')
                  ->comment('Maximum term, in months, for a loan of this product');

            // -----------------------------------------------------------------
            // Default installment frequency.
            //
            // The product's default repayment frequency. The Phase 2C
            // freeze (D132) requires the value `'monthly'`; the column
            // default is `'monthly'` accordingly.
            //
            // VARCHAR(16) rather than a MySQL ENUM: a future Change
            // Request that introduces bi-weekly, quarterly, or other
            // frequencies adds a value without a schema migration. The
            // service layer validates that the value is one the
            // amortization engine supports.
            // -----------------------------------------------------------------
            $table->string('default_installment_frequency', 16)
                  ->default('monthly')
                  ->comment('Default repayment frequency (Phase 2C: monthly)');

            // -----------------------------------------------------------------
            // Active flag.
            //
            // Whether the product is currently offered for new loan
            // applications. Deactivating a product does not delete it and
            // does not affect loans already originated under it; those
            // loans retain their frozen policy version and continue to be
            // serviced.
            // -----------------------------------------------------------------
            $table->boolean('is_active')
                  ->default(true)
                  ->comment('Whether the product is currently offered for new applications');

            // -----------------------------------------------------------------
            // Policy reference (B6, Extra 2).
            //
            // A nullable FK to the Core Versioned Policy Registry. When
            // non-null, it points at the specific version of the
            // `loan_product_rules` policy that governs this product's
            // financial rules.
            //
            // Nullable because a product may exist before its first
            // policy version is authored. Non-null is required by the
            // service layer at the point a product is enabled for
            // applications; the schema does not enforce that conjunction.
            //
            // On delete: restrict. Versioned policies are immutable-on-
            // create and cannot be deleted through any code path; the
            // RESTRICT is a defensive layer against a manual DB edit
            // that would otherwise silently null the reference.
            //
            // Type compatibility (that the referenced policy's
            // `policy_type` is `'loan_product_rules'`) is NOT enforced by
            // the FK; see the class docblock, §"Policy reference".
            // -----------------------------------------------------------------
            $table->foreignId('product_rules_policy_version_id')
                  ->nullable()
                  ->constrained('versioned_policies')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete()
                  ->comment('VersionedPolicy reference for loan_product_rules; nullable');

            // -----------------------------------------------------------------
            // Standard Eloquent timestamps.
            //
            // The table carries `created_at` and `updated_at`. Unlike the
            // append-only ledger tables (e.g. `wallet_entries`), the
            // product catalogue is mutable reference data; a product's
            // names, bounds, or policy reference may legitimately be
            // updated. The timestamps record the last mutation.
            // -----------------------------------------------------------------
            $table->timestamps();

            // -----------------------------------------------------------------
            // Composite uniqueness — one product per (tenant, code).
            //
            // Declared after the FK columns for readability. The composite
            // index's leading column (`tenant_id`) also serves as the
            // backing index for the `tenant_id` FK, so MySQL will not
            // create a duplicate single-column index for that FK.
            //
            // The explicit index name is chosen to stay well below the
            // MySQL 64-character limit and to be self-documenting.
            // -----------------------------------------------------------------
            $table->unique(
                ['tenant_id', 'code'],
                'loan_products_tenant_code_unique',
            );

            // -----------------------------------------------------------------
            // Index on `is_active`.
            //
            // Supports the primary read pattern of the `/loans/products`
            // endpoint: "list active products for the current tenant".
            // A plain single-column index, matching the Phase 2A
            // `share_classes.is_active` and Phase 2B `wallets.is_active`
            // precedents.
            // -----------------------------------------------------------------
            $table->index(
                'is_active',
                'loan_products_is_active_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table. Because `loans` (migration A.9) references this
     * table via a foreign key, a rollback will only succeed if the
     * `loans` table has been dropped first — which is the normal reverse
     * order of `php artisan migrate:rollback`.
     *
     * No data is preserved on rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_products');
    }
};