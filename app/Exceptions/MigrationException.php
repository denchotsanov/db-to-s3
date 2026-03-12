<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class MigrationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $migrationContext,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

