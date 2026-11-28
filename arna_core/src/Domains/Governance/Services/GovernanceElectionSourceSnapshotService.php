<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Governance\Services;

use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\Arna\Core\Audit\Services\AuditService;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Authorization\Services\AuthorizationService;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceElectionSourceSnapshot;
use RuntimeException;

final class GovernanceElectionSourceSnapshotService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
        private readonly GovernanceElectionShareholderSourceService $shareholderSource,
    ) {
    }

    public function list(int $tenantId, int $electionId, User $actor): array
    {
        $this->authorize($actor, $tenantId, 'governance.election.register.view');
        $this->election($tenantId, $electionId);

        return GovernanceElectionSourceSnapshot::query()
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)
            ->with('reconciliations')
            ->orderByDesc('version')
            ->get()
            ->all();
    }

    public function get(int $tenantId, int $electionId, int $snapshotId, User $actor): array
    {
        $this->authorize($actor, $tenantId, 'governance.election.register.view');
        $snapshot = $this->snapshot($tenantId, $electionId, $snapshotId);

        return [
            'snapshot' => $snapshot,
            'holders' => $snapshot->holders()->orderBy('shareholder_id')->get(),
            'holdings' => $snapshot->holdings()->orderBy('shareholder_id')->orderBy('share_class_code')->get(),
            'source_entries' => $snapshot->sourceEntries()->orderBy('source_holding_id')->orderBy('source_entry_id')->get(),
            'reconciliations' => $snapshot->reconciliations()->orderBy('id')->get(),
        ];
    }

    public function capture(
        int $tenantId,
        int $electionId,
        string $recordDateTime,
        User $actor,
    ): array {
        $this->authorize($actor, $tenantId, 'governance.election.register.prepare');
        $cutoff = GovernanceElectionShareholderSourceService::parseRecordDateTime($recordDateTime);

        return DB::transaction(function () use ($tenantId, $electionId, $recordDateTime, $cutoff, $actor): array {
            $election = $this->election($tenantId, $electionId, true);
            if (!$election->isAuthorityVerified() || !in_array($election->status, ['authorized', 'scheduled'], true)) {
                throw ValidationException::withMessages([
                    'election' => 'A tenant-scoped election with independently verified authority must be authorized or scheduled before source capture.',
                ]);
            }

            $preview = $this->shareholderSource->previewTenant($tenantId, $recordDateTime);
            if ((int) $preview['shareholder_count'] < 1) {
                throw ValidationException::withMessages([
                    'shareholders' => 'The source preview contains no shareholder records; an empty election source snapshot is not accepted.',
                ]);
            }

            $version = ((int) DB::table('governance_election_source_snapshots')
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->lockForUpdate()
                ->max('version')) + 1;

            $manifestRows = [];
            $ledgerEntryCount = 0;
            foreach ($preview['shareholders'] as $holder) {
                foreach ($holder['holdings'] as $holding) {
                    foreach ($holding['ledger_manifest'] as $entry) {
                        $manifestRows[] = [
                            'holding_id' => (int) $holding['holding_id'],
                            'entry_id' => (int) $entry['entry_id'],
                            'entry_digest' => (string) $entry['entry_digest'],
                            'included_by_business_time' => (bool) $entry['included_by_business_time'],
                        ];
                        $ledgerEntryCount++;
                    }
                }
            }
            usort($manifestRows, static fn (array $a, array $b): int => [$a['holding_id'], $a['entry_id']] <=> [$b['holding_id'], $b['entry_id']]);
            $manifestDigest = hash('sha256', json_encode($manifestRows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $capturedAt = Carbon::parse($preview['captured_at_utc'], 'UTC');
            $snapshotId = DB::table('governance_election_source_snapshots')->insertGetId([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'version' => $version,
                'source_schema_version' => '1',
                'record_date_time_utc' => $cutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'record_date_timezone' => $cutoff->format('P'),
                'database_timezone' => (string) $preview['database_timezone'],
                'captured_at_utc' => $capturedAt->format('Y-m-d H:i:s.u'),
                'source_digest' => (string) $preview['source_digest'],
                'manifest_digest' => $manifestDigest,
                'shareholder_count' => (int) $preview['shareholder_count'],
                'holding_count' => (int) $preview['holding_count'],
                'ledger_entry_count' => $ledgerEntryCount,
                'snapshot_state' => 'captured',
                'electorate_state' => 'blocked_unverified',
                'prepared_by' => (int) $actor->getAuthIdentifier(),
                'prepared_at_utc' => Carbon::now('UTC')->format('Y-m-d H:i:s.u'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($preview['shareholders'] as $holder) {
                $holderIdentity = $holder;
                unset($holderIdentity['holdings']);
                $holderDigest = hash('sha256', json_encode($holderIdentity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                DB::table('governance_election_source_snapshot_holders')->insert([
                    'tenant_id' => $tenantId,
                    'election_id' => $electionId,
                    'snapshot_id' => $snapshotId,
                    'shareholder_id' => (int) $holder['shareholder_id'],
                    'shareholder_number' => $holder['shareholder_number'],
                    'shareholder_type' => (string) $holder['shareholder_type'],
                    'person_id' => $holder['person_id'],
                    'direct_user_id' => $holder['direct_user_id'],
                    'representative_user_id' => $holder['representative_user_id'],
                    'current_status' => (string) $holder['current_status'],
                    'identity_temporal_state' => (string) $holder['identity_temporal_state'],
                    'holder_digest' => $holderDigest,
                    'holding_count' => count($holder['holdings']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($holder['holdings'] as $holding) {
                    DB::table('governance_election_source_snapshot_holdings')->insert([
                        'tenant_id' => $tenantId,
                        'election_id' => $electionId,
                        'snapshot_id' => $snapshotId,
                        'shareholder_id' => (int) $holder['shareholder_id'],
                        'source_holding_id' => (int) $holding['holding_id'],
                        'share_class_id' => (int) $holding['share_class_id'],
                        'share_class_code' => (string) $holding['share_class_code'],
                        'quantity_at_cutoff' => (string) $holding['quantity_at_cutoff'],
                        'current_projection' => (string) $holding['current_projection'],
                        'ledger_entry_count' => (int) $holding['entry_count'],
                        'ledger_digest' => (string) $holding['ledger_digest'],
                        'source_state' => (string) $holding['source_state'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    foreach ($holding['ledger_manifest'] as $entry) {
                        DB::table('governance_election_source_snapshot_entries')->insert([
                            'tenant_id' => $tenantId,
                            'election_id' => $electionId,
                            'snapshot_id' => $snapshotId,
                            'source_holding_id' => (int) $holding['holding_id'],
                            'source_entry_id' => (int) $entry['entry_id'],
                            'entry_digest' => (string) $entry['entry_digest'],
                            'included_by_business_time' => (bool) $entry['included_by_business_time'],
                            'created_at' => now(),
                        ]);
                    }
                }
            }

            $this->auditOrFail('governance.election.shareholder_source_snapshot.captured', $tenantId, $actor, $election, [
                'snapshot_id' => $snapshotId,
                'version' => $version,
                'source_digest' => $preview['source_digest'],
                'manifest_digest' => $manifestDigest,
                'shareholder_count' => $preview['shareholder_count'],
                'holding_count' => $preview['holding_count'],
                'ledger_entry_count' => $ledgerEntryCount,
                'electorate_state' => 'blocked_unverified',
            ]);

            return GovernanceElectionSourceSnapshot::query()
                ->where('tenant_id', $tenantId)
                ->where('election_id', $electionId)
                ->findOrFail($snapshotId)
                ->toArray();
        }, 3);
    }

    public function reconcile(
        int $tenantId,
        int $electionId,
        int $snapshotId,
        string $reason,
        User $actor,
    ): array {
        $this->authorize($actor, $tenantId, 'governance.election.register.reconcile');
        if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages([
                'reason' => 'A reconciliation reason of at least 10 characters is required.',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $electionId, $snapshotId, $reason, $actor): array {
            $election = $this->election($tenantId, $electionId, true);
            $snapshot = $this->snapshot($tenantId, $electionId, $snapshotId, true);
            if ((int) $snapshot->prepared_by === (int) $actor->getAuthIdentifier()) {
                throw ValidationException::withMessages([
                    'reconciler' => 'The source snapshot preparer cannot reconcile their own snapshot.',
                ]);
            }

            $recordDateTime = Carbon::parse($snapshot->record_date_time_utc, 'UTC')->format('Y-m-d\TH:i:s.u\Z');
            $observed = $this->shareholderSource->previewTenant(
                $tenantId,
                $recordDateTime,
                (string) $snapshot->database_timezone,
            );
            $matched = hash_equals((string) $snapshot->source_digest, (string) $observed['source_digest']);
            $result = $matched ? 'matched' : 'changed';
            $reconciledAt = Carbon::now('UTC');

            $reconciliationId = DB::table('governance_election_source_snapshot_reconciliations')->insertGetId([
                'tenant_id' => $tenantId,
                'election_id' => $electionId,
                'snapshot_id' => $snapshotId,
                'observed_source_digest' => (string) $observed['source_digest'],
                'result' => $result,
                'reason' => trim($reason),
                'reconciled_by' => (int) $actor->getAuthIdentifier(),
                'reconciled_at_utc' => $reconciledAt->format('Y-m-d H:i:s.u'),
                'created_at' => now(),
            ]);

            $this->auditOrFail('governance.election.shareholder_source_snapshot.reconciled', $tenantId, $actor, $election, [
                'snapshot_id' => $snapshotId,
                'reconciliation_id' => $reconciliationId,
                'result' => $result,
                'source_digest' => $observed['source_digest'],
                'reason' => trim($reason),
            ]);

            return [
                'snapshot_id' => $snapshotId,
                'reconciliation_id' => $reconciliationId,
                'result' => $result,
                'observed_source_digest' => $observed['source_digest'],
                'electorate_state' => 'blocked_unverified',
                'message' => $matched
                    ? 'The current technical Shareholder source matches the captured source snapshot. This does not verify statutory ownership or voting entitlement.'
                    : 'The current technical Shareholder source differs from the captured snapshot. The original snapshot remains immutable; prepare a new version after investigating the change.',
            ];
        }, 3);
    }

    private function snapshot(int $tenantId, int $electionId, int $snapshotId, bool $lock = false): GovernanceElectionSourceSnapshot
    {
        $query = GovernanceElectionSourceSnapshot::query()
            ->where('tenant_id', $tenantId)
            ->where('election_id', $electionId);
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->findOrFail($snapshotId);
    }

    private function election(int $tenantId, int $electionId, bool $lock = false): GovernanceElection
    {
        $query = GovernanceElection::query()->forTenant($tenantId);
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->findOrFail($electionId);
    }

    private function authorize(User $actor, int $tenantId, string $permission): void
    {
        if ($tenantId < 1 || !$this->authorization->hasPermission(
            permissionSlug: $permission,
            user: $actor,
            tenantId: $tenantId,
        )) {
            throw new AuthorizationException('The required election register permission is not granted in this tenant.');
        }
    }

    private function auditOrFail(string $action, int $tenantId, User $actor, GovernanceElection $election, array $metadata): void
    {
        $entry = $this->audit->log(
            action: $action,
            data: $metadata,
            user: $actor,
            tenantId: $tenantId,
            resourceType: 'governance_election',
            resourceId: (string) $election->getKey(),
        );
        if ($entry === null) {
            throw new RuntimeException('Required election source snapshot audit record could not be persisted; transaction rolled back.');
        }
    }
}
