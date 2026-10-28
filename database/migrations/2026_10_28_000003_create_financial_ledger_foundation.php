<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name_am', 180)->nullable();
            $table->string('name_en', 180)->nullable();
            $table->char('base_currency', 3)->default('ETB');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'legal_entities_tenant_code_unique');
            $table->unique(['tenant_id', 'id'], 'legal_entities_tenant_id_id_unique');
            $table->index(['tenant_id', 'status'], 'legal_entities_tenant_status_index');
        });

        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id');
            $table->foreignId('parent_id')->nullable();
            $table->string('code', 64);
            $table->string('name_am', 180)->nullable();
            $table->string('name_en', 180);
            $table->string('account_type', 24);
            $table->string('normal_balance', 8);
            $table->boolean('is_posting_allowed')->default(true);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'code'], 'ledger_accounts_entity_code_unique');
            $table->unique(['tenant_id', 'legal_entity_id', 'id'], 'ledger_accounts_entity_id_unique');
            $table->foreign(['tenant_id', 'legal_entity_id'], 'ledger_accounts_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'parent_id'], 'ledger_accounts_parent_same_entity_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'account_type', 'status'], 'ledger_accounts_type_status_index');
        });

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id');
            $table->string('code', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('close_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'code'], 'accounting_periods_entity_code_unique');
            $table->unique(['tenant_id', 'legal_entity_id', 'id'], 'accounting_periods_entity_id_unique');
            $table->foreign(['tenant_id', 'legal_entity_id'], 'accounting_periods_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'starts_on', 'ends_on'], 'accounting_periods_dates_index');
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id');
            $table->foreignId('accounting_period_id');
            $table->string('journal_number', 64);
            $table->date('effective_date');
            $table->string('status', 16)->default('draft');
            $table->string('currency', 3)->default('ETB');
            $table->string('description', 500);
            $table->string('source_type', 64)->nullable();
            $table->string('source_id', 128)->nullable();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversal_of_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'journal_number'], 'journal_entries_entity_number_unique');
            $table->unique(['tenant_id', 'legal_entity_id', 'idempotency_key'], 'journal_entries_idempotency_unique');
            $table->unique(['tenant_id', 'legal_entity_id', 'id'], 'journal_entries_entity_id_unique');
            $table->foreign(['tenant_id', 'legal_entity_id'], 'journal_entries_entity_tenant_fk')
                ->references(['tenant_id', 'id'])->on('legal_entities')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'accounting_period_id'], 'journal_entries_period_entity_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('accounting_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'reversal_of_id'], 'journal_entries_reversal_same_entity_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'status', 'effective_date'], 'journal_entries_ledger_index');
            $table->index(['tenant_id', 'legal_entity_id', 'source_type', 'source_id'], 'journal_entries_source_index');
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('legal_entity_id');
            $table->foreignId('journal_entry_id');
            $table->foreignId('account_id');
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 500)->nullable();
            $table->decimal('debit_amount', 20, 4)->default(0);
            $table->decimal('credit_amount', 20, 4)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'legal_entity_id', 'journal_entry_id', 'line_number'], 'journal_lines_entry_line_unique');
            $table->foreign(['tenant_id', 'legal_entity_id', 'journal_entry_id'], 'journal_lines_entry_entity_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'legal_entity_id', 'account_id'], 'journal_lines_account_entity_fk')
                ->references(['tenant_id', 'legal_entity_id', 'id'])->on('ledger_accounts')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_entity_id', 'account_id', 'journal_entry_id'], 'journal_lines_account_ledger_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER journal_entries_immutable_update BEFORE UPDATE ON journal_entries FOR EACH ROW BEGIN IF OLD.status IN ('posted', 'reversed') THEN IF NOT (OLD.status = 'posted' AND NEW.status = 'reversed' AND NEW.reversed_at IS NOT NULL AND OLD.id <=> NEW.id AND OLD.tenant_id <=> NEW.tenant_id AND OLD.legal_entity_id <=> NEW.legal_entity_id AND OLD.accounting_period_id <=> NEW.accounting_period_id AND OLD.journal_number <=> NEW.journal_number AND OLD.effective_date <=> NEW.effective_date AND OLD.currency <=> NEW.currency AND OLD.description <=> NEW.description AND OLD.source_type <=> NEW.source_type AND OLD.source_id <=> NEW.source_id AND OLD.idempotency_key <=> NEW.idempotency_key AND OLD.request_hash <=> NEW.request_hash AND OLD.created_by <=> NEW.created_by AND OLD.posted_by <=> NEW.posted_by AND OLD.posted_at <=> NEW.posted_at AND OLD.reversal_of_id <=> NEW.reversal_of_id AND OLD.created_at <=> NEW.created_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries are immutable'; END IF; END IF; END");
            DB::unprepared("CREATE TRIGGER journal_entries_validate_post BEFORE UPDATE ON journal_entries FOR EACH ROW BEGIN IF OLD.status = 'draft' AND NEW.status = 'posted' THEN IF NEW.posted_at IS NULL OR NEW.posted_by IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posting actor and timestamp are required'; END IF; IF NOT EXISTS (SELECT 1 FROM legal_entities e WHERE e.id = NEW.legal_entity_id AND e.tenant_id = NEW.tenant_id AND e.status = 'active' AND e.base_currency = NEW.currency) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal currency or legal entity is invalid'; END IF; IF NOT EXISTS (SELECT 1 FROM accounting_periods p WHERE p.id = NEW.accounting_period_id AND p.tenant_id = NEW.tenant_id AND p.legal_entity_id = NEW.legal_entity_id AND p.status = 'open' AND NEW.effective_date BETWEEN p.starts_on AND p.ends_on) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal date is outside an open accounting period'; END IF; IF (SELECT COUNT(*) FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id) < 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A journal requires at least two lines'; END IF; IF (SELECT COALESCE(SUM(l.debit_amount), 0) FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id) <= 0 OR (SELECT COALESCE(SUM(l.debit_amount), 0) FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id) <> (SELECT COALESCE(SUM(l.credit_amount), 0) FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal debits and credits must balance'; END IF; IF EXISTS (SELECT 1 FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id AND ((l.debit_amount <= 0 AND l.credit_amount <= 0) OR (l.debit_amount > 0 AND l.credit_amount > 0))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Each journal line must have exactly one positive side'; END IF; IF EXISTS (SELECT 1 FROM journal_lines l INNER JOIN ledger_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id AND a.legal_entity_id = l.legal_entity_id WHERE l.tenant_id = NEW.tenant_id AND l.legal_entity_id = NEW.legal_entity_id AND l.journal_entry_id = NEW.id AND (a.status <> 'active' OR a.is_posting_allowed = 0)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal contains an inactive or non-postable account'; END IF; END IF; END");
            DB::unprepared("CREATE TRIGGER journal_entries_immutable_delete BEFORE DELETE ON journal_entries FOR EACH ROW BEGIN IF OLD.status IN ('posted', 'reversed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal entries cannot be deleted'; END IF; END");
            DB::unprepared("CREATE TRIGGER journal_lines_insert_draft_only BEFORE INSERT ON journal_lines FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM journal_entries WHERE id = NEW.journal_entry_id AND tenant_id = NEW.tenant_id AND legal_entity_id = NEW.legal_entity_id AND status <> 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal lines may only be added to drafts'; END IF; END");
            DB::unprepared("CREATE TRIGGER journal_lines_update_draft_only BEFORE UPDATE ON journal_lines FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM journal_entries WHERE id = OLD.journal_entry_id AND tenant_id = OLD.tenant_id AND legal_entity_id = OLD.legal_entity_id AND status <> 'draft') OR EXISTS (SELECT 1 FROM journal_entries WHERE id = NEW.journal_entry_id AND tenant_id = NEW.tenant_id AND legal_entity_id = NEW.legal_entity_id AND status <> 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal lines are immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER journal_lines_delete_draft_only BEFORE DELETE ON journal_lines FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM journal_entries WHERE id = OLD.journal_entry_id AND tenant_id = OLD.tenant_id AND legal_entity_id = OLD.legal_entity_id AND status <> 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal lines cannot be deleted'; END IF; END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_delete_draft_only');
            DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_update_draft_only');
            DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_insert_draft_only');
            DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_immutable_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_validate_post');
        }
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('legal_entities');
    }
};
