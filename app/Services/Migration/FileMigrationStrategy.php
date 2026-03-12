<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Contracts\FileRepositoryContract;
use App\Contracts\MigrationStrategyContract;
use App\Contracts\StorageUploaderContract;
use App\DTOs\MigrationResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Concrete Strategy: migrates physical files from local disk to S3.
 *
 * SRP: only concerned with file-to-S3 upload orchestration.
 */
final class FileMigrationStrategy implements MigrationStrategyContract
{
    public function __construct(
        private readonly FileRepositoryContract  $fileRepository,
        private readonly StorageUploaderContract $uploader,
        private readonly Filesystem              $localDisk,
        private readonly LoggerInterface         $logger,
    ) {}

    public function label(): string
    {
        return 'Local Files → S3';
    }

    /**
     * {@inheritdoc}
     *
     * Supported $options keys:
     *   - chunk_size (int)   : records per lazy-cursor chunk (default 500)
     *   - dry_run    (bool)  : log what would happen without writing (default false)
     *   - s3_prefix  (string): S3 key prefix (default 'emails/files')
     */
    public function migrate(array $options = []): MigrationResult
    {
        $chunkSize = (int)   ($options['chunk_size'] ?? 500);
        $dryRun    = (bool)  ($options['dry_run']    ?? false);
        $prefix    = (string)($options['s3_prefix']  ?? 'emails/files');

        $processed = $succeeded = $failed = $skipped = 0;
        $startedAt = microtime(true);

        $this->logger->info("FileMigrationStrategy: starting{$this->dryRunLabel($dryRun)}.", [
            'chunkSize' => $chunkSize,
            'prefix'    => $prefix,
        ]);
        foreach ($this->fileRepository->streamUnmigrated($chunkSize) as $file) {
            $processed++;

            if (empty($file->path)) {
                $skipped++;
                $this->logger->warning('FileMigrationStrategy: file has no local path, skipping.', [
                    'fileId' => $file->id,
                ]);
                continue;
            }

            $localAbsolutePath = storage_path('app/private/' . $file->path);
            if (! $this->localDisk->exists($file->path)) {
                $failed++;
                $this->logger->error('FileMigrationStrategy: local file missing on disk.', [
                    'fileId'    => $file->id,
                    'localPath' => $localAbsolutePath,
                ]);
                continue;
            }

            $s3Key = "{$prefix}/{$file->id}_{$file->name}";

            // Idempotency guard
            if ($this->uploader->exists($s3Key)) {
                $this->logger->debug('FileMigrationStrategy: s3 key already exists, marking migrated.', [
                    'fileId' => $file->id,
                    's3Key'  => $s3Key,
                ]);

                if (! $dryRun) {
                    $this->fileRepository->markMigrated($file->id, $s3Key);
                }

                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->logger->debug('FileMigrationStrategy [DRY-RUN]: would upload.', [
                    'fileId'    => $file->id,
                    'localPath' => $localAbsolutePath,
                    's3Key'     => $s3Key,
                ]);
                $succeeded++;
                continue;
            }

            // Read content through the injected disk so Storage::fake() works in tests.
            $fileContent = $this->localDisk->get($file->path);
            $result      = $this->uploader->uploadContent($fileContent, $s3Key);

            if ($result->success) {
                $this->fileRepository->markMigrated($file->id, $s3Key);
                $succeeded++;

                $this->logger->debug('FileMigrationStrategy: file migrated.', [
                    'fileId'   => $file->id,
                    's3Key'    => $s3Key,
                    'attempts' => $result->attempts,
                ]);
            } else {
                $failed++;

                $this->logger->error('FileMigrationStrategy: upload failed.', [
                    'fileId'   => $file->id,
                    's3Key'    => $s3Key,
                    'error'    => $result->errorMessage,
                    'attempts' => $result->attempts,
                ]);
            }
        }

        $duration = microtime(true) - $startedAt;

        $result = new MigrationResult(
            totalProcessed: $processed,
            succeeded: $succeeded,
            failed: $failed,
            skipped: $skipped,
            durationSeconds: $duration,
        );

        $this->logger->info("FileMigrationStrategy: finished{$this->dryRunLabel($dryRun)}. {$result->summary()}");

        return $result;
    }

    private function dryRunLabel(bool $dryRun): string
    {
        return $dryRun ? ' [DRY-RUN]' : '';
    }
}

