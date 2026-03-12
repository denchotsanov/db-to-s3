<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\File;
use Illuminate\Support\LazyCollection;

/**
 * Repository contract for file data access.
 */
interface FileRepositoryContract
{
    /**
     * Stream all files that have not yet been migrated to S3.
     *
     * @return LazyCollection<int, File>
     */
    public function streamUnmigrated(int $chunkSize): LazyCollection;

    /**
     * Mark a file as migrated by saving the S3 path.
     */
    public function markMigrated(int $fileId, string $s3Path): void;

    /**
     * Count how many files have not yet been migrated.
     */
    public function countUnmigrated(): int;

    /**
     * Find a file by its primary key.
     */
    public function findById(int $id): ?File;
}

