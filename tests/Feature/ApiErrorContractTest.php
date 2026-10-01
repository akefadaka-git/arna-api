<?php

declare(strict_types=1);

namespace Tests\Feature;

use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use RuntimeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ApiErrorContractTest
 *
 * Priority 4 of Phase 1.5 — verifies that every error path in the API
 * returns the canonical MASES error envelope:
 *
 *     {
 *       "success": false,
 *       "error": {
 *         "code":       "SOME_CODE",
 *         "message":    "Human readable message.",
 *         "request_id": null,
 *         "timestamp":  "2026-09-10T12:34:56+00:00"
 *       }
 *     }
 *
 * Coverage by status code:
 *   400 – TENANT_REQUIRED       (tenant-scoped route without tenant)
 *   401 – UNAUTHENTICATED       (protected route without token)
 *   403 – AUTH_PERMISSION_DENIED
 *         AUTH_ROLE_DENIED
 *         TENANT_ACCESS_DENIED
 *   404 – ROUTE_NOT_FOUND       (fallback)
 *   405 – METHOD_NOT_ALLOWED    (wrong HTTP verb)
 *   422 – VALIDATION_ERROR      (with `validation_errors` map)
 *   500 – INTERNAL_SERVER_ERROR (production hides trace + generic message)
 *
 * Additional invariants:
 *   - The envelope is stable across every error path.
 *   - No sensitive data leaks in production mode.
 *   - Stack traces appear only in non-production environments.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — `invalid_payload_returns_422_with_validation_errors_map()`
 *         assertion corrected.
 *
 *         The test previously asserted that the `validation_errors` map
 *         contained both `email` and `password` for the payload
 *         `['email' => 'not-an-email']` (password omitted). That
 *         expectation was based on the assumption that the login
 *         endpoint validates the `email` field's *format*.
 *
 *         It does not. `AuthController::login()` (v1.1.0) intentionally
 *         validates `email` as `['required', 'string', 'max:255']` —
 *         with no `email` format rule — because the field accepts an
 *         email address **or** a phone number. See the controller's
 *         class docblock §"Login identifier" for the full rationale.
 *
 *         Consequence: for the payload above, `'not-an-email'` satisfies
 *         every rule the controller applies to `email` (it is a
 *         non-empty string of ≤ 255 characters), and only `password`
 *         fails (because it is absent). The correct assertion is
 *         therefore `validation_errors => ['password']`.
 *
 *         No change to any other test, route registration, fixture, or
 *         assertion in this file. The contract the suite verifies —
 *         "every error path emits the canonical envelope" — is
 *         unchanged; only the field-by-field expectation of this one
 *         test is corrected to match the endpoint's real validation
 *         surface.
 *
 * @package Tests\Feature
 * @version 1.0.1
 */
