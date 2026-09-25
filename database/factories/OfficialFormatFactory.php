<?php

namespace Database\Factories;

use App\Enums\EstadoVersionFormato;
use App\Enums\TipoFormatoOficial;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatVersion;
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
            'aplica_a' => 'colaborador',
            'source_disk' => 'nas',
            'source_path' => 'formatos-oficiales/'.fake()->uuid().'.pdf',
            'original_filename' => 'formato.pdf',
            'file_type' => 'pdf',
            'is_active' => true,
            'overlay_config' => null,
        ];
    }

    /**
     * Con una versión 1 publicada (vigente) cuyo PDF base es el mismo
     * `source_path` del formato y que pinta el nombre completo — lo mínimo
     * para poder generar.
     *
     * @param  list<array<string, mixed>>|null  $campos
     */
    public function configurado(?array $campos = null): self
    {
        return $this->afterCreating(function (OfficialFormat $formato) use ($campos): void {
            $version = OfficialFormatVersion::factory()->create([
                'official_format_id' => $formato->id,
                'estado' => EstadoVersionFormato::Publicada->value,
                'source_path' => (string) $formato->source_path,
                'base_path' => (string) $formato->source_path,
                'campos' => $campos ?? [OfficialFormatVersionFactory::campo('colaborador.nombre_completo')],
                'publicada_en' => now(),
            ]);

            $formato->update(['version_vigente_id' => $version->id]);
        });
    }
}
