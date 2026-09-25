<?php

namespace Database\Factories;

use App\Enums\EstadoVersionFormato;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficialFormatVersion>
 */
class OfficialFormatVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ruta = 'formatos-oficiales/versiones/'.fake()->uuid().'.pdf';

        return [
            'official_format_id' => OfficialFormat::factory(),
            'numero' => 1,
            'estado' => EstadoVersionFormato::Borrador->value,
            'estrategia' => 'overlay',
            'file_type' => 'pdf',
            'source_disk' => 'nas',
            'source_path' => $ruta,
            'original_filename' => 'formato.pdf',
            'source_mime' => 'application/pdf',
            'base_path' => $ruta,
            'fidelidad' => 'exacta',
            'paginas' => [['numero' => 1, 'ancho' => 215.9, 'alto' => 279.4]],
            'campos' => [],
        ];
    }

    /**
     * Campo de variable del catálogo en una posición razonable.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function campo(string $variable, array $extra = []): array
    {
        return [
            'id' => substr(md5($variable.json_encode($extra)), 0, 10),
            'tipo' => 'variable',
            'variable' => $variable,
            'etiqueta' => null,
            'texto' => null,
            'pagina' => 1,
            'x' => 20.0,
            'y' => 40.0,
            'ancho' => 120.0,
            'alto' => 6.0,
            'font_size' => 11,
            'align' => 'left',
            'negrita' => false,
            'color' => '#111111',
            'formato' => null,
            'max_caracteres' => null,
            'multilinea' => false,
            'requerido' => false,
            ...$extra,
        ];
    }
}
