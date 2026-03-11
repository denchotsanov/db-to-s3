<?php

namespace Database\Seeders;

use App\Models\Email;
use App\Models\File;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;
    private const TOTAL_EMAILS = 100_000;
    private const CHUNK_SIZE = 500;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('Starting seeder: ' . self::TOTAL_EMAILS . ' email records...');

        $emailFactory = Email::factory();
        $fileFactory  = File::factory();
        $totalChunks = (int) ceil(self::TOTAL_EMAILS / self::CHUNK_SIZE);

        $bar = $this->command->getOutput()->createProgressBar($totalChunks);
        $bar->start();

        for ($chunk = 0; $chunk < $totalChunks; $chunk++) {
            $countInChunk = ($chunk === $totalChunks - 1)
                ? self::TOTAL_EMAILS - ($chunk * self::CHUNK_SIZE)
                : self::CHUNK_SIZE;
            $filesPerEmail = [];
            $allFileIds    = [];

            for ($i = 0; $i < $countInChunk; $i++) {
                $numFiles = random_int(1, 3);
                $files = $fileFactory->count($numFiles)->create();
                $ids   = $files->pluck('id')->all();

                $filesPerEmail[] = $ids;
                $allFileIds      = array_merge($allFileIds, $ids);
            }

            $emailRows = [];

            for ($i = 0; $i < $countInChunk; $i++) {
                $attributes = $emailFactory->make()->toArray();
                $attributes['file_ids'] = json_encode($filesPerEmail[$i]);
                if ($attributes['created_at'] instanceof \DateTimeInterface) {
                    $attributes['created_at'] = $attributes['created_at']->format('Y-m-d H:i:s');
                }
                if (isset($attributes['sent_at']) && $attributes['sent_at'] instanceof \DateTimeInterface) {
                    $attributes['sent_at'] = $attributes['sent_at']->format('Y-m-d H:i:s');
                }

                $emailRows[] = $attributes;
            }

            DB::transaction(function () use ($emailRows) {
                foreach (array_chunk($emailRows, 50) as $batch) {
                    DB::table('emails')->insert($batch);
                }
            });
            unset($emailRows, $filesPerEmail, $allFileIds);
            $bar->advance();
        }
        $bar->finish();
        $this->command->newLine();
        $this->command->info('Seeding complete! ' . self::TOTAL_EMAILS . ' emails with associated files have been created.');
    }
}
