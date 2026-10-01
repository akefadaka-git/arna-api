<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AuthenticationLifecycleTest
 *
 * Comprehensive test suite for the complete authentication lifecycle of the MASES API.
 *
 * This suite verifies:
 * - Login with valid/invalid credentials and tenant access
 * - Logout and token revocation (including cache blacklist)
 * - Token refresh (including cache blacklist)
 * - Password reset request and reset with valid/invalid tokens
 * - Email verification (verify and resend)
 * - Account deactivation (inactive users cannot log in)
 * - Registration with automatic login and duplicate email handling
 * - Unauthenticated access to protected routes
 * - Authenticated profile access
 *
 * All tests use the `RefreshDatabase` trait to ensure a clean database state
 * for each test, and the `WithFaker` trait for generating test data.
 *
 * -----------------------------------------------------------------------------
 * Envelope contract (Phase 2A CR-003a / CR-021a)
 * -----------------------------------------------------------------------------
 * The `/api/user` endpoint returns the canonical MASES envelope:
 *
 *     { "success": true, "data": { "id": …, "name": …, "email": …, … } }
 *
 * Individual user fields are nested under `data`. The assertion in
 * `a_user_can_login_with_valid_credentials()` uses `assertJsonPath('data.email', …)`
 * accordingly. This file was previously asserting a root-level `email` field;
 * CR-021a aligns it with the current envelope.
 *
 * -----------------------------------------------------------------------------
 * Password reset neutrality (v1.2.1)
 * -----------------------------------------------------------------------------
 * The two password-reset-request tests assert the endpoint's NEUTRAL
 * contract, documented in `AuthController`'s class docblock
 * §"Password reset neutrality":
 *
 *   - A request for a registered email returns 200 with the neutral
 *     message "If an account exists for that email address, a password
 *     reset link has been sent."
 *
 *   - A request for an UNREGISTERED email returns the SAME 200 with the
 *     SAME neutral message. It does NOT return 422, because a 422 keyed
 *     on `email` would leak whether the address is registered.
 *
 * The pair of tests together pins the invariant: identical response
 * shape and status across the two cases, distinguished only by the
 * server-side side effect (a row in `password_reset_tokens` exists only
 * for a registered email).
 *
 * -----------------------------------------------------------------------------
 * Revision history
 * -----------------------------------------------------------------------------
 * 1.2.0 — baseline. Envelope contract alignment (CR-021a).
 *
 * 1.2.1 — password-reset neutrality alignment:
 *
 *           · `a_user_can_request_a_password_reset_link()` now asserts
 *             the v1.1.0 neutral message string, matching the endpoint's
 *             actual response.
 *
 *           · `a_user_cannot_request_a_password_reset_link_for_nonexistent_email()`
 *             no longer asserts 422 + `assertJsonValidationErrors`.
 *             The endpoint deliberately returns 200 with the neutral
 *             message for an unregistered email; the test now asserts
 *             that behaviour, and documents it with an inline rationale
 *             that points at the controller's class docblock.
 *
 *         No change to any other test method, fixture, setup step, or
 *         import. The `$this->user` fixture (`test@example.com`) and
 *         `$this->tenant` fixture remain unchanged.
 *
 * 1.2.2 — D-15 / H-13 alignment. The unauthenticated-request test now
 *         asserts the canonical MASES 401 code `AUTH_UNAUTHENTICATED`
 *         rather than the historical `UNAUTHENTICATED`.
 *
 *         Background: prior to D-15, `App\Http\Middleware\Authenticate`
 *         threw `Illuminate\Auth\AuthenticationException`, which the
 *         canonical renderer in `bootstrap/app.php` mapped to the code
 *         `UNAUTHENTICATED`. That string had no `AUTH_` prefix and was
 *         the only 401 code in the platform not drawn from the
 *         `MaaleArna\MASES\Core\Exceptions\AuthenticationException`
 *         taxonomy. Client code that branched on `error.code` could not
 *         treat it as the same condition as the codes produced by
 *         application-layer authentication code.
 *
 *         D-15 changed `Authenticate` to throw MASES
 *         `AuthenticationException::unauthenticated()` (and, on the
 *         revoked-token path, `::invalidToken()`). The renderer's
 *         `CoreException` branch maps both to canonical `AUTH_*` codes.
 *
 *         This file's only affected assertion is in
 *         `unauthenticated_user_cannot_access_protected_routes()`; it is
 *         updated from `UNAUTHENTICATED` to `AUTH_UNAUTHENTICATED`. No
 *         other assertion in this file changes.
 *
 *         The revoked-token path is exercised by
 *         `a_user_can_logout_and_tokens_are_revoked()` and
 *         `a_user_can_refresh_their_token()`, both of which continue to
 *         assert 401 status but do NOT assert a specific `error.code`.
 *         They are therefore unaffected by the code change. Adding an
 *         assertion for `AUTH_TOKEN_INVALID` on those tests is a
 *         separate scope decision (T-2), not part of this change.
 *
 * @package Tests\Feature
 * @author  MASES Development Team
 * @version 1.2.2
 */
