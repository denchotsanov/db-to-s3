<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\EmailRepositoryContract;
use App\Models\Email;
use Illuminate\Support\LazyCollection;

final class EmailRepository implements EmailRepositoryContract
{
    public function streamUnmigratedBodies(int $chunkSize = 500): LazyCollection
    {
        return Email::query()
            ->whereNull('body_s3_path')
            ->whereNotNull('body')
            ->select(['id', 'body', 'file_ids'])
            ->orderBy('id')
            ->lazy($chunkSize);
    }

    public function markBodyMigrated(int $emailId, string $s3Path): void
    {
        Email::query()
            ->where('id', $emailId)
            ->update([
                'body_s3_path' => $s3Path,
                'body'         => null,   // free DB space once safely on S3
            ]);
    }

    public function markFilesMigrated(int $emailId, array $s3Paths): void
    {
        Email::query()
            ->where('id', $emailId)
            ->update([
                'file_ids'      => json_encode($s3Paths),
                'file_s3_paths' => json_encode($s3Paths),
            ]);
    }

    public function countUnmigratedBodies(): int
    {
        return Email::query()
            ->whereNull('body_s3_path')
            ->whereNotNull('body')
            ->count();
    }
}
