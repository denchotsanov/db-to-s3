<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Email;
use Illuminate\Support\LazyCollection;

/**
 * Repository contract for email data access.
 * Implementing the Repository design pattern (Dependency Inversion).
 */
interface EmailRepositoryContract
{
    /**
     * Stream emails that need body migration, including file_ids for attachment processing.
     *
     * @return LazyCollection<int, Email>
     */
    public function streamUnmigratedBodies(int $chunkSize): LazyCollection;

    /**
     * Mark the email body as migrated and persist the S3 path.
     */
    public function markBodyMigrated(int $emailId, string $s3Path): void;

    /**
     * Update the email's file_ids column to a JSON array of S3 paths
     * once all attachments have been uploaded.
     *
     * @param  string[]  $s3Paths
     */
    public function markFilesMigrated(int $emailId, array $s3Paths): void;

    /**
     * Count how many email bodies have not yet been migrated.
     */
    public function countUnmigratedBodies(): int;
}
