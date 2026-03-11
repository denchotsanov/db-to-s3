<?php

namespace Database\Factories;

use App\Models\Email;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Email>
 */
class EmailFactory extends Factory
{
    protected $model = Email::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id'         => $this->faker->numberBetween(1, 10_000),
            'loan_id'           => $this->faker->numberBetween(1, 50_000),
            'email_template_id' => $this->faker->numberBetween(1, 20),
            'receiver_email'    => $this->faker->safeEmail(),
            'sender_email'      => $this->faker->safeEmail(),
            'subject'           => $this->faker->sentence(8),
            'body'              => $this->generateHtmlBody(),
            'file_ids'          => [],   // will be populated in the seeder
            'created_at'        => $this->faker->dateTimeBetween('-2 years', 'now'),
            'sent_at'           => $this->faker->optional(0.85)->dateTimeBetween('-2 years', 'now'),
            'body_s3_path'      => null,
            'file_s3_paths'     => null,
        ];
    }

    /**
     * Generate a realistic-looking HTML email body that is at least 10 KB.
     */
    private function generateHtmlBody(): string
    {
        $faker = $this->faker;

        // Build several sections until we exceed 10 KB
        $sections = '';
        $minBytes  = 10_240; // 10 KB

        do {
            $heading    = $faker->sentence(6);
            $paragraphs = '';
            foreach (range(1, $faker->numberBetween(4, 8)) as $ignored) {
                $paragraphs .= '<p>' . $faker->paragraph($faker->numberBetween(8, 20)) . '</p>' . PHP_EOL;
            }

            // Add a fake table of data
            $tableRows = '';
            foreach (range(1, $faker->numberBetween(5, 15)) as $ignored) {
                $tableRows .= '<tr>'
                    . '<td>' . $faker->name() . '</td>'
                    . '<td>' . $faker->safeEmail() . '</td>'
                    . '<td>' . $faker->phoneNumber() . '</td>'
                    . '<td>' . $faker->dateTimeThisDecade()->format('Y-m-d') . '</td>'
                    . '<td>$' . number_format($faker->randomFloat(2, 100, 100_000), 2) . '</td>'
                    . '</tr>' . PHP_EOL;
            }

            $table = <<<HTML
<table style="border-collapse:collapse;width:100%;border:1px solid #ccc">
  <thead>
    <tr>
      <th>Name</th><th>Email</th><th>Phone</th><th>Date</th><th>Amount</th>
    </tr>
  </thead>
  <tbody>
{$tableRows}  </tbody>
</table>
HTML;

            $sections .= <<<HTML
<section style="margin-bottom:24px">
  <h2>{$heading}</h2>
  {$paragraphs}
  {$table}
</section>

HTML;
        } while (strlen($sections) < $minBytes);

        $companyName = $faker->company();
        $address     = $faker->address();

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$faker->sentence(5)}</title>
  <style>
    body { font-family: Arial, sans-serif; font-size: 14px; color: #333; margin: 0; padding: 0; background: #f9f9f9; }
    .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 32px; }
    h1 { color: #1a1a2e; }
    h2 { color: #16213e; border-bottom: 1px solid #eee; padding-bottom: 6px; }
    table { font-size: 13px; }
    th { background: #16213e; color: #fff; }
    .footer { margin-top: 40px; font-size: 12px; color: #888; border-top: 1px solid #eee; padding-top: 16px; }
  </style>
</head>
<body>
  <div class="container">
    <h1>{$faker->catchPhrase()}</h1>
    <p><strong>Dear {$faker->name()},</strong></p>
    <p>{$faker->paragraph(10)}</p>

    {$sections}

    <div class="footer">
      <p>{$companyName} &bull; {$address}</p>
      <p>This email was generated automatically. Please do not reply.</p>
    </div>
  </div>
</body>
</html>
HTML;

        return $html;
    }
}

