<?php

namespace App\Providers;

use App\Console\Commands\MigrateToS3Command;
use App\Contracts\EmailRepositoryContract;
use App\Contracts\FileRepositoryContract;
use App\Contracts\StorageUploaderContract;
use App\Repositories\EmailRepository;
use App\Repositories\FileRepository;
use App\Services\Migration\EmailBodyMigrationStrategy;
use App\Services\Migration\FileMigrationStrategy;
use App\Services\Migration\MigrationOrchestrator;
use App\Services\Storage\RetryingUploaderDecorator;
use App\Services\Storage\S3UploaderService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Repositories
        $this->app->bind(EmailRepositoryContract::class, EmailRepository::class);
        $this->app->bind(FileRepositoryContract::class, FileRepository::class);

        // Storage uploader (S3 + retry decorator)
        // Storage::disk('s3') is resolved lazily inside this closure; it is
        // only called when StorageUploaderContract is actually needed, so
        // repository/unit tests that never touch S3 are not affected.
        $this->app->bind(StorageUploaderContract::class, function (Application $app) {
            $maxAttempts = (int) config('migration.s3_max_attempts', 3);
            $baseDelayMs = (int) config('migration.s3_base_delay_ms', 200);

            $inner = new S3UploaderService(
                disk:   Storage::disk('s3'),
                logger: $app->make('log'),
            );

            return new RetryingUploaderDecorator(
                inner:       $inner,
                logger:      $app->make('log'),
                maxAttempts: $maxAttempts,
                baseDelayMs: $baseDelayMs,
            );
        });

        // Strategies
        $this->app->bind(EmailBodyMigrationStrategy::class, function (Application $app) {
            return new EmailBodyMigrationStrategy(
                emailRepository: $app->make(EmailRepositoryContract::class),
                fileRepository:  $app->make(FileRepositoryContract::class),
                uploader:        $app->make(StorageUploaderContract::class),
                localDisk:       Storage::disk('local'),
                logger:          $app->make('log'),
            );
        });

        $this->app->bind(FileMigrationStrategy::class, function (Application $app) {
            return new FileMigrationStrategy(
                fileRepository: $app->make(FileRepositoryContract::class),
                uploader:       $app->make(StorageUploaderContract::class),
                localDisk:      Storage::disk('local'),
                logger:         $app->make('log'),
            );
        });

        // Orchestrator
        $this->app->bind(MigrationOrchestrator::class, function (Application $app) {
            return new MigrationOrchestrator(logger: $app->make('log'));
        });

        // Artisan command
        // Only the IoC container is injected; all S3-dependent services are
        // resolved lazily inside handle() so Storage::fake() in tests applies.
        $this->app->bind(MigrateToS3Command::class, function (Application $app) {
            return new MigrateToS3Command(container: $app);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
