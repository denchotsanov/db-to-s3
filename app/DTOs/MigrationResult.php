<?php

declare(strict_types=1);

namespace App\DTOs;

final class MigrationResult
{
    public function __construct(
        public readonly int $totalProcessed,
        public readonly int $succeeded,
        public readonly int $failed,
        public readonly int $skipped,
        public readonly float $durationSeconds,
    ) {}

    public function failureRate(): float
    {
        if ($this->totalProcessed === 0) {
            return 0.0;
        }

        return round($this->failed / $this->totalProcessed * 100, 2);
    }

    public function summary(): string
    {
        return sprintf(
            'Processed: %d | Succeeded: %d | Failed: %d | Skipped: %d | Duration: %.2fs | Failure rate: %.2f%%',
            $this->totalProcessed,
            $this->succeeded,
            $this->failed,
            $this->skipped,
            $this->durationSeconds,
            $this->failureRate(),
        );
    }
}

