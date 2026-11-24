<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Crypt;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Route;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Permission;
use Tests\\TestCase;

final class AdminPlatformSettingsApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_and_configuration_routes_use_distinct_read_manage_and_approve_permissions(): void
    {
        $expected = [
            'api.admin.platform.settings.index' => 'auth.permission:platform.settings.read',
            'api.admin.platform.settings.requests.store' => 'auth.permission:platform.settings.manage',
            'api.admin.platform.settings.requests.index' => 'auth.permission:platform.settings.approve',
            'api.admin.platform.settings.requests.approve' => 'auth.permission:platform.settings.approve',
            'api.admin.platform.settings.requests.reject' => 'auth.permission:platform.settings.approve',
            'api.admin.platform.configuration.index' => 'auth.permission:platform.configuration.read',
            'api.admin.platform.configuration.requests.store' => 'auth.permission:platform.configuration.manage',
            'api.admin.platform.configuration.requests.index' => 'auth.permission:platform.configuration.approve',
            'api.admin.platform.configuration.requests.approve' => 'auth.permission:platform.configuration.approve',
            'api.admin.platform.configuration.requests.reject' => 'auth.permission:platform.configuration.approve',
        ];

        foreach ($expected as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Missing route {$name}");
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains($permission, $route->gatherMiddleware());
            $this->assertNotContains('tenant.membership', $route->gatherMiddleware());
        }
    }

    public function test_secret_values_are_encrypted_and_never_returned_from_read_or_approval_payloads(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/PlatformSettingsController.php'));

        $this->assertIsString($controller);
        $this->assertStringContainsString('Crypt::encryptString($data[\\'value\\'])', $controller);
        $this->assertStringContainsString("'value' => $row->is_secret ? null", $controller);
        $this->assertStringContainsString("'proposed_value' => $row->is_secret ? null", $controller);
        $this->assertStringContainsString("'secret_value_provided' =>", $controller);
    }

    public function test_setting_changes_require_a_different_approver_and_are_applied_transactionally(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/PlatformSettingsController.php'));

        $this->assertIsString($controller);
        $this->assertStringContainsString('The requester cannot approve their own change.', $controller);
        $this->assertStringContainsString('The requester cannot reject their own change.', $controller);
        $this->assertStringContainsString('DB::transaction(function () use ($changeRequest', $controller);
        $this->assertStringContainsString("'status' => 'approved'", $controller);
        $this->assertStringContainsString("'status' => 'rejected'", $controller);
        $this->assertStringContainsString("'base_version' =>", $controller);
        $this->assertStringContainsString('A newer setting version exists', $controller);
    }

    public function test_secret_setting_reads_return_only_redaction_metadata(): void
    {
        $secret = 'ARNA_SECRET_SHOULD_NEVER_LEAK';
        $ciphertext = Crypt::encryptString($secret);
        DB::table('platform_settings')->insert([
            'setting_key' => 'mail.smtp.password',
            'category' => 'setting',
            'value_ciphertext' => $ciphertext,
            'is_secret' => true,
            'description' => 'SMTP password',
            'environment' => 'testing',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();
        $permission = Permission::factory()->create([
            'slug' => 'platform.settings.read',
            'scope' => 'global',
            'is_active' => true,
        ]);
        $user->permissions()->attach($permission->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/admin/platform/settings?environment=testing');
        $response->assertOk()
            ->assertJsonPath('data.items.0.key', 'mail.smtp.password')
            ->assertJsonPath('data.items.0.value', null)
            ->assertJsonPath('data.items.0.is_secret', true)
            ->assertJsonPath('data.items.0.is_configured', true);

        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($ciphertext, $response->getContent());
    }
}
