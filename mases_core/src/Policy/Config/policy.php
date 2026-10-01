<?php

declare(strict_types=1);

/*
 * -----------------------------------------------------------------------------
 * MASES CORE POLICY — CONFIGURATION
 * -----------------------------------------------------------------------------
 *
 * The versioned-policy registry is a domain-agnostic Core capability that
 * stores immutable, versioned policy definitions for consumption by any
 * MASES domain. It is introduced in Phase 2B to satisfy three architectural
 * mandates that converged on the same primitive:
 *
 *   - A7   — KYC/AML capability must be architected centrally and versioned.
 *   - A8   — Savings interest must be configured through a versioned policy
 *            architecture, with the rule itself deferred.
 *   - D110 — Payment-allocation rules must be policy-driven and versioned,
 *            with the priority order itself deferred.
 *
 * Phase 2C extends the registry with a fourth type:
 *
 *   - B6 / Extra 2 — Loan product financial rules must live in a SINGLE
 *                    versioned policy type. The Loan domain attaches
 *                    `loan_product_rules` policies to `loan_products` rows
 *                    and freezes the applicable version onto each loan at
 *                    approval time.
 *
 * The registry stores policy payloads opaquely. It does not evaluate them,
 * validate them, or assign them semantics. Each consumer (a future KYC
 * capability, a future interest capability, the wallet allocation surface,
 * the Loan domain's product-rule surface) defines its own payload schema in
 * its own layer.
 *
 * =============================================================================
 * What this capability is
 * =============================================================================
 *   - A store of immutable, versioned policy rows.
 *   - An identity scheme: (policy_type, version).
 *   - A temporal resolution rule: the version with the latest
 *     effective_from <= T whose effective_until is null or > T.
 *   - An append-only history: a new version supersedes; nothing is edited.
 *
 * =============================================================================
 * What this capability is NOT
 * =============================================================================
 *   - It is NOT an interest calculator.
 *   - It is NOT an allocation engine.
 *   - It is NOT a KYC evaluation system.
 *   - It is NOT a loan-amortization engine.
 *   - It does NOT validate payloads — consumer-layer concern.
 *   - It does NOT cache lookups — consumers that need caching do so at
 *     their layer.
 *   - It does NOT enforce effective-date monotonicity beyond uniqueness on
 *     (policy_type, version).
 *
 * =============================================================================
 * Configuration keys
 * =============================================================================
 *   enabled           Whether the capability is active. When false, the
 *                     PolicyServiceProvider does not load the migration
 *                     directory and the registry is not bound into the
 *                     container. The default is true.
 *
 *   types             The recognised policy-type discriminators. Adding or
 *                     removing a type is a Change Request, because existing
 *                     rows reference their type by string.
 *
 * =============================================================================
 * Recognised types
 * =============================================================================
 * Phase 2B (slot-only — no payload schema is frozen, no consumer evaluates
 * them yet):
 *
 *   kyc               Reserved for the future KYC capability (A7).
 *   interest          Reserved for the future interest capability (A8).
 *   allocation        Reserved for the future wallet allocation-policy
 *                     capability (D110).
 *
 * Phase 2C (live — consumed by the Loan domain):
 *
 *   loan_product_rules
 *                     The SINGLE policy type that governs a loan product's
 *                     financial rules. Per the frozen B6 / Extra-2 decision,
 *                     every loan product's interest, term structure,
 *                     arrears, collateral, and fee rules live under this
 *                     one type. There is no separate `loan_interest`,
 *                     `loan_term`, or `loan_arrears` type.
 *
 *                     The Loan domain reads the applicable version through
 *                     `loans.frozen_policy_version_id` (set at approval
 *                     time) and parses its payload via the keys declared on
 *                     `MaaleArna\MASES\Core\Domains\Loan\Support\LoanPolicyKeys`.
 *
 *                     This is the CR-2C-POLICY-TYPES-001 addition.
 *
 * =============================================================================
 * Environment variables
 * =============================================================================
 *   MASES_POLICY_ENABLED      default true
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §8      — Versioned-policy registry integration
 *   Architecture v1.2.0 §8.2    — Identity and temporal applicability
 *   Architecture v1.2.0 §8.3    — Immutability and deletion
 *   Architecture v1.2.0 §8.4    — Policy types recognised in Phase 2B
 *   Architecture v1.2.0 §8.7    — Registry boundary conditions
 *   Phase 2C Decision Register  — B6 (policy storage), Extra 2 (single
 *                                 Loan policy type)
 *   Phase 2C A.1                — `Config/loan.php` (the Loan domain's own
 *                                 configuration, which does NOT declare a
 *                                 policy-type list; the type is declared
 *                                 here)
 *   Phase 2C A.7                — `LoanPolicyKeys` (the Loan-domain reader
 *                                 of this type's payload)
 *   Phase 2C A.8                — `loan_products` migration (the FK
 *                                 `product_rules_policy_version_id`)
 *   Phase 2C A.9                — `loans` migration (the FK
 *                                 `frozen_policy_version_id`)
 *   CR-2C-POLICY-TYPES-001      — the change request that adds the
 *                                 `loan_product_rules` type
 *   Step 5 (manifest) CORE.1    — This file
 *   Step 6 v1.1.0 §3.1          — versioned_policies table specification
 *   Step 6 v1.1.0 S6-C          — Immutable-on-create, no updated_at
 */

return [

    // =========================================================================
    // Capability toggle
    // =========================================================================
    //
    // When false, PolicyServiceProvider skips migration loading and does not
    // bind the registry into the container. Consumer domains (notably Wallet
    // and Loan) that hold nullable policy-version FKs remain structurally
    // valid; the references are simply always null. This is the operator's
    // switch for disabling the capability without schema changes.
    //
    // The default is true because both the Wallet domain (Phase 2B) and the
    // Loan domain (Phase 2C) depend on the registry being available for
    // their policy-reference columns.
    // -------------------------------------------------------------------------
    'enabled' => env('MASES_POLICY_ENABLED', true),

    // =========================================================================
    // Recognised policy types
    // =========================================================================
    //
    // The closed vocabulary of policy_type discriminators that the registry
    // accepts. Consumers resolve their policy rows by matching on one of
    // these values.
    //
    // Adding a type is a Change Request. Removing a type is a Change Request
    // that must be accompanied by a data migration if any row references the
    // removed type.
    //
    // The Phase 2B entries (kyc, interest, allocation) are slot-only. The
    // Phase 2C entry (loan_product_rules) is live: the Loan domain attaches
    // rows of this type to loan products and freezes the applicable version
    // onto each loan at approval time.
    //
    // The list's order has no runtime meaning. Each entry is a plain string;
    // payload schemas are defined by the consumers, not here.
    //
    // See the class docblock §"Recognised types" for the full per-type
    // semantics.
    // -------------------------------------------------------------------------
    'types' => [
        'kyc',
        'interest',
        'allocation',
        'loan_product_rules',
    ],

];