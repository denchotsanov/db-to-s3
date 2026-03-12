<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\EmailRepositoryContract;
use App\Contracts\FileRepositoryContract;
use App\Services\Migration\MigrationOrchestrator;
use App\Services\Migration\EmailBodyMigrationStrategy;
use App\Services\Migration\FileMigrationStrategy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Lean Artisan command: parses options and delegates 100% of logic to the
 * MigrationOrchestrator and its Strategies.
 *
 * Single Responsibility: CLI surface area only.
 *
 * S3-dependent services are resolved lazily from the container inside
 * handle(), so they are only instantiated when the command actually runs
 * (not during application boot / test setup).
 */
class MigrateToS3Command extends Command
{
    protected $signature = 'emails:migrate-to-s3
        {--type=all         : Which migration to run: all|emails|files}
        {--chunk=500        : Records per lazy-cursor chunk}
        {--dry-run          : Preview what would be migrated without writing}
        {--s3-prefix=       : Override the default S3 key prefix}
        {--max-attempts=3   : Max S3 upload retry attempts per record}';

    protected $description = 'Migrate email bodies and/or file attachments from the database / local disk to S3.';

    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $type   = strtolower((string) $this->option('type'));
        $chunk  = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');
        $prefix = (string) $this->option('s3-prefix');

        $options = array_filter([
            'chunk_size' => $chunk,
            'dry_run'    => $dryRun,
            's3_prefix'  => $prefix ?: null,
        ], fn ($v) => $v !== null);

        if (! in_array($type, ['all', 'emails', 'files'], true)) {
            $this->error("Unknown --type value [{$type}]. Use: all, emails, or files.");
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN mode — no data will be written.');
        }

        // Resolve lazily — S3 disk is only instantiated here, after Storage::fake() is set up in tests
        /** @var EmailRepositoryContract $emailRepository */
        $emailRepository = $this->container->make(EmailRepositoryContract::class);
        /** @var FileRepositoryContract $fileRepository */
        $fileRepository  = $this->container->make(FileRepositoryContract::class);
        /** @var MigrationOrchestrator $orchestrator */
        $orchestrator    = $this->container->make(MigrationOrchestrator::class);
        /** @var LoggerInterface $logger */
        $logger          = $this->container->make('log');

        if (in_array($type, ['all', 'emails'], true)) {
            $pending = $emailRepository->countUnmigratedBodies();
            $this->info("Email bodies pending migration: <comment>{$pending}</comment>");
            $orchestrator->addStrategy($this->container->make(EmailBodyMigrationStrategy::class));
        }

        if (in_array($type, ['all', 'files'], true)) {
            $pending = $fileRepository->countUnmigrated();
            $this->info("Files pending migration: <comment>{$pending}</comment>");
            $orchestrator->addStrategy($this->container->make(FileMigrationStrategy::class));
        }

        try {
            $results = $orchestrator->run($options);
        } catch (\Throwable $e) {
            $logger->critical('MigrateToS3Command: unrecoverable error.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error("Migration failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('─────────────────────────────────────────────────────');
        $this->info('  Migration Summary');
        $this->line('─────────────────────────────────────────────────────');

        $overallFailed = 0;

        foreach ($results as $label => $result) {
            $this->line("  <info>{$label}</info>");
            $this->line("  {$result->summary()}");
            $this->newLine();
            $overallFailed += $result->failed;
        }

        $this->line('─────────────────────────────────────────────────────');

        if ($overallFailed > 0) {
            $this->warn("{$overallFailed} record(s) failed to upload. Check the logs for details.");
            return self::FAILURE;
        }

        $this->info('Migration completed successfully.');
        return self::SUCCESS;
    }
}

