# DB to S3 — Email Migration Service

A Laravel application that migrates large email bodies and file attachments from a PostgreSQL database to Amazon S3 (or a local MinIO equivalent), dramatically reducing database size and improving query performance.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Prerequisites](#prerequisites)
4. [Environment Variables](#environment-variables)
5. [Docker Setup](#docker-setup)
6. [Database Setup & Seeding](#database-setup--seeding)
7. [Running the Migration Command](#running-the-migration-command)
8. [Running the Tests](#running-the-tests)
9. [Project Structure](#project-structure)

---

## Overview

The `emails` table stores full HTML email bodies and references to local file attachments. Over time this table grows to hundreds of GB, causing slow queries. This service offloads the heavy columns to S3:

| What moves            | From                        | To                               |
|-----------------------|-----------------------------|----------------------------------|
| Email HTML body       | `emails.body` (text)        | S3 key `emails/bodies/{id}.html` |
| File attachments      | Local disk path             | S3 key `emails/files/{id}_{name}`|
| DB columns freed      | `body` set to `NULL`        | `body_s3_path` stores S3 key     |
| File IDs updated      | `file_ids` = `[1, 2, 3]`   | `file_ids` = `["s3/path", ...]`  |

---

## Architecture

The solution follows **SOLID** principles with these design patterns:

- **Strategy Pattern** — `EmailBodyMigrationStrategy` and `FileMigrationStrategy` implement `MigrationStrategyContract`. New migration types are added by creating a new strategy, with zero changes to the command or orchestrator.
- **Repository Pattern** — `EmailRepository` and `FileRepository` abstract all database access behind contracts, keeping strategy logic database-agnostic.
- **Decorator Pattern** — `RetryingUploaderDecorator` wraps `S3UploaderService` to add retry logic with exponential back-off, transparently to callers.
- **Command Bus** — `MigrationOrchestrator` acts as a lean coordinator, running registered strategies and aggregating results.
- **Dependency Inversion** — All dependencies are injected via interfaces (`EmailRepositoryContract`, `StorageUploaderContract`, etc.), making every component independently testable.

```
Artisan Command
    └── MigrationOrchestrator
            ├── EmailBodyMigrationStrategy
            │       ├── EmailRepository   (reads / writes emails table)
            │       ├── FileRepository    (resolves file records)
            │       └── RetryingUploaderDecorator → S3UploaderService
            └── FileMigrationStrategy
                    ├── FileRepository
                    └── RetryingUploaderDecorator → S3UploaderService
```

---

## Prerequisites

| Tool            | Minimum version |
|-----------------|-----------------|
| Docker Desktop  | 4.x             |
| Docker Compose  | v2              |
| PHP (local CLI) | 8.2 (only needed to run `composer install` outside Docker) |
| Composer        | 2.x             |

> **Tip:** If you have [Laravel Sail](https://laravel.com/docs/sail) installed globally you can replace every `docker compose` call below with `./vendor/bin/sail`.

---

## Environment Variables

Copy `.env.example` and fill in the required values:

```bash
cp .env.example .env
```

### Core Application

| Variable         | Default        | Description                        |
|------------------|----------------|------------------------------------|
| `APP_NAME`       | `Laravel`      | Application name                   |
| `APP_ENV`        | `local`        | Environment (`local`, `production`)|
| `APP_KEY`        | *(empty)*      | **Required.** Generate with `php artisan key:generate` |
| `APP_DEBUG`      | `true`         | Show detailed errors               |
| `APP_URL`        | `http://localhost` | Base URL                       |

### Database (PostgreSQL)

| Variable      | Default     | Description                      |
|---------------|-------------|----------------------------------|
| `DB_CONNECTION` | `pgsql`   | Driver — keep as `pgsql`         |
| `DB_HOST`     | `pgsql`     | Service name from `compose.yaml` |
| `DB_PORT`     | `5432`      | PostgreSQL port                  |
| `DB_DATABASE` | `laravel`   | Database name                    |
| `DB_USERNAME` | `root`      | Database user                    |
| `DB_PASSWORD` | `secret`    | Database password                |

### Amazon S3 / MinIO

| Variable                   | Example (MinIO local) | Production (AWS)       | Description                              |
|----------------------------|-----------------------|------------------------|------------------------------------------|
| `AWS_ACCESS_KEY_ID`        | `minio`               | *(IAM key)*            | S3 access key                            |
| `AWS_SECRET_ACCESS_KEY`    | `minio123`            | *(IAM secret)*         | S3 secret key                            |
| `AWS_DEFAULT_REGION`       | `us-east-1`           | `eu-west-1` etc.       | AWS / MinIO region                       |
| `AWS_BUCKET`               | `emails`              | `my-production-bucket` | Target S3 bucket name                    |
| `AWS_ENDPOINT`             | `http://minio:9000`   | *(omit for AWS)*       | Custom endpoint — required for MinIO     |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true`             | `false`                | Must be `true` for MinIO                 |

### Migration Tuning (optional)

| Variable                    | Default | Description                                   |
|-----------------------------|---------|-----------------------------------------------|
| `MIGRATION_S3_MAX_ATTEMPTS` | `3`     | Max upload retry attempts per record          |
| `MIGRATION_S3_BASE_DELAY_MS`| `200`   | Base delay (ms) between retries (doubles each attempt) |

### Full `.env` for local Docker development

```dotenv
APP_NAME="DB to S3"
APP_ENV=local
APP_KEY=                         # filled by: php artisan key:generate
APP_DEBUG=true
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=secret

FILESYSTEM_DISK=local

MINIO_ROOT_USER=minio
MINIO_ROOT_PASSWORD=minio123

AWS_ACCESS_KEY_ID="${MINIO_ROOT_USER}"
AWS_SECRET_ACCESS_KEY="${MINIO_ROOT_PASSWORD}"
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=test
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_ENDPOINT=http://minio:9000
AWS_URL=http://minio:9000/test

MIGRATION_S3_MAX_ATTEMPTS=3
MIGRATION_S3_BASE_DELAY_MS=200
```

---

## Docker Setup

### 1. Install PHP dependencies

```bash
composer install --no-interaction
```

### 2. Copy and configure environment

```bash
cp .env.example .env
# Edit .env — set DB_HOST=pgsql, AWS_ENDPOINT=http://minio:9000, etc.
```

### 3. Generate application key

```bash
php artisan key:generate
# or inside the container after step 4:
# docker compose exec laravel.test php artisan key:generate
```

### 4. Start all services

```bash
docker compose up -d
```

This starts three containers:

| Container      | Service    | Default port |
|----------------|------------|--------------|
| `laravel.test` | PHP-FPM app| 80           |
| `pgsql`        | PostgreSQL | 5432         |
| `minio`        | MinIO (S3) | 9000         |

### 5. Verify containers are healthy

```bash
docker compose ps
```

All three services should show `healthy` or `running`.

### 6. Create the MinIO bucket

MinIO does not auto-create buckets. Create the target bucket once:

```bash
docker compose exec minio \
  mc alias set local http://localhost:9000 minio minio123

docker compose exec minio \
  mc mb local/emails
```

Or open the MinIO web console at **http://localhost:9000** (user: `minio`, password: `minio123`) and create the bucket manually.

---

## Database Setup & Seeding

### Run migrations

```bash
docker compose exec laravel.test php artisan migrate
```

This creates all tables including:
- `emails` — with `body_s3_path` and `file_s3_paths` columns
- `files` — with `s3_path` column

### Seed fake data

The seeder generates **100,000+ email records**, each with:
- A randomly generated HTML body (≥ 10 KB)
- 1–3 associated fake file records with physical files written to `storage/app/private/`

```bash
docker compose exec laravel.test php artisan db:seed
```

> ⏱ **Expected time:** ~3–8 minutes depending on hardware (100,000 records with large HTML bodies and file I/O).

To seed with a custom record count set `EMAIL_SEED_COUNT` in `.env`:

```bash
EMAIL_SEED_COUNT=10000 docker compose exec laravel.test php artisan db:seed
```

### Verify seed

```bash
docker compose exec laravel.test php artisan tinker --execute="
    echo 'Emails: ' . \App\Models\Email::count() . PHP_EOL;
    echo 'Files:  ' . \App\Models\File::count()  . PHP_EOL;
"
```

---

## Running the Migration Command

```bash
php artisan emails:migrate-to-s3 [options]
```

Or inside Docker:

```bash
docker compose exec laravel.test php artisan emails:migrate-to-s3
```

### Options

| Option              | Default | Description                                                   |
|---------------------|---------|---------------------------------------------------------------|
| `--type`            | `all`   | What to migrate: `all` \| `emails` \| `files`                |
| `--chunk`           | `500`   | Records per lazy-cursor chunk (tune for available memory)     |
| `--dry-run`         | off     | Preview what would happen — no writes to S3 or DB            |
| `--s3-prefix`       | *(auto)*| Override default S3 key prefix                                |
| `--max-attempts`    | `3`     | Max S3 upload retry attempts per record                       |

### Examples

```bash
# Migrate everything (email bodies + file attachments)
php artisan emails:migrate-to-s3

# Dry run — inspect what would be migrated
php artisan emails:migrate-to-s3 --dry-run

# Migrate only email bodies, in chunks of 1000
php artisan emails:migrate-to-s3 --type=emails --chunk=1000

# Migrate only file attachments
php artisan emails:migrate-to-s3 --type=files

# Use a custom S3 prefix
php artisan emails:migrate-to-s3 --s3-prefix=archive/2025/emails

# Increase retry attempts for unreliable connections
php artisan emails:migrate-to-s3 --max-attempts=5
```

### What the command does (step by step)

1. Counts unmigrated records and reports progress to the console.
2. Streams records in configurable chunks to avoid memory exhaustion.
3. For each email:
   - Uploads `body` as `{s3_prefix}/{id}.html` to S3.
   - Updates `emails.body_s3_path` with the S3 key and sets `emails.body = NULL`.
   - For each file ID in `file_ids`, uploads the local file to S3 and updates `files.s3_path`.
   - Replaces `emails.file_ids` with the array of S3 paths.
4. **Idempotent** — already-migrated records are detected and skipped safely.
5. **Resilient** — S3 failures are retried with exponential back-off; failures are logged and counted, not fatal.
6. Prints a summary table: processed / succeeded / failed / skipped and failure rate.

### Monitoring progress

All activity is written to the Laravel log (`storage/logs/laravel.log`). Follow it live:

```bash
tail -f storage/logs/laravel.log
```

Or inside Docker:

```bash
docker compose exec laravel.test tail -f storage/logs/laravel.log
```

---

## Running the Tests

The test suite uses an **in-memory SQLite** database and `Storage::fake()` — no running Docker containers required.

### Run all tests

```bash
php vendor/bin/phpunit --testdox
```

### Run only unit tests

```bash
php vendor/bin/phpunit --testdox --testsuite Unit
```

### Run only feature tests

```bash
php vendor/bin/phpunit --testdox --testsuite Feature
```

### Run a specific test class

```bash
php vendor/bin/phpunit --testdox --filter EmailBodyMigrationStrategyTest
```

### Test coverage areas

| Suite   | Test class                        | What is covered                                          |
|---------|-----------------------------------|----------------------------------------------------------|
| Unit    | `EmailBodyMigrationStrategyTest`  | Body upload, attachment upload, dry-run, idempotency     |
| Unit    | `FileMigrationStrategyTest`       | File upload, missing file, dry-run, retry via decorator  |
| Unit    | `EmailRepositoryTest`             | Stream filtering, count, markBodyMigrated, markFiles     |
| Unit    | `FileRepositoryTest`              | Stream filtering, count, markMigrated, findById          |
| Unit    | `MigrationOrchestratorTest`       | Strategy registration, ordered execution, error handling |
| Unit    | `RetryingUploaderDecoratorTest`   | Retry logic, back-off, max attempts                      |
| Unit    | `MigrationResultTest`             | DTO calculations, failure rate, readonly properties      |
| Unit    | `UploadResultTest`                | Success/failure factory methods                          |
| Feature | `MigrateToS3CommandTest`          | End-to-end command with real DB (SQLite) + fake S3       |

---

## Project Structure

```
app/
├── Console/Commands/
│   └── MigrateToS3Command.php        # Lean Artisan command (CLI surface only)
├── Contracts/
│   ├── EmailRepositoryContract.php
│   ├── FileRepositoryContract.php
│   └── StorageUploaderContract.php
├── DTOs/
│   ├── MigrationResult.php           # Readonly result value object
│   └── UploadResult.php              # Readonly upload outcome value object
├── Models/
│   ├── Email.php
│   └── File.php
├── Providers/
│   └── AppServiceProvider.php        # All IoC bindings
├── Repositories/
│   ├── EmailRepository.php           # Lazy-streaming DB access for emails
│   └── FileRepository.php            # Lazy-streaming DB access for files
└── Services/
    ├── Migration/
    │   ├── EmailBodyMigrationStrategy.php  # Strategy: body + attachments → S3
    │   ├── FileMigrationStrategy.php       # Strategy: local files → S3
    │   └── MigrationOrchestrator.php       # Command Bus coordinator
    └── Storage/
        ├── S3UploaderService.php           # Concrete S3 uploader
        └── RetryingUploaderDecorator.php   # Decorator: retry with back-off

database/
├── migrations/                       # All schema migrations
├── seeders/
│   └── DatabaseSeeder.php            # 100k+ email records with files
└── factories/

tests/
├── Unit/
│   ├── Repositories/
│   └── Services/
└── Feature/
    └── MigrateToS3CommandTest.php
```
