<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Tests\\Feature;

use Illuminate\\Support\\Facades\\Route;
use PHPUnit\\Framework\\Attributes\\Test;
use Tests\\TestCase;

final class FinanceRouteRegistrationTest extends TestCase
{
    /**
     * The route contract for the Finance domain-owned API route file.
     *
     * This is a source-level regression guard when the application test suite
     * is run; it does not perform HTTP requests or access the database.
     */
    #[Test]
    public function test_finance_routes_are_registered_once_with_expected_paths_and_names(): void
    {
        $expected = [
            'api.finance.entities.index' => ['GET', 'api/finance/entities'],
            'api.finance.entities.store' => ['POST', 'api/finance/entities'],
            'api.finance.source-mappings.index' => ['GET', 'api/finance/source-mappings'],
            'api.finance.source-mappings.store' => ['POST', 'api/finance/source-mappings'],
            'api.finance.source-mappings.approve' => ['POST', 'api/finance/source-mappings/{mappingId}/approve'],
            'api.finance.source-mappings.revoke' => ['POST', 'api/finance/source-mappings/{mappingId}/revoke'],
            'api.finance.accounts.index' => ['GET', 'api/finance/accounts'],
            'api.finance.accounts.store' => ['POST', 'api/finance/accounts'],
            'api.finance.periods.index' => ['GET', 'api/finance/periods'],
            'api.finance.periods.store' => ['POST', 'api/finance/periods'],
            'api.finance.periods.close' => ['POST', 'api/finance/periods/{periodId}/close'],
            'api.finance.journals.index' => ['GET', 'api/finance/journals'],
            'api.finance.journals.store' => ['POST', 'api/finance/journals'],
            'api.finance.journals.cancel' => ['POST', 'api/finance/journals/{journalId}/cancel'],
            'api.finance.journals.post' => ['POST', 'api/finance/journals/{journalId}/post'],
            'api.finance.journals.reverse' => ['POST', 'api/finance/journals/{journalId}/reverse'],
            'api.finance.reports.trial-balance' => ['GET', 'api/finance/trial-balance'],
        ];

        $routes = Route::getRoutes()->getRoutes();
        $financeRoutes = array_values(array_filter(
            $routes,
            static fn ($route): bool => str_starts_with((string) $route->getName(), 'api.finance.')
        ));

        $this->assertCount(
            count($expected),
            $financeRoutes,
            'Finance route count changed; check for missing routes or duplicate method/URI registrations.'
        );

        $identities = [];
        foreach ($financeRoutes as $route) {
            $identity = implode('|', [$route->methods()[0] ?? '', $route->uri()]);
            $identities[] = $identity;
        }
        $this->assertCount(
            count(array_unique($identities)),
            $identities,
            'Finance routes must not register duplicate method/URI identities.'
        );

        foreach ($expected as $name => [$method, $uri]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Missing Finance route [{$name}].");
            $this->assertSame($uri, $route->uri(), "Unexpected URI for [{$name}].");
            $this->assertContains($method, $route->methods(), "Unexpected HTTP method for [{$name}].");
            $this->assertStringContainsString(
                'FinanceController@',
                $route->getActionName(),
                "Finance route [{$name}] must target the Finance controller."
            );
        }
    }

    #[Test]
    public function test_finance_routes_retain_authentication_tenant_and_permission_middleware(): void
    {
        $route = Route::getRoutes()->getByName('api.finance.entities.index');

        $this->assertNotNull($route, 'Finance entity index route must be registered.');

        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth:sanctum', $middleware);
        $this->assertContains('tenant.membership', $middleware);
        $this->assertContains('auth.permission:finance.entity.view', $middleware);
    }
}
