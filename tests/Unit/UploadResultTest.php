<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\UploadResult;
use PHPUnit\Framework\TestCase;

class UploadResultTest extends TestCase
{
    public function test_success_factory_sets_correct_fields(): void
    {
        $result = UploadResult::success('emails/bodies/1.html', '/tmp/file.html', 2);

        $this->assertTrue($result->success);
        $this->assertSame('emails/bodies/1.html', $result->s3Path);
        $this->assertSame('/tmp/file.html', $result->localPath);
        $this->assertNull($result->errorMessage);
        $this->assertSame(2, $result->attempts);
    }

    public function test_success_factory_defaults_to_one_attempt(): void
    {
        $result = UploadResult::success('k', '');

        $this->assertSame(1, $result->attempts);
    }

    public function test_failure_factory_sets_correct_fields(): void
    {
        $result = UploadResult::failure('/tmp/file', 'connection refused', 3);

        $this->assertFalse($result->success);
        $this->assertSame('', $result->s3Path);
        $this->assertSame('/tmp/file', $result->localPath);
        $this->assertSame('connection refused', $result->errorMessage);
        $this->assertSame(3, $result->attempts);
    }

    public function test_failure_factory_defaults_to_one_attempt(): void
    {
        $result = UploadResult::failure('', 'error');

        $this->assertSame(1, $result->attempts);
    }

    public function test_success_has_null_error_message(): void
    {
        $result = UploadResult::success('k', '');

        $this->assertNull($result->errorMessage);
    }
}

