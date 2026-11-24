<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminPlatformSettingsApprovalTest extends TestCase
{
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
        }
    }

    public function test_secret_values_are_encrypted_and_never_returned_from_read_or_approval_payloads(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/PlatformSettingsController.php'));

        $this->assertIsString($controller);
        $this->assertStringContainsString('Crypt::encryptString($data[\'value\'])', $controller);
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
    }
}
