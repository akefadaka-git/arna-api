<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Immutable GA electorate snapshots and fail-closed quorum evaluation.
 *
 * ShareHolding.current_quantity is reconciled against the append-only
 * ShareHoldingEntry ledger before it is captured. Share-class voting weights
 * are never inferred: an approved quorum rule must explicitly provide them.
 */
final class GovernanceMeetingElectorateController extends Controller
{
    private function tenantId(): int
    {
        $id = (int) (function_exists('mases_tenant_id') ? mases_tenant_id() : 0);
        if ($id < 1) {
            throw new NotFoundHttpException('Tenant context is required.');
        }
        return $id;
    }

    private function actorId(): int
    {
        return (int) auth()->id();
    }

    public function createSnapshot(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'source_register_version' => ['required', 'string', 'max:120'],
            'cutoff_at' => ['required', 'date'],
        ]);
        $tenantId = $this->tenantId();
        $cutoffAt = CarbonImmutable::parse($data['cutoff_at']);
        if ($cutoffAt->greaterThan(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['cutoff_at' => 'The register cut-off cannot be in the future.']);
        }

        $snapshot = DB::transaction(function () use ($tenantId, $meeting, $data, $cutoffAt): object {
            $meetingRow = DB::table('governance_meetings')
                ->where('tenant_id', $tenantId)->where('id', $meeting)
                ->lockForUpdate()->first();
            if (!$meetingRow) {
                throw new NotFoundHttpException('Meeting was not found in this tenant.');
            }
            if ($meetingRow->meeting_kind !== 'general_assembly') {
                throw ValidationException::withMessages(['meeting' => 'This electorate snapshot builder is for General Assembly meetings only.']);
            }
            if (!in_array($meetingRow->meeting_category, ['ordinary', 'extraordinary'], true)) {
                throw ConflictHttpException('Ordinary or extraordinary General Assembly category must be recorded before creating the electorate snapshot.');
            }
            if ($cutoffAt->greaterThan(CarbonImmutable::parse($meetingRow->starts_at))) {
                throw ValidationException::withMessages(['cutoff_at' => 'The electorate cut-off cannot be later than the scheduled meeting start.']);
            }
            if (in_array($meetingRow->status, ['cancelled', 'closed'], true)) {
                throw new ConflictHttpException('A cancelled or closed meeting cannot receive a new electorate snapshot.');
            }
            $unreconciledShareholders = DB::table('shareholders')
                ->where('tenant_id', $tenantId)->where('created_at', '<=', $cutoffAt->toDateTimeString())
                ->where('updated_at', '>', $cutoffAt->toDateTimeString())->exists();
            if ($unreconciledShareholders) {
                throw new ConflictHttpException('The shareholder register changed after the requested cut-off and has no effective-dated status history. Reconcile the historical register before creating this snapshot.');
            }

            $latestVersion = (int) DB::table('governance_meeting_electorate_snapshots')
                ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->max('version');
            $ledger = DB::table('share_holding_entries')
                ->select('share_holding_id')
                ->selectRaw('COALESCE(SUM(quantity), 0) AS ledger_quantity')
                ->selectRaw('MAX(id) AS latest_entry_id')
                ->groupBy('share_holding_id');
            $cutoffLedger = DB::table('share_holding_entries')
                ->where('occurred_at', '<=', $cutoffAt->toDateTimeString())
                ->select('share_holding_id')
                ->selectRaw('COALESCE(SUM(quantity), 0) AS snapshot_quantity')
                ->selectRaw('MAX(id) AS snapshot_latest_entry_id')
                ->groupBy('share_holding_id');

            $holdingRows = DB::table('share_holdings as sh')
                ->join('shareholders as s', 's.id', '=', 'sh.shareholder_id')
                ->join('share_classes as sc', 'sc.id', '=', 'sh.share_class_id')
                ->leftJoinSub($ledger, 'sl', function ($join): void {
                    $join->on('sl.share_holding_id', '=', 'sh.id');
                })
                ->leftJoinSub($cutoffLedger, 'cl', function ($join): void {
                    $join->on('cl.share_holding_id', '=', 'sh.id');
                })
                ->where('s.tenant_id', $tenantId)
                ->where('s.created_at', '<=', $cutoffAt->toDateTimeString())
                ->where('s.updated_at', '<=', $cutoffAt->toDateTimeString())
                ->where('s.is_active', true)
                ->where(function ($query) use ($cutoffAt): void {
                    $query->whereNull('s.deleted_at')->orWhere('s.deleted_at', '>', $cutoffAt->toDateTimeString());
                })

                ->orderBy('s.id')
                ->orderBy('sc.id')
                ->get([
                    'sh.id as holding_id', 'sh.shareholder_id', 'sh.share_class_id',
                    'sh.current_quantity', 'sl.ledger_quantity', 'sl.latest_entry_id',
                    'cl.snapshot_quantity', 'cl.snapshot_latest_entry_id',
                ]);

            $holdingsByShareholder = [];
            $sourceLedgerMaxId = 0;
            foreach ($holdingRows as $holding) {
                $current = (string) $holding->current_quantity;
                $ledgerQuantity = (string) ($holding->ledger_quantity ?? '0.0000');
                if (!$this->decimalEqual($current, $ledgerQuantity)) {
                    throw new ConflictHttpException(
                        'Shareholding projection and immutable ledger disagree for holding #' . $holding->holding_id . '. Resolve the register discrepancy before snapshotting.'
                    );
                }
                $sourceLedgerMaxId = max($sourceLedgerMaxId, (int) ($holding->latest_entry_id ?? 0));
                $holdingSource = [
                    'holding_id' => (int) $holding->holding_id,
                    'share_class_id' => (int) $holding->share_class_id,
                    'quantity' => (string) ($holding->snapshot_quantity ?? '0.0000'),
                    'latest_ledger_entry_id' => (int) ($holding->snapshot_latest_entry_id ?? 0),
                ];
                $holdingSource['source_hash'] = hash('sha256', json_encode($holdingSource, JSON_THROW_ON_ERROR));
                $holdingsByShareholder[(int) $holding->shareholder_id][] = $holdingSource;
            }

            $shareholders = DB::table('shareholders')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '<=', $cutoffAt->toDateTimeString())
                ->where('updated_at', '<=', $cutoffAt->toDateTimeString())
                ->where('is_active', true)
                ->where(function ($query) use ($cutoffAt): void {
                    $query->whereNull('deleted_at')->orWhere('deleted_at', '>', $cutoffAt->toDateTimeString());
                })
                ->orderBy('id')
                ->get(['id']);

            $entries = [];
            foreach ($shareholders as $shareholder) {
                $shareholdings = $holdingsByShareholder[(int) $shareholder->id] ?? [];
                $eligible = false;
                foreach ($shareholdings as $holding) {
                    if ($this->decimalCompare($holding['quantity'], '0') > 0) {
                        $eligible = true;
                        break;
                    }
                }
                $entry = [
                    'shareholder_id' => (int) $shareholder->id,
                    'shareholdings' => $shareholdings,
                    'eligibility_status' => $eligible ? 'pending_rule' : 'excluded_no_shares',
                ];
                $entry['source_hash'] = hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR));
                $entries[] = $entry;
            }

            $sourceRegisterVersion = trim($data['source_register_version']);
            $cutoffString = $cutoffAt->toDateTimeString();
            $snapshotManifest = [
                'tenant_id' => $tenantId,
                'meeting_id' => $meeting,
                'version' => $latestVersion + 1,
                'source_register_version' => $sourceRegisterVersion,
                'cutoff_at' => $cutoffString,
                'source_ledger_max_id' => $sourceLedgerMaxId,
                'entries' => $entries,
            ];
            $snapshotHash = hash('sha256', json_encode($snapshotManifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $snapshotId = DB::table('governance_meeting_electorate_snapshots')->insertGetId([
                'tenant_id' => $tenantId,
                'meeting_id' => $meeting,
                'version' => $latestVersion + 1,
                'source_register_version' => $sourceRegisterVersion,
                'cutoff_at' => $cutoffString,
                'entry_count' => count($entries),
                'source_ledger_max_id' => $sourceLedgerMaxId,
                'source_hash' => $snapshotHash,
                'status' => 'draft',
                'created_by' => $this->actorId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($entries as $entry) {
                DB::table('governance_meeting_electorate_entries')->insert([
                    'tenant_id' => $tenantId,
                    'snapshot_id' => $snapshotId,
                    'shareholder_id' => $entry['shareholder_id'],
                    'shareholdings_json' => json_encode($entry['shareholdings'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'voting_units' => null,
                    'eligibility_status' => $entry['eligibility_status'],
                    'eligibility_reason' => $entry['eligibility_status'] === 'excluded_no_shares' ? 'No positive active shareholding at snapshot capture.' : 'Voting weight awaits an approved share-class rule.',
                    'source_record_hash' => $entry['source_hash'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->activity($tenantId, $meeting, 'electorate.snapshot_created', [
                'snapshot_id' => $snapshotId, 'version' => $latestVersion + 1,
                'entry_count' => count($entries), 'source_ledger_max_id' => $sourceLedgerMaxId,
                'source_hash' => $snapshotHash,
            ]);

            return DB::table('governance_meeting_electorate_snapshots')
                ->where('tenant_id', $tenantId)->where('id', $snapshotId)->first();
        });

        return response()->json(['success' => true, 'data' => $snapshot], 201);
    }

    public function approveSnapshot(Request $request, int $snapshot): JsonResponse
    {
        $data = $request->validate([
            'approval_reference' => ['required', 'string', 'min:3', 'max:200'],
        ]);
        $tenantId = $this->tenantId();

        $result = DB::transaction(function () use ($tenantId, $snapshot, $data): object {
            $record = DB::table('governance_meeting_electorate_snapshots')
                ->where('tenant_id', $tenantId)->where('id', $snapshot)
                ->lockForUpdate()->first();
            if (!$record) {
                throw new NotFoundHttpException('Electorate snapshot was not found.');
            }
            if ($record->status !== 'draft') {
                throw new ConflictHttpException('Only a draft electorate snapshot can be approved.');
            }
            if ((int) $record->created_by === $this->actorId()) {
                throw new ConflictHttpException('The snapshot creator cannot approve the same snapshot.');
            }
            $count = (int) DB::table('governance_meeting_electorate_entries')
                ->where('tenant_id', $tenantId)->where('snapshot_id', $snapshot)->count();
            if ($count !== (int) $record->entry_count || $count < 1) {
                throw new ConflictHttpException('Snapshot entry count is incomplete or empty.');
            }
            DB::table('governance_meeting_electorate_snapshots')
                ->where('tenant_id', $tenantId)->where('id', $snapshot)
                ->update([
                    'status' => 'approved', 'approved_by' => $this->actorId(),
                    'approved_at' => now(), 'approval_reference' => trim($data['approval_reference']),
                    'updated_at' => now(),
                ]);
            $this->activity($tenantId, (int) $record->meeting_id, 'electorate.snapshot_approved', [
                'snapshot_id' => $snapshot, 'source_hash' => $record->source_hash,
                'approval_reference' => $data['approval_reference'],
            ]);
            return DB::table('governance_meeting_electorate_snapshots')
                ->where('tenant_id', $tenantId)->where('id', $snapshot)->first();
        });

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function createQuorumRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meeting_kind' => ['required', 'in:general_assembly'],
            'meeting_category' => ['required', 'in:ordinary,extraordinary'],
            'meeting_round' => ['required', 'integer', 'min:1', 'max:3'],
            'rule_code' => ['required', 'string', 'max:80'],
            'legal_basis' => ['required', 'string', 'min:5', 'max:1000'],
            'quorum_mode' => ['required', 'in:capital_threshold,no_capital_threshold'],
            'quorum_numerator' => ['required_if:quorum_mode,capital_threshold', 'nullable', 'integer', 'min:1', 'max:100000'],
            'quorum_denominator' => ['required_if:quorum_mode,capital_threshold', 'nullable', 'integer', 'min:1', 'max:100000'],
            'share_class_weights' => ['required', 'array', 'min:1'],
            'share_class_weights.*' => ['required', 'string', 'regex:/^\\d{1,12}(\\.\\d{1,4})?$/', 'gt:0'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        if ($data['quorum_mode'] === 'capital_threshold'
            && (int) $data['quorum_numerator'] > (int) $data['quorum_denominator']) {
            throw ValidationException::withMessages(['quorum_numerator' => 'Quorum numerator cannot exceed denominator.']);
        }
        if ($data['quorum_mode'] === 'no_capital_threshold'
            && (isset($data['quorum_numerator']) || isset($data['quorum_denominator']))) {
            throw ValidationException::withMessages(['quorum_mode' => 'A no-capital-threshold rule must not include a numeric quorum fraction.']);
        }
        $shareClassIds = [];
        foreach (array_keys($data['share_class_weights']) as $classId) {
            if (!ctype_digit((string) $classId) || (int) $classId < 1) {
                throw ValidationException::withMessages(['share_class_weights' => 'Share-class weights must be keyed by numeric share_class_id.']);
            }
            $shareClassIds[] = (int) $classId;
        }
        if (DB::table('share_classes')->whereIn('id', $shareClassIds)->count() !== count(array_unique($shareClassIds))) {
            throw ValidationException::withMessages(['share_class_weights' => 'Every voting-weight key must identify an existing share class.']);
        }

        $tenantId = $this->tenantId();
        $meetingCategory = $data['meeting_category'];
        if ($meetingCategory === 'ordinary' && (int) $data['meeting_round'] > 2) {
            throw ValidationException::withMessages(['meeting_round' => 'Ordinary General Assembly rules support only first and second meeting rounds.']);
        }
        $meetingRound = (int) $data['meeting_round'];
        $payload = [
            'quorum_mode' => $data['quorum_mode'],
            'quorum_numerator' => isset($data['quorum_numerator']) ? (int) $data['quorum_numerator'] : null,
            'quorum_denominator' => isset($data['quorum_denominator']) ? (int) $data['quorum_denominator'] : null,
            'share_class_weights' => $data['share_class_weights'],
            'denominator_basis' => 'total_eligible_voting_units',
            'counted_attendance_statuses' => ['present', 'late', 'represented'],
        ];
        $effectiveFrom = CarbonImmutable::parse($data['effective_from'])->toDateTimeString();
        $effectiveUntil = isset($data['effective_until']) ? CarbonImmutable::parse($data['effective_until'])->toDateTimeString() : null;
        $ruleDefinition = [
            'meeting_kind' => $data['meeting_kind'],
            'meeting_category' => $meetingCategory,
            'meeting_round' => $meetingRound,
            'rule_code' => trim($data['rule_code']),
            'rule_payload' => $payload,
            'legal_basis' => trim($data['legal_basis']),
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
        ];
        $ruleDefinitionHash = hash('sha256', $this->canonicalJson($ruleDefinition));

        $id = DB::table('governance_quorum_rules')->insertGetId([
            'tenant_id' => $tenantId,
            'meeting_kind' => $data['meeting_kind'],
            'meeting_category' => $meetingCategory,
            'meeting_round' => $meetingRound,
            'rule_code' => trim($data['rule_code']),
            'rule_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'rule_definition_hash' => $ruleDefinitionHash,
            'legal_basis' => trim($data['legal_basis']),
            'status' => 'draft',
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
            'created_by' => $this->actorId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => DB::table('governance_quorum_rules')->where('tenant_id', $tenantId)->find($id),
        ], 201);
    }

    public function approveQuorumRule(Request $request, int $rule): JsonResponse
    {
        $data = $request->validate([
            'approval_reference' => ['required', 'string', 'min:3', 'max:200'],
            'approval_document_hash' => ['required', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        $tenantId = $this->tenantId();

        $result = DB::transaction(function () use ($tenantId, $rule, $data): object {
            $record = DB::table('governance_quorum_rules')
                ->where('tenant_id', $tenantId)->where('id', $rule)
                ->lockForUpdate()->first();
            if (!$record) {
                throw new NotFoundHttpException('Quorum rule was not found.');
            }
            if ($record->status !== 'draft') {
                throw new ConflictHttpException('Only a draft quorum rule can be approved.');
            }
            if ((int) $record->created_by === $this->actorId()) {
                throw new ConflictHttpException('The quorum-rule author cannot approve the same rule.');
            }
            DB::table('governance_quorum_rules')->where('tenant_id', $tenantId)->where('id', $rule)->update([
                'status' => 'pending_legal_verification',
                'approval_reference' => trim($data['approval_reference']),
                'approval_document_hash' => strtolower($data['approval_document_hash']),
                'approved_by' => $this->actorId(),
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
            return DB::table('governance_quorum_rules')->where('tenant_id', $tenantId)->find($rule);
        });

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function verifyQuorumRule(Request $request, int $rule): JsonResponse
    {
        $data = $request->validate([
            'legal_verification_reference' => ['required', 'string', 'min:3', 'max:200'],
            'legal_verification_document_hash' => ['required', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        $tenantId = $this->tenantId();

        $result = DB::transaction(function () use ($tenantId, $rule, $data): object {
            $record = DB::table('governance_quorum_rules')
                ->where('tenant_id', $tenantId)->where('id', $rule)
                ->lockForUpdate()->first();
            if (!$record) {
                throw new NotFoundHttpException('Quorum rule was not found.');
            }
            if ($record->status !== 'pending_legal_verification') {
                throw new ConflictHttpException('Only a rule with recorded governance approval can enter legal verification.');
            }
            if ((int) $record->created_by === $this->actorId() || (int) $record->approved_by === $this->actorId()) {
                throw new ConflictHttpException('The rule author and governance approver cannot perform the independent legal verification.');
            }
            DB::table('governance_quorum_rules')->where('tenant_id', $tenantId)->where('id', $rule)->update([
                'status' => 'approved',
                'legal_verified_by' => $this->actorId(),
                'legal_verified_at' => now(),
                'legal_verification_reference' => trim($data['legal_verification_reference']),
                'legal_verification_document_hash' => strtolower($data['legal_verification_document_hash']),
                'updated_at' => now(),
            ]);
            return DB::table('governance_quorum_rules')->where('tenant_id', $tenantId)->find($rule);
        });

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function evaluateQuorum(int $meeting): JsonResponse
    {
        $tenantId = $this->tenantId();
        $meetingRow = DB::table('governance_meetings')
            ->where('tenant_id', $tenantId)->where('id', $meeting)->first();
        if (!$meetingRow) {
            throw new NotFoundHttpException('Meeting was not found in this tenant.');
        }

        if ($meetingRow->meeting_kind !== 'general_assembly') {
            return $this->blocked($tenantId, $meeting, null, null, 'BOARD_ELECTORATE_ADAPTER_NOT_IMPLEMENTED');
        }
        if (!in_array($meetingRow->meeting_category, ['ordinary', 'extraordinary'], true)) {
            return $this->blocked($tenantId, $meeting, null, null, 'GENERAL_ASSEMBLY_CATEGORY_MISSING');
        }
        if (!in_array((int) $meetingRow->meeting_round, [1, 2, 3], true)
            || ($meetingRow->meeting_category === 'ordinary' && (int) $meetingRow->meeting_round > 2)) {
            return $this->blocked($tenantId, $meeting, null, null, 'GENERAL_ASSEMBLY_MEETING_ROUND_MISSING_OR_INVALID');
        }
        $snapshot = DB::table('governance_meeting_electorate_snapshots')
            ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)
            ->orderByDesc('version')->first();
        if (!$snapshot || $snapshot->status !== 'approved') {
            return $this->blocked($tenantId, $meeting, $snapshot?->id, null, 'LATEST_ELECTORATE_SNAPSHOT_NOT_APPROVED');
        }

        $rule = DB::table('governance_quorum_rules')
            ->where('tenant_id', $tenantId)
            ->where('meeting_kind', $meetingRow->meeting_kind)
            ->where('meeting_category', $meetingRow->meeting_category)
            ->where('meeting_round', $meetingRow->meeting_round)
            ->where('status', 'approved')
            ->where('effective_from', '<=', $meetingRow->starts_at)
            ->where(function ($query) use ($meetingRow): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $meetingRow->starts_at);
            })
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        if (!$rule || !$rule->approval_reference || !$rule->approval_document_hash || !$rule->approved_at
            || !$rule->legal_verified_by || !$rule->legal_verified_at
            || !$rule->legal_verification_reference || !$rule->legal_verification_document_hash) {
            return $this->blocked($tenantId, $meeting, (int) $snapshot->id, $rule?->id, 'APPROVED_QUORUM_RULE_MISSING');
        }
        if (!function_exists('bcadd') || !function_exists('bcmul') || !function_exists('bccomp')) {
            return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'DECIMAL_ARITHMETIC_EXTENSION_UNAVAILABLE');
        }

        $payload = json_decode((string) $rule->rule_payload, true);
        if (!is_array($payload)) {
            return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'APPROVED_QUORUM_RULE_INVALID');
        }
        $ruleDefinition = [
            'meeting_kind' => (string) $rule->meeting_kind,
            'meeting_category' => $rule->meeting_category === null ? null : (string) $rule->meeting_category,
            'meeting_round' => (int) $rule->meeting_round,
            'rule_code' => (string) $rule->rule_code,
            'rule_payload' => $payload,
            'legal_basis' => (string) $rule->legal_basis,
            'effective_from' => (string) $rule->effective_from,
            'effective_until' => $rule->effective_until === null ? null : (string) $rule->effective_until,
        ];
        $recomputedRuleHash = hash('sha256', $this->canonicalJson($ruleDefinition));
        if (!hash_equals((string) $rule->rule_definition_hash, $recomputedRuleHash)) {
            return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'APPROVED_QUORUM_RULE_HASH_MISMATCH');
        }
        $quorumMode = (string) ($payload['quorum_mode'] ?? '');
        $numerator = (int) ($payload['quorum_numerator'] ?? 0);
        $denominator = (int) ($payload['quorum_denominator'] ?? 0);
        $weights = $payload['share_class_weights'] ?? null;
        $validThreshold = $quorumMode === 'capital_threshold'
            && $numerator >= 1 && $denominator >= 1 && $numerator <= $denominator;
        $validNoThreshold = $quorumMode === 'no_capital_threshold'
            && ($payload['quorum_numerator'] ?? null) === null
            && ($payload['quorum_denominator'] ?? null) === null;
        if ((!$validThreshold && !$validNoThreshold) || !is_array($weights) || $weights === []) {
            return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'APPROVED_QUORUM_RULE_INVALID');
        }

        $entries = DB::table('governance_meeting_electorate_entries')
            ->where('tenant_id', $tenantId)->where('snapshot_id', $snapshot->id)
            ->orderBy('shareholder_id')->get();
        if ($entries->isEmpty()) {
            return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'ELECTORATE_SNAPSHOT_EMPTY');
        }

        $unitsByShareholder = [];
        $totalUnits = '0.0000';
        $canonicalEntries = [];
        foreach ($entries as $entry) {
            $units = '0.0000';
            $holdings = json_decode((string) $entry->shareholdings_json, true);
            if (!is_array($holdings)) {
                return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'ELECTORATE_ENTRY_CORRUPT');
            }
            foreach ($holdings as $holding) {
                $quantity = (string) ($holding['quantity'] ?? '0');
                if (bccomp($quantity, '0', 4) <= 0) {
                    continue;
                }
                $classId = (string) ($holding['share_class_id'] ?? '');
                if (!array_key_exists($classId, $weights)) {
                    return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'APPROVED_RULE_SHARE_CLASS_WEIGHT_MISSING');
                }
                $weight = (string) $weights[$classId];
                $units = bcadd($units, bcmul($quantity, $weight, 4), 4);
            }
            $unitsByShareholder[(int) $entry->shareholder_id] = $units;
            $totalUnits = bcadd($totalUnits, $units, 4);
            $canonicalEntry = [
                'shareholder_id' => (int) $entry->shareholder_id,
                'shareholdings' => $holdings,
                'eligibility_status' => $entry->eligibility_status,
            ];
            $canonicalEntry['source_hash'] = hash('sha256', json_encode($canonicalEntry, JSON_THROW_ON_ERROR));
            if (!hash_equals((string) $entry->source_record_hash, $canonicalEntry['source_hash'])) {
                return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'ELECTORATE_ENTRY_HASH_MISMATCH');
            }
            $canonicalEntries[] = $canonicalEntry;
        }
        $snapshotManifest = [
            'tenant_id' => (int) $snapshot->tenant_id,
            'meeting_id' => (int) $snapshot->meeting_id,
            'version' => (int) $snapshot->version,
            'source_register_version' => (string) $snapshot->source_register_version,
            'cutoff_at' => (string) $snapshot->cutoff_at,
            'source_ledger_max_id' => (int) $snapshot->source_ledger_max_id,
            'entries' => $canonicalEntries,
        ];
        $recomputedSnapshotHash = hash('sha256', json_encode($snapshotManifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (!hash_equals((string) $snapshot->source_hash, $recomputedSnapshotHash)) {
            return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'ELECTORATE_SNAPSHOT_HASH_MISMATCH');
        }
        if (bccomp($totalUnits, '0', 4) <= 0) {
            return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'TOTAL_ELIGIBLE_VOTING_UNITS_ZERO');
        }

        $attendance = DB::table('governance_meeting_attendance')
            ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)
            ->where('subject_type', 'shareholder')->orderBy('subject_id')->get();
        $presentUnits = '0.0000';
        $countedShareholders = [];
        foreach ($attendance as $row) {
            $shareholderId = (int) $row->subject_id;
            if (!array_key_exists($shareholderId, $unitsByShareholder)) {
                if (in_array($row->attendance_status, ['present', 'late', 'represented'], true)) {
                    return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'ATTENDING_SHAREHOLDER_NOT_IN_ELECTORATE');
                }
                continue;
            }
            if (!in_array($row->attendance_status, ['present', 'late', 'represented'], true)) {
                continue;
            }
            if (isset($countedShareholders[$shareholderId])) {
                return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'DUPLICATE_ATTENDANCE_RECORD');
            }
            $countedShareholders[$shareholderId] = true;

            $approvedProxyExists = DB::table('governance_meeting_proxies')
                ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)
                ->where('principal_type', 'shareholder')->where('principal_id', $shareholderId)
                ->where('status', 'approved')->exists();
            if ($row->attendance_status === 'represented') {
                $linkedProxy = !empty($row->proxy_id)
                    ? DB::table('governance_meeting_proxies')
                        ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)
                        ->where('id', $row->proxy_id)->where('principal_type', 'shareholder')
                        ->where('principal_id', $shareholderId)->where('status', 'approved')->first()
                    : null;
                if (!$linkedProxy) {
                    return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'REPRESENTATION_WITHOUT_LINKED_APPROVED_PROXY');
                }
                if (!empty($linkedProxy->proxy_user_id)) {
                    if ((int) ($row->represented_by_user_id ?? 0) !== (int) $linkedProxy->proxy_user_id) {
                        return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'PROXY_ATTENDEE_IDENTITY_MISMATCH');
                    }
                } else {
                    $attendingName = trim((string) ($row->represented_by_name ?? ''));
                    $approvedNames = array_filter([
                        trim((string) ($linkedProxy->proxy_name_am ?? '')),
                        trim((string) ($linkedProxy->proxy_name_en ?? '')),
                    ]);
                    if ($attendingName === '' || !in_array($attendingName, $approvedNames, true)) {
                        return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'EXTERNAL_PROXY_ATTENDEE_IDENTITY_MISMATCH');
                    }
                }
            }
            if (in_array($row->attendance_status, ['present', 'late'], true) && $approvedProxyExists) {
                return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'PERSONAL_AND_PROXY_ATTENDANCE_CONFLICT');
            }
            $presentUnits = bcadd($presentUnits, $unitsByShareholder[$shareholderId], 4);
        }

        if ($quorumMode === 'capital_threshold') {
            $left = bcmul($presentUnits, (string) $denominator, 4);
            $right = bcmul($totalUnits, (string) $numerator, 4);
            $status = bccomp($left, $right, 4) >= 0 ? 'PASS' : 'FAIL';
        } else {
            // No capital threshold is not a default: it is usable only when
            // the exact approved rule explicitly selects this mode.
            $status = count($countedShareholders) > 0 && bccomp($presentUnits, '0', 4) > 0 ? 'PASS' : 'FAIL';
        }
        $result = [
            'status' => $status,
            'meeting_id' => $meeting,
            'snapshot_id' => (int) $snapshot->id,
            'snapshot_hash' => $snapshot->source_hash,
            'quorum_rule_id' => (int) $rule->id,
            'rule_code' => $rule->rule_code,
            'present_voting_units' => $presentUnits,
            'total_eligible_voting_units' => $totalUnits,
            'quorum_mode' => $quorumMode,
            'threshold' => $quorumMode === 'capital_threshold' ? ($numerator . '/' . $denominator) : 'No capital threshold (approved rule)',
            'counted_shareholders' => count($countedShareholders),
            'can_open_business' => $status === 'PASS',
        ];
        $inputHash = hash('sha256', json_encode([
            'snapshot_hash' => $snapshot->source_hash,
            'rule_id' => (int) $rule->id,
            'attendance' => $attendance->toArray(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $id = DB::table('governance_meeting_quorum_evaluations')->insertGetId([
            'tenant_id' => $tenantId, 'meeting_id' => $meeting,
            'snapshot_id' => $snapshot->id, 'quorum_rule_id' => $rule->id,
            'status' => $status, 'reason_code' => null,
            'result_payload' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'input_hash' => $inputHash, 'evaluated_by' => $this->actorId(),
            'evaluated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, $meeting, 'quorum.evaluated', [
            'evaluation_id' => $id, 'status' => $status, 'input_hash' => $inputHash,
        ]);

        return response()->json(['success' => true, 'data' => $result + ['evaluation_id' => $id]]);
    }

    private function blocked(int $tenantId, int $meeting, ?int $snapshotId, ?int $ruleId, string $reason): JsonResponse
    {
        $result = [
            'status' => 'BLOCKED',
            'reason_code' => $reason,
            'meeting_id' => $meeting,
            'electorate_snapshot_id' => $snapshotId,
            'quorum_rule_id' => $ruleId,
            'can_open_business' => false,
        ];
        $id = DB::table('governance_meeting_quorum_evaluations')->insertGetId([
            'tenant_id' => $tenantId, 'meeting_id' => $meeting,
            'snapshot_id' => $snapshotId, 'quorum_rule_id' => $ruleId,
            'status' => 'BLOCKED', 'reason_code' => $reason,
            'result_payload' => json_encode($result, JSON_THROW_ON_ERROR),
            'input_hash' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)),
            'evaluated_by' => $this->actorId(), 'evaluated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, $meeting, 'quorum.blocked', [
            'evaluation_id' => $id, 'reason_code' => $reason,
        ]);

        return response()->json(['success' => true, 'data' => $result + ['evaluation_id' => $id]]);
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalise, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $child) {
                $item[$key] = $normalise($child);
            }
            return $item;
        };

        return json_encode($normalise($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decimalEqual(string $left, string $right): bool
    {
        return $this->decimalCompare($left, $right) === 0;
    }

    private function decimalCompare(string $left, string $right): int
    {
        if (function_exists('bccomp')) {
            return bccomp($left, $right, 4);
        }
        $normalise = static function (string $value): array {
            $value = trim($value);
            $negative = str_starts_with($value, '-');
            $value = ltrim($value, '+-');
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $whole = ltrim($whole, '0') ?: '0';
            $fraction = rtrim(str_pad(substr($fraction, 0, 4), 4, '0'), '0');
            return [$negative, $whole, $fraction === '' ? '0' : $fraction];
        };
        [$leftNegative, $leftWhole, $leftFraction] = $normalise($left);
        [$rightNegative, $rightWhole, $rightFraction] = $normalise($right);
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }
        $wholeCompare = strlen($leftWhole) <=> strlen($rightWhole);
        if ($wholeCompare === 0) {
            $wholeCompare = strcmp($leftWhole, $rightWhole) <=> 0;
        }
        $fractionCompare = strcmp(str_pad($leftFraction, 4, '0'), str_pad($rightFraction, 4, '0')) <=> 0;
        $comparison = $wholeCompare !== 0 ? $wholeCompare : $fractionCompare;
        return $leftNegative ? -$comparison : $comparison;
    }

    private function activity(int $tenantId, int $meetingId, string $action, array $payload): void
    {
        DB::table('governance_meeting_activity')->insert([
            'tenant_id' => $tenantId, 'meeting_id' => $meetingId, 'actor_id' => $this->actorId(),
            'action' => $action, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }
}
