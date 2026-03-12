<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\File;
use App\Repositories\FileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private FileRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new FileRepository();
    }

    private function createFile(array $overrides = []): File
    {
        return File::create(array_merge([
            'name'    => 'document.pdf',
            'path'    => 'fake_files/document.pdf',
            'size'    => 10240,
            'type'    => 'application/pdf',
        ], $overrides));
    }

    // ── findById ──────────────────────────────────────────────────────────

    public function test_find_by_id_returns_correct_file(): void
    {
        $file = $this->createFile(['name' => 'specific.pdf']);

        $found = $this->repository->findById($file->id);

        $this->assertNotNull($found);
        $this->assertSame($file->id, $found->id);
        $this->assertSame('specific.pdf', $found->name);
    }

    public function test_find_by_id_returns_null_for_nonexistent(): void
    {
        $found = $this->repository->findById(99999);

        $this->assertNull($found);
    }
}

