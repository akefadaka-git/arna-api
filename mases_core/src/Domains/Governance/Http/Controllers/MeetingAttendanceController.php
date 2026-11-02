<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Domains\Governance\Services\MeetingAttendanceService;

final class MeetingAttendanceController extends Controller
{
    public function __construct(private readonly MeetingAttendanceService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->list($request->only('status', 'meeting_kind', 'per_page'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'governance_body_id' => ['required', 'integer', 'min:1'],
            'finance_legal_entity_id' => ['nullable', 'integer', 'min:1'],
            'meeting_kind' => ['required', Rule::in(['ga', 'board'])],
            'title_am' => ['nullable', 'string', 'max:180'],
            'title_en' => ['nullable', 'string', 'max:180'],
            'scheduled_at' => ['required', 'date'],
            'timezone' => ['nullable', 'timezone'],
        ]);
        return response()->json(['success' => true, 'data' => $this->service->create($data)], 201);
    }

    public function show(int $meeting): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->show($meeting)]);
    }

    public function start(int $meeting): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->start($meeting)]);
    }

    public function submitProxy(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'principal_membership_id' => ['required', 'integer', 'min:1'],
            'representative_membership_id' => ['nullable', 'integer', 'min:1'],
            'representative_name' => ['required', 'string', 'max:180'],
            'representative_contact' => ['nullable', 'string', 'max:100'],
            'instrument_reference' => ['required', 'string', 'max:120'],
            'document_path' => ['nullable', 'string', 'max:500'],
            'appointed_at' => ['required', 'date'],
        ]);
        return response()->json(['success' => true, 'data' => $this->service->submitProxy($meeting, $data)], 201);
    }

    public function reviewProxy(Request $request, int $meeting, int $proxy): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['success' => true, 'data' => $this->service->reviewProxy($meeting, $proxy, $data)]);
    }

    public function recordArrival(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'membership_id' => ['required', 'integer', 'min:1'],
            'proxy_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['success' => true, 'data' => $this->service->recordArrival($meeting, $data)], 201);
    }

    public function authorizeAbsence(Request $request, int $meeting): JsonResponse
    {
        $data = $request->validate([
            'membership_id' => ['required', 'integer', 'min:1'],
            'authorization_reference' => ['required', 'string', 'max:180'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        return response()->json(['success' => true, 'data' => $this->service->authorizeAbsence($meeting, $data)]);
    }

    public function close(int $meeting): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->close($meeting)]);
    }

    public function reviewPenalty(Request $request, int $meeting, int $penalty): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['assess', 'waive'])],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        return response()->json(['success' => true, 'data' => $this->service->reviewPenalty($meeting, $penalty, $data)]);
    }

    public function disputePenalty(Request $request, int $meeting, int $penalty): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        return response()->json(['success' => true, 'data' => $this->service->disputePenalty($meeting, $penalty, $data)]);
    }

    public function recordPayment(Request $request, int $meeting, int $penalty): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'mobile_money', 'other'])],
            'receipt_reference' => ['required', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date'],
        ]);
        return response()->json(['success' => true, 'data' => $this->service->recordPayment($meeting, $penalty, $data)], 201);
    }

    public function financeReconciliationQueue(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->financeReconciliationQueue()]);
    }

    public function reconcilePayment(Request $request, int $meeting, int $payment): JsonResponse
    {
        $data = $request->validate(['finance_reference' => ['required', 'integer', 'min:1']]);
        return response()->json(['success' => true, 'data' => $this->service->reconcilePayment($meeting, $payment, (int) $data['finance_reference'])]);
    }

    public function reviewDividendSettlement(Request $request, int $meeting, int $settlement): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'authority_reference' => ['required_if:decision,approve', 'nullable', 'string', 'max:180'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        return response()->json(['success' => true, 'data' => $this->service->reviewDividendSettlement($meeting, $settlement, $data)]);
    }

    public function reconcileDividendSettlement(Request $request, int $meeting, int $settlement): JsonResponse
    {
        $data = $request->validate(['finance_reference' => ['required', 'integer', 'min:1']]);
        return response()->json(['success' => true, 'data' => $this->service->reconcileDividendSettlement($meeting, $settlement, (int) $data['finance_reference'])]);
    }

    public function requestDividendOffset(Request $request, int $meeting, int $penalty): JsonResponse
    {
        $data = $request->validate([
            'shareholder_id' => ['required', 'integer', 'min:1'],
            'dividend_reference' => ['required', 'string', 'max:160'],
            'requested_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);
        return response()->json(['success' => true, 'data' => $this->service->requestDividendOffset($meeting, $penalty, $data)], 201);
    }
}
