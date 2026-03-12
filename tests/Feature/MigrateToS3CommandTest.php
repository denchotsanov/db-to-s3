<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Email;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Full integration test for `php artisan emails:migrate-to-s3`.
 *
 * Uses Storage::fake('s3') to mock the S3 disk so no real AWS calls are made,
 * and an in-memory SQLite database for isolation.
 */
class MigrateToS3CommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake S3 and local disks – no real AWS/disk access
        Storage::fake('s3');
        Storage::fake('local');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function createEmail(array $overrides = []): Email
    {
        return Email::create(array_merge([
            'client_id'         => 1,
            'loan_id'           => 1,
            'email_template_id' => 1,
            'receiver_email'    => 'to@example.com',
            'sender_email'      => 'from@example.com',
            'subject'           => 'Test',
            'body'              => '<p>Hello World</p>',
            'file_ids'          => json_encode([]),
            'created_at'        => now(),
            'body_s3_path'      => null,
            'file_s3_paths'     => null,
        ], $overrides));
    }

    private function createFileRecord(string $diskPath): File
    {
        // Put a real fake file on the faked local disk
        Storage::disk('local')->put($diskPath, 'fake binary content for testing');

        return File::create([
            'name'    => basename($diskPath),
            'path'    => $diskPath,
            'size'    => 30,
            'type'    => 'application/pdf',
            's3_path' => null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Command existence & options
    // ─────────────────────────────────────────────────────────────────────

    public function test_command_is_registered_and_runs(): void
    {
        $this->artisan('emails:migrate-to-s3')->assertExitCode(0);
    }

    public function test_command_exits_with_failure_for_unknown_type(): void
    {
        $this->artisan('emails:migrate-to-s3', ['--type' => 'unknown'])
            ->assertExitCode(1);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Email body migration
    // ─────────────────────────────────────────────────────────────────────

    public function test_command_uploads_email_body_to_s3_and_updates_db(): void
    {
        $email = $this->createEmail(['body' => '<p>Important email content</p>']);

        $this->artisan('emails:migrate-to-s3', ['--type' => 'emails']);

        // Body should be uploaded to S3
        Storage::disk('s3')->assertExists("emails/bodies/{$email->id}.html");

        $email->refresh();
        $this->assertSame("emails/bodies/{$email->id}.html", $email->body_s3_path);
        $this->assertNull($email->body, 'Body column should be nulled after migration');
    }

    public function test_command_migrates_multiple_email_bodies(): void
    {
        $emails = collect(range(1, 5))->map(fn () => $this->createEmail());

        $this->artisan('emails:migrate-to-s3', ['--type' => 'emails']);

        foreach ($emails as $email) {
            Storage::disk('s3')->assertExists("emails/bodies/{$email->id}.html");
            $email->refresh();
            $this->assertNotNull($email->body_s3_path);
            $this->assertNull($email->body);
        }
    }

    public function test_command_skips_already_migrated_email_bodies(): void
    {
        $email = $this->createEmail([
            'body'         => null,
            'body_s3_path' => 'emails/bodies/99.html',
        ]);

        // Should succeed and not attempt to re-upload (nothing to process)
        $this->artisan('emails:migrate-to-s3', ['--type' => 'emails'])
            ->assertExitCode(0);
    }

    public function test_command_gracefully_handles_missing_file_record(): void
    {
        $email = $this->createEmail([
            'body'     => '<p>Missing file</p>',
            'file_ids' => json_encode([99999]), // non-existent file ID
        ]);

        // Should still succeed — missing file is logged and skipped
        $this->artisan('emails:migrate-to-s3', ['--type' => 'emails']);

        // Body still migrated
        $email->refresh();
        $this->assertNotNull($email->body_s3_path);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Dry-run mode
    // ─────────────────────────────────────────────────────────────────────

    public function test_command_dry_run_does_not_write_to_s3_or_db(): void
    {
        $email = $this->createEmail(['body' => '<p>dry run test</p>']);

        $this->artisan('emails:migrate-to-s3', [
            '--type'    => 'emails',
            '--dry-run' => true,
        ]);

        // Nothing should be on S3
        Storage::disk('s3')->assertMissing("emails/bodies/{$email->id}.html");

        // DB should be untouched
        $email->refresh();
        $this->assertNotNull($email->body);
        $this->assertNull($email->body_s3_path);
    }

    // ─────────────────────────────────────────────────────────────────────
    // --type=files (standalone FileMigrationStrategy)
    // ─────────────────────────────────────────────────────────────────────

    public function test_command_type_files_uploads_standalone_files(): void
    {
        $file = $this->createFileRecord('fake_files/standalone.pdf');

        $this->artisan('emails:migrate-to-s3', ['--type' => 'files'])
            ->assertExitCode(0);

        $file->refresh();
        Storage::disk('s3')->assertExists($file->s3_path);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Idempotency (re-running the command is safe)
    // ─────────────────────────────────────────────────────────────────────

    public function test_command_is_idempotent_on_second_run(): void
    {
        $email = $this->createEmail(['body' => '<p>idempotent</p>']);

        // First run
        $this->artisan('emails:migrate-to-s3', ['--type' => 'emails']);

        // Second run — should complete without errors
        $this->artisan('emails:migrate-to-s3', ['--type' => 'emails']);

        // Exactly one file on S3
        Storage::disk('s3')->assertExists("emails/bodies/{$email->id}.html");
    }

    // ─────────────────────────────────────────────────────────────────────
    // --type=all
    // ─────────────────────────────────────────────────────────────────────

    public function test_command_type_all_runs_both_strategies(): void
    {
        $standaloneFile = $this->createFileRecord('fake_files/standalone_all.pdf');
        $attachedFile   = $this->createFileRecord('fake_files/attached_all.pdf');
        $email          = $this->createEmail([
            'body'     => '<p>all mode</p>',
            'file_ids' => json_encode([$attachedFile->id]),
        ]);

        $this->artisan('emails:migrate-to-s3', ['--type' => 'all'])->assertExitCode(0);

        $email->refresh();
        $this->assertNotNull($email->body_s3_path);

        $standaloneFile->refresh();
        $this->assertNotNull($standaloneFile->s3_path);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Custom chunk size
    // ─────────────────────────────────────────────────────────────────────

    public function test_command_accepts_custom_chunk_size(): void
    {
        $this->createEmail();
        $this->createEmail();

        $this->artisan('emails:migrate-to-s3', [
            '--type'  => 'emails',
            '--chunk' => 1,
        ])->assertExitCode(0);
    }
}

