<?php

namespace Database\Factories;

use App\Enums\TipoPlantillaDocumento;
use App\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(3, true),
            'tipo' => fake()->randomElement(TipoPlantillaDocumento::cases())->value,
            'disk' => 'nas',
            'path' => 'plantillas/'.fake()->uuid().'.docx',
            'original_name' => 'plantilla.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 20480,
            'version' => 1,
            'activo' => true,
        ];
    }
}
