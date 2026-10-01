<?php

declare(strict_types=1);

namespace Tests\Unit;

use ReflectionMethod;
use ReflectionClassConstant;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Security\Services\SecurityEventService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SecurityEventServiceTest
 *
 * Unit coverage for the sensitive-key filter in
 * `MaaleArna\MASES\Core\Security\Services\SecurityEventService`,
 * introduced/hardened by D-14 (H-19).
 *
 * The filter is a private method `buildEventData()` invoked by
 * `SecurityEventService::log()` immediately before persistence. This
 * file exercises that filter directly via reflection, so the assertions
 * describe exactly what the filter does — independently of the database,
 * the request context, or the calling services.
 *
 * Coverage:
 *   1. Every key on the sensitive list is dropped from the top level.
 *   2. The sensitive list matches the expected set exactly (drift guard).
 *   3. Matching is case-insensitive.
 *   4. Sensitive keys inside nested arrays are dropped (recursion).
 *   5. Sensitive keys inside deeply nested arrays are dropped.
 *   6. A nested array whose every entry is filtered is preserved as an
 *      empty array under its original key.
 *   7. Legitimate audit fields are preserved at every level.
 *   8. The nested `authorization` payload from
 *      `LogAuthorizationFailureListener` is preserved intact — this is
 *      the contract established by v1.0.2 and confirmed by
 *      `AuthorizationFailureEventTest`.
 *   9. `User` and `Tenant` model instances are dropped.
 *
 * @package Tests\Unit
 */
final class SecurityEventServiceTest extends TestCase
{
    /**
     * The complete expected set of sensitive keys.
     *
     * This list is intentionally duplicated from the private constant in
     * `SecurityEventService`. The duplication is deliberate: it is the
     * specification the constant must satisfy. A future change to the
     * constant that is not accompanied by a change here will fail
     * `sensitive_keys_constant_matches_expected_set()`, forcing the
     * change to be an explicit, reviewed decision.
     *
     * Grouped by credential family:
     *   - direct credentials
     *   - token family
     *   - secret family
     *   - OTP / MFA code family
     *
     * @var list<string>
     */
    private const EXPECTED_SENSITIVE_KEYS = [
        // Direct credential fields.
        'password',
        'password_confirmation',
        'current_password',

        // Generic token field.
        'token',

        // Token family.
        'access_token',
        'bearer_token',
        'api_token',
        'session_token',
        'password_reset_token',
        'email_verification_token',
        'refresh_token',

        // Secret family.
        'secret',
        'api_key',
        'api_secret',
        'client_secret',
        'mfa_secret',
        'totp_secret',
        'two_factor_secret',
        'two_factor_recovery_codes',

        // OTP / MFA code family.
        'otp',
        'otp_code',
        'one_time_password',
        'one_time_code',
        'totp',
        'totp_code',
        'mfa_code',
        'verification_code',
    ];

    /**
     * The service under test.
     *
     * @var SecurityEventService
     */
    private SecurityEventService $service;

    /**
     * Reflected handle to the private `buildEventData()` method.
     *
     * @var ReflectionMethod
     */
    private ReflectionMethod $buildEventData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SecurityEventService();

