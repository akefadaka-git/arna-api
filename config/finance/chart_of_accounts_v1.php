<?php

declare(strict_types=1);

/**
 * ARNA canonical chart of accounts v1.0.0.
 *
 * Accounting/tax policy status: OWNER REVIEW REQUIRED before production posting.
 * Tax values are defaults for account-level routing only, not a substitute for
 * transaction-level tax determination. "undetermined" must fail closed for tax
 * calculation until a tax code and supporting evidence are supplied.
 *
 * Fields: code, parent, en, am, type, normal, post, control, domain, tax.
 */
return [
    ['1000', null, 'Assets', 'ሀብቶች', 'asset', 'debit', false, false, 'Finance', 'out_of_scope'],
    ['1100', '1000', 'Current assets', 'የአጭር ጊዜ ሀብቶች', 'asset', 'debit', false, false, 'Finance', 'out_of_scope'],
    ['1110', '1100', 'Cash on hand', 'በእጅ ያለ ጥሬ ገንዘብ', 'asset', 'debit', true, true, 'Finance', 'out_of_scope'],
    ['1120', '1100', 'Bank accounts', 'የባንክ ሂሳቦች', 'asset', 'debit', true, true, 'Finance', 'out_of_scope'],
    ['1130', '1100', 'Mobile money and payment clearing', 'የሞባይል ገንዘብና የክፍያ ማጣሪያ', 'asset', 'debit', true, true, 'Wallet/Finance', 'out_of_scope'],
    ['1140', '1100', 'Wallet settlement receivable', 'የዋሌት ማስታረቂያ ተቀባይ', 'asset', 'debit', true, true, 'Wallet', 'out_of_scope'],
    ['1150', '1100', 'Trade receivables', 'የንግድ ተቀባይ ሂሳቦች', 'asset', 'debit', true, true, 'Finance/Procurement', 'undetermined'],
    ['1160', '1100', 'Employee advances', 'ለሠራተኞች የተሰጡ ቅድሚያ ክፍያዎች', 'asset', 'debit', true, true, 'People/Finance', 'undetermined'],
    ['1170', '1100', 'Supplier advances', 'ለአቅራቢዎች የተሰጡ ቅድሚያ ክፍያዎች', 'asset', 'debit', true, true, 'Procurement', 'undetermined'],
    ['1180', '1100', 'Prepaid expenses', 'በቅድሚያ የተከፈሉ ወጪዎች', 'asset', 'debit', true, false, 'Finance', 'undetermined'],
    ['1190', '1100', 'Recoverable input tax', 'ተመላሽ የግብዓት ታክስ', 'asset', 'debit', true, true, 'Finance/Tax', 'non_taxable'],
    ['1200', '1000', 'Loan principal receivable', 'የብድር ዋና ገንዘብ ተቀባይ', 'asset', 'debit', true, true, 'Loan', 'out_of_scope'],
    ['1210', '1000', 'Accrued loan interest receivable', 'የተጠራቀመ የብድር ወለድ ተቀባይ', 'asset', 'debit', true, true, 'Loan', 'undetermined'],
    ['1220', '1000', 'Expected credit loss allowance', 'የሚጠበቅ የብድር ኪሳራ አበል', 'asset', 'credit', true, true, 'Loan/Finance', 'out_of_scope'],
    ['1300', '1000', 'Inventory', 'ዕቃ ክምችት', 'asset', 'debit', true, true, 'Procurement/Inventory', 'undetermined'],
    ['1400', '1000', 'Property, plant and equipment', 'ቋሚ ንብረቶች', 'asset', 'debit', false, false, 'Finance/Assets', 'out_of_scope'],
    ['1410', '1400', 'Accumulated depreciation', 'የተጠራቀመ የዋጋ ቅናሽ', 'asset', 'credit', true, true, 'Finance/Assets', 'out_of_scope'],
    ['1500', '1000', 'Intangible assets', 'የማይዳሰሱ ንብረቶች', 'asset', 'debit', true, false, 'Finance/Assets', 'out_of_scope'],
    ['1600', '1000', 'Intercompany receivables', 'ከተዛማጅ ድርጅቶች የሚቀበሉ ሂሳቦች', 'asset', 'debit', true, true, 'Finance/Consolidation', 'out_of_scope'],
    ['1700', '1000', 'Other assets', 'ሌሎች ሀብቶች', 'asset', 'debit', true, false, 'Finance', 'undetermined'],

    ['2000', null, 'Liabilities', 'እዳዎች', 'liability', 'credit', false, false, 'Finance', 'out_of_scope'],
    ['2100', '2000', 'Current liabilities', 'የአጭር ጊዜ እዳዎች', 'liability', 'credit', false, false, 'Finance', 'out_of_scope'],
    ['2110', '2100', 'Trade payables', 'የንግድ ከፋይ ሂሳቦች', 'liability', 'credit', true, true, 'Procurement/Finance', 'undetermined'],
    ['2120', '2100', 'Accrued expenses payable', 'የተጠራቀሙ ወጪዎች ከፋይ', 'liability', 'credit', true, true, 'Finance', 'undetermined'],
    ['2130', '2100', 'Payroll payable', 'የደመወዝ ከፋይ', 'liability', 'credit', true, true, 'People/Payroll', 'out_of_scope'],
    ['2140', '2100', 'Tax payable', 'የታክስ ከፋይ', 'liability', 'credit', true, true, 'Finance/Tax', 'out_of_scope'],
    ['2150', '2100', 'Customer advances', 'ከደንበኞች የተቀበሉ ቅድሚያ ክፍያዎች', 'liability', 'credit', true, true, 'Sales/Finance', 'undetermined'],
    ['2160', '2100', 'Supplier and payment settlement payable', 'የአቅራቢና የክፍያ ማስታረቂያ ከፋይ', 'liability', 'credit', true, true, 'Procurement/Wallet', 'out_of_scope'],
    ['2200', '2000', 'Wallet customer funds payable', 'ለዋሌት ደንበኞች የሚከፈል ገንዘብ', 'liability', 'credit', true, true, 'Wallet', 'out_of_scope'],
    ['2210', '2000', 'Unclaimed wallet funds payable', 'ያልተጠየቀ የዋሌት ገንዘብ ከፋይ', 'liability', 'credit', true, true, 'Wallet', 'out_of_scope'],
    ['2300', '2000', 'Loan funds payable', 'የብድር ገንዘብ ከፋይ', 'liability', 'credit', true, true, 'Loan/Finance', 'out_of_scope'],
    ['2400', '2000', 'Short-term borrowings', 'የአጭር ጊዜ ብድር እዳ', 'liability', 'credit', true, true, 'Finance/Treasury', 'out_of_scope'],
    ['2500', '2000', 'Long-term borrowings', 'የረጅም ጊዜ ብድር እዳ', 'liability', 'credit', true, true, 'Finance/Treasury', 'out_of_scope'],
    ['2600', '2000', 'Lease liabilities', 'የኪራይ ውል እዳዎች', 'liability', 'credit', true, true, 'Finance', 'out_of_scope'],
    ['2700', '2000', 'Intercompany payables', 'ለተዛማጅ ድርጅቶች የሚከፈል', 'liability', 'credit', true, true, 'Finance/Consolidation', 'out_of_scope'],
    ['2800', '2000', 'Other liabilities', 'ሌሎች እዳዎች', 'liability', 'credit', true, false, 'Finance', 'undetermined'],

    ['3000', null, 'Equity', 'ካፒታል', 'equity', 'credit', false, false, 'Finance/Shareholder', 'out_of_scope'],
    ['3100', '3000', 'Share capital', 'የአክሲዮን ካፒታል', 'equity', 'credit', true, true, 'Shareholder', 'out_of_scope'],
    ['3200', '3000', 'Share premium and contributed surplus', 'የአክሲዮን ተጨማሪ ካፒታል', 'equity', 'credit', true, false, 'Shareholder/Finance', 'out_of_scope'],
    ['3300', '3000', 'Retained earnings', 'የተያዙ ትርፎች', 'equity', 'credit', true, false, 'Finance', 'out_of_scope'],
    ['3400', '3000', 'Current-year earnings', 'የአሁኑ ዓመት ውጤት', 'equity', 'credit', true, false, 'Finance', 'out_of_scope'],
    ['3500', '3000', 'Statutory and other reserves', 'ሕጋዊና ሌሎች ተቀማጭ ሂሳቦች', 'equity', 'credit', true, false, 'Governance/Finance', 'out_of_scope'],
    ['3600', '3000', 'Declared shareholder distributions', 'የታወጁ የባለአክሲዮን ስርጭቶች', 'equity', 'debit', true, true, 'Shareholder/Finance', 'out_of_scope'],

    ['4000', null, 'Revenue', 'ገቢ', 'revenue', 'credit', false, false, 'Finance', 'out_of_scope'],
    ['4100', '4000', 'Sales revenue', 'የሽያጭ ገቢ', 'revenue', 'credit', true, false, 'Sales/Procurement', 'undetermined'],
    ['4200', '4000', 'Service revenue', 'የአገልግሎት ገቢ', 'revenue', 'credit', true, false, 'Sales/Finance', 'undetermined'],
    ['4300', '4000', 'Loan interest income', 'የብድር ወለድ ገቢ', 'revenue', 'credit', true, true, 'Loan', 'undetermined'],
    ['4400', '4000', 'Wallet and transaction fee income', 'የዋሌትና የግብይት ክፍያ ገቢ', 'revenue', 'credit', true, true, 'Wallet', 'undetermined'],
    ['4500', '4000', 'Commission income', 'የኮሚሽን ገቢ', 'revenue', 'credit', true, true, 'Finance/Commercial', 'undetermined'],
    ['4600', '4000', 'Procurement and fulfillment income', 'የግዥና የማሟያ ገቢ', 'revenue', 'credit', true, false, 'Procurement', 'undetermined'],
    ['4700', '4000', 'Other operating revenue', 'ሌላ የሥራ ገቢ', 'revenue', 'credit', true, false, 'Finance', 'undetermined'],

    ['5000', null, 'Direct costs', 'ቀጥተኛ ወጪዎች', 'expense', 'debit', false, false, 'Finance', 'out_of_scope'],
    ['5100', '5000', 'Cost of goods sold', 'የተሸጡ ዕቃዎች ወጪ', 'expense', 'debit', true, false, 'Sales/Inventory', 'undetermined'],
    ['5200', '5000', 'Direct service delivery costs', 'ቀጥተኛ የአገልግሎት ወጪዎች', 'expense', 'debit', true, false, 'Operations', 'undetermined'],
    ['5300', '5000', 'Procurement and fulfillment costs', 'የግዥና የማሟያ ወጪዎች', 'expense', 'debit', true, false, 'Procurement', 'undetermined'],
    ['5400', '5000', 'Inventory consumption', 'የዕቃ ክምችት ፍጆታ', 'expense', 'debit', true, false, 'Inventory', 'undetermined'],
    ['5500', '5000', 'Other direct costs', 'ሌሎች ቀጥተኛ ወጪዎች', 'expense', 'debit', true, false, 'Finance', 'undetermined'],

    ['6000', null, 'Operating expenses', 'የሥራ ማስኬጃ ወጪዎች', 'expense', 'debit', false, false, 'Finance', 'out_of_scope'],
    ['6100', '6000', 'Salaries and employee benefits', 'ደመወዝና የሠራተኛ ጥቅማጥቅሞች', 'expense', 'debit', true, true, 'People/Payroll', 'undetermined'],
    ['6200', '6000', 'Rent and utilities', 'ኪራይና መገልገያ ወጪዎች', 'expense', 'debit', true, false, 'Finance/Facilities', 'undetermined'],
    ['6300', '6000', 'Office and administration', 'የቢሮና አስተዳደር ወጪዎች', 'expense', 'debit', true, false, 'Finance', 'undetermined'],
    ['6400', '6000', 'IT and communications', 'የአይቲና የመገናኛ ወጪዎች', 'expense', 'debit', true, false, 'IT/Finance', 'undetermined'],
    ['6500', '6000', 'Marketing and public relations', 'የግብይትና የሕዝብ ግንኙነት ወጪዎች', 'expense', 'debit', true, false, 'Commercial/Finance', 'undetermined'],
    ['6600', '6000', 'Travel and transport', 'የጉዞና የትራንስፖርት ወጪዎች', 'expense', 'debit', true, false, 'Operations/Finance', 'undetermined'],
    ['6700', '6000', 'Depreciation and amortization', 'የዋጋ ቅናሽና የማካካሻ ወጪ', 'expense', 'debit', true, false, 'Finance/Assets', 'out_of_scope'],
    ['6800', '6000', 'Credit loss expense', 'የብድር ኪሳራ ወጪ', 'expense', 'debit', true, true, 'Loan/Finance', 'out_of_scope'],
    ['6900', '6000', 'Other operating expenses', 'ሌሎች የሥራ ማስኬጃ ወጪዎች', 'expense', 'debit', true, false, 'Finance', 'undetermined'],

    ['7000', null, 'Finance and other expenses', 'የፋይናንስና ሌሎች ወጪዎች', 'expense', 'debit', false, false, 'Finance', 'out_of_scope'],
    ['7100', '7000', 'Interest expense', 'የወለድ ወጪ', 'expense', 'debit', true, true, 'Finance/Treasury', 'undetermined'],
    ['7200', '7000', 'Bank and payment processing charges', 'የባንክና የክፍያ ማስኬጃ ክፍያዎች', 'expense', 'debit', true, false, 'Finance/Wallet', 'undetermined'],
    ['7300', '7000', 'Foreign exchange losses', 'የምንዛሪ ኪሳራ', 'expense', 'debit', true, false, 'Finance/Treasury', 'out_of_scope'],
    ['7400', '7000', 'Penalties and non-operating expenses', 'ቅጣቶችና ከዋና ሥራ ውጭ ወጪዎች', 'expense', 'debit', true, false, 'Finance/Tax', 'undetermined'],
    ['7500', '7000', 'Income tax expense', 'የገቢ ግብር ወጪ', 'expense', 'debit', true, true, 'Finance/Tax', 'out_of_scope'],
    ['7600', '7000', 'Other finance costs', 'ሌሎች የፋይናንስ ወጪዎች', 'expense', 'debit', true, false, 'Finance', 'undetermined'],

    ['8000', null, 'Other income and gains', 'ሌሎች ገቢዎችና ትርፎች', 'revenue', 'credit', false, false, 'Finance', 'out_of_scope'],
    ['8100', '8000', 'Interest and investment income', 'የወለድና የኢንቨስትመንት ገቢ', 'revenue', 'credit', true, false, 'Finance/Treasury', 'undetermined'],
    ['8200', '8000', 'Foreign exchange gains', 'የምንዛሪ ትርፍ', 'revenue', 'credit', true, false, 'Finance/Treasury', 'out_of_scope'],
    ['8300', '8000', 'Gains on disposal of assets', 'ንብረት በማስወገድ የተገኘ ትርፍ', 'revenue', 'credit', true, false, 'Finance/Assets', 'undetermined'],
    ['8400', '8000', 'Other non-operating income', 'ሌላ ከዋና ሥራ ውጭ ገቢ', 'revenue', 'credit', true, false, 'Finance', 'undetermined'],
];
