<?php

declare(strict_types=1);

namespace Tests\Feature;

use MaaleArna\MASES\Core\Domains\FileManagement\Models\File as CanonicalFile;
use MaaleArna\MASES\Core\Domains\FileManagement\Services\FileService as CanonicalFileService;
use MaaleArna\MASES\Core\Files\Models\File as LegacyFile;
use MaaleArna\MASES\Core\Files\Services\FileService as LegacyFileService;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FileManagementBoundaryTest extends TestCase
{
    #[Test]
    public function legacy_file_model_is_an_alias_of_the_canonical_domain_model(): void
    {
        $this->assertTrue(class_exists(CanonicalFile::class));
        $this->assertTrue(class_exists(LegacyFile::class));
        $this->assertSame(
            (new \ReflectionClass(CanonicalFile::class))->getName(),
            (new \ReflectionClass(LegacyFile::class))->getName()
        );
        $this->assertSame('files', (new CanonicalFile())->getTable());
    }

    #[Test]
    public function legacy_file_service_resolves_to_the_canonical_singleton(): void
    {
        $canonical = $this->app->make(CanonicalFileService::class);
        $legacy = $this->app->make(LegacyFileService::class);

        $this->assertSame($canonical, $legacy);
    }
}
