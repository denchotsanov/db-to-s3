<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\EmailRepositoryContract;
use App\Contracts\FileRepositoryContract;
use App\Contracts\StorageUploaderContract;
use App\DTOs\MigrationResult;
use App\DTOs\UploadResult;
use App\Models\Email;
use App\Models\File;
use App\Services\Migration\EmailBodyMigrationStrategy;
use Illuminate\Contracts\Filesystem\Filesystem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Illuminate\Support\LazyCollection;

class EmailBodyMigrationStrategyTest extends TestCase
{
    private EmailRepositoryContract&MockObject $emailRepo;
    private FileRepositoryContract&MockObject  $fileRepo;
    private StorageUploaderContract&MockObject $uploader;
    private Filesystem&MockObject              $localDisk;
    private EmailBodyMigrationStrategy         $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emailRepo = $this->createMock(EmailRepositoryContract::class);
        $this->fileRepo  = $this->createMock(FileRepositoryContract::class);
        $this->uploader  = $this->createMock(StorageUploaderContract::class);
        $this->localDisk = $this->createMock(Filesystem::class);

        $this->strategy = new EmailBodyMigrationStrategy(
            emailRepository: $this->emailRepo,
            fileRepository:  $this->fileRepo,
            uploader:        $this->uploader,
            localDisk:       $this->localDisk,
            logger:          new NullLogger(),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeEmail(int $id, string $body, array $fileIds = []): Email
    {
        $email           = new Email();
        $email->id       = $id;
        $email->body     = $body;
        $email->file_ids = $fileIds;
        return $email;
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
        $this->assertSame('Email Bodies → S3', $this->strategy->label());
    }

    // ── empty stream ──────────────────────────────────────────────────────

    public function test_migrate_returns_zeros_when_no_emails(): void
    {
        $this->emailRepo->method('streamUnmigratedBodies')
            ->willReturn(LazyCollection::empty());

        $result = $this->strategy->migrate();

        $this->assertInstanceOf(MigrationResult::class, $result);
        $this->assertSame(0, $result->totalProcessed);
        $this->assertSame(0, $result->succeeded);
        $this->assertSame(0, $result->failed);
    }

    // ── skips email with empty body ───────────────────────────────────────

    public function test_migrate_skips_email_with_empty_body(): void
    {
        $email       = $this->makeEmail(1, '');
        $this->emailRepo->method('streamUnmigratedBodies')
            ->willReturn(LazyCollection::make([$email]));

        $this->uploader->expects($this->never())->method('uploadContent');
        $this->emailRepo->expects($this->never())->method('markBodyMigrated');

        $result = $this->strategy->migrate();

        $this->assertSame(1, $result->totalProcessed);
        $this->assertSame(0, $result->succeeded);
        $this->assertSame(1, $result->skipped);
    }

    // ── successful single email without attachments ───────────────────────

    public function test_migrate_uploads_body_and_marks_migrated(): void
    {
        $email = $this->makeEmail(42, '<p>hello</p>');
        $this->emailRepo->method('streamUnmigratedBodies')
            ->willReturn(LazyCollection::make([$email]));

        $this->uploader->method('exists')->willReturn(false);
        $this->uploader->expects($this->once())
            ->method('uploadContent')
            ->with('<p>hello</p>', 'emails/bodies/42.html')
            ->willReturn(UploadResult::success('emails/bodies/42.html', ''));

        $this->emailRepo->expects($this->once())
            ->method('markBodyMigrated')
            ->with(42, 'emails/bodies/42.html');

        $result = $this->strategy->migrate();

        $this->assertSame(1, $result->succeeded);
        $this->assertSame(0, $result->failed);
    }

    // ── body upload failure ───────────────────────────────────────────────

    public function test_migrate_counts_failure_when_body_upload_fails(): void
    {
        $email = $this->makeEmail(7, '<p>body</p>');
        $this->emailRepo->method('streamUnmigratedBodies')
            ->willReturn(LazyCollection::make([$email]));

        $this->uploader->method('exists')->willReturn(false);
        $this->uploader->method('uploadContent')
            ->willReturn(UploadResult::failure('', 'connection refused'));

        $this->emailRepo->expects($this->never())->method('markBodyMigrated');

        $result = $this->strategy->migrate();

        $this->assertSame(0, $result->succeeded);
        $this->assertSame(1, $result->failed);
    }

    // ── idempotency: key already on S3 ───────────────────────────────────

    public function test_migrate_skips_and_marks_when_body_already_on_s3(): void
    {
        $email = $this->makeEmail(5, '<p>already</p>');
        $this->emailRepo->method('streamUnmigratedBodies')
            ->willReturn(LazyCollection::make([$email]));

        $this->uploader->method('exists')->willReturn(true);
        $this->uploader->expects($this->never())->method('uploadContent');

        $this->emailRepo->expects($this->once())
            ->method('markBodyMigrated')
            ->with(5, 'emails/bodies/5.html');

        $result = $this->strategy->migrate();

        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->succeeded);
    }

