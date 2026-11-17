<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GovernanceElectionDisputeEvidenceService
{
    private const SUBJECT_TABLES = [
        'objection' => 'governance_election_objections',
        'appeal' => 'governance_election_appeals',
    ];

    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct(
        private readonly GovernanceElectionDisputeEventLedger $ledger,
    ) {}

    /**
     * Store evidence as a private object and record integrity metadata.
     * The returned record is quarantined/pending; this method does not
     * establish admissibility or treat the evidence as accepted.
     */
    public function store(
        UploadedFile $file,
        int $tenantId,
        int $electionId,
        string $subjectType,
        int $subjectId,
        int $actorUserId,
    ): object {
        if ($tenantId < 1 || $electionId < 1 || $subjectId < 1 || $actorUserId < 1 || !isset(self::SUBJECT_TABLES[$subjectType])) {
            throw new InvalidArgumentException('A valid tenant, election, dispute subject, and authenticated actor are required.');
        }

        $actor = DB::table('users')->where('id', $actorUserId)->where('is_active', true)->whereNull('deleted_at')->first(['id']);
        if ($actor === null) {
            throw new InvalidArgumentException('The evidence uploader must be an active account.');
        }

        $person = DB::table('people')->where('user_id', $actorUserId)->whereNull('deleted_at')->first(['id']);
        if ($person === null || !DB::table('person_tenant_accesses')
            ->where('person_id', $person->id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists()) {
            throw new InvalidArgumentException('The evidence uploader must have active access to the requested tenant.');
        }

        $maxBytes = (int) config('mases.governance.dispute_evidence_max_bytes', 20 * 1024 * 1024);
        $size = (int) $file->getSize();
        if ($size < 1 || $size > $maxBytes) {
            throw new InvalidArgumentException('Evidence file is empty or exceeds the configured upload limit.');
        }

        $realPath = $file->getRealPath();
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new RuntimeException('Uploaded evidence bytes are not readable.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);
        if (!is_string($mime) || !isset(self::ALLOWED_MIME_TYPES[$mime])) {
            throw new InvalidArgumentException('Evidence file type is not permitted.');
        }

        if (!$this->hasValidContentSignature($realPath, $mime)) {
            throw new InvalidArgumentException('Evidence content signature does not match its detected file type.');
        }

        $clientExtension = strtolower($file->getClientOriginalExtension());
        $expectedExtensions = $mime === 'image/jpeg'
            ? ['jpg', 'jpeg']
            : [self::ALLOWED_MIME_TYPES[$mime]];
        if (!in_array($clientExtension, $expectedExtensions, true)) {
            throw new InvalidArgumentException('Evidence filename extension does not match its detected content type.');
        }

        $sha256 = hash_file('sha256', $realPath);
        if (!is_string($sha256) || strlen($sha256) !== 64) {
            throw new RuntimeException('Evidence SHA-256 digest could not be calculated.');
        }

        $diskName = (string) config('mases.governance.dispute_evidence_disk', 'local');
        if ($diskName === '' || in_array($diskName, ['public', 's3-public'], true)) {
            throw new RuntimeException('Dispute evidence must use a private storage disk.');
        }

        $disk = Storage::disk($diskName);
        $key = sprintf(
            'governance/dispute-evidence/%d/%d/%s.%s',
            $tenantId,
            $electionId,
            (string) Str::uuid(),
            self::ALLOWED_MIME_TYPES[$mime],
        );
        $stream = fopen($realPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Evidence upload stream could not be opened.');
        }

        try {
            $stored = $disk->put($key, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (!$stored || !$disk->exists($key)) {
            $disk->delete($key);
            throw new RuntimeException('Private evidence storage failed.');
        }

        try {
            $disk->setVisibility($key, 'private');
            if ($disk->getVisibility($key) !== 'private') {
                throw new RuntimeException('Evidence storage did not confirm private visibility.');
            }

            $storedStream = $disk->readStream($key);
            if (!is_resource($storedStream)) {
                throw new RuntimeException('Stored evidence could not be read back for integrity verification.');
            }

            $storedHashContext = hash_init('sha256');
            hash_update_stream($storedHashContext, $storedStream);
            fclose($storedStream);
            $storedSha256 = hash_final($storedHashContext);

            if (!hash_equals($sha256, $storedSha256)) {
                throw new RuntimeException('Stored evidence checksum does not match the uploaded bytes.');
            }

            return DB::transaction(function () use (
                $tenantId,
                $electionId,
                $subjectType,
                $subjectId,
                $actorUserId,
                $file,
                $mime,
                $size,
                $sha256,
                $diskName,
                $key,
            ): object {
                // Keep lock ordering consistent with the event ledger:
                // election first, then dispute subject, to avoid deadlocks.
                $election = DB::table('governance_elections')
                    ->where('id', $electionId)
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->first(['id']);

                if ($election === null) {
                    throw new RuntimeException('Election does not exist in the requested tenant.');
                }

                $subject = DB::table(self::SUBJECT_TABLES[$subjectType])
                    ->where('id', $subjectId)
                    ->where('tenant_id', $tenantId)
                    ->where('election_id', $electionId)
                    ->lockForUpdate()
                    ->first(['id']);

                if ($subject === null) {
                    throw new RuntimeException('Dispute subject does not exist in the requested tenant and election.');
                }

                $now = now()->format('Y-m-d H:i:s');
                $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', basename($file->getClientOriginalName())) ?: 'evidence';
                $id = DB::table('governance_election_dispute_evidence')->insertGetId([
                    'tenant_id' => $tenantId,
                    'election_id' => $electionId,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'storage_disk' => $diskName,
                    'storage_key' => $key,
                    'original_filename' => mb_substr($originalName, 0, 255),
                    'detected_mime_type' => $mime,
                    'byte_size' => $size,
                    'content_sha256' => $sha256,
                    'verification_status' => 'pending',
                    'uploaded_by' => $actorUserId,
                    'uploaded_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->ledger->append(
                    $tenantId,
                    $electionId,
                    $subjectType,
                    $subjectId,
                    'evidence.uploaded',
                    $actorUserId,
                    ['evidence_id' => $id, 'sha256' => $sha256, 'byte_size' => $size, 'mime_type' => $mime],
                );

                return DB::table('governance_election_dispute_evidence')->where('id', $id)->first();
            }, 3);
        } catch (Throwable $exception) {
            $disk->delete($key);
            throw $exception;
        }

    private function hasValidContentSignature(string $path, string $mime): bool
    {
        $sample = file_get_contents($path, false, null, 0, 4096);
        if (!is_string($sample)) {
            return false;
        }

        return match ($mime) {
            'application/pdf' => str_starts_with($sample, '%PDF-'),
            'image/jpeg' => str_starts_with($sample, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($sample, "\x89PNG\r\n\x1A\n"),
            'image/webp' => str_starts_with($sample, 'RIFF') && substr($sample, 8, 4) === 'WEBP',
            'text/plain' => !str_contains($sample, "\0"),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->isOfficeArchive($path, 'word/document.xml'),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => $this->isOfficeArchive($path, 'xl/workbook.xml'),
            default => false,
        };
    }

    private function isOfficeArchive(string $path, string $requiredPart): bool
    {
        if (!class_exists(\ZipArchive::class)) {
            return false;
        }

        $archive = new \ZipArchive();
        if ($archive->open($path) !== true) {
            return false;
        }

        try {
            return $archive->locateName('[Content_Types].xml') !== false
                && $archive->locateName($requiredPart) !== false;
        } finally {
            $archive->close();
        }
    }
    }
}
