<?php
declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use RuntimeException;

/**
 * Reconstructs an election register from the immutable shareholding ledger.
 * Class rights must be explicitly verified in governance.election_voting.
 */
final class GovernanceElectionRegisterService
{
    public static function defaultVotingWeight(string|int $shareQuantity, string|int|null $votesPerShare = null): string
    {
        self::assertDecimalArithmetic();
        $quantity = (string) $shareQuantity;
        if ($votesPerShare === null) {
            throw ValidationException::withMessages(['votes_per_share' => 'A verified positive class voting rate is required; no default voting weight is assumed.']);
        }
        $rate = (string) $votesPerShare;
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $quantity)
            || bccomp($quantity, '0', 4) <= 0
            || bccomp($quantity, bcadd($quantity, '0', 0), 4) !== 0) {
            throw ValidationException::withMessages(['share_quantity' => 'Voting shares must be a positive whole-share quantity.']);
        }
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $rate) || bccomp($rate, '0', 4) <= 0) {
            throw ValidationException::withMessages(['votes_per_share' => 'A verified positive class voting rate is required.']);
        }
        return bcmul($quantity, $rate, 4);
    }

    /**
     * Build one entitlement row per shareholder/share-class pair from ledger
     * entries effective on the record date. No current-balance backdating.
     *
     * @return array<int,array{shareholder_id:int,share_class_code:string,share_quantity:string,voting_weight:string,entitlement_reference:string,proxy_authorized:bool}>
     */
    public function reconstructAsOf(int $tenantId, string $recordDate, string $evidenceReference): array
    {
        self::assertDecimalArithmetic();
        if ($tenantId < 1 || trim($evidenceReference) === '') {
            throw ValidationException::withMessages(['register' => 'Tenant and authoritative ledger evidence are required.']);
        }
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $recordDate)) {
            throw ValidationException::withMessages(['record_date' => 'The register record date must use YYYY-MM-DD.']);
        }
        $recordDateValue = Carbon::createFromFormat('!Y-m-d', $recordDate);
        if ($recordDateValue === false || $recordDateValue->format('Y-m-d') !== $recordDate) {
            throw ValidationException::withMessages(['record_date' => 'The register record date is not a valid calendar date.']);
        }
        if (mb_strlen(trim($evidenceReference)) > 120) {
            throw ValidationException::withMessages(['register' => 'The authoritative ledger evidence reference is too long for the voter register.']);
        }
        $cutoff = $recordDateValue->endOfDay();
        $rates = (array) config('governance.election_voting.verified_class_entitlements', []);
        if (DB::transactionLevel() > 0) {
            // ShareHoldingService locks the shareholder before creating a
            // holding. Locking the tenant's shareholder range prevents a
            // concurrent new holding from appearing outside this snapshot.
            DB::table('shareholders')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }
        // Inspect every current holding, not only rows that happen to have
        // an entry before the record date. Otherwise a positive projection
        // with a missing ledger could be silently omitted when other valid
        // holdings exist in the same register.
        $holdingsQuery = DB::table('share_holdings as h')
            ->join('shareholders as s', 's.id', '=', 'h.shareholder_id')
            ->join('share_classes as c', 'c.id', '=', 'h.share_class_id')
            ->where('s.tenant_id', $tenantId)
            ->select(['h.id as holding_id', 'h.shareholder_id', 'h.current_quantity', 'h.created_at as holding_created_at', 's.deleted_at as shareholder_deleted_at', 'c.code as class_code'])
            ->orderBy('h.shareholder_id')->orderBy('c.code');
        if (DB::transactionLevel() > 0) {
            // ShareHoldingService locks the same projection row before
            // appending a ledger entry; freezing holds that lock through the
            // snapshot write so the reconstructed balance cannot race.
            $holdingsQuery->lockForUpdate();
        }
        $holdings = $holdingsQuery->get();

        $entries = [];
        foreach ($holdings as $holding) {
            // Validate the complete immutable ledger in append order, then
            // calculate the record-date balance by business time. A backfilled
            // entry may have an earlier occurred_at but a later id.
            // Ledger rows are append-only. The locked holding row serializes
            // all domain writes; a consistent read avoids lock-order inversion
            // with reversal operations, which first lock the original entry.
            $ledger = DB::table('share_holding_entries')
                ->where('share_holding_id', $holding->holding_id)
                ->orderBy('id')->get();

            if ($ledger->isEmpty()) {
                if (bccomp((string) $holding->current_quantity, '0', 4) !== 0
                    || $holding->holding_created_at === null
                    || Carbon::parse($holding->holding_created_at)->lessThanOrEqualTo($cutoff)) {
                    throw ValidationException::withMessages([
                        'share_ledger' => "Holding {$holding->holding_id} has no immutable ledger history sufficient to prove its record-date balance.",
                    ]);
                }

                // A zero-balance holding created after the record date could
                // not have held shares on that date; an older empty ledger is
                // unresolved and must block reconstruction.
                continue;
            }

            $balance = self::reconstructHistoricalHoldingBalance(
                $ledger,
                $recordDate,
                (string) $holding->current_quantity,
            );
            if (bccomp($balance, '0', 4) <= 0) {
                continue;
            }
            if ($holding->shareholder_deleted_at !== null
                && Carbon::parse($holding->shareholder_deleted_at)->lessThanOrEqualTo($cutoff)) {
                throw ValidationException::withMessages([
                    'shareholder_eligibility' => "Shareholder {$holding->shareholder_id} was soft-deleted on or before the record date but has a positive holding; eligibility must be resolved from the authoritative register before freeze.",
                ]);
            }

            $class = strtoupper((string) $holding->class_code);
            $classRule = $rates[$class] ?? null;
            if (!is_array($classRule) || ($classRule['verified'] ?? false) !== true
                || ($classRule['eligible'] ?? false) !== true
                || !isset($classRule['votes_per_share'], $classRule['evidence_reference'])
                || trim((string) $classRule['evidence_reference']) === '') {
                throw ValidationException::withMessages([
                    'share_class_entitlements' => "Share class {$class} has no verified eligible voting entitlement. Register freeze is blocked.",
                ]);
            }

            $rate = (string) $classRule['votes_per_share'];
            $entitlementReference = trim($evidenceReference) . ';class:' . trim((string) $classRule['evidence_reference']);
            if (mb_strlen($entitlementReference) > 160) {
                throw ValidationException::withMessages([
                    'share_class_entitlements' => "The combined entitlement evidence reference for share class {$class} exceeds the register field limit.",
                ]);
            }
            $entries[] = [
                'shareholder_id' => (int) $holding->shareholder_id,
                'share_class_code' => $class,
                'share_quantity' => $balance,
                'voting_weight' => self::defaultVotingWeight($balance, $rate),
                'entitlement_reference' => $entitlementReference,
                'proxy_authorized' => false,
            ];
        }
        if ($entries === []) {
            throw ValidationException::withMessages(['entries' => 'No eligible positive share entitlements exist at the record date.']);
        }
        return $entries;
    }

    /**
     * Freeze a register only after its record-date ledger reconstruction and
     * class-rights verification. Multiple classes per holder are preserved.
     *
     * @param array<int,array{shareholder_id:int,share_class_code:string,share_quantity:string|int|float,voting_weight?:string|int|float,entitlement_reference:string,proxy_authorized?:bool}> $entries
     */
    public function freeze(int $tenantId, int $electionId, string $recordDate, string $sourceReference, string $ruleVersion, int $actorId, array $entries): int
    {
        if ($entries === []) {
            throw ValidationException::withMessages(['entries' => 'The approved voter register cannot be empty.']);
        }
        self::assertDecimalArithmetic();
        return DB::transaction(function () use ($tenantId, $electionId, $recordDate, $sourceReference, $ruleVersion, $actorId, $entries): int {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            if (!$election->isAuthorityVerified() || $election->status !== 'planned') {
                throw new RuntimeException('Verified authority and planned status are required before register freeze.');
            }
            if ($election->record_date !== null && $election->record_date->toDateString() !== $recordDate) {
                throw new RuntimeException('The supplied record date does not match the approved record date.');
            }
            if (trim($sourceReference) === '' || trim($ruleVersion) === '' || $actorId < 1) {
                throw ValidationException::withMessages(['source_reference' => 'Evidence, rule version and actor are required.']);
            }
            if (DB::table('governance_election_register_snapshots')->where('tenant_id', $tenantId)->where('election_id', $electionId)->exists()) {
                throw new RuntimeException('An election register snapshot is immutable and may only be frozen once.');
            }

            $rates = (array) config('governance.election_voting.verified_class_entitlements', []);
            $normalised = [];
            $holderIds = [];
            $keys = [];
            foreach ($entries as $entry) {
                $id = (int) ($entry['shareholder_id'] ?? 0);
                $class = strtoupper(trim((string) ($entry['share_class_code'] ?? '')));
                $quantity = (string) ($entry['share_quantity'] ?? '0');
                $evidence = trim((string) ($entry['entitlement_reference'] ?? ''));
                $rule = $rates[$class] ?? null;
                if ($id < 1 || $class === '' || $evidence === '' || !is_array($rule)
                    || ($rule['verified'] ?? false) !== true || ($rule['eligible'] ?? false) !== true
                    || !isset($rule['votes_per_share'], $rule['evidence_reference'])) {
                    throw ValidationException::withMessages(['entries' => 'Each register row requires a shareholder, evidence and a verified eligible share-class entitlement.']);
                }
                $key = $id . ':' . $class;
                if (isset($keys[$key])) {
                    throw ValidationException::withMessages(['entries' => 'Duplicate shareholder/share-class entitlement row.']);
                }
                $weight = self::defaultVotingWeight($quantity, (string) $rule['votes_per_share']);
                if (isset($entry['voting_weight']) && bccomp((string) $entry['voting_weight'], $weight, 4) !== 0) {
                    throw ValidationException::withMessages(['entries' => 'Voting weight does not match the verified class entitlement rate.']);
                }
                $keys[$key] = true;
                $holderIds[$id] = true;
                $normalised[] = [
                    'shareholder_id' => $id,
                    'share_class_code' => $class,
                    'share_quantity' => bcadd($quantity, '0', 4),
                    'voting_weight' => $weight,
                    'entitlement_reference' => $evidence,
                    'proxy_authorized' => (bool) ($entry['proxy_authorized'] ?? false),
                ];
            }
            $found = DB::table('shareholders')->where('tenant_id', $tenantId)->whereIn('id', array_keys($holderIds))->count();
            if ($found !== count($holderIds)) {
                throw ValidationException::withMessages(['entries' => 'One or more shareholders are unavailable in this tenant.']);
            }
            usort($normalised, static fn (array $a, array $b): int => [$a['shareholder_id'], $a['share_class_code']] <=> [$b['shareholder_id'], $b['share_class_code']]);

            // Do not freeze caller-supplied quantities or proxy flags merely
            // because their arithmetic is internally consistent. Rebuild the
            // register under the same transaction and require exact equality
            // with the authoritative immutable ledger.
            $reconstructed = $this->reconstructAsOf($tenantId, $recordDate, $sourceReference);
            usort($reconstructed, static fn (array $a, array $b): int => [$a['shareholder_id'], $a['share_class_code']] <=> [$b['shareholder_id'], $b['share_class_code']]);
            $expectedCanonical = json_encode($reconstructed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $submittedCanonical = json_encode($normalised, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!hash_equals(hash('sha256', $expectedCanonical), hash('sha256', $submittedCanonical))) {
                throw ValidationException::withMessages([
                    'entries' => 'The proposed voter register does not exactly match the authoritative record-date share ledger reconstruction.',
                ]);
            }

            $canonical = $submittedCanonical;
            $now = Carbon::now();
            $snapshotId = DB::table('governance_election_register_snapshots')->insertGetId([
                'tenant_id' => $tenantId, 'election_id' => $election->id, 'record_date' => $recordDate,
                'source_reference' => $sourceReference, 'rule_version' => $ruleVersion,
                'content_sha256' => hash('sha256', $canonical), 'voter_count' => count($normalised),
                'created_by' => $actorId, 'frozen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $rows = array_map(static fn (array $entry): array => [
                'tenant_id' => $tenantId, 'election_id' => $election->id, 'snapshot_id' => $snapshotId,
                ...$entry, 'created_at' => $now, 'updated_at' => $now,
            ], $normalised);
            DB::table('governance_election_voters')->insert($rows);
            $election->forceFill(['record_date' => $recordDate, 'voting_rule_version' => $ruleVersion])->save();
            return $snapshotId;
        });
    }

    /**
     * Validate the append-order balance chain and calculate a historical
     * balance using business-time occurred_at. This distinction is required
     * for valid backdated ledger corrections.
     *
     * @param iterable<object> $ledgerEntries Ordered by immutable entry id.
     */
    public static function reconstructHistoricalHoldingBalance(
        iterable $ledgerEntries,
        string $recordDate,
        string $currentProjection,
    ): string {
        self::assertDecimalArithmetic();
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $recordDate)) {
            throw ValidationException::withMessages(['record_date' => 'The register record date must use YYYY-MM-DD.']);
        }
        $cutoffDate = Carbon::createFromFormat('!Y-m-d', $recordDate);
        if ($cutoffDate === false || $cutoffDate->format('Y-m-d') !== $recordDate) {
            throw ValidationException::withMessages(['record_date' => 'The register record date is not a valid calendar date.']);
        }
        $cutoff = $cutoffDate->endOfDay();
        $entries = is_array($ledgerEntries) ? array_values($ledgerEntries) : array_values(iterator_to_array($ledgerEntries));
        if ($entries === []) {
            throw ValidationException::withMessages(['share_ledger' => 'A complete ledger history is required.']);
        }

        // The append-only chain is ordered by immutable insertion id. Business
        // time is a separate axis used only for the record-date reconstruction.
        usort($entries, static fn (object $a, object $b): int => (int) $a->id <=> (int) $b->id);
        $byId = [];
        foreach ($entries as $entry) {
            $id = (int) $entry->id;
            if ($id < 1 || isset($byId[$id])) {
                throw ValidationException::withMessages(['share_ledger' => 'Ledger entry identifiers must be positive and unique.']);
            }
            $byId[$id] = $entry;
        }

        if (!preg_match('/^-?\\d+(?:\\.\\d{1,4})?$/', $currentProjection)) {
            throw ValidationException::withMessages(['share_ledger' => 'The current holding projection is not a valid decimal quantity.']);
        }

        $balance = '0.0000';
        $eligibleEntries = [];
        $reversed = [];
        $allowedTypes = ['subscription', 'transfer_in', 'transfer_out', 'allocation', 'adjustment', 'reversal'];
        foreach ($entries as $entry) {
            $id = (int) $entry->id;
            $type = (string) $entry->entry_type;
            $quantity = (string) $entry->quantity;
            if (!in_array($type, $allowedTypes, true)
                || !preg_match('/^-?\\d+(?:\\.\\d{1,4})?$/', $quantity)
                || !preg_match('/^-?\\d+(?:\\.\\d{1,4})?$/', (string) $entry->balance_before)
                || !preg_match('/^-?\\d+(?:\\.\\d{1,4})?$/', (string) $entry->balance_after)) {
                throw ValidationException::withMessages(['share_ledger' => "Ledger entry {$id} contains an invalid type or decimal value."]);
            }
            if ((in_array($type, ['subscription', 'transfer_in', 'allocation'], true) && bccomp($quantity, '0', 4) <= 0)
                || ($type === 'transfer_out' && bccomp($quantity, '0', 4) >= 0)) {
                throw ValidationException::withMessages(['share_ledger' => "Ledger entry {$id} has a quantity sign inconsistent with its entry type."]);
            }
            if (bccomp((string) $entry->balance_before, $balance, 4) !== 0) {
                throw ValidationException::withMessages(['share_ledger' => "Ledger append-order chain is inconsistent at entry {$id}."]);
            }
            $expected = bcadd($balance, $quantity, 4);
            if (bccomp($expected, (string) $entry->balance_after, 4) !== 0 || bccomp($expected, '0', 4) < 0) {
                throw ValidationException::withMessages(['share_ledger' => "Ledger balance invariant failed at entry {$id}."]);
            }

            $reversalId = $entry->reversed_entry_id ?? null;
            if ($type === 'reversal') {
                $originalId = (int) ($reversalId ?? 0);
                $original = $byId[$originalId] ?? null;
                if ($original === null || (int) $original->id >= $id
                    || (string) $original->entry_type === 'reversal'
                    || bccomp(bcadd($quantity, (string) $original->quantity, 4), '0', 4) !== 0
                    || Carbon::parse($entry->occurred_at)->lessThan(Carbon::parse($original->occurred_at))
                    || isset($reversed[$originalId])) {
                    throw ValidationException::withMessages(['share_ledger' => "Reversal linkage or business chronology is invalid at entry {$id}."]);
                }
                $reversed[$originalId] = true;
            } elseif ($reversalId !== null) {
                throw ValidationException::withMessages(['share_ledger' => "Non-reversal entry {$id} has a reversal reference."]);
            }

            if (Carbon::parse($entry->occurred_at)->lessThanOrEqualTo($cutoff)) {
                $eligibleEntries[] = $entry;
            }
            $balance = $expected;
        }

        // Validate the record-date running balance in business-time order,
        // independently from append-order chain validation above.
        usort($eligibleEntries, static fn (object $a, object $b): int =>
            [Carbon::parse($a->occurred_at)->getTimestamp(), (int) $a->id]
            <=> [Carbon::parse($b->occurred_at)->getTimestamp(), (int) $b->id]
        );
        $historicalBalance = '0.0000';
        foreach ($eligibleEntries as $entry) {
            $historicalBalance = bcadd($historicalBalance, (string) $entry->quantity, 4);
            if (bccomp($historicalBalance, '0', 4) < 0) {
                throw ValidationException::withMessages([
                    'share_ledger' => "Historical balance becomes negative at entry " . (int) $entry->id . ".",
                ]);
            }
        }

        if (bccomp($balance, $currentProjection, 4) !== 0) {
            throw ValidationException::withMessages(['share_ledger' => 'The current share-holding projection does not reconcile to the complete ledger.']);
        }

        return $historicalBalance;
    }

    private static function assertDecimalArithmetic(): void
    {
        foreach (['bcadd', 'bcmul', 'bccomp'] as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException('BCMath is required for precise share-voting calculations.');
            }
        }
    }
}