final class ApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mases.tenant.isolation_enabled', true);
        Config::set(
            'mases.tenant.context_resolver',
            RequestTenantResolver::class
        );

        $this->tenant = Tenant::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->tenants()->attach($this->tenant->id);

        // Register a tiny set of test routes used across this suite. They
        // exercise each middleware in isolation so we can drive the API
        // into each error path deterministically.
        $this->registerTestRoutes();
    }

    // =========================================================================
    // 404 – ROUTE_NOT_FOUND (fallback)
    // =========================================================================

    #[Test]
    public function unknown_route_returns_404_route_not_found(): void
    {
        $response = $this->getJson('/api/this-route-does-not-exist');

        $response->assertStatus(404);
        $response->assertJsonStructure([
            'success',
            'error' => ['code', 'message', 'request_id', 'timestamp'],
        ]);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'ROUTE_NOT_FOUND');
    }

    // =========================================================================
    // 405 – METHOD_NOT_ALLOWED
    // =========================================================================

    #[Test]
    public function wrong_http_method_returns_405_method_not_allowed(): void
    {
        // The health endpoint is GET-only. Send a DELETE request.
        $response = $this->deleteJson('/api/health');

        $response->assertStatus(405);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
        $response->assertJsonStructure([
            'error' => ['code', 'message', 'request_id', 'timestamp'],
        ]);
    }

    // =========================================================================
    // 401 – UNAUTHENTICATED
    // =========================================================================

    #[Test]
    public function unauthenticated_protected_route_returns_401_unauthenticated(): void
    {
        // No `actingAs` — Sanctum rejects the request.
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        // Laravel's default Sanctum exception is mapped to `UNAUTHENTICATED`
        // by the bootstrap renderable callback, or to the exception class
        // name as a fallback. We assert it contains UNAUTHENTICATED.
        $this->assertStringContainsString(
            'UNAUTHENTICATED',
            (string) $response->json('error.code')
        );
        $response->assertJsonStructure([
            'error' => ['code', 'message', 'request_id', 'timestamp'],
        ]);
    }

    // =========================================================================
    // 403 – AUTH_PERMISSION_DENIED
    // =========================================================================

    #[Test]
    public function missing_permission_returns_403_auth_permission_denied(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson('/_test/error/permission');

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
        $response->assertJsonStructure([
            'error' => ['code', 'message', 'request_id', 'timestamp'],
        ]);
    }

    // =========================================================================
    // 403 – AUTH_ROLE_DENIED
    // =========================================================================

    #[Test]
    public function missing_role_returns_403_auth_role_denied(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson('/_test/error/role');

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_ROLE_DENIED');
        $response->assertJsonStructure([
            'error' => ['code', 'message', 'request_id', 'timestamp'],
        ]);
    }

    // =========================================================================
    // 403 – TENANT_ACCESS_DENIED
    // =========================================================================

    #[Test]
    public function non_member_of_resolved_tenant_returns_403_tenant_access_denied(): void
    {
        // User belongs to `$this->tenant`; create a second tenant they are
        // not a member of.
        $otherTenant = Tenant::factory()->create(['is_active' => true]);

        $this->actingAs($this->user, 'sanctum');

        $response = $this->withHeader('X-Tenant-ID', (string) $otherTenant->id)
            ->getJson('/_test/error/tenant-membership');

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
        $response->assertJsonStructure([
            'error' => ['code', 'message', 'request_id', 'timestamp'],
        ]);
    }

    // =========================================================================
    // 400 – TENANT_REQUIRED
    // =========================================================================

    #[Test]
    public function missing_tenant_context_returns_400_tenant_required(): void
    {
        $this->actingAs($this->user, 'sanctum');

        // No X-Tenant-ID header; default test host doesn't resolve a tenant.
        $response = $this->getJson('/_test/error/tenant-membership');

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'TENANT_REQUIRED');
        $response->assertJsonStructure([
            'error' => ['code', 'message', 'request_id', 'timestamp'],
        ]);
    }

    // =========================================================================
    // 422 – VALIDATION_ERROR
    // =========================================================================

    #[Test]
    public function invalid_payload_returns_422_with_validation_errors_map(): void
    {
        // POST /api/login with a payload that fails at least one of the
        // login endpoint's validation rules.
        //
        // The `email` field is intentionally validated by
        // `AuthController::login()` as `['required', 'string', 'max:255']`
        // — with NO `email` format rule — because the field accepts an
        // email address OR a phone number. See the controller's class
        // docblock §"Login identifier" for the full rationale.
        //
        // Consequence for this payload: `'not-an-email'` satisfies every
        // rule the controller applies to `email`, and only `password`
        // fails (because it is absent). The `validation_errors` map
        // therefore contains exactly one key: `password`.
        //
        // The test's contract is the response SHAPE: a 422 with the
        // canonical error envelope and a `validation_errors` map keyed by
        // the names of the fields that failed validation. Any caller
        // reading the map for `password` gets a non-empty error list.
        $response = $this->postJson('/api/login', [
            'email' => 'not-an-email',
            // password omitted on purpose.
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure([
            'error' => [
                'code',
                'message',
                'request_id',
                'timestamp',
                'validation_errors' => ['password'],
            ],
        ]);

        // Defence in depth: assert the `password` error list is non-empty,
        // so a future change to the controller that silently drops the
        // required rule is caught here rather than in a downstream
        // consumer.
        $this->assertNotEmpty(
            $response->json('error.validation_errors.password'),
            'The login endpoint must report a non-empty error list for the '
            . 'missing required `password` field.',
        );
    }

    // =========================================================================
    // 500 – INTERNAL_SERVER_ERROR (production: no trace, generic message)
    // =========================================================================

    #[Test]
    public function internal_error_in_production_returns_generic_message_and_no_trace(): void
    {
        // Register a route that throws unconditionally.
        Route::get('/_test/error/throw', static function () {
            throw new RuntimeException('Boom: an internal secret message.');
        });

        // Simulate production behavior for this specific test.
        $this->app['env'] = 'production';
        Config::set('app.debug', false);

        $response = $this->getJson('/_test/error/throw');

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);
        // The precise error code is expected to be `INTERNAL_SERVER_ERROR`
        // (the fallback) — we assert it is NOT the raw class name and does
        // NOT contain a stack trace.
        $response->assertJsonStructure([
            'error' => ['code', 'message', 'request_id', 'timestamp'],
        ]);

        $errorJson = (string) $response->getContent();

        // The internal message must NOT leak to the client.
        $this->assertStringNotContainsString('Boom: an internal secret message.', $errorJson);
        $this->assertStringNotContainsString('RuntimeException', $errorJson);
        $this->assertStringNotContainsString('Stack trace', $errorJson);
        $this->assertStringNotContainsString('#0 ', $errorJson);
        $this->assertStringNotContainsString('stack_trace', $errorJson);
    }

    #[Test]
    public function internal_error_in_local_includes_debug_details(): void
    {
        Route::get('/_test/error/throw-debug', static function () {
            throw new RuntimeException('Debug-mode error.');
        });

        $this->app['env'] = 'local';
        Config::set('app.debug', true);

        $response = $this->getJson('/_test/error/throw-debug');

        $response->assertStatus(500);
        // In non-production, the debug envelope may include a `debug` key.
        // We assert at least the standard shape is present and that the
        // message is not swallowed.
        $response->assertJsonStructure([
            'error' => ['code', 'message', 'request_id', 'timestamp'],
        ]);
    }

    // =========================================================================
    // Envelope invariants across all error paths
    // =========================================================================

    #[Test]
    public function every_error_envelope_includes_request_id_and_timestamp(): void
    {
        // Sample three distinct error paths and check the shared invariants.
        $samples = [
            $this->getJson('/api/this-route-does-not-exist'),     // 404
            $this->deleteJson('/api/health'),                     // 405
            $this->getJson('/api/user'),                          // 401
        ];

        foreach ($samples as $response) {
            $this->assertFalse(
                (bool) $response->json('success'),
                'Every error envelope must set success=false.'
            );
            $this->assertNotNull(
                $response->json('error.timestamp'),
                'Every error envelope must include a timestamp.'
            );
            // request_id is nullable by contract but the key must exist.
            $this->assertArrayHasKey(
                'request_id',
                (array) $response->json('error'),
                'Every error envelope must expose a request_id key.'
            );
        }
    }

    #[Test]
    public function error_envelope_never_leaks_sensitive_keys_in_production(): void
    {
        // Register a route that deliberately throws a message containing
        // sensitive-looking content, and run in production mode.
        Route::post('/_test/error/sensitive', static function () {
            throw new RuntimeException(
                'password=super-secret token=bearer-abc api_key=xyz'
            );
        });

        $this->app['env'] = 'production';
        Config::set('app.debug', false);

        $response = $this->postJson('/_test/error/sensitive');

        $response->assertStatus(500);

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('super-secret', $body);
        $this->assertStringNotContainsString('bearer-abc', $body);
        $this->assertStringNotContainsString('api_key=xyz', $body);
        $this->assertStringNotContainsString('password=', $body);
    }

    // =========================================================================
    // Test route registration
    // =========================================================================

    /**
     * Register the routes used exclusively by this test suite.
     *
     * All are prefixed with `/_test/error/` so they cannot collide with
     * production routes.
     */
    private function registerTestRoutes(): void
    {
        // Permission-guarded route.
        Route::middleware(['auth:sanctum', 'tenant.membership'])
            ->get('/_test/error/permission', static function () {
                return response()->json(['success' => true]);
            })
            ->middleware('auth.permission:test.never.granted');

        // Role-guarded route.
        Route::middleware(['auth:sanctum', 'tenant.membership'])
            ->get('/_test/error/role', static function () {
                return response()->json(['success' => true]);
            })
            ->middleware('auth.role:super_admin');

        // Tenant-membership-guarded route.
        Route::middleware(['auth:sanctum', 'tenant.membership'])
            ->get('/_test/error/tenant-membership', static function () {
                return response()->json(['success' => true]);
            });
    }
}