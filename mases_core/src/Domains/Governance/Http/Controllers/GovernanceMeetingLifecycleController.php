<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceMeetingLegalGate;
use DomainException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Evidence-backed GA lifecycle attestations.
 *
 * This records a controlled attestation against a source artifact; it does
 * not itself send notices, calculate quorum, count votes, or create minutes.
 */
final class GovernanceMeetingLifecycleController extends Controller
{
    private const ACTIONS = ['notice', 'quorum', 'voting', 'minutes'];

    private function tenantId(): int
    {
        $tenantId = (int) (function_exists('mases_tenant_id') ? mases_tenant_id() : 0);
        if ($tenantId < 1) {
            throw new NotFoundHttpException('Tenant context is required.');
        }
        return $tenantId;
    }

    private function authorizeEvidence(int $tenantId, int $meetingId, string $action): int
    {
        $basis = DB::table('governance_meeting_legal_bases')
            ->where('tenant_id', $tenantId)->where('meeting_id', $meetingId)->first();
        if (!$basis) {
            throw new ConflictHttpException('Lifecycle action blocked: verified legal-basis evidence is missing.');
        }
        $rows = DB::table('governance_meeting_legal_evidence')
            ->where('tenant_id', $tenantId)->where('legal_basis_id', $basis->id)
            ->where('action', $action)->get();
        $evidence = [];
        foreach ($rows as $row) {
            $evidence[$row->evidence_key] = [
                'status' => $row->status, 'source_reference' => $row->source_reference,
                'approved_at' => $row->approved_at, 'approved_by' => $row->approved_by,
            ];
        }
        try {
            (new GovernanceMeetingLegalGate())->assertAllowed($action, [
                'legal_form' => $basis->legal_form, 'category' => $basis->meeting_category,
                'legal_basis_status' => $basis->status, 'legal_basis_reference' => $basis->source_reference,
                'legal_basis_verified_at' => $basis->verified_at, 'legal_basis_verified_by' => $basis->verified_by,
                'external_review_status' => $basis->external_review_status,
                'external_counsel_name' => $basis->external_counsel_name,
                'external_counsel_firm' => $basis->external_counsel_firm,
                'external_counsel_license_reference' => $basis->external_counsel_license_reference,
                'external_opinion_reference' => $basis->external_opinion_reference,
                'external_opinion_sha256' => $basis->external_opinion_sha256,
                'external_reviewed_at' => $basis->external_reviewed_at,
                'created_by' => $basis->created_by,
            ], $evidence);
        } catch (DomainException $exception) {
            throw new ConflictHttpException($exception->getMessage());
        }
        return (int) $basis->id;
    }

    private function activity(int $tenantId, int $meetingId, string $action, array $payload): void
    {
        DB::table('governance_meeting_activity')->insert([
            'tenant_id' => $tenantId, 'meeting_id' => $meetingId,
            'actor_id' => (int) auth()->id(), 'action' => $action,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    }

    public function store(Request $request, int $meeting, string $action): JsonResponse
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new NotFoundHttpException('Unsupported GA lifecycle action.');
        }
        $data = $request->validate([
            'source_reference' => ['required', 'string', 'max:2000'],
            'source_sha256' => ['required', 'regex:/^[a-fA-F0-9]{64}$/'],
            'details' => ['nullable', 'array'],
        ]);
        $tenantId = $this->tenantId();
        $meetingRecord = DB::table('governance_meetings')
            ->where('tenant_id', $tenantId)->where('id', $meeting)->first();
        if (!$meetingRecord || $meetingRecord->meeting_kind !== 'general_assembly') {
            throw new NotFoundHttpException('General Assembly meeting was not found in this tenant.');
        }
        $basisId = $this->authorizeEvidence($tenantId, $meeting, $action);
        $id = DB::table('governance_meeting_lifecycle_attestations')->insertGetId([
            'tenant_id' => $tenantId, 'meeting_id' => $meeting, 'legal_basis_id' => $basisId,
            'action' => $action, 'source_reference' => trim($data['source_reference']),
            'source_sha256' => strtolower($data['source_sha256']),
            'details' => isset($data['details']) ? json_encode($data['details'], JSON_THROW_ON_ERROR) : null,
            'status' => 'pending_review', 'submitted_by' => (int) auth()->id(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, $meeting, 'meeting.lifecycle_attestation.submitted', ['attestation_id' => $id, 'action' => $action]);
        return response()->json([
            'success' => true,
            'data' => DB::table('governance_meeting_lifecycle_attestations')
                ->where('tenant_id', $tenantId)->find($id),
        ], 201);
    }

    public function review(Request $request, int $attestation): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:verified,rejected'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $tenantId = $this->tenantId();
        $record = DB::table('governance_meeting_lifecycle_attestations')
            ->where('tenant_id', $tenantId)->where('id', $attestation)->first();
        if (!$record) {
            throw new NotFoundHttpException('Lifecycle attestation was not found.');
        }
        if ($record->status !== 'pending_review') {
            throw new ConflictHttpException('Only pending lifecycle attestations can be reviewed.');
        }
        if ((int) $record->submitted_by === (int) auth()->id()) {
            throw new ConflictHttpException('The lifecycle attestation submitter cannot verify their own submission.');
        }
        $updated = DB::table('governance_meeting_lifecycle_attestations')
            ->where('tenant_id', $tenantId)->where('id', $attestation)->where('status', 'pending_review')
            ->update([
                'status' => $data['decision'], 'reviewed_by' => (int) auth()->id(),
                'reviewed_at' => now(), 'review_reason' => $data['reason'], 'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new ConflictHttpException('Lifecycle attestation was concurrently reviewed.');
        }
        $this->activity($tenantId, (int) $record->meeting_id, 'meeting.lifecycle_attestation.reviewed', ['attestation_id' => $attestation, 'action' => $record->action, 'decision' => $data['decision']]);
        return response()->json([
            'success' => true,
            'data' => DB::table('governance_meeting_lifecycle_attestations')
                ->where('tenant_id', $tenantId)->find($attestation),
        ]);
    }
}
