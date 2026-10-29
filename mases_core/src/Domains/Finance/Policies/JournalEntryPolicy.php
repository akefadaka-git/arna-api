<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;

final class JournalEntryPolicy extends FinancePolicy
{
    public function viewAny(User $user): bool { return $this->allows($user, 'finance.journal.view'); }

    public function view(User $user, JournalEntry $journal): bool { return $this->belongsToCurrentTenant($journal) && $this->allows($user, 'finance.journal.view', (int) $journal->tenant_id); }

    public function create(User $user): bool { return $this->allows($user, 'finance.journal.create'); }

    public function cancel(User $user, JournalEntry $journal): bool { return $this->belongsToCurrentTenant($journal) && $this->allows($user, 'finance.journal.cancel', (int) $journal->tenant_id); }

    public function post(User $user, JournalEntry $journal): bool { return $this->belongsToCurrentTenant($journal) && $this->allows($user, 'finance.journal.post', (int) $journal->tenant_id); }

    public function reverse(User $user, JournalEntry $journal): bool { return $this->belongsToCurrentTenant($journal) && $this->allows($user, 'finance.journal.reverse', (int) $journal->tenant_id); }

    public function viewReport(User $user): bool { return $this->allows($user, 'finance.report.view'); }
}
