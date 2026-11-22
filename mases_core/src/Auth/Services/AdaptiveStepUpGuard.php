<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Services;

use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Exceptions\AuthenticationException;

class AdaptiveStepUpGuard
{
    public function __construct(
        private readonly AdaptiveStepUpService $stepUp,
        private readonly AdaptiveStepUpContext $context,
    ) {
    }

    /**
     * Consume the one-time grant only after the caller has resolved the
     * tenant-scoped resource and completed its ordinary authorization checks.
     */
    public function assert(Request $request, string $action, int $tenantId): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw AuthenticationException::unauthenticated();
        }

        $grant = (string) ($request->header('X-ARNA-Step-Up-Token') ?? $request->input('step_up_token', ''));
        $context = $this->context->fromRequest($action, $request);

        if (! $this->stepUp->consumeGrant($user, $tenantId, $action, $context, $grant)) {
            throw new AuthenticationException('Additional authentication is required for this action.', 'AUTH_STEP_UP_REQUIRED', 403);
        }
    }
}
