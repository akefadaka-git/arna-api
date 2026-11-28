<?php

declare(strict_types=1);

/**
 * Opening-balance import remains disabled until the Owner approves a
 * documented cutover and evidence package. This policy never creates balances.
 */
return [
    'activation_status' => 'disabled',
    'owner_approval_required' => true,
    'required_evidence' => [
        'signed_source_trial_balance',
        'account_level_opening_balance_schedule',
        'bank_and_cash_reconciliation',
        'receivables_and_payables_subledger_reconciliation',
        'inventory_and_fixed_asset_subledger_reconciliation',
        'share_capital_register_reconciliation',
        'control_account_owner_signoff',
        'cutover_date_and_fiscal_period_approval',
    ],
    'required_controls' => [
        'tenant_and_legal_entity_scope',
        'canonical_coa_mapping',
        'balanced_debit_credit_batch',
        'source_document_links',
        'idempotent_import_batch',
        'dual_authorization',
        'immutable_import_audit',
        'post_import_trial_balance_reconciliation',
    ],
    'prohibited_actions' => [
        'direct_account_balance_mutation',
        'unbalanced_opening_journal',
        'unverified_source_import',
        'automatic_seeded_opening_balances',
        'overwrite_of_posted_journal_history',
    ],
];
