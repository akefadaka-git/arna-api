<?php

declare(strict_types=1);

/* Versioned policy registry: immutable opaque payloads keyed by type/version. Consumers validate their own schemas; resolution selects the latest version effective at the requested time. */

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
    'enabled' => env('ARNA_POLICY_ENABLED', true),

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