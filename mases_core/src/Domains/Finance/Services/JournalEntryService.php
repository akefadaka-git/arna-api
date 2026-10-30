<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalEntryType;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Events\JournalDraftCreated;
use MaaleArna\MASES\Core\Domains\Finance\Events\JournalPosted;
use MaaleArna\MASES\Core\Domains\Finance\Events\JournalCancelled;
use MaaleArna\MASES\Core\Domains\Finance\Events\JournalReversed;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalLine;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceReferenceResolverRegistry;

final class JournalEntryService
{
    public function __construct(
        private readonly JournalSourceReferenceResolverRegistry $sourceReferenceResolvers,
    ) {
    }

    /**
     * Create an immutable-identity draft with entity-scoped, validated lines.
     * Ordinary callers may create standard or adjusting journals only.
     * Amounts remain decimal strings and are never converted to PHP floats.
     *
     * @param array<string, mixed> $header
     * @param list<array<string, mixed>> $lines
     */
    public function createDraft(int $tenantId, int $actorId, array $header, array $lines): JournalEntry
    {
        $typeValue = (string) ($header['journal_type'] ?? JournalEntryType::STANDARD->value);
        $journalType = JournalEntryType::tryFrom($typeValue);

        if ($journalType === null || !$journalType->isOrdinaryManualEntryAllowed()) {
            throw ValidationException::withMessages([
                'journal_type' => 'Only standard or adjusting journals may be created through the ordinary draft workflow.',
            ]);
        }

        // Normalize effective enum values before hashing so semantically identical
        // requests produce the same idempotency fingerprint.
        $header['journal_type'] = $journalType->value;

        $journalNumber = trim((string) ($header['journal_number'] ?? ''));
        if ($journalNumber === '' || mb_strlen($journalNumber) > 64) {
            throw ValidationException::withMessages([
                'journal_number' => 'A journal number between 1 and 64 characters is required.',
            ]);
        }

        $effectiveDate = trim((string) ($header['effective_date'] ?? ''));
        $parsedEffectiveDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$parsedEffectiveDate
            || $parsedEffectiveDate->format('Y-m-d') !== $effectiveDate
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw ValidationException::withMessages([
                'effective_date' => 'The effective date must be a valid ISO date in YYYY-MM-DD format.',
            ]);
        }

        $description = trim((string) ($header['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            throw ValidationException::withMessages([
                'description' => 'The journal description cannot exceed 500 characters.',
            ]);
        }

        $header['journal_number'] = $journalNumber;
        $header['effective_date'] = $effectiveDate;
        $header['description'] = $description;

        $sourceValue = trim((string) ($header['source_type'] ?? JournalSourceType::MANUAL->value));
        $sourceType = JournalSourceType::tryFrom($sourceValue);
        if ($sourceType === null) {
            throw ValidationException::withMessages([
                'source_type' => 'Source type must be a supported Finance provenance value.',
            ]);
        }
        $sourceId = trim((string) ($header['source_id'] ?? ''));
        if ($sourceType->requiresSourceReference() && $sourceId === '') {
            throw ValidationException::withMessages([
                'source_id' => 'This source type requires an originating business reference.',
            ]);
        }
        $header['source_type'] = $sourceType->value;
        $header['source_id'] = $sourceId === '' ? null : $sourceId;
        $sourceEventId = trim((string) ($header['source_event_id'] ?? ''));
        $sourceEventVersion = trim((string) ($header['source_event_version'] ?? ''));
        if ($sourceType->requiresSourceReference()) {
            if ($sourceEventId === '' || mb_strlen($sourceEventId) > 128) {
                throw ValidationException::withMessages([
                    'source_event_id' => 'An immutable originating source event ID of at most 128 characters is required.',
                ]);
            }
            if ($sourceEventVersion === '' || mb_strlen($sourceEventVersion) > 64) {
                throw ValidationException::withMessages([
                    'source_event_version' => 'An originating source event version of at most 64 characters is required.',
                ]);
            }
        } elseif ($sourceEventId !== '' || $sourceEventVersion !== '') {
            throw ValidationException::withMessages([
                'source_event_id' => 'Source event IDs and versions are only accepted for external business sources.',
            ]);
        }
        if ($sourceEventId === '') {
            unset($header['source_event_id']);
        } else {
            $header['source_event_id'] = $sourceEventId;
        }
        if ($sourceEventVersion === '') {
            unset($header['source_event_version']);
        } else {
            $header['source_event_version'] = $sourceEventVersion;
        }

