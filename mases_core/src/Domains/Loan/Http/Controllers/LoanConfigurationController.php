<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanConfigurationService;

final class LoanConfigurationController
{
    public function __construct(
        private readonly LoanConfigurationService $configurations,
        private readonly AuthorizationService $authorization,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $tenantId = $this->tenantId();
        if (!$this->authorization->hasPermission('loan.configuration.view', $actor, $tenantId, 'loan')) {
            abort(403, 'You are not authorized to perform this loan configuration action.');
        }

        $submissions = $this->configurations->listForTenant($this->tenantId(), $this->actor($request));
        return response()->json([
            'success' => true,
            'data' => $submissions->items(),
            'meta' => [
                'current_page' => $submissions->currentPage(),
                'last_page' => $submissions->lastPage(),
                'per_page' => $submissions->perPage(),
                'total' => $submissions->total(),
            ],
        ]);
    }

    public function update(Request $request, int $submission): JsonResponse
    {
        $actor = $this->actor($request);
        $tenantId = $this->tenantId();
        if (!$this->authorization->hasPermission('loan.configuration.manage', $actor, $tenantId, 'loan')) {
            abort(403, 'You are not authorized to perform this loan configuration action.');
        }

        $result = $this->configurations->updateDraft(
            $this->tenantId(),
            $submission,
            $this->actor($request),
            (array) $request->input('configuration', []),
        );
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $tenantId = $this->tenantId();
        if (!$this->authorization->hasPermission('loan.configuration.manage', $actor, $tenantId, 'loan')) {
            abort(403, 'You are not authorized to perform this loan configuration action.');
        }

        $submission = $this->configurations->createDraft(
            $this->tenantId(),
            $this->actor($request),
            (array) $request->input('configuration', []),
        );

        return response()->json(['success' => true, 'data' => $submission], 201);
    }

    public function submit(Request $request, int $submission): JsonResponse
    {
        $actor = $this->actor($request);
        $tenantId = $this->tenantId();
        if (!$this->authorization->hasPermission('loan.configuration.submit', $actor, $tenantId, 'loan')) {
            abort(403, 'You are not authorized to perform this loan configuration action.');
        }

        $result = $this->configurations->submit($this->tenantId(), $submission, $this->actor($request));
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function review(Request $request, int $submission): JsonResponse
    {
        $actor = $this->actor($request);
        $tenantId = $this->tenantId();
        if (!$this->authorization->hasPermission('loan.configuration.approve', $actor, $tenantId, 'loan')) {
            abort(403, 'You are not authorized to perform this loan configuration action.');
        }

        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = $this->configurations->review(
            $this->tenantId(),
            $submission,
            $this->actor($request),
            (bool) $validated['approve'],
            $validated['note'] ?? null,
        );
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function activate(Request $request, int $submission): JsonResponse
    {
        $actor = $this->actor($request);
        $tenantId = $this->tenantId();
        if (!$this->authorization->hasPermission('loan.configuration.activate', $actor, $tenantId, 'loan')) {
            abort(403, 'You are not authorized to perform this loan configuration action.');
        }

        $result = $this->configurations->activate($this->tenantId(), $submission, $this->actor($request));
        return response()->json(['success' => true, 'data' => $result]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (!$actor instanceof User) {
            abort(401);
        }
        return $actor;
    }

    private function tenantId(): int
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        if ($tenantId === null || $tenantId === '') {
            throw new LogicException('Loan configuration endpoints require tenant.membership middleware.');
        }
        return (int) $tenantId;
    }
}