        $this->buildEventData = new ReflectionMethod(
            SecurityEventService::class,
            'buildEventData'
        );
        $this->buildEventData->setAccessible(true);
    }

    /**
     * Invoke the private filter on the given payload.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function filter(array $data): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->buildEventData->invoke($this->service, $data);

        return $result;
    }

    /**
     * Read the private `SENSITIVE_KEYS` constant via reflection.
     *
     * @return list<string>
     */
    private function readSensitiveKeysConstant(): array
    {
        $reflection = new ReflectionClassConstant(
            SecurityEventService::class,
            'SENSITIVE_KEYS'
        );

        /** @var list<string> $value */
        $value = $reflection->getValue();

        return $value;
    }

    // =========================================================================
    // 1. Sensitive list contract
    // =========================================================================

    /**
     * The private constant must match the test's expected set exactly.
     *
     * This is the drift guard. It ensures that a change to the constant
     * is a deliberate, reviewed decision rather than a silent edit. If a
     * future CR adds or removes a key, both this test and the constant
     * must change together.
     */
    #[Test]
    public function sensitive_keys_constant_matches_expected_set(): void
    {
        $actual = $this->readSensitiveKeysConstant();
        $expected = self::EXPECTED_SENSITIVE_KEYS;

        sort($actual);
        sort($expected);

        $this->assertSame(
            $expected,
            $actual,
            'The SENSITIVE_KEYS constant has drifted from the test expectation. '
            . 'Update both the constant and EXPECTED_SENSITIVE_KEYS together, '
            . 'and re-review the security impact of the change.'
        );
    }

    // =========================================================================
    // 2. Top-level filtering
    // =========================================================================

    /**
     * Every sensitive key is dropped from the top level of the payload.
     *
     * The associated value is not inspected — the key's presence alone is
     * enough to drop the entry. This is the primary guarantee the filter
     * provides.
     */
    #[Test]
    public function every_sensitive_key_is_dropped_from_the_top_level(): void
    {
        $payload = [];
        foreach (self::EXPECTED_SENSITIVE_KEYS as $key) {
            $payload[$key] = 'SENSITIVE_VALUE_FOR_' . $key;
        }
        $payload['email'] = 'safe@example.test';

        $result = $this->filter($payload);

        $this->assertArrayHasKey(
            'email',
            $result,
            'A non-sensitive control key must survive the filter.'
        );

        foreach (self::EXPECTED_SENSITIVE_KEYS as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $result,
                sprintf('Sensitive key [%s] survived the top-level filter.', $key)
            );
        }
    }

    // =========================================================================
    // 3. Case-insensitivity
    // =========================================================================

    /**
     * The filter matches keys case-insensitively.
     *
     * Prior to v1.0.3 the comparison was strict (`in_array($key, $list, true)`),
     * so a caller using `Password`, `PASSWORD`, `Access_Token`, or
     * `MFA_SECRET` would bypass the filter. This test pins the fix.
     */
    #[Test]
    public function sensitive_key_matching_is_case_insensitive(): void
    {
        $mixedCaseKeys = [
            'Password',
            'PASSWORD',
            'password',
            'PASSWORD_CONFIRMATION',
            'Current_Password',
            'Token',
            'TOKEN',
            'Access_Token',
            'ACCESS_TOKEN',
            'Bearer_Token',
            'MFA_SECRET',
            'Mfa_Secret',
            'OTP',
            'Otp',
            'Totp',
            'TOTP_CODE',
            'Client_Secret',
            'Two_Factor_Secret',
            'Two_Factor_Recovery_Codes',
            'Verification_Code',
        ];

        $payload = [];
        foreach ($mixedCaseKeys as $key) {
            $payload[$key] = 'value';
        }
        $payload['Email'] = 'safe@example.test';

        $result = $this->filter($payload);

        $this->assertArrayHasKey(
            'Email',
            $result,
            'A non-sensitive mixed-case control key must survive.'
        );

        foreach ($mixedCaseKeys as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $result,
                sprintf('Mixed-case sensitive key [%s] survived the filter.', $key)
            );
        }
    }

    // =========================================================================
    // 4. Recursion — nested arrays
    // =========================================================================

    /**
     * Sensitive keys inside a nested array are dropped.
     *
     * Prior to v1.0.3 the filter inspected only the top level of `$data`,
     * so `['context' => ['password' => 'x']]` would persist the nested
     * credential verbatim.
     */
    #[Test]
    public function sensitive_keys_inside_nested_arrays_are_dropped(): void
    {
        $payload = [
            'context' => [
                'password' => 'nested-secret',
                'token'    => 'nested-token',
                'otp'      => '123456',
                'safe'     => 'nested-safe',
            ],
            'email' => 'safe@example.test',
        ];

        $result = $this->filter($payload);

        $this->assertArrayHasKey('context', $result);
        $this->assertIsArray($result['context']);

        $this->assertArrayNotHasKey('password', $result['context']);
        $this->assertArrayNotHasKey('token', $result['context']);
        $this->assertArrayNotHasKey('otp', $result['context']);

        $this->assertArrayHasKey('safe', $result['context']);
        $this->assertSame('nested-safe', $result['context']['safe']);

        $this->assertArrayHasKey('email', $result);
        $this->assertSame('safe@example.test', $result['email']);
    }

    /**
     * Sensitive keys at arbitrary depth are dropped.
     *
     * The recursion has no arbitrary depth cap. A key nested four levels
     * deep is filtered identically to a top-level key.
     */
    #[Test]
    public function sensitive_keys_inside_deeply_nested_arrays_are_dropped(): void
    {
        $payload = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => [
                            'password'        => 'deep-secret',
                            'access_token'    => 'deep-token',
                            'mfa_secret'      => 'deep-mfa',
                            'safe'            => 'deep-safe',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->filter($payload);

        $level4 = $result['level1']['level2']['level3']['level4'];

        $this->assertArrayNotHasKey('password', $level4);
        $this->assertArrayNotHasKey('access_token', $level4);
        $this->assertArrayNotHasKey('mfa_secret', $level4);
        $this->assertArrayHasKey('safe', $level4);
        $this->assertSame('deep-safe', $level4['safe']);
    }

    /**
     * A nested array whose every entry is filtered is preserved as an
     * empty array under its original key.
     *
     * This documents a deliberate design decision — the key's presence is
     * itself audit-meaningful, so it is not collapsed away. See the
     * `buildEventData()` docblock for the rationale.
     */
    #[Test]
    public function fully_filtered_nested_array_preserves_key_with_empty_value(): void
    {
        $payload = [
            'context' => [
                'password' => 'x',
                'token'    => 'y',
            ],
        ];

        $result = $this->filter($payload);

        $this->assertArrayHasKey('context', $result);
        $this->assertSame([], $result['context']);
    }

    // =========================================================================
    // 5. Preservation of legitimate audit fields
    // =========================================================================

    /**
     * Every legitimate audit field currently emitted by any caller of
     * `SecurityEventService::log()` survives the filter at the top level.
     *
     * This is the anti-overfilter guard: the extension of the sensitive
     * list in v1.0.3 must not silently drop the fields the audit trail
     * depends on.
     */
    #[Test]
    public function legitimate_audit_fields_are_preserved_at_top_level(): void
    {
        $payload = [
            'user_id'      => 7,
            'tenant_id'    => 42,
            'email'        => 'user@example.test',
            'reason'       => 'Invalid credentials or inactive account',
            'status'       => 'passwords.sent',
            'ip'           => '127.0.0.1',
            'ip_address'   => '127.0.0.1',
            'user_agent'   => 'TestAgent/1.0',
            'request_id'   => 'req-abc-123',
            'remember'     => true,
            'resource_type'=> 'transaction',
            'resource_id'  => '123',
            'check_type'   => 'permission',
            'check_value'  => 'finance.transaction.create',
            'action'       => 'create',
            'occurred_at'  => '2026-09-22T00:00:00+00:00',
        ];

        $result = $this->filter($payload);

        foreach ($payload as $key => $expectedValue) {
            $this->assertArrayHasKey(
                $key,
                $result,
                sprintf('Legitimate audit field [%s] was unexpectedly dropped.', $key)
            );
            $this->assertSame(
                $expectedValue,
                $result[$key],
                sprintf('Legitimate audit field [%s] value was unexpectedly altered.', $key)
            );
        }
    }

    // =========================================================================
    // 6. Preservation of the nested authorization payload
    // =========================================================================

    /**
     * The nested `authorization` payload from `LogAuthorizationFailureListener`
     * is preserved intact.
     *
     * This is the load-bearing case introduced by v1.0.2 (removal of
     * `'authorization'` from the sensitive list) and preserved by v1.0.3
     * (recursion descends into it, finds no matching keys, returns it
     * unchanged). See the class docblock §"Revision history" of the
     * service for the full rationale, and
     * `AuthorizationFailureEventTest::denied_event_data_contains_structured_authorization_context`
     * for the end-to-end contract this test supports at the unit level.
     */
    #[Test]
    public function nested_authorization_payload_is_preserved_intact(): void
    {
        $authorization = [
            'check_type'    => 'permission',
            'check_value'   => 'finance.transaction.create',
            'application'   => 'finance',
            'resource_type' => 'transaction',
            'resource_id'   => null,
            'action'        => 'create',
            'occurred_at'   => '2026-09-22T00:00:00+00:00',
        ];

        $payload = [
            'user_id'       => 7,
            'tenant_id'     => 42,
            'request_id'    => 'req-abc-123',
            'ip'            => '127.0.0.1',
            'user_agent'    => 'TestAgent/1.0',
            'authorization' => $authorization,
        ];

        $result = $this->filter($payload);

        $this->assertArrayHasKey(
            'authorization',
            $result,
            'The authorization payload must survive the recursive filter.'
        );
        $this->assertSame(
            $authorization,
            $result['authorization'],
            'The authorization payload must be returned unchanged by the filter.'
        );
    }

    /**
     * The nested `authorization` payload preserves its keys even when
     * some of its values are null.
     *
     * This is a specific instance of the case above, split out because
     * `array_filter` on the top level of the listener's payload is
     * sensitive to null values — the filter here must not be.
     */
    #[Test]
    public function nested_authorization_payload_with_null_values_is_preserved(): void
    {
        $authorization = [
            'check_type'    => 'role',
            'check_value'   => 'super_admin',
            'application'   => null,
            'resource_type' => null,
            'resource_id'   => null,
            'action'        => null,
            'occurred_at'   => '2026-09-22T00:00:00+00:00',
        ];

        $result = $this->filter(['authorization' => $authorization]);

        $this->assertSame($authorization, $result['authorization']);
    }

    // =========================================================================
    // 7. User / Tenant model filtering
    // =========================================================================

    /**
     * `User` and `Tenant` instances are dropped from the payload.
     *
     * This preserves the pre-v1.0.3 contract (Rules 2 of the old filter)
     * under the recursive rewrite. It also confirms that recursion does
     * not attempt to descend into these objects — they are dropped before
     * the array-recursion check.
     */
    #[Test]
    public function user_and_tenant_model_instances_are_dropped(): void
    {
        $payload = [
            'user'   => new User(),
            'tenant' => new Tenant(),
            'email'  => 'safe@example.test',
            'user_id' => 7,
        ];

        $result = $this->filter($payload);

        $this->assertArrayNotHasKey('user', $result);
        $this->assertArrayNotHasKey('tenant', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertSame(7, $result['user_id']);
    }

    /**
     * `User` and `Tenant` instances are dropped even when nested.
     *
     * Under the recursive rewrite, Rule 2 runs before Rule 3 (recursion),
     * so a `User` instance inside a nested array is dropped at that
     * level rather than being serialised into the persisted payload.
     */
    #[Test]
    public function nested_user_and_tenant_model_instances_are_dropped(): void
    {
        $payload = [
            'context' => [
                'user'   => new User(),
                'tenant' => new Tenant(),
                'safe'   => 'nested-safe',
            ],
        ];

        $result = $this->filter($payload);

        $this->assertArrayHasKey('context', $result);
        $this->assertArrayNotHasKey('user', $result['context']);
        $this->assertArrayNotHasKey('tenant', $result['context']);
        $this->assertArrayHasKey('safe', $result['context']);
        $this->assertSame('nested-safe', $result['context']['safe']);
    }
}