        $sourceEventHash = null;
        if ($sourceType->requiresSourceReference()) {
            $sourcePayload = ['header' => $header, 'lines' => $lines];
            unset($sourcePayload['header']['idempotency_key']);
            $sourceEventHash = hash('sha256', json_encode($this->canonicalize($sourcePayload), JSON_THROW_ON_ERROR));
        }

        try {
            return DB::transaction(function () use ($tenantId, $actorId, $header, $lines, $journalType, $sourceType, $sourceEventVersion, $sourceEventHash): JournalEntry {
                if (count($lines) < 2) {
                    throw ValidationException::withMessages(['lines' => 'A journal must contain at least two lines.']);
                }

                $entity = LegalEntity::query()->where('tenant_id', $tenantId)
                    ->whereKey((int) ($header['legal_entity_id'] ?? 0))
                    ->where('status', 'active')->lockForUpdate()->first();

                if (!$entity) {
                    throw (new ModelNotFoundException())->setModel(LegalEntity::class);
                }

                // Validate source provenance against the resolved tenant and
                // legal entity before accepting or returning an idempotent draft.
                $this->sourceReferenceResolvers->assertValid(
                    $sourceType,
                    isset($header['source_id']) ? (string) $header['source_id'] : null,
                    $tenantId,
                    (int) $entity->id,
                );

                $date = (string) ($header['effective_date'] ?? '');
                $currency = strtoupper((string) ($header['currency'] ?? $entity->base_currency));
                if ($currency !== $entity->base_currency) {
                    throw ValidationException::withMessages(['currency' => 'Foreign-currency journals are disabled until the approved FX contract is implemented.']);
                }

                $key = trim((string) ($header['idempotency_key'] ?? ''));
                if ($key === '' || strlen($key) > 128) {
                    throw ValidationException::withMessages(['idempotency_key' => 'A non-empty idempotency key of at most 128 characters is required.']);
                }

                $requestHash = hash('sha256', json_encode($this->canonicalize(['header' => $header, 'lines' => $lines]), JSON_THROW_ON_ERROR));
                $existing = JournalEntry::query()->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $entity->id)->where('idempotency_key', $key)->first();
                if ($existing) {
                    if (hash_equals((string) $existing->request_hash, $requestHash)) {
                        return $existing->load('lines');
                    }
                    throw new ConflictHttpException('Idempotency key was already used for a different journal payload.');
                }

                if ($sourceType->requiresSourceReference()) {
                    $sourceExisting = JournalEntry::query()
                        ->where('tenant_id', $tenantId)
                        ->where('legal_entity_id', $entity->id)
                        ->where('source_type', $sourceType->value)
                        ->where('source_event_id', $sourceEventId)
                        ->where('source_event_version', $sourceEventVersion)
                        ->first();

                    if ($sourceExisting) {
                        if ($sourceEventHash !== null && hash_equals((string) $sourceExisting->source_event_hash, $sourceEventHash)) {
                            return $sourceExisting->load('lines');
                        }
                        throw new ConflictHttpException('This source event version is already bound to a different journal payload.');
                    }
                }

                $period = AccountingPeriod::query()->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $entity->id)
                    ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)
                    ->lockForUpdate()->first();

                if (!$period || $period->status !== AccountingPeriod::OPEN) {
                    throw ValidationException::withMessages(['effective_date' => 'The effective date must belong to an open accounting period.']);
                }

                $entry = JournalEntry::query()->create([
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $entity->id,
                    'accounting_period_id' => $period->id,
                    'journal_number' => trim((string) ($header['journal_number'] ?? '')),
                    'journal_type' => $journalType,
                    'effective_date' => $date,
                    'status' => JournalEntry::DRAFT,
                    'currency' => $currency,
                    'description' => trim((string) ($header['description'] ?? '')),
                    'source_type' => $header['source_type'] ?? null,
                    'source_id' => isset($header['source_id']) ? (string) $header['source_id'] : null,
                    'source_event_id' => $header['source_event_id'] ?? null,
                    'source_event_version' => $header['source_event_version'] ?? null,
                    'source_event_hash' => $sourceEventHash,
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'created_by' => $actorId,
                    'reversal_of_id' => null,
                ]);

