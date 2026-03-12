<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\FileRepositoryContract;
use App\Models\File;
use Illuminate\Support\LazyCollection;

final class FileRepository implements FileRepositoryContract
{
    public function streamUnmigrated(int $chunkSize = 500): LazyCollection
    {
        return File::query()
            ->whereNull('s3_path')
            ->select(['id', 'path', 'name', 'type'])
            ->lazyById($chunkSize);
    }

    public function markMigrated(int $fileId, string $s3Path): void
    {
        File::query()
            ->where('id', $fileId)
            ->update(['s3_path' => $s3Path]);
    }

    public function countUnmigrated(): int
    {
        return File::query()
            ->whereNull('s3_path')
            ->count();
    }

    public function findById(int $id): ?File
    {
        return File::query()->find($id);
    }
}

