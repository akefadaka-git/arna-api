<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function installPostingTrigger(string $lineConstraint): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_validate_post');
        DB::unprepared("CREATE TRIGGER journal_entries_validate_post BEFORE UPDATE ON journal_entries FOR EACH ROW
        BEGIN
            IF OLD.status = 'draft' AND NEW.status = 'posted' THEN
                IF NEW.posted_at IS NULL OR NEW.posted_by IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posting actor and timestamp are required';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM legal_entities e WHERE e.id = NEW.legal_entity_id AND e.tenant_id = NEW.tenant_id AND e.status = 'active' AND e.base_currency = NEW.currency) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal currency or legal entity is invalid';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM accounting_periods p WHERE p.id = NEW.accounting_period_id AND p.tenant_id = NEW.tenant_id AND p.legal_entity_id = NEW.legal_entity_id AND p.status = 'open' AND NEW.effective_date BETWEEN p.starts_on AND p.ends_on) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal date is outside an open accounting period';
                END IF;
                IF (SELECT COUNT(*) FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id) < 2 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A journal requires at least two lines';
                END IF;
                IF (SELECT COALESCE(SUM(l.debit_amount), 0) FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id) <= 0
                   OR (SELECT COALESCE(SUM(l.debit_amount), 0) FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id)
                   <> (SELECT COALESCE(SUM(l.credit_amount), 0) FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal debits and credits must balance';
                END IF;
                IF EXISTS (SELECT 1 FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id AND (${lineConstraint})) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Each journal line must have one non-negative positive side';
                END IF;
                IF EXISTS (SELECT 1 FROM journal_lines l INNER JOIN ledger_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id AND a.legal_entity_id = l.legal_entity_id WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id AND (a.status <> 'active' OR a.is_posting_allowed = 0)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal contains an inactive or non-postable account';
                END IF;
            END IF;
        END");
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->installPostingTrigger(
            'l.debit_amount < 0 OR l.credit_amount < 0 OR (l.debit_amount = 0 AND l.credit_amount = 0) OR (l.debit_amount > 0 AND l.credit_amount > 0)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Restore the original posting trigger condition for rollback compatibility.
        $this->installPostingTrigger(
            '(l.debit_amount <= 0 AND l.credit_amount <= 0) OR (l.debit_amount > 0 AND l.credit_amount > 0)'
        );
    }
};
