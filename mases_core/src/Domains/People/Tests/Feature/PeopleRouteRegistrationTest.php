<?php
declare(strict_types=1);
namespace Tests\Feature;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
final class PeopleRouteRegistrationTest extends TestCase {
 #[Test]
 public function people_routes_have_authentication_and_tenant_middleware(): void {
  $route=Route::getRoutes()->getByName('api.people.index');
  $this->assertNotNull($route);
  $this->assertSame('api/people',trim($route->uri(),'/'));
  $this->assertContains('auth:sanctum',$route->middleware());
  $this->assertContains('tenant.membership',$route->gatherMiddleware());
 }
 #[Test]
 public function people_mutations_keep_the_explicit_permission_gate(): void {
  $route=Route::getRoutes()->getByName('api.people.contacts.store');
  $this->assertNotNull($route);
  $this->assertContains('auth.permission:people.manage',$route->gatherMiddleware());
 }
}
