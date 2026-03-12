<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\FileRepositoryContract;
use App\Contracts\StorageUploaderContract;
use App\DTOs\UploadResult;
use App\Models\File;
use App\Services\Migration\FileMigrationStrategy;
use Illuminate\Contracts\Filesystem\Filesystem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Illuminate\Support\LazyCollection;

class FileMigrationStrategyTest extends TestCase
{
    private FileRepositoryContract&MockObject  $fileRepo;
    private StorageUploaderContract&MockObject $uploader;
    private Filesystem&MockObject              $localDisk;
    private FileMigrationStrategy              $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileRepo  = $this->createMock(FileRepositoryContract::class);
        $this->uploader  = $this->createMock(StorageUploaderContract::class);
        $this->localDisk = $this->createMock(Filesystem::class);
        $this->localDisk->method('get')->willReturn('fake file content');

        $this->strategy = new FileMigrationStrategy(
            fileRepository: $this->fileRepo,
            uploader:       $this->uploader,
            localDisk:      $this->localDisk,
            logger:         new NullLogger(),
        );
    }

    private function makeFile(int $id, string $name, string $path): File
    {
        $file       = new File();
        $file->id   = $id;
        $file->name = $name;
        $file->path = $path;
        return $file;
    }

    // ── label ─────────────────────────────────────────────────────────────

    public function test_label_returns_expected_string(): void
    {
        $this->assertSame('Local Files → S3', $this->strategy->label());
    }

    // ── empty stream ──────────────────────────────────────────────────────

    public function test_migrate_returns_zeros_when_no_files(): void
    {
        $this->fileRepo->method('streamUnmigrated')->willReturn(LazyCollection::empty());

        $result = $this->strategy->migrate();

        $this->assertSame(0, $result->totalProcessed);
        $this->assertSame(0, $result->succeeded);
        $this->assertSame(0, $result->failed);
    }

    // ── skips file with empty path ────────────────────────────────────────

    public function test_migrate_skips_file_with_empty_path(): void
    {
        $file = $this->makeFile(1, 'doc.pdf', '');
        $this->fileRepo->method('streamUnmigrated')->willReturn(LazyCollection::make([$file]));

        $this->uploader->expects($this->never())->method('uploadContent');

        $result = $this->strategy->migrate();

        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->failed);
    }

    // ── fails when local file missing on disk ────────────────────────────

    public function test_migrate_counts_failure_when_local_file_missing(): void
    {
        $file = $this->makeFile(2, 'missing.pdf', 'fake_files/missing.pdf');
        $this->fileRepo->method('streamUnmigrated')->willReturn(LazyCollection::make([$file]));

        $this->localDisk->method('exists')->willReturn(false);
        $this->uploader->expects($this->never())->method('uploadContent');

        $result = $this->strategy->migrate();

        $this->assertSame(1, $result->failed);
        $this->assertSame(0, $result->succeeded);
    }

    // ── successful upload ─────────────────────────────────────────────────

    public function test_migrate_uploads_file_and_marks_migrated(): void
    {
        $file = $this->makeFile(3, 'invoice.pdf', 'fake_files/invoice.pdf');
        $this->fileRepo->method('streamUnmigrated')->willReturn(LazyCollection::make([$file]));

        $this->localDisk->method('exists')->willReturn(true);
        $this->uploader->method('exists')->willReturn(false);

        $expectedS3Key = 'emails/files/3_invoice.pdf';
        $this->uploader->expects($this->once())
            ->method('uploadContent')
            ->willReturn(UploadResult::success($expectedS3Key, '/path/invoice.pdf'));

        $this->fileRepo->expects($this->once())
            ->method('markMigrated')
            ->with(3, $expectedS3Key);

        $result = $this->strategy->migrate();

        $this->assertSame(1, $result->succeeded);
        $this->assertSame(0, $result->failed);
    }

    // ── upload failure ────────────────────────────────────────────────────

    public function test_migrate_counts_failure_when_upload_fails(): void
    {
        $file = $this->makeFile(4, 'photo.jpg', 'fake_files/photo.jpg');
        $this->fileRepo->method('streamUnmigrated')->willReturn(LazyCollection::make([$file]));

        $this->localDisk->method('exists')->willReturn(true);
        $this->uploader->method('exists')->willReturn(false);
        $this->uploader->method('uploadContent')
            ->willReturn(UploadResult::failure('/path/photo.jpg', 's3 timeout'));

        $this->fileRepo->expects($this->never())->method('markMigrated');

        $result = $this->strategy->migrate();

        $this->assertSame(1, $result->failed);
        $this->assertSame(0, $result->succeeded);
    }

    // ── idempotency ───────────────────────────────────────────────────────

    public function test_migrate_skips_when_key_already_on_s3(): void
    {
        $file = $this->makeFile(5, 'report.xlsx', 'fake_files/report.xlsx');
        $this->fileRepo->method('streamUnmigrated')->willReturn(LazyCollection::make([$file]));

        $this->localDisk->method('exists')->willReturn(true);
        $this->uploader->method('exists')->willReturn(true);
        $this->uploader->expects($this->never())->method('uploadContent');

        $this->fileRepo->expects($this->once())
            ->method('markMigrated')
            ->with(5, 'emails/files/5_report.xlsx');

        $result = $this->strategy->migrate();

        $this->assertSame(1, $result->skipped);
    }

    // ── dry-run ───────────────────────────────────────────────────────────

    public function test_migrate_dry_run_does_not_upload_or_write(): void
    {
        $file = $this->makeFile(6, 'data.csv', 'fake_files/data.csv');
        $this->fileRepo->method('streamUnmigrated')->willReturn(LazyCollection::make([$file]));

        $this->localDisk->method('exists')->willReturn(true);
        $this->uploader->method('exists')->willReturn(false);
        $this->uploader->expects($this->never())->method('uploadContent');
        $this->fileRepo->expects($this->never())->method('markMigrated');

        $result = $this->strategy->migrate(['dry_run' => true]);

        $this->assertSame(1, $result->succeeded);
        $this->assertSame(0, $result->failed);
    }

    // ── custom s3 prefix ──────────────────────────────────────────────────

    public function test_migrate_uses_custom_s3_prefix(): void
    {
        $file = $this->makeFile(7, 'doc.txt', 'fake_files/doc.txt');
        $this->fileRepo->method('streamUnmigrated')->willReturn(LazyCollection::make([$file]));

        $this->localDisk->method('exists')->willReturn(true);
        $this->uploader->method('exists')->willReturn(false);

        $this->uploader->expects($this->once())
            ->method('uploadContent')
            ->with($this->anything(), 'custom/7_doc.txt')
            ->willReturn(UploadResult::success('custom/7_doc.txt', ''));

        $this->strategy->migrate(['s3_prefix' => 'custom']);
    }

    // ── chunk_size forwarded ──────────────────────────────────────────────

    public function test_migrate_passes_chunk_size_to_repository(): void
    {
        $this->fileRepo->expects($this->once())
            ->method('streamUnmigrated')
            ->with(100)
            ->willReturn(LazyCollection::empty());

        $this->strategy->migrate(['chunk_size' => 100]);
    }

    // ── multiple files, mixed outcomes ────────────────────────────────────

    public function test_migrate_handles_mixed_outcomes(): void
    {
        $files = [
            $this->makeFile(1, 'a.pdf', 'fake_files/a.pdf'),
            $this->makeFile(2, 'b.pdf', 'fake_files/b.pdf'),
            $this->makeFile(3, 'c.pdf', ''), // empty path → skipped
        ];
        $this->fileRepo->method('streamUnmigrated')->willReturn(LazyCollection::make($files));

        $this->localDisk->method('exists')->willReturn(true);
        $this->uploader->method('exists')->willReturn(false);
        $this->uploader->method('uploadContent')
            ->willReturnOnConsecutiveCalls(
                UploadResult::success('emails/files/1_a.pdf', ''),
                UploadResult::failure('', 'network error'),
            );

        $result = $this->strategy->migrate();

        $this->assertSame(3, $result->totalProcessed);
        $this->assertSame(1, $result->succeeded);
        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->skipped);
    }
}