final class AuthenticationLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    /**
     * The tenant used for the primary test user.
     *
     * @var Tenant
     */
    private Tenant $tenant;

    /**
     * The primary test user, active and email-verified.
     *
     * @var User
     */
    private User $user;

    // =========================================================================
    // Setup
    // =========================================================================

    /**
     * Set up the test environment before each test.
     *
     * Creates a tenant and a user with known credentials, and attaches
     * the user to the tenant. This provides a consistent baseline for
     * all authentication tests.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create an active tenant.
        $this->tenant = Tenant::factory()->create(['is_active' => true]);

        // Create a user with a known email, password, and active status.
        $this->user = User::factory()->create([
            'email'             => 'test@example.com',
            'password'          => Hash::make('Password123!'),
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        // Attach the user to the tenant so they can log in with tenant context.
        $this->user->tenants()->attach($this->tenant->id);
    }

    // =========================================================================
    // Login Tests
    // =========================================================================

    /**
     * Test that a user can log in with valid credentials and tenant context.
     *
     * Verifies:
     * - Response status is 200.
     * - Response structure includes user data, token, and token_type.
     * - Token is valid and can be used to access a protected route.
     *
     * @return void
     */
    #[Test]
    public function a_user_can_login_with_valid_credentials(): void
    {
        // Send login request with valid email, password, and tenant ID.
        $response = $this->postJson('/api/login', [
            'email'     => 'test@example.com',
            'password'  => 'Password123!',
            'tenant_id' => $this->tenant->id,
        ]);

        // Assert response status and structure.
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'user' => ['id', 'name', 'email'],
                'token',
                'token_type',
            ],
        ]);
        $response->assertJson([
            'success' => true,
            'data'    => [
                'user'       => ['email' => 'test@example.com'],
                'token_type' => 'Bearer',
            ],
        ]);

        // Extract token and verify it can access a protected endpoint.
        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        $protectedResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user');

        $protectedResponse->assertStatus(200);

        // The `/api/user` endpoint returns the canonical MASES envelope:
        // {success: true, data: {email: …}}. Assert on the nested path.
        $protectedResponse->assertJsonPath('data.email', 'test@example.com');
    }

    /**
     * Test that a user cannot log in with an incorrect password.
     *
     * Expects a 401 Unauthorized response with the appropriate error code.
     *
     * @return void
     */
    #[Test]
    public function a_user_cannot_login_with_invalid_password(): void
    {
        $response = $this->postJson('/api/login', [
            'email'     => 'test@example.com',
            'password'  => 'WrongPassword!',
            'tenant_id' => $this->tenant->id,
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'error'   => ['code' => 'AUTH_INVALID_CREDENTIALS'],
        ]);
    }

    /**
     * Test that a user cannot log in with a non-existent email.
     *
     * Expects a 401 Unauthorized response with the same error code as invalid
     * credentials to avoid leaking user existence information.
     *
     * @return void
     */
    #[Test]
    public function a_user_cannot_login_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/login', [
            'email'     => 'nonexistent@example.com',
            'password'  => 'Password123!',
            'tenant_id' => $this->tenant->id,
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'error'   => ['code' => 'AUTH_INVALID_CREDENTIALS'],
        ]);
    }

    /**
     * Test that an inactive user cannot log in.
     *
     * A user with `is_active = false` should be denied access, even with
     * correct credentials. Expects a 401 with the same generic error code.
     *
     * @return void
     */
    #[Test]
    public function an_inactive_user_cannot_login(): void
    {
        // Create an inactive user.
        $inactiveUser = User::factory()->create([
            'email'             => 'inactive@example.com',
            'password'          => Hash::make('Password123!'),
            'is_active'         => false,
            'email_verified_at' => now(),
        ]);
        $inactiveUser->tenants()->attach($this->tenant->id);

        // Attempt login with the inactive user's credentials.
        $response = $this->postJson('/api/login', [
            'email'     => 'inactive@example.com',
            'password'  => 'Password123!',
            'tenant_id' => $this->tenant->id,
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'error'   => ['code' => 'AUTH_INVALID_CREDENTIALS'],
        ]);
    }

    /**
     * Test that a user cannot log in to a tenant they are not a member of.
     *
     * Even with valid credentials, if the user is not a member of the
     * specified tenant, access should be denied with a 403 Forbidden status
     * and a specific tenant access error code.
     *
     * @return void
     */
    #[Test]
    public function a_user_cannot_login_to_a_tenant_they_are_not_a_member_of(): void
    {
        // Create a different tenant that the user does not belong to.
        $otherTenant = Tenant::factory()->create(['is_active' => true]);

        // Attempt login with the user's credentials but the other tenant ID.
        $response = $this->postJson('/api/login', [
            'email'     => 'test@example.com',
            'password'  => 'Password123!',
            'tenant_id' => $otherTenant->id,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error'   => ['code' => 'AUTH_TENANT_ACCESS_DENIED'],
        ]);
    }

    // =========================================================================
    // Logout & Token Revocation
    // =========================================================================

    /**
     * Test that a user can log out and their tokens are revoked.
     *
     * After a successful login, the token should work. After logout,
     * the same token should be invalid (401). The token should also
     * be present in the cache blacklist.
     *
     * @return void
     */
    #[Test]
    public function a_user_can_logout_and_tokens_are_revoked(): void
    {
        // Login and obtain a token.
        $loginResponse = $this->postJson('/api/login', [
            'email'     => 'test@example.com',
            'password'  => 'Password123!',
            'tenant_id' => $this->tenant->id,
        ]);
        $token = $loginResponse->json('data.token');

        // Confirm token works before logout.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user')
            ->assertStatus(200);

        // Logout using the same token.
        $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout');

        $logoutResponse->assertStatus(200);
        $logoutResponse->assertJson([
            'success' => true,
            'message' => 'Successfully logged out.',
        ]);

        // Verify the token has been added to the cache blacklist.
        $hashedToken = hash('sha256', $token);
        $this->assertTrue(
            Cache::has('revoked_token:' . $hashedToken),
            'Token was not added to the revocation cache.'
        );

        // Verify the token is no longer accepted.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    /**
     * Test that a user can refresh their token.
     *
     * A new token should be generated, the old token should become invalid,
     * and the new token should be usable. The old token should also be
     * blacklisted in the cache.
     *
     * @return void
     */
    #[Test]
    public function a_user_can_refresh_their_token(): void
    {
        // Login and obtain an initial token.
        $loginResponse = $this->postJson('/api/login', [
            'email'     => 'test@example.com',
            'password'  => 'Password123!',
            'tenant_id' => $this->tenant->id,
        ]);
        $oldToken = $loginResponse->json('data.token');

        // Request a token refresh.
        $refreshResponse = $this->withHeader('Authorization', 'Bearer ' . $oldToken)
            ->postJson('/api/token/refresh');

        $refreshResponse->assertStatus(200);
        $refreshResponse->assertJsonStructure([
            'success',
            'data' => ['token', 'token_type'],
        ]);

        $newToken = $refreshResponse->json('data.token');
        $this->assertNotEquals($oldToken, $newToken, 'New token should differ from old token.');

        // Verify the old token has been added to the cache blacklist.
        $hashedOldToken = hash('sha256', $oldToken);
        $this->assertTrue(
            Cache::has('revoked_token:' . $hashedOldToken),
            'Old token was not added to the revocation cache.'
        );

        // Old token should be revoked.
        $this->withHeader('Authorization', 'Bearer ' . $oldToken)
            ->getJson('/api/user')
            ->assertStatus(401);

        // New token should work.
        $this->withHeader('Authorization', 'Bearer ' . $newToken)
            ->getJson('/api/user')
            ->assertStatus(200);
    }

    // =========================================================================
    // Password Reset Tests
    // =========================================================================

    /**
     * Test that a user can request a password reset link.
     *
     * A valid email should trigger the creation of a password reset token
     * and return the neutral success response. The token should exist in
     * the database.
     *
     * The response message is the v1.1.0 NEUTRAL message, documented in
     * `AuthController`'s class docblock §"Password reset neutrality". The
     * endpoint does not disclose whether the email is registered; the
     * neutral message is the same for both cases, and only the server-side
     * side effect (a row in `password_reset_tokens`) distinguishes them.
     *
     * @return void
     */
    #[Test]
    public function a_user_can_request_a_password_reset_link(): void
    {
        $response = $this->postJson('/api/password/email', [
            'email' => 'test@example.com',
        ]);

        // The endpoint returns the neutral message. See the class docblock
        // §"Password reset neutrality" for the rationale: a
        // registered-vs-unregistered distinction in the response would
        // leak registration status to the caller.
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'If an account exists for that email address, a password reset link has been sent.',
        ]);

        // Verify a token was created for this email. This is the
        // server-side effect that distinguishes the registered case from
        // the unregistered case; it is NOT observable to the client.
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * Test that a password reset request for a non-existent email is
     * indistinguishable from the registered case.
     *
     * The endpoint deliberately returns the SAME 200 with the SAME neutral
     * message for an unregistered email, because a distinct response (a
     * 422, a validation error keyed on `email`, a different message)
     * would let a caller probe the user table for registered addresses.
     *
     * See `AuthController`'s class docblock §"Password reset neutrality"
     * for the full rationale. This test pins the invariant:
     *
     *   - status 200
     *   - the neutral message
     *   - no `password_reset_tokens` row (the server-side side effect
     *     does NOT occur for an unregistered email)
     *
     * The first two are observable to the client and MUST match the
     * registered case exactly. The third is a defensive assertion of the
     * server-side behaviour; it is not observable to the client and
     * exists to catch a future regression in the broker wiring.
     *
     * @return void
     */
    #[Test]
    public function a_user_cannot_request_a_password_reset_link_for_nonexistent_email(): void
    {
        $response = $this->postJson('/api/password/email', [
            'email' => 'nonexistent@example.com',
        ]);

        // The endpoint returns the SAME neutral 200 as the registered
        // case. A distinct status or a validation error would leak
        // registration status. See the class docblock for the rationale.
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'If an account exists for that email address, a password reset link has been sent.',
        ]);

        // Defensive: no token row is created for an unregistered email.
        // This is the server-side fact that the neutral response conceals;
        // a future regression that started writing a token for a
        // nonexistent user would be caught here.
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'nonexistent@example.com',
        ]);
    }

    /**
     * Test that a user can reset their password with a valid token.
     *
     * A valid token should allow the user to set a new password. The old password
     * should be invalid after the reset, and the token should be removed from the
     * password_reset_tokens table.
     *
     * @return void
     */
    #[Test]
    public function a_user_can_reset_password_with_valid_token(): void
    {
        // Generate a valid password reset token for the user.
        $token = Password::createToken($this->user);
        $newPassword = 'NewPassword456!';

        // Send the reset request with the new password and token.
        $response = $this->postJson('/api/password/reset', [
            'email'                 => 'test@example.com',
            'password'              => $newPassword,
            'password_confirmation' => $newPassword,
            'token'                 => $token,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Password has been reset successfully.',
        ]);

        // Verify the password was updated.
        $this->user->refresh();
        $this->assertTrue(
            Hash::check($newPassword, $this->user->password),
            'Password was not updated correctly.'
        );

        // Verify the reset token was deleted.
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * Test that a user cannot reset their password with an invalid token.
     *
     * An invalid token should result in a 422 validation error on the email field
     * (the password broker returns a generic error for security).
     *
     * @return void
     */
    #[Test]
    public function a_user_cannot_reset_password_with_invalid_token(): void
    {
        $response = $this->postJson('/api/password/reset', [
            'email'                 => 'test@example.com',
            'password'              => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
            'token'                 => 'invalid-token',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    // =========================================================================
    // Email Verification Tests
    // =========================================================================

    /**
     * Test that a user can verify their email with a valid hash.
     *
     * The hash is the SHA1 of the user's email. On success, `email_verified_at`
     * should be set to the current timestamp.
     *
     * @return void
     */
    #[Test]
    public function a_user_can_verify_their_email_with_valid_hash(): void
    {
        // Create an unverified user.
        $unverifiedUser = User::factory()->create([
            'email'             => 'unverified@example.com',
            'email_verified_at' => null,
            'password'          => Hash::make('Password123!'),
            'is_active'         => true,
        ]);
        $unverifiedUser->tenants()->attach($this->tenant->id);

        // Calculate the expected hash.
        $hash = sha1($unverifiedUser->getEmailForVerification());

        // Call the verification endpoint.
        $response = $this->getJson("/api/verify-email/{$unverifiedUser->id}/{$hash}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Email verified successfully.',
        ]);

        // Confirm that email_verified_at is now set.
        $unverifiedUser->refresh();
        $this->assertNotNull($unverifiedUser->email_verified_at);
    }

    /**
     * Test that a user cannot verify their email with an invalid hash.
     *
     * An incorrect hash should result in a 401 Unauthorized response with
     * the appropriate error code.
     *
     * @return void
     */
    #[Test]
    public function a_user_cannot_verify_email_with_invalid_hash(): void
    {
        $unverifiedUser = User::factory()->create([
            'email'             => 'unverified@example.com',
            'email_verified_at' => null,
            'password'          => Hash::make('Password123!'),
            'is_active'         => true,
        ]);

        // Use an invalid hash string.
        $response = $this->getJson("/api/verify-email/{$unverifiedUser->id}/invalid-hash");

        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'error'   => ['code' => 'AUTH_INVALID_VERIFICATION_TOKEN'],
        ]);
    }

    /**
     * Test that a user can resend the verification email.
     *
     * For an unverified user, a resend request should succeed and return
     * a success message.
     *
     * @return void
     */
    #[Test]
    public function a_user_can_resend_verification_email(): void
    {
        $unverifiedUser = User::factory()->create([
            'email'             => 'unverified@example.com',
            'email_verified_at' => null,
            'password'          => Hash::make('Password123!'),
            'is_active'         => true,
        ]);

        $response = $this->postJson('/api/verify-email/resend', [
            'email' => 'unverified@example.com',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Verification email resent successfully.',
        ]);
    }

    /**
     * Test that a user cannot resend verification email if already verified.
     *
     * A user whose email is already verified should receive a 400 Bad Request
     * with an appropriate error code.
     *
     * @return void
     */
    #[Test]
    public function a_user_cannot_resend_verification_email_for_already_verified_user(): void
    {
        // The primary user `test@example.com` is already verified.
        $response = $this->postJson('/api/verify-email/resend', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error'   => ['code' => 'AUTH_EMAIL_ALREADY_VERIFIED'],
        ]);
    }

    // =========================================================================
    // Protected Route Access
    // =========================================================================

    /**
     * Test that an unauthenticated user cannot access protected routes.
     *
     * A request to a protected endpoint without a valid token should result
     * in a 401 Unauthorized response with the canonical MASES error code
     * `AUTH_UNAUTHENTICATED`.
     *
     * Prior to D-15, the middleware threw Laravel's own
     * `Illuminate\Auth\AuthenticationException`, which the canonical
     * renderer mapped to the code `UNAUTHENTICATED`. That string had no
     * `AUTH_` prefix and was the only 401 code in the platform not drawn
     * from the `MaaleArna\MASES\Core\Exceptions\AuthenticationException`
     * taxonomy. D-15 aligned the middleware's exception family with the
     * rest of MASES, so both this HTTP-layer path and the application-
     * layer paths now produce canonical `AUTH_*` codes. See the class
     * docblock §"Revision history" for the full rationale.
     *
     * @return void
     */
    #[Test]
    public function unauthenticated_user_cannot_access_protected_routes(): void
    {
        $response = $this->getJson('/api/user');
        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'error'   => ['code' => 'AUTH_UNAUTHENTICATED'],
        ]);
    }

    /**
     * Test that an authenticated user can access their profile.
     *
     * A valid token should grant access to the `/api/profile` endpoint,
     * returning the full user profile including tenants, roles, and permissions.
     *
     * @return void
     */
    #[Test]
    public function authenticated_user_can_access_their_profile(): void
    {
        // Login and get token.
        $loginResponse = $this->postJson('/api/login', [
            'email'     => 'test@example.com',
            'password'  => 'Password123!',
            'tenant_id' => $this->tenant->id,
        ]);
        $token = $loginResponse->json('data.token');

        // Access profile with the token.
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/profile');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data'    => [
                'email' => 'test@example.com',
                'name'  => $this->user->name,
            ],
        ]);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'name',
                'email',
                'locale',
                'default_tenant_id',
                'is_active',
                'email_verified_at',
                'created_at',
                'updated_at',
                'tenants',
                'roles',
                'permissions',
            ],
        ]);
    }

    // =========================================================================
    // Registration Tests
    // =========================================================================

    /**
     * Test that a user can register and automatically log in.
     *
     * A valid registration request should:
     * - Create the user in the database.
     * - Assign the user to the given tenant.
     * - Return a token for automatic login.
     * - Allow the token to access protected routes.
     *
     * @return void
     */
    #[Test]
    public function a_user_can_register_and_automatically_log_in(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);

        $response = $this->postJson('/api/register', [
            'name'                  => 'New User',
            'email'                 => 'newuser@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'tenant_id'             => $tenant->id,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Registration successful.',
        ]);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user' => ['id', 'name', 'email'],
                'token',
                'token_type',
            ],
        ]);

        // Verify user creation and tenant membership.
        $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);
        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->id,
            // user_id is not known until we retrieve the user.
        ]);

        // Verify token works.
        $token = $response->json('data.token');
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user')
            ->assertStatus(200);
    }

    /**
     * Test that registration fails with a duplicate email.
     *
     * If the email is already taken, the server should return a 409 Conflict
     * response with the error code 'AUTH_EMAIL_TAKEN'.
     *
     * @return void
     */
    #[Test]
    public function registration_fails_with_duplicate_email(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);

        $response = $this->postJson('/api/register', [
            'name'                  => 'Duplicate User',
            'email'                 => 'test@example.com', // Already exists.
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'tenant_id'             => $tenant->id,
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
            'error'   => ['code' => 'AUTH_EMAIL_TAKEN'],
        ]);
    }

    /**
     * Test that registration fails when the password and confirmation do not match.
     *
     * A password mismatch should trigger a 422 validation error on the password field.
     *
     * @return void
     */
    #[Test]
    public function registration_fails_with_password_mismatch(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);

        $response = $this->postJson('/api/register', [
            'name'                  => 'New User',
            'email'                 => 'newuser@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'DifferentPassword!',
            'tenant_id'             => $tenant->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }
}