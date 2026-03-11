<?php

namespace Database\Factories;

use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\File>
 */
class FileFactory extends Factory
{
    protected $model = File::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $extensions = ['pdf', 'docx', 'xlsx', 'txt', 'png', 'jpg', 'csv'];
        $extension  = $this->faker->randomElement($extensions);
        $name       = $this->faker->slug(3) . '.' . $extension;

        $mimeTypes = [
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt'  => 'text/plain',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'csv'  => 'text/csv',
        ];

        $relativePath = 'fake_files/' . $name;
        $fullPath      = storage_path('app/private/' . $relativePath);

        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        // Generate a fake binary blob of ~50–200 KB
        $sizeBytes = $this->faker->numberBetween(50_000, 200_000);
        file_put_contents($fullPath, random_bytes($sizeBytes));

        return [
            'name' => $name,
            'path' => $relativePath,
            'size' => $sizeBytes,
            'type' => $mimeTypes[$extension],
        ];
    }
}

