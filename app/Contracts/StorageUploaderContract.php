<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\UploadResult;

interface StorageUploaderContract
{
    /**
     * Upload content (string) to the given S3 key.
     * Returns an UploadResult DTO describing the outcome.
     */
    public function uploadContent(string $content, string $s3Key): UploadResult;

    /**
     * Upload a local file by its path to the given S3 key.
     * Returns an UploadResult DTO describing the outcome.
     */
    public function uploadFile(string $localPath, string $s3Key): UploadResult;

    /**
     * Check whether a key already exists on the remote storage.
     */
    public function exists(string $s3Key): bool;
}

