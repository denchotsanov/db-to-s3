<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\DTOs\UploadResult;
use App\Exceptions\S3UploadException;
use App\Services\Storage\RetryingUploaderDecorator;
use App\Contracts\StorageUploaderContract;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RetryingUploaderDecoratorTest extends TestCase
{
    private function makeDecorator(
        StorageUploaderContract $inner,
        int $maxAttempts = 3,
        int $baseDelayMs = 0,   // zero delay in tests
    ): RetryingUploaderDecorator {
        return new RetryingUploaderDecorator($inner, new NullLogger(), $maxAttempts, $baseDelayMs);
    }

    // ── uploadContent ────────────────────────────────────────────────────

    public function test_upload_content_succeeds_on_first_attempt(): void
    {
        $inner = $this->createMock(StorageUploaderContract::class);
        $inner->expects($this->once())
            ->method('uploadContent')
            ->willReturn(UploadResult::success('emails/bodies/1.html', ''));

        $result = $this->makeDecorator($inner)->uploadContent('<p>hi</p>', 'emails/bodies/1.html');

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->attempts);
        $this->assertSame('emails/bodies/1.html', $result->s3Path);
    }

    public function test_upload_content_retries_on_s3_exception_and_eventually_succeeds(): void
    {
        $inner = $this->createMock(StorageUploaderContract::class);
        $inner->expects($this->exactly(3))
            ->method('uploadContent')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new S3UploadException('err', '', 'k')),
                $this->throwException(new S3UploadException('err', '', 'k')),
                UploadResult::success('k', ''),
            );

        $result = $this->makeDecorator($inner, maxAttempts: 3)->uploadContent('body', 'k');

        $this->assertTrue($result->success);
        $this->assertSame(3, $result->attempts);
    }

    public function test_upload_content_returns_failure_after_max_attempts(): void
    {
        $inner = $this->createMock(StorageUploaderContract::class);
        $inner->expects($this->exactly(3))
            ->method('uploadContent')
            ->willThrowException(new S3UploadException('network error', '', 'k'));

        $result = $this->makeDecorator($inner, maxAttempts: 3)->uploadContent('body', 'k');

        $this->assertFalse($result->success);
        $this->assertSame(3, $result->attempts);
        $this->assertStringContainsString('network error', $result->errorMessage);
    }

    public function test_upload_content_respects_max_attempts_of_one(): void
    {
        $inner = $this->createMock(StorageUploaderContract::class);
        $inner->expects($this->once())
            ->method('uploadContent')
            ->willThrowException(new S3UploadException('fail', '', 'k'));

        $result = $this->makeDecorator($inner, maxAttempts: 1)->uploadContent('body', 'k');

        $this->assertFalse($result->success);
        $this->assertSame(1, $result->attempts);
    }

    // ── uploadFile ────────────────────────────────────────────────────────

    public function test_upload_file_succeeds_on_first_attempt(): void
    {
        $inner = $this->createMock(StorageUploaderContract::class);
        $inner->expects($this->once())
            ->method('uploadFile')
            ->willReturn(UploadResult::success('emails/files/1_doc.pdf', '/tmp/doc.pdf'));

        $result = $this->makeDecorator($inner)->uploadFile('/tmp/doc.pdf', 'emails/files/1_doc.pdf');

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->attempts);
    }

    public function test_upload_file_retries_and_succeeds(): void
    {
        $inner = $this->createMock(StorageUploaderContract::class);
        $inner->expects($this->exactly(2))
            ->method('uploadFile')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new S3UploadException('timeout', '/tmp/f', 'k')),
                UploadResult::success('k', '/tmp/f'),
            );

        $result = $this->makeDecorator($inner, maxAttempts: 3)->uploadFile('/tmp/f', 'k');

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->attempts);
    }

    public function test_upload_file_returns_failure_after_all_attempts(): void
    {
        $inner = $this->createMock(StorageUploaderContract::class);
        $inner->expects($this->exactly(2))
            ->method('uploadFile')
            ->willThrowException(new S3UploadException('s3 down', '/tmp/f', 'k'));

        $result = $this->makeDecorator($inner, maxAttempts: 2)->uploadFile('/tmp/f', 'k');

        $this->assertFalse($result->success);
        $this->assertSame(2, $result->attempts);
    }

    // ── exists ────────────────────────────────────────────────────────────

    public function test_exists_delegates_to_inner(): void
    {
        $inner = $this->createMock(StorageUploaderContract::class);
        $inner->expects($this->once())->method('exists')->with('some/key')->willReturn(true);

        $this->assertTrue($this->makeDecorator($inner)->exists('some/key'));
    }
}

