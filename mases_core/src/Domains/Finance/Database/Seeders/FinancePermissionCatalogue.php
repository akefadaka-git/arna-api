<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Database\Seeders;

/**
 * Canonical Finance-owned authorization capabilities.
 */
final class FinancePermissionCatalogue
{
    /** @var list<array{slug:string,name_am:string,name_en:string,category:string,scope:string,is_system:bool}> */
    public const ROWS = [
        ['slug' => 'app.finance.access',  'name_am' => 'ፋይናንስ መድረስ',              'name_en' => 'Access Finance',  'category' => 'app_access', 'scope' => 'application', 'is_system' => false],
        ['slug' => 'finance.entity.view', 'name_am' => 'የሕጋዊ አካላትን ማየት', 'name_en' => 'View Legal Entities', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.entity.manage', 'name_am' => 'ሕጋዊ አካላትን ማስተዳደር', 'name_en' => 'Manage Legal Entities', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.account.view', 'name_am' => 'የሂሳብ መዝገብ ማየት', 'name_en' => 'View Chart of Accounts', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.account.manage', 'name_am' => 'የሂሳብ መዝገብ ማስተዳደር', 'name_en' => 'Manage Chart of Accounts', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.period.view', 'name_am' => 'የሂሳብ ጊዜዎችን ማየት', 'name_en' => 'View Accounting Periods', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.period.manage', 'name_am' => 'የሂሳብ ጊዜዎችን ማስተዳደር', 'name_en' => 'Manage Accounting Periods', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.journal.view', 'name_am' => 'የመዝገብ ማስታወሻዎችን ማየት', 'name_en' => 'View Journal Entries', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.journal.create', 'name_am' => 'የመዝገብ ማስታወሻ መፍጠር', 'name_en' => 'Create Journal Entries', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.journal.cancel', 'name_am' => 'የሂሳብ ረቂቅ ማስታወሻዎችን መሰረዝ', 'name_en' => 'Cancel Draft Journal Entries', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.journal.post', 'name_am' => 'የመዝገብ ማስታወሻ መለጠፍ', 'name_en' => 'Post Journal Entries', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.journal.reverse', 'name_am' => 'የመዝገብ ማስታወሻ መቀልበስ', 'name_en' => 'Reverse Journal Entries', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.report.view', 'name_am' => 'የፋይናንስ ሪፖርቶችን ማየት', 'name_en' => 'View Financial Reports', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
    ];
}
