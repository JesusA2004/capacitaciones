<?php

namespace Database\Factories;

use App\Enums\PlataformaApp;
use App\Models\MobileAppRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MobileAppRelease>
 */
class MobileAppReleaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform' => PlataformaApp::Android->value,
            'version' => '1.0.'.$this->faker->numberBetween(0, 20),
            'build_number' => (string) $this->faker->numberBetween(1, 50),
            'file_path' => null,
            'original_filename' => null,
            'file_size' => null,
            'mime_type' => null,
            'sha256' => null,
            'changelog' => 'Primera versión.',
            'is_published' => false,
            'is_latest' => false,
            'minimum_required' => false,
            'uploaded_by_id' => null,
            'published_at' => null,
        ];
    }

    public function conArchivo(): static
    {
        return $this->state(fn () => [
            'file_path' => 'app-releases/android/'.$this->faker->uuid().'.apk',
            'original_filename' => 'mr-lana-people.apk',
            'file_size' => $this->faker->numberBetween(10_000_000, 60_000_000),
            'mime_type' => 'application/vnd.android.package-archive',
            'sha256' => hash('sha256', $this->faker->uuid()),
        ]);
    }

    public function publicada(): static
    {
        return $this->conArchivo()->state(fn () => [
            'is_published' => true,
            'is_latest' => true,
            'published_at' => now(),
        ]);
    }
}
