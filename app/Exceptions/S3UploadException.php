<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class S3UploadException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $localPath,
        public readonly string $s3Key,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

