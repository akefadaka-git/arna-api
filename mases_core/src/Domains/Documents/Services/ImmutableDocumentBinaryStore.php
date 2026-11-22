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
            $root = realpath($targetDisk->path(''));
        } catch (\Throwable $exception) {
            fclose($source);
            throw new RuntimeException('The configured evidence disk does not support local write-once semantics.', 0, $exception);
        }
        if ($root === false || ! is_dir($root)) {
            fclose($source);
            throw new RuntimeException('The configured evidence disk root is unavailable.');
        }

        $fileDirectory = $root . DIRECTORY_SEPARATOR . 'document-evidence'
            . DIRECTORY_SEPARATOR . $tenantId . DIRECTORY_SEPARATOR . $fileId;
        if (! is_dir($fileDirectory) && ! mkdir($fileDirectory, 0700, true) && ! is_dir($fileDirectory)) {
            fclose($source);
            throw new RuntimeException('The immutable evidence parent directory could not be created.');
        }
        if (! @chmod($fileDirectory, 0700) || (fileperms($fileDirectory) & 0777) !== 0700) {
            fclose($source);
            throw new RuntimeException('The immutable evidence parent directory permissions could not be restricted.');
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
        if (! @chmod($temporary, 0600) || (fileperms($temporary) & 0777) !== 0600) {
            fclose($source);
            fclose($output);
            @unlink($temporary);
            throw new RuntimeException('The temporary evidence file permissions could not be restricted.');
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
                    if (feof($source)) {
                        break;
                    }
                    throw new RuntimeException('The source evidence stream stalled before EOF.');
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
            if (function_exists('fsync') && ! fsync($output)) {
                throw new RuntimeException('The immutable evidence stream could not be synchronized to storage.');
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
        return $this->persistVersionSnapshot(
            $targetDiskName,
            $fileDirectory,
            $version,
            $temporary,
            $sha256,
            $size,
        );
        }


    /**
     * Serialize writes for one File Management version. The lock lives in the
     * writable parent directory, not the sealed version directory, so a retry
     * can safely verify an existing digest after the first writer finishes.
     *
     * @return array{disk:string,path:string,sha256:string,size:int}
     */
    private function persistVersionSnapshot(
        string $disk,
        string $fileDirectory,
        int $version,
        string $temporary,
        string $sha256,
        int $size,
    ): array {
        $lockPath = $fileDirectory . DIRECTORY_SEPARATOR . '.version-' . $version . '.lock';
        $lock = fopen($lockPath, 'c');
        if (! is_resource($lock)) {
            @unlink($temporary);
            throw new RuntimeException('The immutable evidence version lock could not be opened.');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                @unlink($temporary);
                throw new RuntimeException('The immutable evidence version lock could not be acquired.');
            }

            $versionDirectory = $fileDirectory . DIRECTORY_SEPARATOR . $version;
            if (! is_dir($versionDirectory) && ! mkdir($versionDirectory, 0700) && ! is_dir($versionDirectory)) {
                @unlink($temporary);
                throw new RuntimeException('The immutable evidence version directory could not be created.');
            }

            $relative = 'document-evidence/' . basename(dirname($fileDirectory)) . '/'
                . basename($fileDirectory) . '/' . $version . '/' . $sha256;
            $destination = $versionDirectory . DIRECTORY_SEPARATOR . $sha256;

            if (is_file($destination) && ! is_link($destination)) {
                if (filesize($destination) !== $size || ! hash_equals($sha256, (string) hash_file('sha256', $destination))) {
                    @unlink($temporary);
                    throw new RuntimeException('An existing immutable evidence object does not match its content address.');
                }
                @unlink($temporary);
                return ['disk' => $disk, 'path' => $relative, 'sha256' => $sha256, 'size' => $size];
            }

            if (! @link($temporary, $destination)) {
                @unlink($temporary);
                throw new RuntimeException('The File Management version is already sealed or the filesystem cannot guarantee atomic no-overwrite creation.');
            }
            @unlink($temporary);

            if (! @chmod($destination, 0400) || (fileperms($destination) & 0777) !== 0400
                || ! @chmod($versionDirectory, 0500) || (fileperms($versionDirectory) & 0777) !== 0500) {
                @chmod($versionDirectory, 0700);
                @unlink($destination);
                throw new RuntimeException('The immutable evidence object or version directory could not be sealed with the required permissions.');
            }

            if (! $this->verify($disk, $relative, $sha256, $size)) {
                throw new RuntimeException('The immutable evidence object failed its post-write integrity check.');
            }

            return ['disk' => $disk, 'path' => $relative, 'sha256' => $sha256, 'size' => $size];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Delete only the exact tenant/file/version content-addressed object after
     * the caller has atomically claimed its durable capture intent and checked
     * all database references. Uses the same per-version flock as capture.
     *
     * Returns false for path, symlink, digest or size mismatches. Missing
     * objects are treated as an idempotently completed deletion.
     */
    public function deleteIfMatches(
        string $disk,
        int $tenantId,
        int $fileId,
        int $version,
        string $path,
        string $sha256,
        int $size,
    ): bool {
        if ($tenantId < 1 || $fileId < 1 || $version < 1
            || ! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return false;
        }

        $expected = 'document-evidence/' . $tenantId . '/' . $fileId . '/' . $version . '/' . $sha256;
        if (! hash_equals($expected, $path)) {
            return false;
        }

        try {
            $filesystem = Storage::disk($disk);
            $root = realpath($filesystem->path(''));
        } catch (\\Throwable) {
            return false;
        }
        if ($root === false || ! is_dir($root)) {
            return false;
        }

        $fileDirectory = $root . DIRECTORY_SEPARATOR . 'document-evidence'
            . DIRECTORY_SEPARATOR . $tenantId . DIRECTORY_SEPARATOR . $fileId;
        $versionDirectory = $fileDirectory . DIRECTORY_SEPARATOR . $version;
        $target = $versionDirectory . DIRECTORY_SEPARATOR . $sha256;
        $lockPath = $fileDirectory . DIRECTORY_SEPARATOR . '.version-' . $version . '.lock';

        foreach ([$root . DIRECTORY_SEPARATOR . 'document-evidence',
            $root . DIRECTORY_SEPARATOR . 'document-evidence' . DIRECTORY_SEPARATOR . $tenantId,
            $fileDirectory, $versionDirectory] as $directory) {
            if (is_link($directory)) {
                return false;
            }
        }
        if (! is_dir($fileDirectory)) {
            return ! file_exists($target) && ! is_link($target);
        }

        if (is_link($lockPath)) {
            return false;
        }
        $lock = fopen($lockPath, 'c');
        if (! is_resource($lock)) {
            return false;
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                return false;
            }
            if (is_link($target)) {
                return false;
            }
            if (! is_file($target)) {
                return ! file_exists($target);
            }
            if (! $this->verify($disk, $path, $sha256, $size)) {
                return false;
            }
            if (! @chmod($versionDirectory, 0700)) {
                return false;
            }
            if (! @unlink($target)) {
                @chmod($versionDirectory, 0500);
                return false;
            }
            if (! @chmod($versionDirectory, 0500)) {
                return false;
            }

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function verify(string $disk, string $path, string $sha256, int $size): bool
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $sha256) || $size < 0
            || $path === '' || str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('#(^|/)\.\.?(/|$)#', str_replace('\\', '/', $path))) {
            return false;
        }
        try {
            $filesystem = Storage::disk($disk);
            $root = realpath($filesystem->path(''));
            $absolute = $filesystem->path($path);
            $resolved = realpath($absolute);
        } catch (\Throwable) {
            return false;
        }

        if ($root === false || $resolved === false || is_link($absolute)
            || ! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return false;
        }

        return is_file($resolved)
            && filesize($resolved) === $size
            && hash_equals($sha256, (string) hash_file('sha256', $resolved));
    }
}