    // ── dry-run ───────────────────────────────────────────────────────────

    public function test_migrate_dry_run_does_not_upload_or_write(): void
    {
        $email = $this->makeEmail(99, '<p>test</p>');
        $this->emailRepo->method('streamUnmigratedBodies')
            ->willReturn(LazyCollection::make([$email]));

        $this->uploader->method('exists')->willReturn(false);
        $this->uploader->expects($this->never())->method('uploadContent');
        $this->emailRepo->expects($this->never())->method('markBodyMigrated');

        $result = $this->strategy->migrate(['dry_run' => true]);

        $this->assertSame(1, $result->succeeded); // counted as would-succeed
        $this->assertSame(0, $result->failed);
    }

    // ── attachments ───────────────────────────────────────────────────────

    public function test_migrate_uploads_attachments_and_updates_file_ids(): void
    {
        $email = $this->makeEmail(10, '<p>with files</p>', [1, 2]);
        $this->emailRepo->method('streamUnmigratedBodies')
            ->willReturn(LazyCollection::make([$email]));

        $file1 = $this->makeFile(1, 'doc.pdf', 'fake_files/doc.pdf');
        $file2 = $this->makeFile(2, 'img.png', 'fake_files/img.png');

        $this->uploader->method('exists')->willReturn(false);

        // body upload + 2 attachment uploads = 3 total uploadContent calls
        $this->uploader->expects($this->exactly(3))
            ->method('uploadContent')
            ->willReturn(UploadResult::success('emails/files/x', ''));

        $this->localDisk->method('exists')->willReturn(true);
        $this->localDisk->method('get')->willReturn('file content');

        $this->fileRepo->method('findById')
            ->willReturnMap([[1, $file1], [2, $file2]]);

        $this->fileRepo->expects($this->exactly(2))->method('markMigrated');

        $this->emailRepo->expects($this->once())
            ->method('markFilesMigrated')
            ->with(10, $this->callback(fn ($paths) => count($paths) === 2));

        $result = $this->strategy->migrate();

        $this->assertSame(1, $result->succeeded);
        $this->assertSame(0, $result->failed);
    }

    public function test_migrate_skips_missing_file_records_gracefully(): void
    {
        $email = $this->makeEmail(11, '<p>body</p>', [999]);
        $this->emailRepo->method('streamUnmigratedBodies')
            ->willReturn(LazyCollection::make([$email]));

        $this->uploader->method('exists')->willReturn(false);
        $this->uploader->method('uploadContent')
            ->willReturn(UploadResult::success('emails/bodies/11.html', ''));

        $this->fileRepo->method('findById')->willReturn(null); // file not found


        // markFilesMigrated is NOT called when there are no resolved s3 paths
        $this->emailRepo->expects($this->never())->method('markFilesMigrated');

        $result = $this->strategy->migrate();

        $this->assertSame(1, $result->succeeded);
    }

    // ── custom s3 prefix ──────────────────────────────────────────────────

    public function test_migrate_uses_custom_s3_prefix(): void
    {
        $email = $this->makeEmail(3, '<p>hi</p>');
        $this->emailRepo->method('streamUnmigratedBodies')
            ->willReturn(LazyCollection::make([$email]));

        $this->uploader->method('exists')->willReturn(false);
        $this->uploader->expects($this->once())
            ->method('uploadContent')
            ->with('<p>hi</p>', 'custom/prefix/3.html')
            ->willReturn(UploadResult::success('custom/prefix/3.html', ''));

        $this->emailRepo->expects($this->once())
            ->method('markBodyMigrated')
            ->with(3, 'custom/prefix/3.html');

        $this->strategy->migrate(['s3_prefix' => 'custom/prefix']);
    }

    // ── multiple emails, mixed outcomes ───────────────────────────────────

    public function test_migrate_processes_multiple_emails_independently(): void
    {
        $emails = [
            $this->makeEmail(1, '<p>one</p>'),
            $this->makeEmail(2, '<p>two</p>'),
            $this->makeEmail(3, ''),               // skipped
        ];

        $this->emailRepo->method('streamUnmigratedBodies')
            ->willReturn(LazyCollection::make($emails));

        $this->uploader->method('exists')->willReturn(false);
        $this->uploader->method('uploadContent')
            ->willReturnOnConsecutiveCalls(
                UploadResult::success('emails/bodies/1.html', ''),
                UploadResult::failure('', 'error'),
            );

        $result = $this->strategy->migrate();

        $this->assertSame(3, $result->totalProcessed);
        $this->assertSame(1, $result->succeeded);
        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->skipped);
    }

    // ── chunk_size is forwarded ───────────────────────────────────────────

    public function test_migrate_passes_chunk_size_to_repository(): void
    {
        $this->emailRepo->expects($this->once())
            ->method('streamUnmigratedBodies')
            ->with(250)
            ->willReturn(LazyCollection::empty());

        $this->strategy->migrate(['chunk_size' => 250]);
    }
}

