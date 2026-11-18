<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Content-addressed, write-once evidence snapshots on a local filesystem.
 * Unsupported remote disks fail closed rather than claiming WORM semantics.
 */
final class ImmutableDocumentBinaryStore
{
    /** @return array{disk:string,path:string,sha256:string,size:int} */
    public function capture(
        int $tenantId,
        int $fileId,
        int $version,
        string $sourceDisk,
        string $sourcePath,
    ): array {
        if ($tenantId < 1 || $fileId < 1 || $version < 1) {
            throw new RuntimeException('A valid tenant, file and version are required for immutable evidence.');
        }

        $source = Storage::disk($sourceDisk)->readStream($sourcePath);
        if (! is_resource($source)) {
            throw new RuntimeException('The source binary is unavailable for immutable capture.');
        }

        $targetDiskName = (string) config('mases.documents.evidence_disk', 'local');
        $targetDisk = Storage::disk($targetDiskName);
        try {
            $root = $targetDisk->path('');
        } catch (\Throwable $exception) {
            fclose($source);
            throw new RuntimeException('The configured evidence disk does not support local write-once semantics.', 0, $exception);
        }

        $fileDirectory = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'document-evidence'
            . DIRECTORY_SEPARATOR . $tenantId . DIRECTORY_SEPARATOR . $fileId;
        if (! is_dir($fileDirectory) && ! mkdir($fileDirectory, 0700, true) && ! is_dir($fileDirectory)) {
            fclose($source);
            throw new RuntimeException('The immutable evidence parent directory could not be created.');
        }

        // The temporary file is outside the sealed version directory so a
        // repeated identical capture can be verified after that directory is
        // made read-only. A changed binary for the same version cannot be linked.
        $temporary = $fileDirectory . DIRECTORY_SEPARATOR . '.capture-' . Str::uuid();
        $output = fopen($temporary, 'x+b');
        if (! is_resource($output)) {
            fclose($source);
            throw new RuntimeException('A unique temporary evidence file could not be created.');
        }

        $context = hash_init('sha256');
        $size = 0;
        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('The source evidence stream could not be read.');
                }
                if ($chunk === '') {
                    continue;
                }
                hash_update($context, $chunk);
                $written = 0;
                $length = strlen($chunk);
                while ($written < $length) {
                    $count = fwrite($output, substr($chunk, $written));
                    if ($count === false || $count === 0) {
                        throw new RuntimeException('The immutable evidence stream could not be written.');
                    }
                    $written += $count;
                }
                $size += $length;
            }
            if (! fflush($output)) {
                throw new RuntimeException('The immutable evidence stream could not be flushed.');
            }
            if (function_exists('fsync')) {
                fsync($output);
            }
        } catch (\Throwable $exception) {
            fclose($source);
            fclose($output);
            @unlink($temporary);
            throw $exception;
        }
        fclose($source);
        fclose($output);

        $sha256 = hash_final($context);
        $versionDirectory = $fileDirectory . DIRECTORY_SEPARATOR . $version;
        if (! is_dir($versionDirectory) && ! mkdir($versionDirectory, 0700, true) && ! is_dir($versionDirectory)) {
            @unlink($temporary);
            throw new RuntimeException('The immutable evidence version directory could not be created.');
        }

        $relative = 'document-evidence/' . $tenantId . '/' . $fileId . '/' . $version . '/' . $sha256;
        $destination = $versionDirectory . DIRECTORY_SEPARATOR . $sha256;

        if (is_file($destination)) {
            if (filesize($destination) !== $size || ! hash_equals($sha256, (string) hash_file('sha256', $destination))) {
                @unlink($temporary);
                throw new RuntimeException('An existing immutable evidence object does not match its content address.');
            }
            @unlink($temporary);
            return ['disk' => $targetDiskName, 'path' => $relative, 'sha256' => $sha256, 'size' => $size];
        }

        // link() is atomic and refuses to replace an existing inode. Once the
        // version directory is sealed read/execute-only, this process cannot
        // add another digest for that File Management version.
        if (! @link($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('The File Management version is already sealed or the filesystem cannot guarantee atomic no-overwrite creation.');
        }
        @chmod($destination, 0400);
        @unlink($temporary);
        @chmod($versionDirectory, 0500);

        if (! $this->verify($targetDiskName, $relative, $sha256, $size)) {
            throw new RuntimeException('The immutable evidence object failed its post-write integrity check.');
        }

        return ['disk' => $targetDiskName, 'path' => $relative, 'sha256' => $sha256, 'size' => $size];
    }

    public function verify(string $disk, string $path, string $sha256, int $size): bool
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $sha256) || $size < 0) {
            return false;
        }
        try {
            $filesystem = Storage::disk($disk);
            $absolute = $filesystem->path($path);
        } catch (\Throwable) {
            return false;
        }

        return is_file($absolute)
            && filesize($absolute) === $size
            && hash_equals($sha256, (string) hash_file('sha256', $absolute));
    }
}
