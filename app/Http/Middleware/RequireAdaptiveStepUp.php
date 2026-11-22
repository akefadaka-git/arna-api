<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Auth\Services\AdaptiveStepUpContext;
use MaaleArna\MASES\Core\Auth\Services\AdaptiveStepUpService;
use MaaleArna\MASES\Core\Exceptions\AuthenticationException;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdaptiveStepUp
{
    public function __construct(
        private readonly AdaptiveStepUpService $stepUp,
        private readonly AdaptiveStepUpContext $context,
    ) {
    }

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw AuthenticationException::unauthenticated();
        }

        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        $tenantId = is_numeric($tenantId) && (int) $tenantId > 0
            ? (int) $tenantId
            : (int) ($user->default_tenant_id ?? 0);

        if ($tenantId < 1) {
            throw new AuthenticationException('A verified tenant context is required.', 'AUTH_TENANT_CONTEXT_REQUIRED', 403);
        }

        $grant = (string) ($request->header('X-ARNA-Step-Up-Token') ?? $request->input('step_up_token', ''));
        $context = $this->context->fromRequest($action, $request);

        if (! $this->stepUp->consumeGrant($user, $tenantId, $action, $context, $grant)) {
            throw new AuthenticationException('Additional authentication is required for this action.', 'AUTH_STEP_UP_REQUIRED', 403);
        }

        return $next($request);
    }
}