                foreach (array_values($lines) as $index => $line) {
                    $accountId = (int) ($line['account_id'] ?? 0);
                    $account = LedgerAccount::query()->where('tenant_id', $tenantId)
                        ->where('legal_entity_id', $entity->id)->whereKey($accountId)
                        ->where('status', 'active')->where('is_posting_allowed', true)->first();

                    if (!$account) {
                        throw ValidationException::withMessages(["lines.{$index}.account_id" => 'Account is unavailable for posting in this legal entity.']);
                    }

                    $debit = $this->money((string) ($line['debit_amount'] ?? '0'), "lines.{$index}.debit_amount");
                    $credit = $this->money((string) ($line['credit_amount'] ?? '0'), "lines.{$index}.credit_amount");
                    $hasDebit = $this->isPositive($debit);
                    $hasCredit = $this->isPositive($credit);

                    if ($hasDebit === $hasCredit) {
                        throw ValidationException::withMessages(["lines.{$index}" => 'Each journal line must have exactly one positive debit or credit amount.']);
                    }

                    $cashFlowActivity = $line['cash_flow_activity'] ?? null;
                    if ($cashFlowActivity !== null && !(bool) $account->is_cash_equivalent) {
                        throw ValidationException::withMessages(["lines.{$index}.cash_flow_activity" => 'Cash-flow activity may only be assigned to an account approved as a cash equivalent.']);
                    }

                    JournalLine::query()->create([
                        'tenant_id' => $tenantId,
                        'legal_entity_id' => $entity->id,
                        'journal_entry_id' => $entry->id,
                        'account_id' => $account->id,
                        'line_number' => $index + 1,
                        'description' => isset($line['description']) ? trim((string) $line['description']) : null,
                        'debit_amount' => $debit,
                        'credit_amount' => $credit,
                        'cash_flow_activity' => $cashFlowActivity,
                        'cash_flow_classification_note' => isset($line['cash_flow_classification_note']) ? trim((string) $line['cash_flow_classification_note']) : null,
                    ]);
                }

                $this->assertBalanced($entry);
                $entry = $entry->load('lines');
                event(new JournalDraftCreated($entry, $actorId));

