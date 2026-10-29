<?php

declare(strict_types=1);

namespace Tests\Feature;

use ReflectionMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\FileManagement\Models\File;
use MaaleArna\MASES\Core\Domains\FileManagement\Services\FileService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Exceptions\FileException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FileSecurityTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    private FileService $fileService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileService = app(FileService::class);
        Storage::fake('local');
        config()->set('mases.tenant.isolation_enabled', true);
        config()->set('mases.files.allowed_extensions', ['pdf', 'jpg', 'png']);
    }

    /**
     * Give a global permission to a user.
     */
    private function givePermission(User $user, string $permissionSlug): void
    {
        $permission = Permission::firstOrCreate(
            ['slug' => $permissionSlug],
            ['name' => $permissionSlug, 'scope' => 'tenant', 'tenant_id' => null]
        );
        $user->permissions()->attach($permission->id);
    }

    /**
     * Create a user attached to a tenant with the given permissions.
     *
     * @return array{user: User, tenant: Tenant}
     */
    private function createUserWithPermissions(array $permissions, ?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);
        foreach ($permissions as $perm) {
            $this->givePermission($user, $perm);
        }
        return ['user' => $user, 'tenant' => $tenant];
    }

    /**
     * Assert that a FileException with the given HTTP status code is thrown.
     */
    private function assertFileException(int $httpStatusCode, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected FileException was not thrown.');
        } catch (FileException $e) {
            $this->assertEquals($httpStatusCode, $e->getHttpStatusCode(), 'Incorrect HTTP status code.');
        }
    }

    // ------------------------------------------------------------------
    // Path Traversal & Tenant Membership (baseline)
    // ------------------------------------------------------------------

    #[Test]
    public function path_traversal_sanitization_removes_dangerous_sequences(): void
    {
        $reflection = new ReflectionMethod($this->fileService, 'buildPath');
        $reflection->setAccessible(true);
        $safePath = $reflection->invoke($this->fileService, '../../etc/passwd', 42, 'file.pdf');
        $this->assertStringNotContainsString('..', $safePath);
        $this->assertStringStartsWith('etc/passwd/tenant_42/', $safePath);
    }

    #[Test]
    public function tenant_membership_check_works(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);
        $this->assertTrue($user->tenants()->where('tenant_id', $tenant->id)->exists());
    }

    // ------------------------------------------------------------------
    // Upload Tests
    // ------------------------------------------------------------------

    #[Test]
    public function user_can_upload_file_within_their_tenant(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createUserWithPermissions(['file.upload']);
        $this->actingAs($user, 'sanctum');

        $uploadedFile = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: 'test',
            tenantId: $tenant->id,
            user: $user
        );

        $this->assertInstanceOf(File::class, $file);
        $this->assertEquals($tenant->id, $file->tenant_id);
        $this->assertEquals($user->id, $file->user_id);
        $this->assertTrue(Storage::disk('local')->exists($file->path));
    }

    #[Test]
    public function user_cannot_upload_file_in_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenantA->id);
        $this->givePermission($user, 'file.upload');
        $this->actingAs($user, 'sanctum');

        $uploadedFile = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $this->assertFileException(403, function () use ($uploadedFile, $user, $tenantB) {
            $this->fileService->upload(
                uploadedFile: $uploadedFile,
                fileName: 'test',
                tenantId: $tenantB->id,
                user: $user
            );
        });
    }

    #[Test]
    public function user_without_upload_permission_cannot_upload(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createUserWithPermissions([]);
        $this->actingAs($user, 'sanctum');

        $uploadedFile = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $this->assertFileException(403, function () use ($uploadedFile, $user, $tenant) {
            $this->fileService->upload(
                uploadedFile: $uploadedFile,
                fileName: 'test',
                tenantId: $tenant->id,
                user: $user
            );
        });
    }

    // ------------------------------------------------------------------
    // Delete Tests
    // ------------------------------------------------------------------

    #[Test]
    public function user_can_delete_their_own_file_within_tenant(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createUserWithPermissions(['file.upload', 'file.delete']);
        $this->actingAs($user, 'sanctum');

        $uploadedFile = UploadedFile::fake()->create('delete.pdf', 100, 'application/pdf');
        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: 'delete',
            tenantId: $tenant->id,
            user: $user
        );

        $this->assertTrue($this->fileService->delete($file, $user));
        $this->assertSoftDeleted('files', ['id' => $file->id]);
    }

    #[Test]
    public function user_cannot_delete_file_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->createUserWithPermissions(['file.upload', 'file.delete'], $tenantA)['user'];
        $userB = $this->createUserWithPermissions(['file.delete'], $tenantB)['user'];

        $this->actingAs($userA, 'sanctum');
        $uploadedFile = UploadedFile::fake()->create('delete.pdf', 100, 'application/pdf');
        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: 'delete',
            tenantId: $tenantA->id,
            user: $userA
        );

        $this->actingAs($userB, 'sanctum');
        $this->assertFileException(403, function () use ($file, $userB) {
            $this->fileService->delete($file, $userB);
        });
    }

    #[Test]
    public function user_without_delete_permission_cannot_delete(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createUserWithPermissions(['file.upload']);
        $this->actingAs($user, 'sanctum');

        $uploadedFile = UploadedFile::fake()->create('delete.pdf', 100, 'application/pdf');
        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: 'delete',
            tenantId: $tenant->id,
            user: $user
        );

        $this->assertFileException(403, function () use ($file, $user) {
            $this->fileService->delete($file, $user);
        });
    }

    // ------------------------------------------------------------------
    // Update / Versioning Tests
    // ------------------------------------------------------------------

    #[Test]
    public function user_can_update_file_metadata_within_tenant(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createUserWithPermissions(['file.upload', 'file.update']);
        $this->actingAs($user, 'sanctum');

        $uploadedFile = UploadedFile::fake()->create('meta.pdf', 100, 'application/pdf');
        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: 'meta',
            tenantId: $tenant->id,
            user: $user
        );

        $updated = $this->fileService->update($file, ['metadata' => ['description' => 'Updated']], user: $user);
        $this->assertEquals(['description' => 'Updated'], $updated->metadata);
    }

    #[Test]
    public function user_cannot_update_file_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->createUserWithPermissions(['file.upload', 'file.update'], $tenantA)['user'];
        $userB = $this->createUserWithPermissions(['file.update'], $tenantB)['user'];

        $this->actingAs($userA, 'sanctum');
        $uploadedFile = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: 'doc',
            tenantId: $tenantA->id,
            user: $userA
        );

        $this->actingAs($userB, 'sanctum');
        $this->assertFileException(403, function () use ($file, $userB) {
            $this->fileService->update($file, ['metadata' => ['x' => 1]], user: $userB);
        });
    }

    #[Test]
    public function user_without_update_permission_cannot_update(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createUserWithPermissions(['file.upload']);
        $this->actingAs($user, 'sanctum');

        $uploadedFile = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: 'doc',
            tenantId: $tenant->id,
            user: $user
        );

        $this->assertFileException(403, function () use ($file, $user) {
            $this->fileService->update($file, ['metadata' => ['x' => 1]], user: $user);
        });
    }

    #[Test]
    public function file_versioning_prevents_unauthorized_access(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createUserWithPermissions(['file.upload', 'file.update', 'file.view']);
        $this->actingAs($user, 'sanctum');

        $uploadedFile = UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf');
        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: 'version',
            tenantId: $tenant->id,
            user: $user,
            metadata: ['version' => 1]
        );

        $newFile = UploadedFile::fake()->create('v2.pdf', 120, 'application/pdf');
        $updated = $this->fileService->update(
            file: $file,
            data: ['metadata' => ['version' => 2]],
            newFile: $newFile,
            user: $user
        );

        $this->assertNotEquals($file->id, $updated->id);
        $this->assertEquals(2, $updated->version);
        $this->assertEquals($file->id, $updated->parent_file_id);

        $history = $this->fileService->getVersionHistory($file->id);
        $this->assertCount(2, $history);
        $this->assertEquals(1, $history->first()->version);
        $this->assertEquals(2, $history->last()->version);
    }

    // ------------------------------------------------------------------
    // View / Find Tests
    // ------------------------------------------------------------------

    #[Test]
    public function user_can_view_file_metadata_within_tenant(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createUserWithPermissions(['file.upload', 'file.view']);
        $this->actingAs($user, 'sanctum');

        $uploadedFile = UploadedFile::fake()->create('meta.pdf', 100, 'application/pdf');
        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: 'meta',
            tenantId: $tenant->id,
            user: $user,
            metadata: ['description' => 'Test file']
        );

        $found = $this->fileService->find($file->id);
        $this->assertInstanceOf(File::class, $found);
        $this->assertEquals($file->id, $found->id);
        $this->assertEquals(['description' => 'Test file'], $found->metadata);
    }

    #[Test]
    public function user_cannot_view_metadata_of_file_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->createUserWithPermissions(['file.upload', 'file.view'], $tenantA)['user'];
        $userB = $this->createUserWithPermissions(['file.view'], $tenantB)['user'];

        $this->actingAs($userA, 'sanctum');
        $uploadedFile = UploadedFile::fake()->create('meta.pdf', 100, 'application/pdf');
        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: 'meta',
            tenantId: $tenantA->id,
            user: $userA
        );

        $this->actingAs($userB, 'sanctum');
        $this->assertFileException(403, function () use ($file) {
            $this->fileService->find($file->id);
        });
    }

    #[Test]
    public function user_without_view_permission_cannot_find_file(): void
    {
        ['user' => $user, 'tenant' => $tenant] = $this->createUserWithPermissions(['file.upload']);
        $this->actingAs($user, 'sanctum');

        $uploadedFile = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
        $file = $this->fileService->upload(
            uploadedFile: $uploadedFile,
            fileName: 'doc',
            tenantId: $tenant->id,
            user: $user
        );

        $this->assertFileException(403, function () use ($file) {
            $this->fileService->find($file->id);
        });
    }

    // ------------------------------------------------------------------
    // Download Authorization Tests (Reflection-based, no streaming)
    // ------------------------------------------------------------------

    #[Test]
    public function download_authorization_requires_authentication_for_private_file(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);
        $file = File::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'is_public' => false,
            'original_name' => 'private.pdf',
            'name' => 'private.pdf',
            'size' => 100,
            'path' => 'tenant_' . $tenant->id . '/private.pdf',
            'disk' => 'local',
            'status' => 'published',
            'version' => 1,
        ]);

        $reflection = new ReflectionMethod($this->fileService, 'authorizeAction');
        $reflection->setAccessible(true);

        $this->assertFileException(401, function () use ($reflection, $file) {
            $reflection->invoke($this->fileService, 'file.download', $file, null);
        });
    }

    #[Test]
    public function download_authorization_requires_tenant_membership(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenantA->id);
        $this->givePermission($user, 'file.download');
        $file = File::create([
            'tenant_id' => $tenantB->id,
            'user_id' => $user->id,
            'is_public' => false,
            'original_name' => 'file.pdf',
            'name' => 'file.pdf',
            'size' => 100,
            'path' => 'tenant_' . $tenantB->id . '/file.pdf',
            'disk' => 'local',
            'status' => 'published',
            'version' => 1,
        ]);

        $reflection = new ReflectionMethod($this->fileService, 'authorizeAction');
        $reflection->setAccessible(true);

        $this->assertFileException(403, function () use ($reflection, $file, $user) {
            $reflection->invoke($this->fileService, 'file.download', $file, $user);
        });
    }

    #[Test]
    public function download_authorization_requires_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);
        // User does NOT have 'file.download' permission
        $file = File::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'is_public' => false,
            'original_name' => 'file.pdf',
            'name' => 'file.pdf',
            'size' => 100,
            'path' => 'tenant_' . $tenant->id . '/file.pdf',
            'disk' => 'local',
            'status' => 'published',
            'version' => 1,
        ]);

        $reflection = new ReflectionMethod($this->fileService, 'authorizeAction');
        $reflection->setAccessible(true);

        $this->assertFileException(403, function () use ($reflection, $file, $user) {
            $reflection->invoke($this->fileService, 'file.download', $file, $user);
        });
    }
}