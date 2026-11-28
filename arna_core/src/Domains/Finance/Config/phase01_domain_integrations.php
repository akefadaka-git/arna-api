<?php

declare(strict_types=1);

/**
 * Phase 01 canonical integration ownership and document-use register.
 *
 * This is a contract registry, not permission to post automatically. Source
 * adapters remain inactive until mapping, authority, idempotency and evidence
 * controls are implemented and approved.
 */
return [
    'documents' => [
        'owner' => 'Documents',
        'binary_owner' => 'Files',
        'finance_reference_table' => 'finance_document_links',
        'numbering_owner' => 'Documents',
        'required_invariants' => [
            'document_id_must_belong_to_same_tenant',
            'document_legal_entity_must_match_finance_legal_entity',
            'issued_document_number_is_immutable',
            'document_binary_and_version_are_documents_owned',
            'finance_posting_requires_document_and_source_traceability',
        ],
        'uses' => [
            'INV' => ['sales_invoice', 'supplier_invoice', 'tax_invoice', 'credit_note', 'debit_note', 'proforma_invoice', 'service_invoice'],
            'PUR' => ['purchase_order', 'purchase_requisition', 'contract_award'],
            'EXP' => ['expense_claim', 'expense_approval', 'reimbursement', 'cash_advance'],
            'INC' => ['income_order', 'sales_order', 'revenue_authorization'],
            'RCT' => ['cash_receipt', 'bank_receipt', 'customer_receipt', 'supplier_refund', 'advance_receipt', 'payment_receipt'],
            'CON' => ['supplier_contract', 'customer_contract', 'lease_agreement', 'loan_agreement', 'insurance_policy'],
            'CER' => ['share_certificate', 'asset_certificate', 'tax_registration_certificate'],
            'RES' => ['board_budget_resolution', 'share_capital_resolution', 'dividend_resolution', 'investment_resolution'],
            'RPT' => ['bank_reconciliation', 'cash_close', 'trial_balance', 'financial_statement', 'tax_return', 'payroll_register', 'inventory_valuation', 'asset_register'],
            'LEG' => ['tax_assessment', 'audit_opinion', 'regulatory_filing', 'legal_authority_evidence'],
            'MEM' => ['journal_memo', 'adjustment_memo', 'period_close_memo'],
        ],
    ],
    'domains' => [
        'finance' => [
            'record_owner' => 'Finance',
            'finance_role' => 'general_ledger_and_accounting_policy',
            'document_uses' => ['journal_memo', 'adjustment_memo', 'period_close_memo', 'trial_balance', 'financial_statement', 'bank_reconciliation'],
            'source_events' => ['finance.journal.posted', 'finance.journal.reversed', 'finance.period.closed'],
            'posting_adapter' => 'inactive',
        ],
        'shareholder' => [
            'record_owner' => 'Shareholder',
            'finance_role' => 'capital_and_distribution_subledger',
            'document_uses' => ['share_subscription', 'share_issue', 'share_transfer', 'share_class_rights', 'shareholder_certificate', 'dividend_notice', 'dividend_payment'],
            'source_events' => ['subscription.accepted', 'share.issued', 'share.transfer.completed', 'dividend.declared', 'dividend.settled'],
            'posting_adapter' => 'inactive',
        ],
        'board_election' => [
            'record_owner' => 'Board Election',
            'finance_role' => 'approved_resolution_and_election_cost_evidence',
            'document_uses' => ['election_notice', 'candidate_fee_receipt', 'election_expense', 'election_result_resolution'],
            'source_events' => ['election.approved', 'election.fee.received', 'election.expense.approved'],
            'posting_adapter' => 'inactive',
        ],
        'procurement' => [
            'record_owner' => 'Procurement',
            'finance_role' => 'purchase_commitment_and_payable_recognition',
            'document_uses' => ['requisition', 'rfq', 'tender', 'bid_comparison', 'purchase_order', 'goods_receipt_note', 'inspection_report', 'supplier_invoice', 'supplier_credit_note', 'delivery_note'],
            'source_events' => ['purchase_order.approved', 'goods.received', 'supplier_invoice.approved', 'supplier_credit_note.approved'],
            'posting_adapter' => 'inactive',
        ],
        'inventory' => [
            'record_owner' => 'Inventory',
            'finance_role' => 'inventory_valuation_and_control_account_reconciliation',
            'document_uses' => ['stock_receipt', 'stock_issue', 'stock_transfer', 'stock_adjustment', 'stock_count', 'damaged_or_expired_stock', 'batch_serial_trace'],
            'source_events' => ['stock.received', 'stock.issued', 'stock.transferred', 'stock.adjusted', 'stock.counted'],
            'posting_adapter' => 'inactive',
        ],
        'assets' => [
            'record_owner' => 'Fixed Assets',
            'finance_role' => 'capitalization_depreciation_impairment_and_disposal',
            'document_uses' => ['asset_acquisition_invoice', 'asset_title', 'asset_registration', 'asset_assignment', 'maintenance_order', 'depreciation_schedule', 'impairment_assessment', 'asset_disposal'],
            'source_events' => ['asset.acquired', 'asset.assigned', 'asset.depreciated', 'asset.impaired', 'asset.disposed'],
            'posting_adapter' => 'inactive',
        ],
        'loans' => [
            'record_owner' => 'Loans',
            'finance_role' => 'loan_principal_interest_and_credit_loss_accounting',
            'document_uses' => ['loan_agreement', 'loan_disbursement', 'repayment_schedule', 'interest_schedule', 'collateral_evidence', 'credit_loss_assessment'],
            'source_events' => ['loan.disbursed', 'loan.repayment.received', 'loan.interest.accrued', 'loan.impairment.approved'],
            'posting_adapter' => 'inactive',
        ],
        'people_payroll' => [
            'record_owner' => 'People/Payroll',
            'finance_role' => 'payroll_journal_and_statutory_deduction_recognition',
            'document_uses' => ['employment_contract', 'payroll_register', 'payslip', 'tax_deduction_schedule', 'pension_schedule', 'expense_reimbursement'],
            'source_events' => ['payroll.approved', 'payroll.reversed', 'expense.reimbursement.approved'],
            'posting_adapter' => 'inactive',
            'confidentiality' => 'restricted_personal_financial_data',
        ],
        'banking_wallet' => [
            'record_owner' => 'Banking/Wallet',
            'finance_role' => 'cash_movement_and_settlement_reconciliation',
            'document_uses' => ['bank_statement', 'deposit_slip', 'cheque_copy', 'transfer_advice', 'mobile_money_settlement', 'wallet_reconciliation', 'cashier_close'],
            'source_events' => ['bank.statement.imported', 'bank.transfer.completed', 'wallet.settlement.completed', 'cashier.shift.closed'],
            'posting_adapter' => 'inactive',
        ],
        'sales_receivables' => [
            'record_owner' => 'Sales',
            'finance_role' => 'revenue_recognition_and_receivables',
            'document_uses' => ['quotation', 'sales_order', 'sales_invoice', 'credit_note', 'debit_note', 'delivery_note', 'customer_receipt'],
            'source_events' => ['sales_order.approved', 'sales.invoice.issued', 'sales.credit_note.issued', 'customer.receipt.confirmed'],
            'posting_adapter' => 'inactive',
        ],
        'tax' => [
            'record_owner' => 'Tax/Finance',
            'finance_role' => 'tax_determination_and_reconciliation',
            'document_uses' => ['tax_registration', 'vat_invoice', 'withholding_certificate', 'vat_return', 'withholding_return', 'corporate_income_tax_return', 'tax_payment_receipt'],
            'source_events' => ['tax.invoice.issued', 'withholding.recorded', 'tax.return.approved', 'tax.payment.confirmed'],
            'posting_adapter' => 'inactive',
            'policy_status' => 'requires_current_ethiopian_tax_validation',
        ],
        'governance' => [
            'record_owner' => 'Governance',
            'finance_role' => 'approved_budget_and_distribution_authority_evidence',
            'document_uses' => ['board_minutes', 'board_resolution', 'budget_approval', 'delegation_of_authority', 'dividend_approval', 'investment_approval'],
            'source_events' => ['board.resolution.approved', 'budget.approved', 'delegation.approved'],
            'posting_adapter' => 'inactive',
        ],
    ],
    'event_envelope' => [
        'required' => ['event_id', 'event_version', 'tenant_id', 'legal_entity_id', 'source_domain', 'source_type', 'source_id', 'effective_on', 'currency', 'idempotency_key', 'occurred_at'],
        'evidence' => ['document_ids', 'file_version_ids', 'content_hashes', 'approval_reference'],
        'rules' => [
            'tenant_context_is_resolved_server_side',
            'entity_and_source_mapping_must_be_active_and_approved',
            'source_event_idempotency_is_mandatory',
            'unmapped_or_revoked_sources_are_quarantined',
            'source_events_do_not_directly_mutate_posted_journals',
            'posting_requires_authorized_finance_policy_and_balanced_journal',
        ],
    ],
];
