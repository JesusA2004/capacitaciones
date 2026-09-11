<?php

namespace Database\Factories;

use App\Enums\TipoFormatoOficial;
use App\Models\OfficialFormat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficialFormat>
 */
class OfficialFormatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = 'Formato de '.fake()->unique()->word();

        return [
            'slug' => str($nombre)->slug()->toString(),
            'nombre' => $nombre,
            'tipo' => TipoFormatoOficial::Otro->value,
            'source_disk' => 'nas',
            'source_path' => 'formatos-oficiales/'.fake()->uuid().'.pdf',
            'original_filename' => 'formato.pdf',
            'file_type' => 'pdf',
            'is_active' => true,
            'overlay_config' => null,
        ];
    }

    public function configurado(): self
    {
        return $this->state(fn () => [
            'overlay_config' => [
                'nombre_completo' => [
                    'pagina' => 1,
                    'x' => 20.0,
                    'y' => 40.0,
                    'font_size' => 11,
                    'align' => 'left',
                    'max_width' => 160.0,
                    'color' => '#111111',
                    'enabled' => true,
                ],
            ],
        ]);
    }
}
