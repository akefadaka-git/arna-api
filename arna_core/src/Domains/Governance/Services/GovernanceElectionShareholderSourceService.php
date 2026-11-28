<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Governance\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only adapter from the canonical Shareholder domain into an election
 * source preview. It never calculates voter eligibility or voting weight.
 *
 * The returned preview is technical evidence only. Current identity/status
 * fields are not historical proof, and the statutory/legal blockers must be
 * closed before any Governance register can be approved or frozen.
 */
final class GovernanceElectionShareholderSourceService
{
    private const ENTRY_TYPES = [
        'subscription',
        'transfer_in',
        'transfer_out',
        'allocation',
        'adjustment',
        'reversal',
    ];

    /**
     * Capture a tenant-scoped technical preview at an explicit offset-aware
     * record-date instant. The transaction locks shareholder and holding rows
     * in the same order used by the Shareholder writer before reading ledgers.
     *
     * @return array<string,mixed>
     */
    public function previewTenant(int $tenantId, string $recordDateTime, ?string $databaseTimezoneOverride = null): array
    {
        if ($tenantId < 1) {
            throw ValidationException::withMessages([
                'tenant_id' => 'A valid tenant is required.',
            ]);
        }

        $cutoff = self::parseRecordDateTime($recordDateTime);
        $databaseTimezone = $databaseTimezoneOverride ?? (string) config('app.timezone', 'UTC');
        try {
            new DateTimeZone($databaseTimezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'database_timezone' => 'A valid database timezone is required for source reconstruction.',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $cutoff, $databaseTimezone): array {
            $shareholders = DB::table('shareholders')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get([
                    'id',
                    'shareholder_number',
                    'shareholder_type',
                    'person_id',
                    'user_id',
                    'representative_user_id',
                    'is_active',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ]);

            $holdings = DB::table('share_holdings as h')
                ->join('shareholders as s', 's.id', '=', 'h.shareholder_id')
                ->join('share_classes as c', 'c.id', '=', 'h.share_class_id')
                ->where('s.tenant_id', $tenantId)
                ->orderBy('h.id')
                ->lockForUpdate()
                ->get([
                    'h.id as holding_id',
                    'h.shareholder_id',
                    'h.share_class_id',
                    'h.current_quantity',
                    'h.created_at as holding_created_at',
                    'c.code as share_class_code',
                ]);

            $holdingsByShareholder = [];
            $sourceManifest = [];
            foreach ($holdings as $holding) {
                $ledger = DB::table('share_holding_entries')
                    ->where('share_holding_id', $holding->holding_id)
                    ->orderBy('id')
                    ->get([
                        'id',
                        'share_holding_id',
                        'entry_type',
                        'quantity',
                        'balance_before',
                        'balance_after',
                        'reference_type',
                        'reference_id',
                        'occurred_at',
                        'created_by',
                        'reversed_entry_id',
                        'created_at',
                    ]);

                if ($ledger->isEmpty()) {
                    $createdAt = new DateTimeImmutable((string) $holding->holding_created_at, new DateTimeZone($databaseTimezone));
                    if (bccomp((string) $holding->current_quantity, '0', 4) !== 0 || $createdAt <= $cutoff) {
                        throw ValidationException::withMessages([
                            'share_ledger' => "Holding {$holding->holding_id} has no ledger history sufficient to prove its record-date balance.",
                        ]);
                    }

                    $reconstruction = [
                        'balance' => '0.0000',
                        'current_projection' => bcadd((string) $holding->current_quantity, '0', 4),
                        'entry_count' => 0,
                        'entries' => [],
                        'ledger_digest' => hash('sha256', '[]'),
                    ];
                } else {
                    $reconstruction = self::reconstructLedger(
                        $ledger->all(),
                        (string) $holding->current_quantity,
                        $cutoff,
                        $databaseTimezone,
                    );
                }

                $line = [
                    'holding_id' => (int) $holding->holding_id,
                    'holding_created_at' => (string) $holding->holding_created_at,
                    'share_class_id' => (int) $holding->share_class_id,
                    'share_class_code' => (string) $holding->share_class_code,
                    'quantity_at_cutoff' => $reconstruction['balance'],
                    'current_projection' => $reconstruction['current_projection'],
                    'entry_count' => $reconstruction['entry_count'],
                    'ledger_digest' => $reconstruction['ledger_digest'],
                    'ledger_manifest' => $reconstruction['entries'],
                    'source_state' => 'ledger_chain_validated',
                ];

                $holdingsByShareholder[(int) $holding->shareholder_id][] = $line;
                $sourceManifest[] = [
                    'holding_id' => (int) $holding->holding_id,
                    'holding_created_at' => (string) $holding->holding_created_at,
                    'shareholder_id' => (int) $holding->shareholder_id,
                    'share_class_id' => (int) $holding->share_class_id,
                    'ledger_digest' => $reconstruction['ledger_digest'],
                    'entry_count' => $reconstruction['entry_count'],
                ];
            }

            $holderRows = [];
            foreach ($shareholders as $shareholder) {
                $currentStatus = $shareholder->deleted_at !== null
                    ? 'soft_deleted_current'
                    : ((bool) $shareholder->is_active ? 'active_current' : 'inactive_current');

                $holderRows[] = [
                    'shareholder_id' => (int) $shareholder->id,
                    'shareholder_number' => $shareholder->shareholder_number,
                    'shareholder_type' => (string) $shareholder->shareholder_type,
                    'person_id' => $shareholder->person_id === null ? null : (int) $shareholder->person_id,
                    'direct_user_id' => $shareholder->user_id === null ? null : (int) $shareholder->user_id,
                    'representative_user_id' => $shareholder->representative_user_id === null ? null : (int) $shareholder->representative_user_id,
                    'current_status' => $currentStatus,
                    'identity_temporal_state' => 'unresolved_no_effective_dated_history',
                    'holdings' => $holdingsByShareholder[(int) $shareholder->id] ?? [],
                ];
            }

            $capturedAt = Carbon::now($databaseTimezone)->utc()->format('Y-m-d\TH:i:s.u\Z');
            $canonical = [
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'record_date_time_utc' => $cutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'database_timezone' => $databaseTimezone,
                'captured_at_utc' => $capturedAt,
                'shareholders' => $holderRows,
                'source_manifest' => $sourceManifest,
            ];
            $digestPayload = $canonical;
            unset($digestPayload['captured_at_utc']);
            $digest = hash('sha256', json_encode($digestPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return [
                'status' => 'technical_source_preview_only',
                'entitlement_state' => 'blocked_not_calculated',
                'tenant_id' => $tenantId,
                'record_date_time_utc' => $canonical['record_date_time_utc'],
                'database_timezone' => $databaseTimezone,
                'captured_at_utc' => $capturedAt,
                'shareholder_count' => count($holderRows),
                'holding_count' => count($sourceManifest),
                'shareholders' => $holderRows,
                'source_manifest' => $sourceManifest,
                'source_digest' => $digest,
                'limitations' => [
                    'Current identity and status fields do not prove historical register status.',
                    'The preview does not establish statutory register authenticity, paid-up capital, transfer validity, class rights or voting entitlement.',
                    'No voter eligibility, candidate eligibility, proxy authority, quorum or voting weight is calculated.',
                    'The preview is not an approved or frozen election register.',
                ],
            ];
        }, 3);
    }

    /**
     * Validate the append-order ledger chain, then reconstruct business-time
     * balance at the supplied instant. Backdated rows are included by
     * occurred_at but remain represented in the full source manifest.
     *
     * @param iterable<object> $ledgerEntries
     * @return array{balance:string,current_projection:string,entry_count:int,entries:array<int,array<string,mixed>>,ledger_digest:string}
     */
    public static function reconstructLedger(
        iterable $ledgerEntries,
        string $currentProjection,
        DateTimeImmutable $cutoff,
        string $databaseTimezone,
    ): array {
        self::assertDecimalArithmetic();

        if (!preg_match('/^-?\d+(?:\.\d{1,4})?$/', $currentProjection)) {
            throw ValidationException::withMessages([
                'share_ledger' => 'The current holding projection is not a valid decimal quantity.',
            ]);
        }

        $entries = is_array($ledgerEntries)
            ? array_values($ledgerEntries)
            : array_values(iterator_to_array($ledgerEntries));

        if ($entries === []) {
            throw ValidationException::withMessages([
                'share_ledger' => 'A complete immutable ledger history is required.',
            ]);
        }

        usort($entries, static fn (object $a, object $b): int => (int) $a->id <=> (int) $b->id);

        $byId = [];
        $appendBalance = '0.0000';
        $reconstructedAtCutoff = '0.0000';
        $manifest = [];
        $reversalTargets = [];
        $databaseZone = new DateTimeZone($databaseTimezone);
        $previousId = 0;
        $expectedHoldingId = null;

        foreach ($entries as $entry) {
            $id = (int) ($entry->id ?? 0);
            $holdingId = (int) ($entry->share_holding_id ?? 0);
            $type = (string) ($entry->entry_type ?? '');
            $quantity = (string) ($entry->quantity ?? '');
            $before = (string) ($entry->balance_before ?? '');
            $after = (string) ($entry->balance_after ?? '');
            $occurredAtRaw = (string) ($entry->occurred_at ?? '');

            $expectedHoldingId ??= $holdingId;
            if ($id < 1 || $id <= $previousId || isset($byId[$id]) || $holdingId < 1 || $holdingId !== $expectedHoldingId) {
                throw ValidationException::withMessages([
                    'share_ledger' => 'Ledger entry identifiers and holding references must be valid and unique.',
                ]);
            }
            if (!in_array($type, self::ENTRY_TYPES, true)) {
                throw ValidationException::withMessages([
                    'share_ledger' => "Ledger entry {$id} has an unsupported entry type.",
                ]);
            }
            foreach (['quantity' => $quantity, 'balance_before' => $before, 'balance_after' => $after] as $field => $value) {
                if (!preg_match('/^-?\d+(?:\.\d{1,4})?$/', $value)) {
                    throw ValidationException::withMessages([
                        'share_ledger' => "Ledger entry {$id} has an invalid {$field} decimal.",
                    ]);
                }
            }
            if (bccomp($quantity, '0', 4) === 0 || bccomp($before, '0', 4) < 0 || bccomp($after, '0', 4) < 0) {
                throw ValidationException::withMessages([
                    'share_ledger' => "Ledger entry {$id} has a zero movement or negative balance.",
                ]);
            }
            if (bccomp($before, $appendBalance, 4) !== 0
                || bccomp(bcadd($before, $quantity, 4), $after, 4) !== 0) {
                throw ValidationException::withMessages([
                    'share_ledger' => "Ledger entry {$id} breaks the immutable append-order balance chain.",
                ]);
            }

            if ($occurredAtRaw === '') {
                throw ValidationException::withMessages([
                    'share_ledger' => "Ledger entry {$id} is missing its business-effective timestamp.",
                ]);
            }
            try {
                $occurredAt = new DateTimeImmutable($occurredAtRaw, $databaseZone);
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'share_ledger' => "Ledger entry {$id} has an invalid business-effective timestamp.",
                ]);
            }

            $reversedEntryId = isset($entry->reversed_entry_id) ? (int) $entry->reversed_entry_id : null;
            if ($type === 'reversal') {
                if ($reversedEntryId === null || $reversedEntryId < 1 || isset($reversalTargets[$reversedEntryId])) {
                    throw ValidationException::withMessages([
                        'share_ledger' => "Reversal entry {$id} has missing or duplicate original-entry linkage.",
                    ]);
                }
                $reversalTargets[$reversedEntryId] = true;
            } elseif ($reversedEntryId !== null) {
                throw ValidationException::withMessages([
                    'share_ledger' => "Non-reversal entry {$id} unexpectedly references a reversed entry.",
                ]);
            }

            $canonicalEntry = [
                'id' => $id,
                'share_holding_id' => $holdingId,
                'entry_type' => $type,
                'quantity' => bcadd($quantity, '0', 4),
                'balance_before' => bcadd($before, '0', 4),
                'balance_after' => bcadd($after, '0', 4),
                'reference_type' => isset($entry->reference_type) ? (string) $entry->reference_type : null,
                'reference_id' => isset($entry->reference_id) ? (string) $entry->reference_id : null,
                'occurred_at' => $occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'created_by' => isset($entry->created_by) ? (int) $entry->created_by : null,
                'reversed_entry_id' => $reversedEntryId,
                'created_at' => isset($entry->created_at) ? (string) $entry->created_at : null,
            ];
            $entryDigest = hash('sha256', json_encode($canonicalEntry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $byId[$id] = [
                'entry' => $entry,
                'canonical' => $canonicalEntry,
                'occurred_at' => $occurredAt,
            ];
            $appendBalance = bcadd($appendBalance, $quantity, 4);
            if ($occurredAt <= $cutoff) {
                $reconstructedAtCutoff = bcadd($reconstructedAtCutoff, $quantity, 4);
            }
            $manifest[] = [
                'entry_id' => $id,
                'entry_digest' => $entryDigest,
                'included_by_business_time' => $occurredAt <= $cutoff,
            ];
            $previousId = $id;
        }

        foreach ($byId as $id => $row) {
            $entry = $row['entry'];
            if ((string) $entry->entry_type !== 'reversal') {
                continue;
            }

            $originalId = (int) $entry->reversed_entry_id;
            $original = $byId[$originalId] ?? null;
            if ($original === null
                || $originalId >= (int) $id
                || (int) $original['entry']->share_holding_id !== (int) $entry->share_holding_id
                || (string) $original['entry']->entry_type === 'reversal'
                || bccomp(
                    bcadd((string) $entry->quantity, (string) $original['entry']->quantity, 4),
                    '0',
                    4,
                ) !== 0
                || $row['occurred_at'] < $original['occurred_at']) {
                throw ValidationException::withMessages([
                    'share_ledger' => "Reversal entry {$id} does not validly reverse its original entry.",
                ]);
            }
            $reversalTargets[$originalId] = true;
        }

        if (bccomp($appendBalance, $currentProjection, 4) !== 0) {
            throw ValidationException::withMessages([
                'share_ledger' => 'The append-only ledger does not reconcile to the current holding projection.',
            ]);
        }
        if (bccomp($reconstructedAtCutoff, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'share_ledger' => 'The reconstructed record-date quantity is negative.',
            ]);
        }

        $canonicalManifest = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return [
            'balance' => bcadd($reconstructedAtCutoff, '0', 4),
            'current_projection' => bcadd($currentProjection, '0', 4),
            'entry_count' => count($manifest),
            'entries' => $manifest,
            'ledger_digest' => hash('sha256', $canonicalManifest),
        ];
    }

    public static function parseRecordDateTime(string $value): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw ValidationException::withMessages([
                'record_date_time' => 'An ISO-8601 timestamp with an explicit UTC offset is required.',
            ]);
        }

        $normalised = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $format = str_contains($normalised, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $parsed = DateTimeImmutable::createFromFormat($format, $normalised);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw ValidationException::withMessages([
                'record_date_time' => 'The supplied record-date timestamp is not a valid calendar instant.',
            ]);
        }

        return $parsed;
    }

    private static function assertDecimalArithmetic(): void
    {
        if (!function_exists('bcadd') || !function_exists('bccomp')) {
            throw new \RuntimeException('The BCMath PHP extension is required for share-ledger reconstruction.');
        }
    }
}
