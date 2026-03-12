<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\Email;
use App\Repositories\EmailRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EmailRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EmailRepository();
    }

    private function createEmail(array $overrides = []): Email
    {
        return Email::create(array_merge([
            'client_id'         => 1,
            'loan_id'           => 1,
            'email_template_id' => 1,
            'receiver_email'    => 'receiver@example.com',
            'sender_email'      => 'sender@example.com',
            'subject'           => 'Test Subject',
            'body'              => '<p>Test body content that is long enough</p>',
            'file_ids'          => json_encode([]),
            'created_at'        => now(),
        ], $overrides));
    }

    // ── streamUnmigratedBodies ────────────────────────────────────────────

    public function test_stream_returns_emails_with_body_and_no_s3_path(): void
    {
        $this->createEmail(['body' => '<p>unmigrated</p>', 'body_s3_path' => null]);

        $results = $this->repository->streamUnmigratedBodies(100)->all();

        $this->assertCount(1, $results);
        $this->assertSame('<p>unmigrated</p>', $results[0]->body);
    }

    public function test_stream_excludes_already_migrated_emails(): void
    {
        $this->createEmail(['body' => null, 'body_s3_path' => 'emails/bodies/1.html']);
        $this->createEmail(['body' => '<p>pending</p>', 'body_s3_path' => null]);

        $results = $this->repository->streamUnmigratedBodies(100)->all();

        $this->assertCount(1, $results);
    }

    public function test_stream_excludes_emails_with_null_body(): void
    {
        $this->createEmail(['body' => null, 'body_s3_path' => null]);

        $results = $this->repository->streamUnmigratedBodies(100)->all();

        $this->assertCount(0, $results);
    }

    public function test_stream_selects_file_ids(): void
    {
        $this->createEmail([
            'body'     => '<p>hi</p>',
            'file_ids' => json_encode([1, 2, 3]),
        ]);

        $results = $this->repository->streamUnmigratedBodies(100)->all();

        $fileIds = $results[0]->file_ids;
        // The cast may return array or JSON string depending on driver; normalise.
        if (is_string($fileIds)) {
            $fileIds = json_decode($fileIds, true);
        }

        $this->assertSame([1, 2, 3], $fileIds);
    }

    // ── countUnmigratedBodies ─────────────────────────────────────────────

    public function test_count_returns_correct_number(): void
    {
        $this->createEmail(['body' => '<p>a</p>']);
        $this->createEmail(['body' => '<p>b</p>']);
        $this->createEmail(['body' => null, 'body_s3_path' => 'emails/bodies/x.html']);

        $this->assertSame(2, $this->repository->countUnmigratedBodies());
    }

    public function test_count_returns_zero_when_all_migrated(): void
    {
        $this->createEmail(['body' => null, 'body_s3_path' => 'emails/bodies/1.html']);

        $this->assertSame(0, $this->repository->countUnmigratedBodies());
    }

    // ── markBodyMigrated ─────────────────────────────────────────────────

    public function test_mark_body_migrated_sets_s3_path_and_nulls_body(): void
    {
        $email = $this->createEmail(['body' => '<p>content</p>']);

        $this->repository->markBodyMigrated($email->id, 'emails/bodies/99.html');

        $email->refresh();
        $this->assertSame('emails/bodies/99.html', $email->body_s3_path);
        $this->assertNull($email->body);
    }

    // ── markFilesMigrated ─────────────────────────────────────────────────

    public function test_mark_files_migrated_updates_file_ids_and_s3_paths(): void
    {
        $email = $this->createEmail(['file_ids' => json_encode([1, 2])]);

        $s3Paths = ['emails/files/1_doc.pdf', 'emails/files/2_img.png'];
        $this->repository->markFilesMigrated($email->id, $s3Paths);

        $email->refresh();
        $this->assertSame($s3Paths, $email->file_ids);
        $this->assertSame(json_encode($s3Paths), $email->getRawOriginal('file_s3_paths'));
    }
}

