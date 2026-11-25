<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tenant-scoped source register for meeting legal-basis evidence.
 * Recording a citation never verifies it; a different authorized actor
 * must attest that the cited instrument and hash were checked.
 */
final class GovernanceMeetingLegalEvidenceController extends Controller
{
    private function tenantId(): int
    {
        $tenantId = (int) (function_exists('mases_tenant_id') ? mases_tenant_id() : 0);
        if ($tenantId < 1) {
            throw new NotFoundHttpException('Tenant context is required.');
        }
        return $tenantId;
    }

    private function activity(int $tenantId, int $meetingId, string $action, array $payload): void
    {
        DB::table('governance_meeting_activity')->insert([
            'tenant_id' => $tenantId, 'meeting_id' => $meetingId,
            'actor_id' => (int) auth()->id(), 'action' => $action,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    }

    public function storeBasis(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'legal_form' => ['required', 'in:share_company,other,unknown'],
            'meeting_category' => ['required', 'in:ordinary,extraordinary,special'],
            'source_reference' => ['required', 'string', 'max:2000'],
            'source_sha256' => ['required', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);
        $tenantId = $this->tenantId();
        $meetingRecord = DB::table('governance_meetings')->where('tenant_id', $tenantId)->where('id', $meeting)->first();
        if (!$meetingRecord || $meetingRecord->meeting_kind !== 'general_assembly') {
            throw new NotFoundHttpException('General Assembly meeting was not found in this tenant.');
        }
        if (DB::table('governance_meeting_legal_bases')->where('tenant_id', $tenantId)->where('meeting_id', $meeting)->exists()) {
            throw new ConflictHttpException('A legal-basis record already exists. Supersession must be a separately controlled change.');
        }
        $id = DB::table('governance_meeting_legal_bases')->insertGetId([
            'tenant_id' => $tenantId, 'meeting_id' => $meeting,
            'legal_form' => $data['legal_form'], 'meeting_category' => $data['meeting_category'],
            'source_reference' => trim($data['source_reference']),
            'source_sha256' => strtolower($data['source_sha256']), 'status' => 'pending_review',
            'created_by' => (int) auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, $meeting, 'meeting.legal_basis.submitted', ['legal_basis_id' => $id]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_legal_bases')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function storeEvidence(Request $request, int $legalBasis): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:notice,quorum,voting,proxy,penalty,minutes'],
            'evidence_key' => ['required', 'string', 'max:64'],
            'source_reference' => ['required', 'string', 'max:2000'],
            'source_sha256' => ['required', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);
        $required = [
            'notice' => ['notice_rule'],
            'quorum' => ['quorum_rule', 'meeting_category_rule'],
            'voting' => ['voting_rights_rule', 'share_register_basis'],
            'proxy' => ['proxy_formality_rule', 'proxy_authority'],
            'penalty' => ['penalty_authority', 'penalty_due_process'],
            'minutes' => ['minutes_approval_rule', 'minutes_signatory_authority'],
        ];
        if (!in_array($data['evidence_key'], $required[$data['action']], true)) {
            throw ValidationException::withMessages(['evidence_key' => 'Evidence key does not belong to the selected legal action.']);
        }
        $tenantId = $this->tenantId();
        $basis = DB::table('governance_meeting_legal_bases')->where('tenant_id', $tenantId)->where('id', $legalBasis)->first();
        if (!$basis) {
            throw new NotFoundHttpException('Legal-basis record was not found in this tenant.');
        }
        if ($basis->status !== 'verified') {
            throw new ConflictHttpException('Verify the meeting legal-basis record before registering action-specific evidence.');
        }
        if (DB::table('governance_meeting_legal_evidence')->where('tenant_id', $tenantId)->where('legal_basis_id', $legalBasis)->where('evidence_key', $data['evidence_key'])->exists()) {
            throw new ConflictHttpException('Evidence key already exists. Do not overwrite evidence; use a controlled supersession process.');
        }
        $id = DB::table('governance_meeting_legal_evidence')->insertGetId([
            'tenant_id' => $tenantId, 'legal_basis_id' => $legalBasis,
            'action' => $data['action'], 'evidence_key' => $data['evidence_key'],
            'source_reference' => trim($data['source_reference']),
            'source_sha256' => strtolower($data['source_sha256']), 'status' => 'pending_review',
            'created_by' => (int) auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $basis->meeting_id, 'meeting.legal_evidence.submitted', ['legal_basis_id' => $legalBasis, 'evidence_id' => $id, 'action' => $data['action']]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_legal_evidence')->where('tenant_id', $tenantId)->find($id)], 201);
    }

    public function verifyBasis(Request $request, int $legalBasis): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:verified,rejected'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $tenantId = $this->tenantId();
        $basis = DB::table('governance_meeting_legal_bases')->where('tenant_id', $tenantId)->where('id', $legalBasis)->first();
        if (!$basis) {
            throw new NotFoundHttpException('Legal-basis record was not found in this tenant.');
        }
        if ($basis->status !== 'pending_review') {
            throw new ConflictHttpException('Only pending legal-basis records can be reviewed.');
        }
        if ((int) $basis->created_by === (int) auth()->id()) {
            throw new ConflictHttpException('The legal-basis submitter cannot verify their own evidence.');
        }
        DB::table('governance_meeting_legal_bases')->where('tenant_id', $tenantId)->where('id', $legalBasis)->update([
            'status' => $data['decision'], 'verified_by' => (int) auth()->id(),
            'verified_at' => now(), 'review_reason' => $data['reason'], 'updated_at' => now(),
        ]);
        $this->activity($tenantId, (int) $basis->meeting_id, 'meeting.legal_basis.reviewed', ['legal_basis_id' => $legalBasis, 'decision' => $data['decision']]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_legal_bases')->where('tenant_id', $tenantId)->find($legalBasis)]);
    }

    public function verifyEvidence(Request $request, int $evidence): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:verified,rejected'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $tenantId = $this->tenantId();
        $record = DB::table('governance_meeting_legal_evidence')->where('tenant_id', $tenantId)->where('id', $evidence)->first();
        if (!$record) {
            throw new NotFoundHttpException('Legal evidence was not found in this tenant.');
        }
        if ($record->status !== 'pending_review') {
            throw new ConflictHttpException('Only pending evidence can be reviewed.');
        }
        if ((int) $record->created_by === (int) auth()->id()) {
            throw new ConflictHttpException('The evidence submitter cannot verify their own evidence.');
        }
        DB::table('governance_meeting_legal_evidence')->where('tenant_id', $tenantId)->where('id', $evidence)->update([
            'status' => $data['decision'], 'approved_by' => (int) auth()->id(),
            'approved_at' => now(), 'review_reason' => $data['reason'], 'updated_at' => now(),
        ]);
        $basis = DB::table('governance_meeting_legal_bases')->where('tenant_id', $tenantId)->where('id', $record->legal_basis_id)->first();
        $this->activity($tenantId, (int) $basis->meeting_id, 'meeting.legal_evidence.reviewed', ['evidence_id' => $evidence, 'decision' => $data['decision']]);
        return response()->json(['success' => true, 'data' => DB::table('governance_meeting_legal_evidence')->where('tenant_id', $tenantId)->find($evidence)]);
    }
}
