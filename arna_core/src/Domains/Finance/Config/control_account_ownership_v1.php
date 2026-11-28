<?php

declare(strict_types=1);

/**
 * Fail-closed ownership and reconciliation policy for canonical control
 * accounts. Role assignments are proposed responsibility classes only.
 * No named person is appointed and no adapter is activated by this file.
 */
$base = [
    'designation_status' => 'PENDING_OWNER_DESIGNATION',
    'named_accountable_person' => null,
    'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED',
];

$rows = [
    '1110' => ['cashier', 'finance_manager', 'daily', ['signed_cash_count', 'cashier_shift_close', 'receipt_and_payment_register'], ['cash', 'finance']],
    '1120' => ['treasury_manager', 'finance_manager', 'daily', ['bank_statement', 'bank_reconciliation', 'payment_authorizations'], ['banking_wallet', 'finance']],
    '1130' => ['wallet_operations_manager', 'finance_manager', 'daily', ['wallet_provider_statement', 'settlement_report', 'clearing_reconciliation'], ['banking_wallet', 'wallet']],
    '1140' => ['wallet_operations_manager', 'finance_manager', 'daily', ['wallet_settlement_batch', 'bank_credit_evidence', 'settlement_reconciliation'], ['banking_wallet', 'wallet']],
    '1150' => ['receivables_manager', 'finance_manager', 'monthly', ['customer_subledger', 'issued_invoices', 'receipt_allocations', 'aging_report'], ['sales_receivables', 'finance']],
    '1160' => ['people_manager', 'finance_manager', 'monthly', ['employee_advance_register', 'approval_record', 'payroll_or_repayment_offset'], ['people_payroll', 'finance']],
    '1170' => ['procurement_manager', 'finance_manager', 'monthly', ['supplier_advance_register', 'purchase_order', 'supplier_acknowledgment', 'settlement_evidence'], ['procurement', 'finance']],
    '1190' => ['tax_manager', 'finance_manager', 'monthly', ['tax_invoice', 'input_tax_register', 'tax_return_reconciliation'], ['tax', 'procurement']],
    '1200' => ['loan_operations_manager', 'finance_manager', 'monthly', ['loan_agreement', 'disbursement_evidence', 'principal_schedule', 'borrower_subledger'], ['loans', 'finance']],
    '1210' => ['loan_operations_manager', 'finance_manager', 'monthly', ['loan_agreement', 'interest_accrual_schedule', 'loan_subledger_reconciliation'], ['loans', 'finance']],
    '1220' => ['credit_risk_manager', 'finance_manager', 'quarterly', ['loan_aging', 'credit_risk_assessment', 'impairment_model', 'approval_record'], ['loans', 'finance']],
    '1300' => ['inventory_manager', 'finance_manager', 'monthly', ['stock_valuation', 'warehouse_count', 'inventory_movement_register', 'gl_reconciliation'], ['inventory', 'procurement']],
    '1410' => ['fixed_assets_manager', 'finance_manager', 'monthly', ['fixed_asset_register', 'depreciation_schedule', 'physical_verification'], ['assets', 'finance']],
    '1600' => ['group_accounting_manager', 'cfo', 'monthly', ['intercompany_confirmation', 'counterparty_statement', 'elimination_schedule'], ['finance', 'consolidation']],
    '2110' => ['procurement_manager', 'finance_manager', 'monthly', ['supplier_subledger', 'supplier_invoice', 'goods_receipt_or_service_acceptance', 'aging_report'], ['procurement', 'finance']],
    '2120' => ['finance_manager', 'cfo', 'monthly', ['accrual_schedule', 'supporting_invoice_or_contract', 'reversal_schedule'], ['finance']],
    '2130' => ['payroll_manager', 'finance_manager', 'monthly', ['approved_payroll_register', 'payslip_control_total', 'payroll_payment_reconciliation'], ['people_payroll', 'finance']],
    '2140' => ['tax_manager', 'finance_manager', 'monthly', ['tax_calculation', 'tax_return_or_assessment', 'payment_receipt', 'tax_ledger_reconciliation'], ['tax', 'finance']],
    '2150' => ['sales_manager', 'finance_manager', 'monthly', ['customer_advance_register', 'customer_agreement_or_order', 'invoice_allocation_or_refund'], ['sales_receivables', 'finance']],
    '2160' => ['treasury_manager', 'finance_manager', 'daily', ['settlement_statement', 'supplier_payment_batch', 'unmatched_item_register'], ['banking_wallet', 'procurement']],
    '2200' => ['wallet_operations_manager', 'finance_manager', 'daily', ['wallet_customer_liability_report', 'wallet_balance_confirmation', 'settlement_reconciliation'], ['wallet', 'banking_wallet']],
    '2210' => ['wallet_operations_manager', 'finance_manager', 'monthly', ['unclaimed_funds_register', 'customer_contact_history', 'liability_ageing'], ['wallet', 'finance']],
    '2300' => ['loan_operations_manager', 'finance_manager', 'monthly', ['loan_funding_agreement', 'funding_statement', 'principal_reconciliation'], ['loans', 'finance']],
    '2400' => ['treasury_manager', 'cfo', 'monthly', ['loan_or_credit_agreement', 'lender_statement', 'repayment_schedule'], ['finance', 'treasury']],
    '2500' => ['treasury_manager', 'cfo', 'monthly', ['loan_or_credit_agreement', 'lender_confirmation', 'repayment_schedule'], ['finance', 'treasury']],
    '2600' => ['finance_manager', 'cfo', 'quarterly', ['lease_agreement', 'lease_liability_schedule', 'payment_evidence'], ['finance', 'assets']],
    '2700' => ['group_accounting_manager', 'cfo', 'monthly', ['intercompany_confirmation', 'counterparty_statement', 'elimination_schedule'], ['finance', 'consolidation']],
    '3100' => ['shareholder_registrar', 'cfo', 'per_transaction', ['shareholder_resolution', 'subscription_or_issue_record', 'share_register_reconciliation'], ['shareholder', 'governance']],
    '3600' => ['shareholder_registrar', 'cfo', 'per_transaction', ['dividend_resolution', 'distribution_schedule', 'withholding_evidence', 'settlement_receipt'], ['shareholder', 'governance', 'tax']],
    '4300' => ['loan_operations_manager', 'finance_manager', 'monthly', ['loan_interest_schedule', 'loan_subledger', 'interest_receipt_or_accrual'], ['loans', 'finance']],
    '4400' => ['wallet_operations_manager', 'finance_manager', 'daily', ['approved_fee_schedule', 'wallet_transaction_report', 'settlement_reconciliation'], ['wallet', 'finance']],
    '4500' => ['commercial_manager', 'finance_manager', 'monthly', ['commission_agreement', 'commission_calculation', 'settlement_evidence'], ['sales_receivables', 'finance']],
    '6100' => ['payroll_manager', 'finance_manager', 'monthly', ['approved_payroll_register', 'payroll_summary', 'statutory_deduction_schedule'], ['people_payroll', 'finance']],
    '6800' => ['credit_risk_manager', 'finance_manager', 'monthly', ['credit_loss_model', 'loan_aging', 'impairment_approval'], ['loans', 'finance']],
    '7100' => ['treasury_manager', 'finance_manager', 'monthly', ['loan_or_bank_agreement', 'interest_calculation', 'lender_statement'], ['finance', 'treasury']],
    '7500' => ['tax_manager', 'cfo', 'annual', ['tax_computation', 'approved_tax_return', 'tax_assessment_or_payment_receipt'], ['tax', 'finance']],
];

$policies = [];
foreach ($rows as $code => [$accountable, $reviewer, $frequency, $evidence, $domains]) {
    $policies[$code] = [
        ...$base,
        'accountable_role' => $accountable,
        'independent_reviewer_role' => $reviewer,
        'frequency' => $frequency,
        'required_evidence' => $evidence,
        'contributing_domains' => $domains,
    ];
}

return $policies;
