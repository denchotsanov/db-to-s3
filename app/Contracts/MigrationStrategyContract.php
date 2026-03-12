<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\MigrationResult;

/**
 * Strategy contract for different migration types (emails, files, etc.).
 * Implementing the Strategy design pattern.
 */
interface MigrationStrategyContract
{
    /**
     * A human-readable label for this strategy (used in logs & output).
     */
    public function label(): string;

    /**
     * Execute the migration and return a summarised result.
     *
     * @param  array<string, mixed>  $options  Runtime options (e.g. chunk size, dry-run flag).
     */
    public function migrate(array $options = []): MigrationResult;
}

