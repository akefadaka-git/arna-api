<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Governance\\Http\\Controllers;

use App\\Http\\Controllers\\Controller;
use Carbon\\CarbonImmutable;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Validation\\ValidationException;
use Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException;
use Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException;

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

        $snapshot = DB::transaction(function () use ($tenantId, $meeting, $data): object {
            $meetingRow = DB::table('governance_meetings')
                ->where('tenant_id', $tenantId)->where('id', $meeting)
                ->lockForUpdate()->first();
            if (!$meetingRow) {
                throw new NotFoundHttpException('Meeting was not found in this tenant.');
            }
            if ($meetingRow->meeting_kind !== 'general_assembly') {
                throw ValidationException::withMessages(['meeting' => 'This electorate snapshot builder is for General Assembly meetings only.']);
            }
            if (in_array($meetingRow->status, ['cancelled', 'closed'], true)) {
                throw new ConflictHttpException('A cancelled or closed meeting cannot receive a new electorate snapshot.');
            }

            $latestVersion = (int) DB::table('governance_meeting_electorate_snapshots')
                ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->max('version');
            $ledger = DB::table('share_holding_entries')
                ->select('share_holding_id')
                ->selectRaw('COALESCE(SUM(quantity), 0) AS ledger_quantity')
                ->selectRaw('MAX(id) AS latest_entry_id')
                ->groupBy('share_holding_id');

            $holdingRows = DB::table('share_holdings as sh')
                ->join('shareholders as s', 's.id', '=', 'sh.shareholder_id')
                ->join('share_classes as sc', 'sc.id', '=', 'sh.share_class_id')
                ->leftJoinSub($ledger, 'sl', function ($join): void {
                    $join->on('sl.share_holding_id', '=', 'sh.id');
                })
                ->where('s.tenant_id', $tenantId)
                ->where('s.is_active', true)
                ->whereNull('s.deleted_at')
                ->where('sc.is_active', true)
                ->whereNull('sc.deleted_at')
                ->orderBy('s.id')
                ->orderBy('sc.id')
                ->get([
                    'sh.id as holding_id', 'sh.shareholder_id', 'sh.share_class_id',
                    'sh.current_quantity', 'sc.code as share_class_code',
                    'sl.ledger_quantity', 'sl.latest_entry_id',
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
                    'share_class_code' => (string) $holding->share_class_code,
                    'quantity' => $current,
                    'latest_ledger_entry_id' => (int) ($holding->latest_entry_id ?? 0),
                ];
                $holdingSource['source_hash'] = hash('sha256', json_encode($holdingSource, JSON_THROW_ON_ERROR));
                $holdingsByShareholder[(int) $holding->shareholder_id][] = $holdingSource;
            }

            $shareholders = DB::table('shareholders')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
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

            $canonical = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $snapshotHash = hash('sha256', $canonical);
            $snapshotId = DB::table('governance_meeting_electorate_snapshots')->insertGetId([
                'tenant_id' => $tenantId,
                'meeting_id' => $meeting,
                'version' => $latestVersion + 1,
                'source_register_version' => trim($data['source_register_version']),
                'cutoff_at' => CarbonImmutable::parse($data['cutoff_at'])->toDateTimeString(),
                'entry_count' => count($entries),
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
            'meeting_kind' => ['required', 'in:general_assembly,board'],
            'rule_code' => ['required', 'string', 'max:80'],
            'legal_basis' => ['required', 'string', 'min:5', 'max:1000'],
            'quorum_numerator' => ['required', 'integer', 'min:1', 'max:100000'],
            'quorum_denominator' => ['required', 'integer', 'min:1', 'max:100000'],
            'share_class_weights' => ['required', 'array', 'min:1'],
            'share_class_weights.*' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);
        if ($data['quorum_numerator'] > $data['quorum_denominator']) {
            throw ValidationException::withMessages(['quorum_numerator' => 'Quorum numerator cannot exceed denominator.']);
        }
        foreach (array_keys($data['share_class_weights']) as $classId) {
            if (!ctype_digit((string) $classId) || (int) $classId < 1) {
                throw ValidationException::withMessages(['share_class_weights' => 'Share-class weights must be keyed by numeric share_class_id.']);
            }
        }

        $tenantId = $this->tenantId();
        $id = DB::table('governance_quorum_rules')->insertGetId([
            'tenant_id' => $tenantId,
            'meeting_kind' => $data['meeting_kind'],
            'rule_code' => trim($data['rule_code']),
            'rule_payload' => json_encode([
                'quorum_numerator' => (int) $data['quorum_numerator'],
                'quorum_denominator' => (int) $data['quorum_denominator'],
                'share_class_weights' => $data['share_class_weights'],
                'denominator_basis' => 'total_eligible_voting_units',
                'counted_attendance_statuses' => ['present', 'late', 'represented'],
            ], JSON_THROW_ON_ERROR),
            'legal_basis' => trim($data['legal_basis']),
            'status' => 'draft',
            'effective_from' => CarbonImmutable::parse($data['effective_from'])->toDateTimeString(),
            'effective_until' => isset($data['effective_until']) ? CarbonImmutable::parse($data['effective_until'])->toDateTimeString() : null,
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
                'status' => 'approved',
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

    public function evaluateQuorum(int $meeting): JsonResponse
    {
        $tenantId = $this->tenantId();
        $meetingRow = DB::table('governance_meetings')
            ->where('tenant_id', $tenantId)->where('id', $meeting)->first();
        if (!$meetingRow) {
            throw new NotFoundHttpException('Meeting was not found in this tenant.');
        }

        $snapshot = DB::table('governance_meeting_electorate_snapshots')
            ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)
            ->where('status', 'approved')->orderByDesc('version')->first();
        if (!$snapshot) {
            return $this->blocked($tenantId, $meeting, null, null, 'APPROVED_ELECTORATE_SNAPSHOT_MISSING');
        }

        $rule = DB::table('governance_quorum_rules')
            ->where('tenant_id', $tenantId)
            ->where('meeting_kind', $meetingRow->meeting_kind)
            ->where('status', 'approved')
            ->where('effective_from', '<=', $meetingRow->starts_at)
            ->where(function ($query) use ($meetingRow): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $meetingRow->starts_at);
            })
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        if (!$rule || !$rule->approval_reference || !$rule->approval_document_hash || !$rule->approved_at) {
            return $this->blocked($tenantId, $meeting, (int) $snapshot->id, $rule?->id, 'APPROVED_QUORUM_RULE_MISSING');
        }
        if (!function_exists('bcadd') || !function_exists('bcmul') || !function_exists('bccomp')) {
            return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'DECIMAL_ARITHMETIC_EXTENSION_UNAVAILABLE');
        }

        $payload = json_decode((string) $rule->rule_payload, true);
        $numerator = (int) ($payload['quorum_numerator'] ?? 0);
        $denominator = (int) ($payload['quorum_denominator'] ?? 0);
        $weights = $payload['share_class_weights'] ?? null;
        if ($numerator < 1 || $denominator < 1 || $numerator > $denominator || !is_array($weights) || $weights === []) {
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
        foreach ($entries as $entry) {
            $units = '0.0000';
            $holdings = json_decode((string) $entry->shareholdings_json, true);
            if (!is_array($holdings)) {
                return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'ELECTORATE_ENTRY_CORRUPT');
            }
            foreach ($holdings as $holding) {
                $classId = (string) ($holding['share_class_id'] ?? '');
                if (!array_key_exists($classId, $weights)) {
                    return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'APPROVED_RULE_SHARE_CLASS_WEIGHT_MISSING');
                }
                $quantity = (string) ($holding['quantity'] ?? '0');
                $weight = (string) $weights[$classId];
                $units = bcadd($units, bcmul($quantity, $weight, 4), 4);
            }
            $unitsByShareholder[(int) $entry->shareholder_id] = $units;
            $totalUnits = bcadd($totalUnits, $units, 4);
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

            $approvedProxy = DB::table('governance_meeting_proxies')
                ->where('tenant_id', $tenantId)->where('meeting_id', $meeting)
                ->where('principal_type', 'shareholder')->where('principal_id', $shareholderId)
                ->where('status', 'approved')->exists();
            if ($row->attendance_status === 'represented' && !$approvedProxy) {
                return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'REPRESENTATION_WITHOUT_APPROVED_PROXY');
            }
            if (in_array($row->attendance_status, ['present', 'late'], true) && $approvedProxy) {
                return $this->blocked($tenantId, $meeting, (int) $snapshot->id, (int) $rule->id, 'PERSONAL_AND_PROXY_ATTENDANCE_CONFLICT');
            }
            $presentUnits = bcadd($presentUnits, $unitsByShareholder[$shareholderId], 4);
        }

        $left = bcmul($presentUnits, (string) $denominator, 4);
        $right = bcmul($totalUnits, (string) $numerator, 4);
        $status = bccomp($left, $right, 4) >= 0 ? 'PASS' : 'FAIL';
        $result = [
            'status' => $status,
            'meeting_id' => $meeting,
            'snapshot_id' => (int) $snapshot->id,
            'snapshot_hash' => $snapshot->source_hash,
            'quorum_rule_id' => (int) $rule->id,
            'rule_code' => $rule->rule_code,
            'present_voting_units' => $presentUnits,
            'total_eligible_voting_units' => $totalUnits,
            'threshold' => $numerator . '/' . $denominator,
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

    private function decimalEqual(string $left, string $right): bool
    {
        return $this->decimalCompare($left, $right) === 0;
    }

    private function decimalCompare(string $left, string $right): int
    {
        if (function_exists('bccomp')) {
            return bccomp($left, $right, 4);
        }
        $normalise = static function (string $value): string {
            $value = trim($value);
            $negative = str_starts_with($value, '-');
            $value = ltrim($value, '+-');
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $whole = ltrim($whole, '0') ?: '0';
            $fraction = rtrim(str_pad(substr($fraction, 0, 4), 4, '0'), '0');
            return ($negative ? '-' : '') . $whole . '.' . ($fraction === '' ? '0' : $fraction);
        };
        return strcmp($normalise($left), $normalise($right)) <=> 0;
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
