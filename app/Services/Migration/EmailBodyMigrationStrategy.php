<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Contracts\EmailRepositoryContract;
use App\Contracts\FileRepositoryContract;
use App\Contracts\MigrationStrategyContract;
use App\Contracts\StorageUploaderContract;
use App\DTOs\MigrationResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Concrete Strategy: for each email record, migrates:
 *   1. The HTML body column → S3 as [email_id].html  (body_s3_path)
 *   2. Each attached file referenced in file_ids      (file_ids replaced with S3 paths)
 *
 * Single Responsibility: orchestrates per-email migration logic only.
 * Dependency Inversion: uses contracts for repository and uploader.
 */
final class EmailBodyMigrationStrategy implements MigrationStrategyContract
{
    public function __construct(
        private readonly EmailRepositoryContract $emailRepository,
        private readonly FileRepositoryContract  $fileRepository,
        private readonly StorageUploaderContract $uploader,
        private readonly Filesystem              $localDisk,
        private readonly LoggerInterface         $logger,
    ) {}

    public function label(): string
    {
        return 'Email Bodies → S3';
    }

    /**
     * {@inheritdoc}
     *
     * Supported $options keys:
     *   - chunk_size       (int)    : records per lazy-cursor chunk (default 500)
     *   - dry_run          (bool)   : log without writing (default false)
     *   - s3_prefix        (string) : S3 key prefix for bodies (default 'emails/bodies')
     *   - files_s3_prefix  (string) : S3 key prefix for attachments (default 'emails/files')
     */
    public function migrate(array $options = []): MigrationResult
    {
        $chunkSize   = (int)   ($options['chunk_size']      ?? 500);
        $dryRun      = (bool)  ($options['dry_run']         ?? false);
        $bodyPrefix  = (string)($options['s3_prefix']       ?? 'emails/bodies');
        $filesPrefix = (string)($options['files_s3_prefix'] ?? 'emails/files');

        $processed = $succeeded = $failed = $skipped = 0;
        $startedAt = microtime(true);
        $label     = $this->dryRunLabel($dryRun);

        $this->logger->info("EmailBodyMigrationStrategy: starting{$label}.", [
            'chunkSize'   => $chunkSize,
            'bodyPrefix'  => $bodyPrefix,
            'filesPrefix' => $filesPrefix,
        ]);

        foreach ($this->emailRepository->streamUnmigratedBodies($chunkSize) as $email) {
            $processed++;

            if (empty($email->body)) {
                $skipped++;
                continue;
            }

            $bodyS3Key = "{$bodyPrefix}/{$email->id}.html";
            // ── Idempotency: body already on S3 ────────────────────────────
            if ($this->uploader->exists($bodyS3Key)) {
                $this->logger->debug('EmailBodyMigrationStrategy: body already on S3, marking.', [
                    'emailId' => $email->id,
                ]);
                if (! $dryRun) {
                    $this->emailRepository->markBodyMigrated($email->id, $bodyS3Key);
                    $fileIds = $email->file_ids ?? [];
                    if (is_string($fileIds)) {
                        $fileIds = json_decode($fileIds, true) ?? [];
                    }
                    $this->migrateAttachments($email->id, $fileIds, $filesPrefix, $dryRun);
                }
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $fileIds = $email->file_ids ?? [];
                if (is_string($fileIds)) {
                    $fileIds = json_decode($fileIds, true) ?? [];
                }
                $this->logger->debug("EmailBodyMigrationStrategy [DRY-RUN]: would upload body + attachments.", [
                    'emailId'      => $email->id,
                    'bodyS3Key'    => $bodyS3Key,
                    'attachments'  => count($fileIds),
                ]);
                $succeeded++;
                continue;
            }
            // ── 1. Upload body ──────────────────────────────────────────────
            $bodyResult = $this->uploader->uploadContent($email->body, $bodyS3Key);

            if (! $bodyResult->success) {
                $failed++;
                $this->logger->error('EmailBodyMigrationStrategy: body upload failed.', [
                    'emailId'  => $email->id,
                    's3Key'    => $bodyS3Key,
                    'error'    => $bodyResult->errorMessage,
                    'attempts' => $bodyResult->attempts,
                ]);
                continue;
            }

            $this->emailRepository->markBodyMigrated($email->id, $bodyS3Key);

            // ── 2. Upload attachments ───────────────────────────────────────
            $fileIds = $email->file_ids ?? [];
            if (is_string($fileIds)) {
                $fileIds = json_decode($fileIds, true) ?? [];
            }
            $this->migrateAttachments($email->id, $fileIds, $filesPrefix, dryRun: false);

            $succeeded++;
            $this->logger->debug('EmailBodyMigrationStrategy: email fully migrated.', [
                'emailId'  => $email->id,
                'attempts' => $bodyResult->attempts,
            ]);
        }

        $result = new MigrationResult(
            totalProcessed: $processed,
            succeeded: $succeeded,
            failed: $failed,
            skipped: $skipped,
            durationSeconds: microtime(true) - $startedAt,
        );

        $this->logger->info("EmailBodyMigrationStrategy: finished{$label}. {$result->summary()}");

        return $result;
    }

    /**
     * Upload every attachment referenced in $fileIds to S3 and update the
     * email record's file_ids column with the resulting S3 paths.
     *
     * @param  int[]|string[]  $fileIds
     */
    private function migrateAttachments(int $emailId, array $fileIds, string $prefix, bool $dryRun): void
    {
        if (empty($fileIds)) {
            return;
        }

        $s3Paths = [];

        foreach ($fileIds as $fileId) {
            // If the value is already an S3 path (re-run), keep it as-is.
            if (is_string($fileId) && str_starts_with($fileId, $prefix)) {
                $s3Paths[] = $fileId;
                continue;
            }

            $file = $this->fileRepository->findById((int) $fileId);

            if ($file === null) {
                $this->logger->warning('EmailBodyMigrationStrategy: file record not found.', [
                    'emailId' => $emailId,
                    'fileId'  => $fileId,
                ]);
                continue;
            }

            $s3Key = "{$prefix}/{$file->id}_{$file->name}";

            if ($dryRun) {
                $s3Paths[] = $s3Key;
                continue;
            }

            // Idempotency
            if ($this->uploader->exists($s3Key)) {
                $this->fileRepository->markMigrated($file->id, $s3Key);
                $s3Paths[] = $s3Key;
                continue;
            }

            if (! $this->localDisk->exists($file->path)) {
                $this->logger->error('EmailBodyMigrationStrategy: local file missing.', [
                    'fileId' => $file->id,
                    'path'   => $file->path,
                ]);
                continue;
            }

            $content = $this->localDisk->get($file->path);
            $result  = $this->uploader->uploadContent($content, $s3Key);

            if ($result->success) {
                $this->fileRepository->markMigrated($file->id, $s3Key);
                $s3Paths[] = $s3Key;
            } else {
                $this->logger->error('EmailBodyMigrationStrategy: attachment upload failed.', [
                    'emailId'  => $emailId,
                    'fileId'   => $file->id,
                    's3Key'    => $s3Key,
                    'error'    => $result->errorMessage,
                    'attempts' => $result->attempts,
                ]);
            }
        }

        if (! $dryRun && ! empty($s3Paths)) {
            $this->emailRepository->markFilesMigrated($emailId, $s3Paths);
        }
    }

    private function dryRunLabel(bool $dryRun): string
    {
        return $dryRun ? ' [DRY-RUN]' : '';
    }
}

