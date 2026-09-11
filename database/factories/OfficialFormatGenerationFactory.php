<?php

namespace Database\Factories;

use App\Models\OfficialFormat;
use App\Models\OfficialFormatGeneration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficialFormatGeneration>
 */
class OfficialFormatGenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'official_format_id' => OfficialFormat::factory(),
            'generated_by_id' => User::factory(),
            'generated_disk' => 'nas',
            'generated_path' => 'formatos-oficiales-generados/'.fake()->uuid().'.pdf',
            'generated_name' => 'documento-generado.pdf',
            'data_snapshot' => ['nombre_completo' => fake()->name()],
        ];
    }
}
