<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Contracts\StorageUploaderContract;
use App\DTOs\UploadResult;
use App\Exceptions\S3UploadException;
use Psr\Log\LoggerInterface;

/**
 * Decorator that wraps any StorageUploaderContract implementation and adds
 * configurable exponential-backoff retry logic.
 *
 * Design patterns used:
 *   - Decorator  : transparently wraps the inner uploader.
 *   - Dependency Inversion : depends on the contract, not a concrete class.
 */
final class RetryingUploaderDecorator implements StorageUploaderContract
{
    public function __construct(
        private readonly StorageUploaderContract $inner,
        private readonly LoggerInterface         $logger,
        private readonly int                     $maxAttempts = 3,
        private readonly int                     $baseDelayMs = 200,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function uploadContent(string $content, string $s3Key): UploadResult
    {
        return $this->withRetry(
            fn () => $this->inner->uploadContent($content, $s3Key),
            localPath: '',
            s3Key: $s3Key,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function uploadFile(string $localPath, string $s3Key): UploadResult
    {
        return $this->withRetry(
            fn () => $this->inner->uploadFile($localPath, $s3Key),
            localPath: $localPath,
            s3Key: $s3Key,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $s3Key): bool
    {
        return $this->inner->exists($s3Key);
    }

    /**
     * Execute $operation with exponential backoff on S3UploadException.
     *
     * @param  callable(): UploadResult  $operation
     */
    private function withRetry(callable $operation, string $localPath, string $s3Key): UploadResult
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            try {
                $result = $operation();

                if ($attempt > 1) {
                    $this->logger->info('RetryingUploader: upload succeeded after retry.', [
                        'attempt' => $attempt,
                        's3Key'   => $s3Key,
                    ]);
                }

                // Surface the actual attempt count in the result
                return new UploadResult(
                    success: $result->success,
                    s3Path: $result->s3Path,
                    localPath: $result->localPath,
                    errorMessage: $result->errorMessage,
                    attempts: $attempt,
                );
            } catch (S3UploadException $e) {
                $lastException = $e;

                $this->logger->warning('RetryingUploader: upload attempt failed.', [
                    'attempt'    => $attempt,
                    'maxAttempts' => $this->maxAttempts,
                    's3Key'      => $s3Key,
                    'localPath'  => $localPath,
                    'error'      => $e->getMessage(),
                ]);

                if ($attempt < $this->maxAttempts) {
                    // Exponential back-off: 200ms, 400ms, 800ms …
                    $delayMs = $this->baseDelayMs * (2 ** ($attempt - 1));
                    usleep($delayMs * 1_000);
                }
            }
        }

        $this->logger->error('RetryingUploader: all attempts exhausted.', [
            'attempts'  => $attempt,
            's3Key'     => $s3Key,
            'localPath' => $localPath,
            'error'     => $lastException?->getMessage(),
        ]);

        return UploadResult::failure(
            localPath: $localPath,
            errorMessage: $lastException?->getMessage() ?? 'Unknown upload error',
            attempts: $attempt,
        );
    }
}

