<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use MaaleArna\MASES\Core\Domains\Documents\Services\ImmutableDocumentBinaryStore;
use RuntimeException;
use Tests\TestCase;
use Illuminate\Support\Facades\Storage;

final class ImmutableDocumentBinaryStoreSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['mases.documents.evidence_disk' => 'local']);
    }

    public function test_capture_seals_file_and_version_directory_and_rejects_same_version_mutation(): void
    {
        Storage::disk('local')->put('source.bin', 'original evidence bytes');
        $store = new ImmutableDocumentBinaryStore();

        $snapshot = $store->capture(7, 11, 1, 'local', 'source.bin');
        $absolute = Storage::disk('local')->path($snapshot['path']);
        $directory = dirname($absolute);

        self::assertSame(hash('sha256', 'original evidence bytes'), $snapshot['sha256']);
        self::assertSame(0400, fileperms($absolute) & 0777);
        self::assertSame(0500, fileperms($directory) & 0777);
        self::assertTrue($store->verify('local', $snapshot['path'], $snapshot['sha256'], $snapshot['size']));

        // Identical retries are idempotent; a changed source version cannot
        // create a second digest under the already-sealed version directory.
        $retry = $store->capture(7, 11, 1, 'local', 'source.bin');
        self::assertSame($snapshot, $retry);

        Storage::disk('local')->put('source.bin', 'mutated evidence bytes');
        $this->expectException(RuntimeException::class);
        $store->capture(7, 11, 1, 'local', 'source.bin');
    }

    public function test_verify_rejects_path_traversal_and_symlink_escape(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'arna-evidence-');
        self::assertNotFalse($outside);
        file_put_contents($outside, 'outside evidence');
        $digest = hash('sha256', 'outside evidence');
        $store = new ImmutableDocumentBinaryStore();

        try {
            self::assertFalse($store->verify('local', '../'.basename($outside), $digest, strlen('outside evidence')));

            $root = Storage::disk('local')->path('');
            $escape = $root . DIRECTORY_SEPARATOR . 'evidence-escape';
            self::assertTrue(symlink($outside, $escape));
            self::assertFalse($store->verify('local', 'evidence-escape', $digest, strlen('outside evidence')));
        } finally {
            @unlink(Storage::disk('local')->path('evidence-escape'));
            @unlink($outside);
        }
    }
}
