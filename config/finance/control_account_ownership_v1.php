<?php

declare(strict_types=1);

/**
 * ARNA Finance CoA v1.0.0 reconciliation accountability register.
 * Role assignments are implementation defaults, not formal appointments.
 * Named owners remain null until the Owner records an explicit designation.
 * Adapters must remain disabled while designation_status is pending.
 */
return [
    '1110' => [
        'account_name_en' => 'Cash on hand',
        'account_type' => 'asset',
        'accountable_role' => 'Finance/Treasury Lead',
        'contributing_domains' => [
            'Finance'
        ],
        'frequency' => 'Daily',
        'required_evidence' => [
            'Cash count sheets',
            'cashbook',
            'approved cash vouchers'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1120' => [
        'account_name_en' => 'Bank accounts',
        'account_type' => 'asset',
        'accountable_role' => 'Finance/Treasury Lead',
        'contributing_domains' => [
            'Finance'
        ],
        'frequency' => 'Daily',
        'required_evidence' => [
            'Bank statements',
            'bank reconciliation',
            'outstanding-item aging'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1130' => [
        'account_name_en' => 'Mobile money and payment clearing',
        'account_type' => 'asset',
        'accountable_role' => 'Wallet Operations Lead',
        'contributing_domains' => [
            'Wallet',
            'Finance'
        ],
        'frequency' => 'Daily',
        'required_evidence' => [
            'Payment-provider settlement reports',
            'wallet clearing ledger',
            'bank credits'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1140' => [
        'account_name_en' => 'Wallet settlement receivable',
        'account_type' => 'asset',
        'accountable_role' => 'Wallet Operations Lead',
        'contributing_domains' => [
            'Wallet',
            'Finance'
        ],
        'frequency' => 'Daily',
        'required_evidence' => [
            'Wallet settlement ledger',
            'provider settlement files',
            'bank reconciliation'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1150' => [
        'account_name_en' => 'Trade receivables',
        'account_type' => 'asset',
        'accountable_role' => 'Finance Receivables Lead',
        'contributing_domains' => [
            'Finance',
            'Sales/Procurement'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Customer subledger',
            'invoices',
            'receipts',
            'aged receivables'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1160' => [
        'account_name_en' => 'Employee advances',
        'account_type' => 'asset',
        'accountable_role' => 'People/Payroll Lead',
        'contributing_domains' => [
            'People',
            'Finance'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Employee advance register',
            'approved advance requests',
            'payroll deductions/settlements'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1170' => [
        'account_name_en' => 'Supplier advances',
        'account_type' => 'asset',
        'accountable_role' => 'Procurement/Accounts Payable Lead',
        'contributing_domains' => [
            'Procurement',
            'Finance'
        ],
        'frequency' => 'Per transaction and monthly',
        'required_evidence' => [
            'Supplier advance register',
            'purchase orders',
            'invoices',
            'goods/service receipts'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1190' => [
        'account_name_en' => 'Recoverable input tax',
        'account_type' => 'asset',
        'accountable_role' => 'Finance/Tax Lead',
        'contributing_domains' => [
            'Finance/Tax'
        ],
        'frequency' => 'Monthly and each tax filing',
        'required_evidence' => [
            'Input-tax schedule',
            'valid tax invoices',
            'recoverability evidence',
            'filed return tie-out'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1200' => [
        'account_name_en' => 'Loan principal receivable',
        'account_type' => 'asset',
        'accountable_role' => 'Loan Operations/Credit Lead',
        'contributing_domains' => [
            'Loan',
            'Finance'
        ],
        'frequency' => 'Daily subledger close; monthly GL sign-off',
        'required_evidence' => [
            'Loan principal schedules',
            'disbursements',
            'repayment allocations',
            'GL control report'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1210' => [
        'account_name_en' => 'Accrued loan interest receivable',
        'account_type' => 'asset',
        'accountable_role' => 'Loan Operations/Credit Lead',
        'contributing_domains' => [
            'Loan',
            'Finance'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Accrual schedules',
            'contract terms',
            'repayment status',
            'GL tie-out'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1220' => [
        'account_name_en' => 'Expected credit loss allowance',
        'account_type' => 'asset',
        'accountable_role' => 'Credit Risk/Loan Lead',
        'contributing_domains' => [
            'Loan',
            'Finance'
        ],
        'frequency' => 'Monthly and each reporting close',
        'required_evidence' => [
            'Impairment model',
            'approved assumptions',
            'loan aging',
            'allowance roll-forward'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1300' => [
        'account_name_en' => 'Inventory',
        'account_type' => 'asset',
        'accountable_role' => 'Inventory/Procurement Lead',
        'contributing_domains' => [
            'Inventory',
            'Procurement',
            'Finance'
        ],
        'frequency' => 'Per movement; monthly count/reconciliation',
        'required_evidence' => [
            'Inventory movement ledger',
            'receipts/issues',
            'stock counts',
            'valuation reports'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1410' => [
        'account_name_en' => 'Accumulated depreciation',
        'account_type' => 'asset',
        'accountable_role' => 'Fixed Assets/Finance Lead',
        'contributing_domains' => [
            'Finance/Assets'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Fixed-asset register',
            'depreciation run',
            'additions/disposals',
            'GL tie-out'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '1600' => [
        'account_name_en' => 'Intercompany receivables',
        'account_type' => 'asset',
        'accountable_role' => 'Group Finance/Consolidation Lead',
        'contributing_domains' => [
            'Finance/Consolidation'
        ],
        'frequency' => 'Monthly and each consolidation close',
        'required_evidence' => [
            'Counterparty statements',
            'reciprocal balances',
            'FX schedules',
            'elimination journal evidence'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2110' => [
        'account_name_en' => 'Trade payables',
        'account_type' => 'liability',
        'accountable_role' => 'Procurement/Accounts Payable Lead',
        'contributing_domains' => [
            'Procurement',
            'Finance'
        ],
        'frequency' => 'Per invoice; monthly aging',
        'required_evidence' => [
            'Supplier statements',
            'invoices',
            'purchase orders',
            'receipt evidence',
            'payment records'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2120' => [
        'account_name_en' => 'Accrued expenses payable',
        'account_type' => 'liability',
        'accountable_role' => 'Finance Accounting Lead',
        'contributing_domains' => [
            'Finance'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Accrual schedules',
            'supporting invoices/contracts',
            'reversal and settlement evidence'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2130' => [
        'account_name_en' => 'Payroll payable',
        'account_type' => 'liability',
        'accountable_role' => 'People/Payroll Lead',
        'contributing_domains' => [
            'People/Payroll',
            'Finance'
        ],
        'frequency' => 'Each payroll run and monthly',
        'required_evidence' => [
            'Approved payroll register',
            'deductions',
            'payment files',
            'payroll liability roll-forward'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2140' => [
        'account_name_en' => 'Tax payable',
        'account_type' => 'liability',
        'accountable_role' => 'Finance/Tax Lead',
        'contributing_domains' => [
            'Finance/Tax'
        ],
        'frequency' => 'Monthly and each filing/payment',
        'required_evidence' => [
            'Tax calculation schedules',
            'filed returns',
            'payment receipts',
            'authority statements'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2150' => [
        'account_name_en' => 'Customer advances',
        'account_type' => 'liability',
        'accountable_role' => 'Sales/Receivables Lead',
        'contributing_domains' => [
            'Sales',
            'Finance'
        ],
        'frequency' => 'Per transaction; monthly aging',
        'required_evidence' => [
            'Customer contracts/orders',
            'advance receipts',
            'fulfillment/refund evidence'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2160' => [
        'account_name_en' => 'Supplier and payment settlement payable',
        'account_type' => 'liability',
        'accountable_role' => 'Procurement/Wallet Settlement Lead',
        'contributing_domains' => [
            'Procurement',
            'Wallet',
            'Finance'
        ],
        'frequency' => 'Daily settlement; monthly sign-off',
        'required_evidence' => [
            'Supplier and payment-provider settlement reports',
            'bank statements',
            'unmatched-item log'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2200' => [
        'account_name_en' => 'Wallet customer funds payable',
        'account_type' => 'liability',
        'accountable_role' => 'Wallet Operations Lead',
        'contributing_domains' => [
            'Wallet',
            'Finance'
        ],
        'frequency' => 'Daily',
        'required_evidence' => [
            'Customer wallet subledger',
            'safeguarding/custody evidence',
            'settlement reports',
            'GL tie-out'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2210' => [
        'account_name_en' => 'Unclaimed wallet funds payable',
        'account_type' => 'liability',
        'accountable_role' => 'Wallet Operations Lead',
        'contributing_domains' => [
            'Wallet',
            'Finance'
        ],
        'frequency' => 'Daily; monthly aging review',
        'required_evidence' => [
            'Unclaimed-funds register',
            'wallet history',
            'legal hold/claim evidence',
            'aging report'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2300' => [
        'account_name_en' => 'Loan funds payable',
        'account_type' => 'liability',
        'accountable_role' => 'Loan Treasury/Finance Lead',
        'contributing_domains' => [
            'Loan',
            'Finance/Treasury'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Loan funding agreements',
            'drawdown/repayment schedules',
            'lender statements'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2400' => [
        'account_name_en' => 'Short-term borrowings',
        'account_type' => 'liability',
        'accountable_role' => 'Finance/Treasury Lead',
        'contributing_domains' => [
            'Finance/Treasury'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Loan agreements',
            'lender statements',
            'interest and maturity schedules'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2500' => [
        'account_name_en' => 'Long-term borrowings',
        'account_type' => 'liability',
        'accountable_role' => 'Finance/Treasury Lead',
        'contributing_domains' => [
            'Finance/Treasury'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Loan agreements',
            'lender confirmations',
            'maturity and covenant schedules'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2600' => [
        'account_name_en' => 'Lease liabilities',
        'account_type' => 'liability',
        'accountable_role' => 'Finance Lease Accounting Lead',
        'contributing_domains' => [
            'Finance'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Lease register',
            'contracts',
            'payment schedule',
            'liability roll-forward'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '2700' => [
        'account_name_en' => 'Intercompany payables',
        'account_type' => 'liability',
        'accountable_role' => 'Group Finance/Consolidation Lead',
        'contributing_domains' => [
            'Finance/Consolidation'
        ],
        'frequency' => 'Monthly and each consolidation close',
        'required_evidence' => [
            'Counterparty confirmations',
            'reciprocal balances',
            'dispute log',
            'elimination evidence'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '3100' => [
        'account_name_en' => 'Share capital',
        'account_type' => 'equity',
        'accountable_role' => 'Shareholder Operations Lead with Finance',
        'contributing_domains' => [
            'Shareholder',
            'Finance',
            'Governance'
        ],
        'frequency' => 'Each capital event; monthly GL tie-out',
        'required_evidence' => [
            'Share register',
            'approved resolutions',
            'bank receipts',
            'capital movement register'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '3600' => [
        'account_name_en' => 'Declared shareholder distributions',
        'account_type' => 'equity',
        'accountable_role' => 'Shareholder Operations Lead with Finance',
        'contributing_domains' => [
            'Shareholder',
            'Finance',
            'Governance'
        ],
        'frequency' => 'Each distribution; monthly until settled',
        'required_evidence' => [
            'Approved resolution',
            'shareholder entitlement schedule',
            'payment evidence',
            'payable roll-forward'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '4300' => [
        'account_name_en' => 'Loan interest income',
        'account_type' => 'revenue',
        'accountable_role' => 'Loan Operations/Credit Lead',
        'contributing_domains' => [
            'Loan',
            'Finance'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Interest accrual schedules',
            'loan contracts',
            'collections',
            'product tax-code evidence'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '4400' => [
        'account_name_en' => 'Wallet and transaction fee income',
        'account_type' => 'revenue',
        'accountable_role' => 'Wallet Operations Lead',
        'contributing_domains' => [
            'Wallet',
            'Finance'
        ],
        'frequency' => 'Daily subledger close; monthly GL sign-off',
        'required_evidence' => [
            'Fee schedule',
            'wallet transactions',
            'reversals/refunds',
            'settlement reports'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '4500' => [
        'account_name_en' => 'Commission income',
        'account_type' => 'revenue',
        'accountable_role' => 'Commercial Finance Lead',
        'contributing_domains' => [
            'Finance/Commercial'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Commission agreements',
            'transaction-level commission ledger',
            'invoices',
            'adjustments'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '6100' => [
        'account_name_en' => 'Salaries and employee benefits',
        'account_type' => 'expense',
        'accountable_role' => 'People/Payroll Lead',
        'contributing_domains' => [
            'People/Payroll',
            'Finance'
        ],
        'frequency' => 'Each payroll run; monthly GL tie-out',
        'required_evidence' => [
            'Approved payroll register',
            'benefits schedule',
            'payroll journals',
            'payment evidence'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '6800' => [
        'account_name_en' => 'Credit loss expense',
        'account_type' => 'expense',
        'accountable_role' => 'Credit Risk/Loan Lead',
        'contributing_domains' => [
            'Loan',
            'Finance'
        ],
        'frequency' => 'Monthly and each reporting close',
        'required_evidence' => [
            'Approved impairment methodology',
            'loan aging',
            'write-off approvals',
            'allowance roll-forward'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '7100' => [
        'account_name_en' => 'Interest expense',
        'account_type' => 'expense',
        'accountable_role' => 'Finance/Treasury Lead',
        'contributing_domains' => [
            'Finance/Treasury'
        ],
        'frequency' => 'Monthly',
        'required_evidence' => [
            'Lender/bank statements',
            'amortization schedules',
            'accrued-interest calculations'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ],
    '7500' => [
        'account_name_en' => 'Income tax expense',
        'account_type' => 'expense',
        'accountable_role' => 'Finance/Tax Lead',
        'contributing_domains' => [
            'Finance/Tax'
        ],
        'frequency' => 'Each tax close and statutory filing',
        'required_evidence' => [
            'Approved tax computation',
            'reconciliations',
            'filed return',
            'payment/assessment evidence'
        ],
        'independent_reviewer_role' => 'Finance Controller or formally designated independent reviewer',
        'named_accountable_person' => null,
        'designation_status' => 'PENDING_OWNER_DESIGNATION',
        'adapter_activation' => 'BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED'
    ]
];
