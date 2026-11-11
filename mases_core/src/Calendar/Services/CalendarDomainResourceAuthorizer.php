<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Calendar\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanCollateral;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareCertificate;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves Calendar references only through an explicit model allowlist and
 * the owning domain's registered Laravel policy. Calendar never grants
 * access to a linked aggregate and never resolves arbitrary class names.
 */
final class CalendarDomainResourceAuthorizer
{
    /** @var array<string, class-string<Model>> */
    private const MODELS = [
        'governance:appointment' => GovernanceAppointment::class,
        'governance:body' => GovernanceBody::class,
        'governance:membership' => GovernanceMembership::class,
        'governance:position' => GovernancePosition::class,
        'governance:term' => GovernanceTerm::class,
        'loan:loan' => Loan::class,
        'loan:product' => LoanProduct::class,
        'loan:collateral' => LoanCollateral::class,
        'shareholder:shareholder' => Shareholder::class,
        'shareholder:share_holding' => ShareHolding::class,
        'shareholder:share_certificate' => ShareCertificate::class,
        'shareholder:share_class' => ShareClass::class,
        'wallet:wallet' => Wallet::class,
        'wallet:obligation' => Obligation::class,
        'wallet:payment' => Payment::class,
    ];

    public function resolveAndAuthorize(User $actor, int|string $tenantId, string $domain, string $resourceType, string $resourceId): Model
    {
        $modelClass = self::MODELS[$domain.':'.$resourceType] ?? null;
        if ($modelClass === null || !class_exists($modelClass)) {
            throw new NotFoundHttpException('Linked domain resource not found.');
        }

        $resource = $modelClass::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($resourceId)
            ->first();

        if (!$resource || Gate::getPolicyFor($resource) === null) {
            throw new NotFoundHttpException('Linked domain resource not found.');
        }

        Gate::forUser($actor)->authorize('view', $resource);

        return $resource;
    }

    public function canView(User $actor, int|string $tenantId, string $domain, string $resourceType, string $resourceId): bool
    {
        try {
            $this->resolveAndAuthorize($actor, $tenantId, $domain, $resourceType, $resourceId);
            return true;
        } catch (AuthorizationException|NotFoundHttpException) {
            return false;
        }
    }
}
