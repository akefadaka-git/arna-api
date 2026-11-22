<?php
declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Security\AgeRegisterAttestation;
use MaaleArna\MASES\Core\Domains\Governance\Services\Receipts\AgeReceiptOutbox;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use RuntimeException;

/**
 * Reconstructs an election register from the immutable shareholding ledger.
 * Class rights must be explicitly verified in governance.election_voting.
 */
final class GovernanceElectionRegisterService
{
    public static function defaultVotingWeight(string|int $shareQuantity, string|int $votesPerShare = 1): string
    {
        self::assertDecimalArithmetic();
        $quantity = (string) $shareQuantity;
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
        $rates = (array) config('governance.election_voting.verified_class_entitlements', []);
        $cutoff = Carbon::parse($recordDate)->endOfDay();
        $holdings = DB::table('share_holdings as h')
            ->join('shareholders as s', 's.id', '=', 'h.shareholder_id')
            ->join('share_classes as c', 'c.id', '=', 'h.share_class_id')
            ->where('s.tenant_id', $tenantId)
            ->whereNull('s.deleted_at')
            ->whereExists(function ($query) use ($cutoff): void {
                $query->selectRaw('1')->from('share_holding_entries as e')
                    ->whereColumn('e.share_holding_id', 'h.id')
                    ->where('e.occurred_at', '<=', $cutoff);
            })
            ->select(['h.id as holding_id', 'h.shareholder_id', 'h.current_quantity', 'c.code as class_code'])
            ->orderBy('h.shareholder_id')->orderBy('c.code')->get();

        $entries = [];
        foreach ($holdings as $holding) {
            $class = strtoupper((string) $holding->class_code);
            $classRule = $rates[$class] ?? null;
            if (!is_array($classRule) || ($classRule['verified'] ?? false) !== true
                || ($classRule['eligible'] ?? false) !== true
                || !isset($classRule['votes_per_share'], $classRule['evidence_reference'])) {
                throw ValidationException::withMessages([
                    'share_class_entitlements' => "Share class {$class} has no verified eligible voting entitlement. Register freeze is blocked.",
                ]);
            }
            // Validate the complete append-only ledger against the current-state projection,
            // while separately capturing the balance at the election record-date cutoff.
            // The immutable chain is posting order (id), not business-time order:
            // a valid backfilled entry may have an earlier occurred_at timestamp.
            $ledger = DB::table('share_holding_entries')
                ->where('share_holding_id', $holding->holding_id)
                ->orderBy('id')->get();
            if ($ledger->isEmpty()) {
                throw ValidationException::withMessages([
                    'share_ledger' => "Holding {$holding->holding_id} has no ledger history; its projection cannot be trusted.",
                ]);
            }
            $balance = '0.0000';
            foreach ($ledger as $entry) {
                if (bccomp((string) $entry->balance_before, $balance, 4) !== 0) {
                    throw ValidationException::withMessages(['share_ledger' => "Ledger chain is inconsistent for holding {$holding->holding_id}."]);
                }
                $expected = bcadd($balance, (string) $entry->quantity, 4);
                if (bccomp($expected, (string) $entry->balance_after, 4) !== 0 || bccomp($expected, '0', 4) < 0) {
                    throw ValidationException::withMessages(['share_ledger' => "Ledger balance invariant failed for holding {$holding->holding_id}."]);
                }
                $balance = $expected;
            }
            if (bccomp($balance, (string) $holding->current_quantity, 4) !== 0) {
                throw ValidationException::withMessages([
                    'share_projection' => "Current holding projection disagrees with its authoritative ledger for holding {$holding->holding_id}.",
                ]);
            }

            // Reconstruct effective entitlement by business time separately from
            // the posting-order integrity chain; this supports authorized backfills.
            $recordDateBalance = '0.0000';
            $recordDateEntries = $ledger->filter(
                static fn ($entry): bool => Carbon::parse($entry->occurred_at)->lte($cutoff)
            )->sortBy([
                ['occurred_at', 'asc'],
                ['id', 'asc'],
            ]);
            foreach ($recordDateEntries as $entry) {
                $recordDateBalance = bcadd($recordDateBalance, (string) $entry->quantity, 4);
                if (bccomp($recordDateBalance, '0', 4) < 0) {
                    throw ValidationException::withMessages([
                        'share_ledger' => "Record-date balance becomes negative for holding {$holding->holding_id}.",
                    ]);
                }
            }
            $balance = $recordDateBalance;
            if (bccomp($balance, '0', 4) > 0) {
                $rate = (string) $classRule['votes_per_share'];
                $entries[] = [
                    'shareholder_id' => (int) $holding->shareholder_id,
                    'share_class_code' => $class,
                    'share_quantity' => $balance,
                    'voting_weight' => self::defaultVotingWeight($balance, $rate),
                    'entitlement_reference' => $evidenceReference . ';class:' . (string) $classRule['evidence_reference'],
                    'proxy_authorized' => false,
                ];
            }
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
    public function prepare(int $tenantId, int $electionId, string $recordDate, string $sourceReference, string $ruleVersion, int $actorId, array $entries): int
    {
        if ($entries === []) {
            throw ValidationException::withMessages(['entries' => 'The approved voter register cannot be empty.']);
        }
        self::assertDecimalArithmetic();
        return DB::transaction(function () use ($tenantId, $electionId, $recordDate, $sourceReference, $ruleVersion, $actorId, $entries): int {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            if (!$election->isAuthorityVerified() || !in_array($election->status, ['authorized', 'scheduled', 'nominating'], true)) {
                throw new RuntimeException('Verified authority and planned status are required before register freeze.');
            }
            if ($election->record_date !== null && $election->record_date->toDateString() !== $recordDate) {
                throw new RuntimeException('The supplied record date does not match the approved record date.');
            }
            if (trim($sourceReference) === '' || trim($ruleVersion) === '' || $actorId < 1) {
                throw ValidationException::withMessages(['source_reference' => 'Evidence, rule version and actor are required.']);
            }
            if (DB::table('governance_election_register_snapshots')->where('tenant_id', $tenantId)->where('election_id', $electionId)->exists()) {
                throw new RuntimeException('A prepared register already exists; corrections require a separately versioned superseding snapshot.');
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
            $found = Shareholder::query()->where('tenant_id', $tenantId)->whereIn('id', array_keys($holderIds))->count();
            if ($found !== count($holderIds)) {
                throw ValidationException::withMessages(['entries' => 'One or more shareholders are unavailable in this tenant.']);
            }
            usort($normalised, static fn (array $a, array $b): int => [$a['shareholder_id'], $a['share_class_code']] <=> [$b['shareholder_id'], $b['share_class_code']]);
            $contentHash = self::snapshotContentHash($tenantId, $electionId, $recordDate, $sourceReference, $ruleVersion, $normalised);
            $now = Carbon::now();
            $snapshotUuid = (string) Str::uuid();
            $preparedPayload = [
                'stage' => 'prepared',
                'snapshot_uuid' => $snapshotUuid,
                'tenant_id' => $tenantId,
                'election_id' => (int) $election->id,
                'record_date' => $recordDate,
                'source_reference' => $sourceReference,
                'rule_version' => $ruleVersion,
                'content_sha256' => $contentHash,
                'voter_count' => count($normalised),
                'created_by' => $actorId,
                'prepared_by' => $actorId,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ];
            $preparedAttestation = AgeRegisterAttestation::signActive($preparedPayload);
            $snapshotId = DB::table('governance_election_register_snapshots')->insertGetId([
                'tenant_id' => $tenantId, 'election_id' => $election->id, 'record_date' => $recordDate,
                'source_reference' => $sourceReference, 'rule_version' => $ruleVersion,
                'content_sha256' => $contentHash, 'snapshot_uuid' => $snapshotUuid,
                'prepared_attestation_sha256' => $preparedAttestation['signature'],
                'prepared_attestation_key_id' => $preparedAttestation['key_id'],
                'voter_count' => count($normalised),
                'created_by' => $actorId, 'prepared_by' => $actorId, 'register_status' => 'draft', 'frozen_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $rows = array_map(static fn (array $entry): array => [
                'tenant_id' => $tenantId, 'election_id' => $election->id, 'snapshot_id' => $snapshotId,
                ...$entry, 'created_at' => $now, 'updated_at' => $now,
            ], $normalised);
            DB::table('governance_election_voters')->insert($rows);
            $election->forceFill(['record_date' => $recordDate, 'voting_rule_version' => $ruleVersion])->save();
            AgeReceiptOutbox::enqueue($tenantId, $electionId, $snapshotId, 'register.prepared', $actorId);
            return $snapshotId;
        });
    }

    /** Independently reconcile the prepared snapshot and record the reconciler. */
    public function reconcile(int $tenantId, int $electionId, int $snapshotId, int $actorId): string
    {
        return DB::transaction(function () use ($tenantId, $electionId, $snapshotId, $actorId): string {
            // Lock the parent election first, then the snapshot and its voter rows.
            // This gives register writers a consistent lock order on MySQL/InnoDB.
            GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            $snapshot = DB::table('governance_election_register_snapshots')
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->lockForUpdate()->find($snapshotId);
            if ($snapshot === null || $snapshot->register_status !== 'draft' || $actorId < 1) {
                throw new RuntimeException('A draft register and authenticated reconciler are required.');
            }
            if ((int) $snapshot->prepared_by === $actorId) {
                throw new RuntimeException('The register preparer cannot independently reconcile the register.');
            }
            if (!AgeRegisterAttestation::verify(
                $snapshot->prepared_attestation_key_id === null ? null : (string) $snapshot->prepared_attestation_key_id,
                $snapshot->prepared_attestation_sha256 === null ? null : (string) $snapshot->prepared_attestation_sha256,
                self::preparedAttestationPayload($snapshot),
            )) {
                throw new RuntimeException('Prepared register attestation is missing or invalid; reconciliation failed closed.');
            }
            $rows = DB::table('governance_election_voters')->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)->where('snapshot_id', $snapshotId)
                ->orderBy('shareholder_id')->orderBy('share_class_code')
                ->lockForUpdate()
                ->get(['shareholder_id','share_class_code','share_quantity','voting_weight','entitlement_reference','proxy_authorized'])
                ->map(static fn ($row): array => [
                    'shareholder_id' => (int) $row->shareholder_id,
                    'share_class_code' => (string) $row->share_class_code,
                    'share_quantity' => (string) $row->share_quantity,
                    'voting_weight' => (string) $row->voting_weight,
                    'entitlement_reference' => (string) $row->entitlement_reference,
                    'proxy_authorized' => (bool) $row->proxy_authorized,
                ])->all();
            if (count($rows) !== (int) $snapshot->voter_count || $rows === []) {
                throw new RuntimeException('Register row count does not match the prepared snapshot.');
            }
            $hash = self::snapshotContentHash(
                (int) $snapshot->tenant_id,
                (int) $snapshot->election_id,
                (string) $snapshot->record_date,
                (string) $snapshot->source_reference,
                (string) $snapshot->rule_version,
                $rows,
            );
            if (!hash_equals((string) $snapshot->content_sha256, $hash)) {
                throw new RuntimeException('Register content hash mismatch; reconciliation failed closed.');
            }
            $reconciledAt = Carbon::now();
            $reconciledAtValue = $reconciledAt->format('Y-m-d H:i:s');
            $reconciliationPayload = [
                'stage' => 'reconciled',
                'snapshot_uuid' => (string) $snapshot->snapshot_uuid,
                'tenant_id' => (int) $snapshot->tenant_id,
                'election_id' => (int) $snapshot->election_id,
                'content_sha256' => (string) $snapshot->content_sha256,
                'prepared_attestation_sha256' => (string) $snapshot->prepared_attestation_sha256,
                'reconciled_by' => $actorId,
                'reconciled_at' => $reconciledAtValue,
                'integrity_verified_at' => $reconciledAtValue,
            ];
            $reconciliationAttestation = AgeRegisterAttestation::signActive($reconciliationPayload);
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->update([
                'register_status' => 'reconciled', 'reconciled_by' => $actorId,
                'reconciled_at' => $reconciledAtValue, 'integrity_verified_at' => $reconciledAtValue,
                'reconciliation_attestation_sha256' => $reconciliationAttestation['signature'],
                'reconciliation_attestation_key_id' => $reconciliationAttestation['key_id'],
                'updated_at' => $reconciledAtValue,
            ]);
            AgeReceiptOutbox::enqueue($tenantId, $electionId, $snapshotId, 'register.reconciled', $actorId);
            return $hash;
        });
    }

    /** Recheck stored rows at the point of approval and whenever the poll gate is evaluated. */
    public function hasValidSnapshotIntegrity(int $tenantId, int $electionId, int $snapshotId, bool $lockRows = false): bool
    {
        $snapshot = DB::table('governance_election_register_snapshots')
            ->where('tenant_id', $tenantId)->where('election_id', $electionId)
            ->where('id', $snapshotId)->first();
        if ($snapshot === null || !AgeRegisterAttestation::verify(
            $snapshot->prepared_attestation_key_id === null ? null : (string) $snapshot->prepared_attestation_key_id,
            $snapshot->prepared_attestation_sha256 === null ? null : (string) $snapshot->prepared_attestation_sha256,
            self::preparedAttestationPayload($snapshot),
        )) {
            return false;
        }
        $voterQuery = DB::table('governance_election_voters')->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)->where('snapshot_id', $snapshotId)
            ->orderBy('shareholder_id')->orderBy('share_class_code');
        if ($lockRows) {
            $voterQuery->lockForUpdate();
        }
        $rows = $voterQuery->get(['shareholder_id','share_class_code','share_quantity','voting_weight','entitlement_reference','proxy_authorized'])
            ->map(static fn ($row): array => [
                'shareholder_id' => (int) $row->shareholder_id,
                'share_class_code' => (string) $row->share_class_code,
                'share_quantity' => (string) $row->share_quantity,
                'voting_weight' => (string) $row->voting_weight,
                'entitlement_reference' => (string) $row->entitlement_reference,
                'proxy_authorized' => (bool) $row->proxy_authorized,
            ])->all();
        if (count($rows) !== (int) $snapshot->voter_count || $rows === []) {
            return false;
        }
        $hash = self::snapshotContentHash(
            (int) $snapshot->tenant_id,
            (int) $snapshot->election_id,
            (string) $snapshot->record_date,
            (string) $snapshot->source_reference,
            (string) $snapshot->rule_version,
            $rows,
        );
        if (!hash_equals((string) $snapshot->content_sha256, $hash)) {
            return false;
        }

        if (in_array((string) $snapshot->register_status, ['reconciled', 'frozen'], true)) {
            $reconciliationPayload = [
                'stage' => 'reconciled',
                'snapshot_uuid' => (string) $snapshot->snapshot_uuid,
                'tenant_id' => (int) $snapshot->tenant_id,
                'election_id' => (int) $snapshot->election_id,
                'content_sha256' => (string) $snapshot->content_sha256,
                'prepared_attestation_sha256' => (string) $snapshot->prepared_attestation_sha256,
                'reconciled_by' => (int) $snapshot->reconciled_by,
                'reconciled_at' => (string) $snapshot->reconciled_at,
                'integrity_verified_at' => (string) $snapshot->integrity_verified_at,
            ];
            if (!AgeRegisterAttestation::verify(
                $snapshot->reconciliation_attestation_key_id === null ? null : (string) $snapshot->reconciliation_attestation_key_id,
                $snapshot->reconciliation_attestation_sha256 === null ? null : (string) $snapshot->reconciliation_attestation_sha256,
                $reconciliationPayload,
            )) {
                return false;
            }
        }

        if ((string) $snapshot->register_status === 'frozen') {
            $approvalPayload = [
                'stage' => 'approved_frozen',
                'snapshot_uuid' => (string) $snapshot->snapshot_uuid,
                'tenant_id' => (int) $snapshot->tenant_id,
                'election_id' => (int) $snapshot->election_id,
                'content_sha256' => (string) $snapshot->content_sha256,
                'reconciliation_attestation_sha256' => (string) $snapshot->reconciliation_attestation_sha256,
                'approved_by' => (int) $snapshot->approved_by,
                'approved_at' => (string) $snapshot->approved_at,
                'frozen_at' => (string) $snapshot->frozen_at,
            ];
            if (!AgeRegisterAttestation::verify(
                $snapshot->approval_attestation_key_id === null ? null : (string) $snapshot->approval_attestation_key_id,
                $snapshot->approval_attestation_sha256 === null ? null : (string) $snapshot->approval_attestation_sha256,
                $approvalPayload,
            )) {
                return false;
            }
        }

        return true;
    }

    /** Approve and freeze only after independent reconciliation and a second actor's approval. */
    public function approveAndFreeze(int $tenantId, int $electionId, int $snapshotId, int $actorId): void
    {
        DB::transaction(function () use ($tenantId, $electionId, $snapshotId, $actorId): void {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($electionId);
            $snapshot = DB::table('governance_election_register_snapshots')
                ->where('tenant_id', $tenantId)->where('election_id', $electionId)
                ->lockForUpdate()->find($snapshotId);
            if ($snapshot === null || $snapshot->register_status !== 'reconciled'
                || $snapshot->integrity_verified_at === null || $snapshot->reconciled_at === null) {
                throw new RuntimeException('An integrity-verified reconciled register is required before approval.');
            }
            // Approval re-reads and locks every voter row in the same transaction
            // that commits the frozen state, preventing concurrent row mutation
            // from slipping between digest verification and freeze.
            if (!$this->hasValidSnapshotIntegrity($tenantId, $electionId, $snapshotId, true)) {
                throw new RuntimeException('Register content changed after reconciliation; approval is blocked.');
            }
            if ($actorId < 1 || $actorId === (int) $snapshot->prepared_by || $actorId === (int) $snapshot->reconciled_by) {
                throw new RuntimeException('The approver must be independent of both preparation and reconciliation.');
            }
            if (!$election->isAuthorityVerified() || !in_array($election->status, ['planned', 'nominating'], true)) {
                throw new RuntimeException('Verified authority and a pre-voting election state are required.');
            }
            $approvedAt = Carbon::now()->format('Y-m-d H:i:s');
            $approvalPayload = [
                'stage' => 'approved_frozen',
                'snapshot_uuid' => (string) $snapshot->snapshot_uuid,
                'tenant_id' => (int) $snapshot->tenant_id,
                'election_id' => (int) $snapshot->election_id,
                'content_sha256' => (string) $snapshot->content_sha256,
                'reconciliation_attestation_sha256' => (string) $snapshot->reconciliation_attestation_sha256,
                'approved_by' => $actorId,
                'approved_at' => $approvedAt,
                'frozen_at' => $approvedAt,
            ];
            $approvalAttestation = AgeRegisterAttestation::signActive($approvalPayload);
            DB::table('governance_election_register_snapshots')->where('id', $snapshotId)->update([
                'register_status' => 'frozen', 'approved_by' => $actorId,
                'approved_at' => $approvedAt, 'frozen_at' => $approvedAt,
                'approval_attestation_sha256' => $approvalAttestation['signature'],
                'approval_attestation_key_id' => $approvalAttestation['key_id'],
                'updated_at' => $approvedAt,
            ]);
            AgeReceiptOutbox::enqueue($tenantId, $electionId, $snapshotId, 'register.approved_frozen', $actorId);
        });
    }

    /**
     * Bind both voter rows and the register's governing metadata into one
     * canonical digest so record-date, evidence-reference or rule-version
     * edits are detected as integrity failures too.
     *
     * @param array<int,array{shareholder_id:int,share_class_code:string,share_quantity:string,voting_weight:string,entitlement_reference:string,proxy_authorized:bool}> $rows
     */
    private static function snapshotContentHash(
        int $tenantId,
        int $electionId,
        string $recordDate,
        string $sourceReference,
        string $ruleVersion,
        array $rows,
    ): string {
        // Normalize database DECIMAL values before hashing. SQLite may return
        // "10" while MySQL returns "10.0000" for the same DECIMAL(20,4) value.
        // Canonical four-place strings keep preparation and later verification
        // byte-identical across supported database drivers.
        $canonicalRows = array_map(static function (array $row): array {
            $row['shareholder_id'] = (int) $row['shareholder_id'];
            $row['share_class_code'] = (string) $row['share_class_code'];
            $row['share_quantity'] = bcadd((string) $row['share_quantity'], '0', 4);
            $row['voting_weight'] = bcadd((string) $row['voting_weight'], '0', 4);
            $row['entitlement_reference'] = (string) $row['entitlement_reference'];
            $row['proxy_authorized'] = (bool) $row['proxy_authorized'];

            return $row;
        }, $rows);

        $canonical = json_encode([
            'tenant_id' => $tenantId,
            'election_id' => $electionId,
            'record_date' => $recordDate,
            'source_reference' => $sourceReference,
            'rule_version' => $ruleVersion,
            'voter_count' => count($canonicalRows),
            'voters' => $canonicalRows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $canonical);
    }

    /**
     * @return array<string,mixed>
     */
    private static function preparedAttestationPayload(object $snapshot): array
    {
        return [
            'stage' => 'prepared',
            'snapshot_uuid' => (string) $snapshot->snapshot_uuid,
            'tenant_id' => (int) $snapshot->tenant_id,
            'election_id' => (int) $snapshot->election_id,
            'record_date' => substr((string) $snapshot->record_date, 0, 10),
            'source_reference' => (string) $snapshot->source_reference,
            'rule_version' => (string) $snapshot->rule_version,
            'content_sha256' => (string) $snapshot->content_sha256,
            'voter_count' => (int) $snapshot->voter_count,
            'created_by' => (int) $snapshot->created_by,
            'prepared_by' => (int) $snapshot->prepared_by,
            'created_at' => Carbon::parse($snapshot->created_at)->format('Y-m-d H:i:s'),
        ];
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
