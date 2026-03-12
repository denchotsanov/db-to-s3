<?php

declare(strict_types=1);

namespace App\DTOs;

final class UploadResult
{
    public function __construct(
        public readonly bool   $success,
        public readonly string $s3Path,
        public readonly string $localPath,
        public readonly ?string $errorMessage = null,
        public readonly int    $attempts = 1,
    ) {}

    public static function success(string $s3Path, string $localPath, int $attempts = 1): self
    {
        return new self(
            success: true,
            s3Path: $s3Path,
            localPath: $localPath,
            attempts: $attempts,
        );
    }

    public static function failure(string $localPath, string $errorMessage, int $attempts = 1): self
    {
        return new self(
            success: false,
            s3Path: '',
            localPath: $localPath,
            errorMessage: $errorMessage,
            attempts: $attempts,
        );
    }
}

