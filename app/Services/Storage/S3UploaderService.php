<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Contracts\StorageUploaderContract;
use App\DTOs\UploadResult;
use App\Exceptions\S3UploadException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Concrete implementation that uploads to an S3-compatible disk via
 * Laravel's Filesystem abstraction (Flysystem under the hood).
 *
 * Single Responsibility: only knows how to put things on S3.
 * Dependency Inversion: depends on the Filesystem contract, not the S3 SDK directly.
 */
final class S3UploaderService implements StorageUploaderContract
{
    public function __construct(
        private readonly Filesystem      $disk,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function uploadContent(string $content, string $s3Key): UploadResult
    {
        try {
            $result = $this->disk->put($s3Key, $content);

            if ($result === false) {
                throw new S3UploadException(
                    "Filesystem::put returned false for key [{$s3Key}].",
                    localPath: '',
                    s3Key: $s3Key,
                );
            }

            $this->logger->debug('S3UploaderService: content uploaded.', ['s3Key' => $s3Key]);

            return UploadResult::success(s3Path: $s3Key, localPath: '');
        } catch (S3UploadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new S3UploadException(
                "Failed to upload content to S3 key [{$s3Key}]: {$e->getMessage()}",
                localPath: '',
                s3Key: $s3Key,
                previous: $e,
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function uploadFile(string $localPath, string $s3Key): UploadResult
    {
        if (! file_exists($localPath)) {
            throw new S3UploadException(
                "Local file not found: [{$localPath}].",
                localPath: $localPath,
                s3Key: $s3Key,
            );
        }

        try {
            $stream = fopen($localPath, 'rb');

            if ($stream === false) {
                throw new S3UploadException(
                    "Could not open local file for reading: [{$localPath}].",
                    localPath: $localPath,
                    s3Key: $s3Key,
                );
            }

            $result = $this->disk->put($s3Key, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($result === false) {
                throw new S3UploadException(
                    "Filesystem::put returned false for key [{$s3Key}].",
                    localPath: $localPath,
                    s3Key: $s3Key,
                );
            }

            $this->logger->debug('S3UploaderService: file uploaded.', [
                'localPath' => $localPath,
                's3Key'     => $s3Key,
            ]);

            return UploadResult::success(s3Path: $s3Key, localPath: $localPath);
        } catch (S3UploadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new S3UploadException(
                "Failed to upload file [{$localPath}] to S3 key [{$s3Key}]: {$e->getMessage()}",
                localPath: $localPath,
                s3Key: $s3Key,
                previous: $e,
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $s3Key): bool
    {
        return $this->disk->exists($s3Key);
    }
}

