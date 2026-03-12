<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\MigrationResult;
use PHPUnit\Framework\TestCase;

class MigrationResultTest extends TestCase
{
    public function test_summary_contains_all_fields(): void
    {
        $result = new MigrationResult(100, 90, 5, 5, 3.14);

        $summary = $result->summary();

        $this->assertStringContainsString('100', $summary);
        $this->assertStringContainsString('90', $summary);
        $this->assertStringContainsString('5', $summary);
        $this->assertStringContainsString('3.14', $summary);
    }

    public function test_failure_rate_is_zero_when_nothing_processed(): void
    {
        $result = new MigrationResult(0, 0, 0, 0, 0.0);

        $this->assertSame(0.0, $result->failureRate());
    }

    public function test_failure_rate_calculated_correctly(): void
    {
        $result = new MigrationResult(200, 150, 50, 0, 1.0);

        $this->assertSame(25.0, $result->failureRate());
    }

    public function test_failure_rate_rounded_to_two_decimals(): void
    {
        // 1 failure out of 3 = 33.333...%
        $result = new MigrationResult(3, 2, 1, 0, 0.1);

        $this->assertSame(33.33, $result->failureRate());
    }

    public function test_failure_rate_is_100_when_all_failed(): void
    {
        $result = new MigrationResult(10, 0, 10, 0, 0.5);

        $this->assertSame(100.0, $result->failureRate());
    }

    public function test_all_properties_are_readonly(): void
    {
        $result = new MigrationResult(1, 1, 0, 0, 0.01);

        $this->assertSame(1, $result->totalProcessed);
        $this->assertSame(1, $result->succeeded);
        $this->assertSame(0, $result->failed);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0.01, $result->durationSeconds);
    }
}

