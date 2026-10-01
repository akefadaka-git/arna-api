<?php

declare(strict_types=1);

namespace Tests\Feature;

use Throwable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Security\Models\SecurityEvent;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ============================================================================
 * MASES — Audit & Security Feature Tests
 * ============================================================================
 *
 * These tests verify that security events and audit logs are correctly created
 * for authentication, file operations, and metadata. They also ensure that
 * sensitive data is never stored in logs.
 *
 * Dependencies:
 * - AuditLog model at `MaaleArna\MASES\Core\Audit\Models\AuditLog`
 * - SecurityEvent model at `MaaleArna\MASES\Core\Security\Models\SecurityEvent`
 * - Both tables must be migrated for the test database.
 * - FileController must use the `LogsAudit` trait and call `logAudit()`.
 * - Delete endpoint requires `file.view` permission internally – grant it.
 *
 * @package Tests\Feature
 */
final class AuditSecurityTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    private Tenant $tenant;
    private User $user;

    private const USER_EMAIL = 'test@example.com';
    private const USER_PASSWORD = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();

        // Enable audit functionality for the test suite.
        config()->set('mases.features.audit_enabled', true);

        // Create an isolated tenant.
        $this->tenant = Tenant::factory()->create(['is_active' => true]);

        // Create a test user.
        $this->user = User::factory()->create([
            'email' => self::USER_EMAIL,
            'password' => Hash::make(self::USER_PASSWORD),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // Attach user to tenant.
        $this->user->tenants()->attach($this->tenant->id);

        // Verify required models exist to prevent fatal crashes.
        $this->ensureModelsExist();
    }

    /**
     * Defensive check – ensures essential models are loadable.
     */
    private function ensureModelsExist(): void
    {
        if (! class_exists(AuditLog::class)) {
            $this->fail(
                'AuditLog model not found. Ensure the class ' .
                'MaaleArna\MASES\Core\Audit\Models\AuditLog exists.'
            );
        }

        if (! class_exists(SecurityEvent::class)) {
            $this->fail(
                'SecurityEvent model not found. Ensure the class ' .
                'MaaleArna\MASES\Core\Security\Models\SecurityEvent exists.'
            );
        }

        if (! app('db')->getSchemaBuilder()->hasTable('audit_logs')) {
            $this->fail(
                'The audit_logs table does not exist. ' .
                'Run "php artisan migrate --env=testing" to create it.'
            );
        }

        if (! app('db')->getSchemaBuilder()->hasTable('security_events')) {
            $this->fail(
                'The security_events table does not exist. ' .
                'Run "php artisan migrate --env=testing" to create it.'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Authentication Helpers
    // -------------------------------------------------------------------------

    private function login(): string
    {
        $response = $this->postJson('/api/login', [
            'email' => self::USER_EMAIL,
            'password' => self::USER_PASSWORD,
            'tenant_id' => $this->tenant->id,
        ]);

        $response->assertSuccessful();

        $token = $response->json('data.token');

        $this->assertIsString($token, 'Login response must contain a string at data.token.');
        $this->assertNotSame('', $token, 'Login response returned an empty access token.');

        return $token;
    }

    private function tenantHeaders(?string $requestId = null): array
    {
        $headers = ['X-Tenant-ID' => (string) $this->tenant->id];
        if ($requestId !== null) {
            $headers['X-Request-ID'] = $requestId;
        }
        return $headers;
    }

    private function grantPermission(string $slug): Permission
    {
        $permission = Permission::factory()->create([
            'slug' => $slug,
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user->permissions()->attach($permission->id);

        return $permission;
    }

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
    }

    // -------------------------------------------------------------------------
    // Assertion Helpers with Crash Protection
    // -------------------------------------------------------------------------

    private function firstSecurityEvent(string $eventType): SecurityEvent
    {
        try {
            $event = SecurityEvent::query()
                ->where('event_type', $eventType)
                ->latest('id')
                ->first();

            $this->assertNotNull(
                $event,
                sprintf('Expected security event [%s] was not created.', $eventType)
            );

            return $event;
        } catch (Throwable $e) {
            $this->fail(
                sprintf(
                    'Failed to retrieve security event [%s]: %s',
                    $eventType,
                    $e->getMessage()
                )
            );
        }
    }

    private function firstAuditLog(string $action): AuditLog
    {
        try {
            $log = AuditLog::query()
                ->where('action', $action)
                ->latest('id')
                ->first();

            $this->assertNotNull(
                $log,
                sprintf('Expected audit log [%s] was not created.', $action)
            );

            return $log;
        } catch (Throwable $e) {
            $this->fail(
                sprintf(
                    'Failed to retrieve audit log [%s]: %s',
                    $action,
                    $e->getMessage()
                )
            );
        }
    }

    private function eventData(SecurityEvent $event): array
    {
        $data = $event->event_data;
        if (is_array($data)) {
            return $data;
        }
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function auditNewValues(AuditLog $log): array
    {
        $values = $log->new_values;
        if (is_array($values)) {
            return $values;
        }
        if (is_string($values)) {
            $decoded = json_decode($values, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function successful_login_logs_security_event(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => self::USER_EMAIL,
            'password' => self::USER_PASSWORD,
            'tenant_id' => $this->tenant->id,
        ]);

        $response->assertSuccessful();

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'login.successful',
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
        ]);

        $event = $this->firstSecurityEvent('login.successful');
        $eventData = $this->eventData($event);

        $this->assertArrayNotHasKey('password', $eventData);
        $this->assertArrayNotHasKey('token', $eventData);
        $this->assertArrayNotHasKey('access_token', $eventData);
        $this->assertArrayNotHasKey('refresh_token', $eventData);
        $this->assertArrayNotHasKey('api_key', $eventData);
        $this->assertArrayNotHasKey('secret', $eventData);
    }

    #[Test]
    public function failed_login_logs_security_event(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => self::USER_EMAIL,
            'password' => 'WrongPassword!',
            'tenant_id' => $this->tenant->id,
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'login.failed',
            'severity' => 'warning',
        ]);

        $event = $this->firstSecurityEvent('login.failed');
        $eventData = $this->eventData($event);

        $this->assertArrayHasKey('email', $eventData);
        $this->assertArrayNotHasKey('password', $eventData);
        $this->assertArrayNotHasKey('token', $eventData);
        $this->assertArrayNotHasKey('access_token', $eventData);
        $this->assertArrayNotHasKey('refresh_token', $eventData);
        $this->assertArrayNotHasKey('api_key', $eventData);
        $this->assertArrayNotHasKey('secret', $eventData);
    }

    #[Test]
    public function logout_logs_security_event(): void
    {
        $token = $this->login();

        $response = $this->withToken($token)->postJson('/api/logout');
        $response->assertSuccessful();

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'logout',
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    #[Test]
    public function authorization_failure_returns_forbidden(): void
    {
        $token = $this->login();

        $response = $this->withToken($token)
            ->getJson('/api/test-permission', $this->tenantHeaders());

        $response->assertForbidden();

        $this->assertTrue(true);
    }

    #[Test]
    public function file_upload_logs_audit_event(): void
    {
        $token = $this->login();
        $this->grantPermission('file.upload');

        $response = $this->withToken($token)
            ->post(
                '/api/files/upload',
                [
                    'file' => $this->fakePdf(),
                    'is_public' => false,
                ],
                $this->tenantHeaders()
            );

        $response->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'file.uploaded',
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'resource_type' => 'file',
        ]);

        $log = $this->firstAuditLog('file.uploaded');
        $newValues = $this->auditNewValues($log);

        $this->assertArrayHasKey('original_name', $newValues);
        $this->assertArrayNotHasKey('content', $newValues);
        $this->assertArrayNotHasKey('file_content', $newValues);
        $this->assertArrayNotHasKey('contents', $newValues);
    }

    #[Test]
    public function file_delete_logs_audit_event(): void
    {
        $token = $this->login();
        $this->grantPermission('file.upload');
        $this->grantPermission('file.view');   // REQUIRED – internal permission check
        $this->grantPermission('file.delete');

        // Upload a file first.
        $uploadResponse = $this->withToken($token)
            ->post(
                '/api/files/upload',
                [
                    'file' => $this->fakePdf(),
                    'is_public' => false,
                ],
                $this->tenantHeaders()
            );

        $uploadResponse->assertCreated();
        $fileId = $uploadResponse->json('data.id');
        $this->assertNotNull($fileId, 'Upload response must contain data.id.');

        // Delete the file.
        $deleteResponse = $this->withToken($token)
            ->deleteJson("/api/files/{$fileId}", [], $this->tenantHeaders());

        $deleteResponse->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'file.deleted',
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'resource_type' => 'file',
            'resource_id' => (string) $fileId,
        ]);
    }

    #[Test]
    public function audit_logs_include_request_metadata(): void
    {
        $token = $this->login();
        $this->grantPermission('file.upload');

        $requestId = 'test-request-123';

        $response = $this->withToken($token)
            ->post(
                '/api/files/upload',
                [
                    'file' => $this->fakePdf(),
                    'is_public' => false,
                ],
                $this->tenantHeaders($requestId)
            );

        $response->assertCreated();

        $log = $this->firstAuditLog('file.uploaded');

        $this->assertSame($requestId, $log->request_id);
        $this->assertNotNull($log->ip_address, 'Audit log must contain the originating IP address.');
        $this->assertNotNull($log->user_agent, 'Audit log must contain the user agent.');
    }

    #[Test]
    public function security_events_include_request_metadata(): void
    {
        $requestId = 'security-test-456';

        $response = $this->postJson(
            '/api/login',
            [
                'email' => self::USER_EMAIL,
                'password' => 'WrongPassword!',
                'tenant_id' => $this->tenant->id,
            ],
            ['X-Request-ID' => $requestId]
        );

        $response->assertUnauthorized();

        $event = $this->firstSecurityEvent('login.failed');

        $this->assertSame($requestId, $event->request_id);
        $this->assertNotNull($event->ip_address, 'Security event must contain the originating IP address.');
        $this->assertNotNull($event->user_agent, 'Security event must contain the user agent.');
    }

    #[Test]
    public function security_events_do_not_store_sensitive_data(): void
    {
        $this->postJson('/api/login', [
            'email' => self::USER_EMAIL,
            'password' => 'WrongPassword!',
            'tenant_id' => $this->tenant->id,
        ])->assertUnauthorized();

        $event = $this->firstSecurityEvent('login.failed');
        $eventData = $this->eventData($event);

        $this->assertArrayHasKey('email', $eventData);

        $sensitiveKeys = [
            'password', 'password_confirmation', 'token', 'access_token',
            'refresh_token', 'api_key', 'secret', 'client_secret', 'authorization',
        ];

        foreach ($sensitiveKeys as $key) {
            $this->assertArrayNotHasKey($key, $eventData, sprintf(
                'Security event contains sensitive field [%s].',
                $key
            ));
        }
    }

    #[Test]
    public function audit_logs_do_not_store_sensitive_data(): void
    {
        $token = $this->login();
        $this->grantPermission('file.upload');

        $response = $this->withToken($token)
            ->post(
                '/api/files/upload',
                [
                    'file' => $this->fakePdf(),
                    'is_public' => false,
                    'metadata' => [
                        'secret_key' => 'should-not-be-stored',
                        'password' => 'should-not-be-stored',
                        'token' => 'should-not-be-stored',
                    ],
                ],
                $this->tenantHeaders()
            );

        $response->assertCreated();

        $log = $this->firstAuditLog('file.uploaded');
        $newValues = $this->auditNewValues($log);

        $sensitiveKeys = [
            'secret_key', 'password', 'password_confirmation', 'token',
            'access_token', 'refresh_token', 'api_key', 'secret',
            'client_secret', 'authorization',
        ];

        foreach ($sensitiveKeys as $key) {
            $this->assertArrayNotHasKey($key, $newValues, sprintf(
                'Audit log contains sensitive field [%s].',
                $key
            ));
        }

        $this->assertArrayNotHasKey('content', $newValues);
        $this->assertArrayNotHasKey('file_content', $newValues);
        $this->assertArrayNotHasKey('contents', $newValues);
    }

    #[Test]
    public function audit_logging_can_be_disabled_via_config(): void
    {
        config()->set('mases.features.audit_enabled', false);

        $loginResponse = $this->postJson('/api/login', [
            'email' => self::USER_EMAIL,
            'password' => self::USER_PASSWORD,
            'tenant_id' => $this->tenant->id,
        ]);

        $loginResponse->assertSuccessful();
        $token = $loginResponse->json('data.token');
        $this->assertIsString($token);

        $this->grantPermission('file.upload');

        $response = $this->withToken($token)
            ->post(
                '/api/files/upload',
                [
                    'file' => $this->fakePdf(),
                    'is_public' => false,
                ],
                $this->tenantHeaders()
            );

        $response->assertCreated();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'file.uploaded',
        ]);

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'login.successful',
            'user_id' => $this->user->id,
        ]);
    }
}