                return $entry;
            });
        } catch (QueryException $exception) {
            // Concurrent requests can race at the unique idempotency constraint.
            // Re-read only after the failed transaction has rolled back.
            $key = trim((string) ($header['idempotency_key'] ?? ''));
            $entityId = (int) ($header['legal_entity_id'] ?? 0);
            if ($key !== '' && $entityId > 0) {
                $existing = JournalEntry::query()->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $entityId)->where('idempotency_key', $key)->first();
                if ($existing) {
                    $requestHash = hash('sha256', json_encode(
                        $this->canonicalize(['header' => $header, 'lines' => $lines]),
                        JSON_THROW_ON_ERROR
                    ));
                    if (hash_equals((string) $existing->request_hash, $requestHash)) {
                        return $existing->load('lines');
                    }
                    throw new ConflictHttpException('Idempotency key was already used for a different journal payload.', 0, $exception);
                }

                $sourceType = JournalSourceType::tryFrom((string) ($header['source_type'] ?? ''));
                $sourceEventId = trim((string) ($header['source_event_id'] ?? ''));
                $sourceVersion = trim((string) ($header['source_event_version'] ?? ''));
                if ($sourceType?->requiresSourceReference() && $sourceEventId !== '' && $sourceVersion !== '') {
                    $sourceExisting = JournalEntry::query()->where('tenant_id', $tenantId)
                        ->where('legal_entity_id', $entityId)
                        ->where('source_type', $sourceType->value)
                        ->where('source_event_id', $sourceEventId)
                        ->where('source_event_version', $sourceVersion)
                        ->first();
                    if ($sourceExisting) {
                        $sourcePayload = ['header' => $header, 'lines' => $lines];
                        unset($sourcePayload['header']['idempotency_key']);
                        $sourceEventHash = hash('sha256', json_encode($this->canonicalize($sourcePayload), JSON_THROW_ON_ERROR));
                        if (hash_equals((string) $sourceExisting->source_event_hash, $sourceEventHash)) {
                            return $sourceExisting->load('lines');
                        }
                        throw new ConflictHttpException('This source event version is already bound to a different journal payload.', 0, $exception);
                    }
                }
            }

            throw $exception;
        }
    }

    public function cancelDraft(int $tenantId, int $actorId, int $journalId, string $reason): JournalEntry
    {
        return DB::transaction(function () use ($tenantId, $actorId, $journalId, $reason): JournalEntry {
            $entry = JournalEntry::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($journalId);
            if ($entry->status !== JournalEntry::DRAFT) {
                throw ValidationException::withMessages(['status' => 'Only draft journals can be cancelled.']);
            }

            if (trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => 'A cancellation reason is required.']);
            }

            $period = AccountingPeriod::query()->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $entry->legal_entity_id)
                ->lockForUpdate()->findOrFail($entry->accounting_period_id);
            if ($period->status !== AccountingPeriod::OPEN) {
                throw ValidationException::withMessages(['accounting_period' => 'A draft can only be cancelled while its accounting period is open.']);
            }

            $entry->status = JournalEntry::CANCELLED;
            $entry->cancelled_by = $actorId;
            $entry->cancelled_at = now();
            $entry->cancellation_reason = trim($reason);
            $entry->save();
            $entry = $entry->load('lines');
            event(new JournalCancelled($entry, $actorId, trim($reason)));

            return $entry;
        });
    }

    public function post(int $tenantId, int $actorId, int $journalId): JournalEntry
    {
        return DB::transaction(function () use ($tenantId, $actorId, $journalId): JournalEntry {
            $entry = JournalEntry::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($journalId);

            if ($entry->status !== JournalEntry::DRAFT) {
                throw ValidationException::withMessages(['status' => 'Only draft journals can be posted.']);
            }

            $type = $entry->journal_type instanceof JournalEntryType
                ? $entry->journal_type
                : JournalEntryType::tryFrom((string) $entry->journal_type);

            if ($type === null) {
                throw ValidationException::withMessages(['journal_type' => 'Journal type is invalid.']);
            }

            if ($type->requiresReversalReference()) {
                if ($entry->reversal_of_id === null) {
                    throw ValidationException::withMessages(['reversal_of_id' => 'A reversal journal must reference its original journal.']);
                }

                $original = JournalEntry::query()->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $entry->legal_entity_id)
                    ->whereKey($entry->reversal_of_id)->lockForUpdate()->first();

                if (!$original || $original->status !== JournalEntry::POSTED || $original->reversal_of_id !== null) {
                    throw ValidationException::withMessages(['reversal_of_id' => 'The referenced journal is not eligible for reversal.']);
                }
            } elseif ($entry->reversal_of_id !== null || !$type->isOrdinaryManualEntryAllowed()) {
                throw ValidationException::withMessages(['journal_type' => 'This journal type is not permitted through the ordinary posting workflow.']);
            }

            $entity = LegalEntity::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($entry->legal_entity_id)
                ->lockForUpdate()
                ->first();

            if (!$entity || $entity->status !== 'active' || $entity->base_currency !== $entry->currency) {
                throw ValidationException::withMessages([
                    'legal_entity' => 'The legal entity must remain active and its base currency must match before posting.',
                ]);
            }

            $period = AccountingPeriod::query()->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $entry->legal_entity_id)
                ->lockForUpdate()->findOrFail($entry->accounting_period_id);

            if ($period->status !== AccountingPeriod::OPEN
                || $entry->effective_date->lt($period->starts_on)
                || $entry->effective_date->gt($period->ends_on)) {
                throw ValidationException::withMessages(['accounting_period' => 'The accounting period is not open for this journal date.']);
            }

            $lines = JournalLine::query()
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $entry->legal_entity_id)
                ->where('journal_entry_id', $entry->id)
                ->orderBy('line_number')
                ->lockForUpdate()
                ->get();

            $accountIds = $lines->pluck('account_id')->map(static fn ($id): int => (int) $id)->unique()->values();
            $accounts = LedgerAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $entry->legal_entity_id)
                ->whereIn('id', $accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($accounts->count() !== $accountIds->count()) {
                throw ValidationException::withMessages([
                    'lines' => 'Every journal line must reference an account in the same tenant and legal entity.',
                ]);
            }

            foreach ($lines as $index => $line) {
                $account = $accounts->get((int) $line->account_id);
                if (!$account || $account->status !== 'active' || !(bool) $account->is_posting_allowed) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.account_id" => 'The account must remain active and postable when the journal is posted.',
                    ]);
                }
                if ($line->cash_flow_activity !== null && !(bool) $account->is_cash_equivalent) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.cash_flow_activity" => 'The account is no longer approved as a cash equivalent.',
                    ]);
                }
            }

            $this->assertBalanced($entry);
            $entry->status = JournalEntry::POSTED;
            $entry->posted_by = $actorId;
            $entry->posted_at = now();
            $entry->save();
            $entry = $entry->load('lines');
            event(new JournalPosted($entry, $actorId));

            return $entry;
        });
    }

    public function reverse(int $tenantId, int $actorId, int $journalId, string $reversalNumber, string $reversalDate, string $reason): JournalEntry
    {
        return DB::transaction(function () use ($tenantId, $actorId, $journalId, $reversalNumber, $reversalDate, $reason): JournalEntry {
            $original = JournalEntry::query()->where('tenant_id', $tenantId)
                ->lockForUpdate()->findOrFail($journalId);

            if (trim($reason) === '' || trim($reversalNumber) === '') {
                throw ValidationException::withMessages(['reason' => 'A reversal number and reason are required.']);
            }
            if ($original->reversal_of_id !== null) {
                throw ValidationException::withMessages(['journal' => 'A reversal journal cannot itself be reversed.']);
            }

            $originalLines = $original->lines()->orderBy('line_number')->get();
            $reversalHeader = [
                'legal_entity_id' => $original->legal_entity_id,
                'journal_number' => trim($reversalNumber),
                'effective_date' => $reversalDate,
                'currency' => $original->currency,
                'description' => 'Reversal of '.$original->journal_number.': '.trim($reason),
                // A reversal is generated by the Finance system, while source_id
                // preserves its relationship to the originating journal.
                'source_type' => JournalSourceType::SYSTEM->value,
                'source_id' => (string) $original->id,
                'idempotency_key' => 'reversal:'.$original->id,
            ];
            $reversalLines = $originalLines->map(fn (JournalLine $line): array => [
                'account_id' => $line->account_id,
                'debit_amount' => $line->credit_amount,
                'credit_amount' => $line->debit_amount,
                'description' => $line->description,
                'cash_flow_activity' => $line->cash_flow_activity,
                'cash_flow_classification_note' => $line->cash_flow_classification_note,
            ])->all();

            // A retried request after a successful reversal returns the already
            // persisted reversal only when the full request fingerprint matches.
            if ($original->status === JournalEntry::REVERSED) {
                $existing = JournalEntry::query()->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $original->legal_entity_id)
                    ->where('idempotency_key', 'reversal:'.$original->id)->first();

                if (!$existing) {
                    throw new ConflictHttpException('Original journal is marked reversed but its reversal record is missing.');
                }

                $sameRequest = $this->createDraft($tenantId, $actorId, $reversalHeader, $reversalLines);
                if ((int) $sameRequest->id !== (int) $existing->id
                    || $existing->status !== JournalEntry::POSTED
                    || (int) $existing->reversal_of_id !== (int) $original->id) {
                    throw new ConflictHttpException('The persisted reversal does not match the original journal state.');
                }

                return $existing->load('lines');
            }

            if ($original->status !== JournalEntry::POSTED) {
                throw ValidationException::withMessages(['journal' => 'Only a posted, non-reversal journal can be reversed.']);
            }

            $reversal = $this->createDraft($tenantId, $actorId, $reversalHeader, $reversalLines);

            // Only this controlled reversal path may assign REVERSAL and its reference.
            $reversal->journal_type = JournalEntryType::REVERSAL;
            $reversal->reversal_of_id = $original->id;
            $reversal->save();

            $postedReversal = $this->post($tenantId, $actorId, (int) $reversal->id);
            $original->status = JournalEntry::REVERSED;
            $original->reversed_at = now();
            $original->save();
            event(new JournalReversed($original->fresh(), $postedReversal, $actorId, trim($reason)));

            return $postedReversal;
        });
    }

    /** @param array<string, mixed> $value */
    private function canonicalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }
        unset($item);

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function assertBalanced(JournalEntry $entry): void
    {
        $totals = JournalLine::query()->where('tenant_id', $entry->tenant_id)
            ->where('legal_entity_id', $entry->legal_entity_id)
            ->where('journal_entry_id', $entry->id)
            ->selectRaw('COUNT(*) AS line_count, COALESCE(SUM(debit_amount), 0) AS total_debits, COALESCE(SUM(credit_amount), 0) AS total_credits')
            ->first();

        if ((int) $totals->line_count < 2
            || $this->normalizeDecimal((string) $totals->total_debits) !== $this->normalizeDecimal((string) $totals->total_credits)
            || !$this->isPositive((string) $totals->total_debits)) {
            throw ValidationException::withMessages(['lines' => 'Journal debits and credits must be equal and greater than zero.']);
        }
    }

    private function money(string $value, string $field): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{1,16}(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'Amount must be a non-negative decimal with at most four fractional digits.']);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ltrim($whole, '0') === '' ? '0.'.str_pad($fraction, 4, '0') : ltrim($whole, '0').'.'.str_pad($fraction, 4, '0');
    }

    private function isPositive(string $value): bool
    {
        return $this->normalizeDecimal($value) !== '0';
    }

    private function normalizeDecimal(string $value): string
    {
        // Exact string normalization; never cast monetary values to float.
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }
}
