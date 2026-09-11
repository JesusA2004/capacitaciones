<?php

namespace App\Models;

use App\Enums\TipoFormatoOficial;
use Database\Factories\OfficialFormatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Formato oficial fijo de MR. LANA (docs/FORMATOS_OFICIALES.md): el archivo
 * base (normalmente PDF) NUNCA se edita desde el sistema, solo se pinta
 * encima con App\Services\Formatos\OfficialFormatOverlayService usando las
 * coordenadas de overlay_config. Sembrado por
 * `php artisan formatos:importar-originales` desde claude/formatos/originales.
 *
 * @property int $id
 * @property string $slug
 * @property string $nombre
 * @property string|null $descripcion
 * @property TipoFormatoOficial $tipo
 * @property string $source_disk
 * @property string $source_path
 * @property string $original_filename
 * @property string $file_type
 * @property bool $is_active
 * @property array<string, array<string, mixed>>|null $overlay_config
 */
class OfficialFormat extends Model
{
    /** @use HasFactory<OfficialFormatFactory> */
    use HasFactory;

    protected $hidden = ['source_disk', 'source_path'];

    protected $fillable = [
        'slug',
        'nombre',
        'descripcion',
        'tipo',
        'source_disk',
        'source_path',
        'original_filename',
        'file_type',
        'is_active',
        'overlay_config',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoFormatoOficial::class,
            'is_active' => 'boolean',
            'overlay_config' => 'array',
        ];
    }

    /**
     * Un formato "listo" tiene al menos un campo habilitado en su
     * configuracion de overlay; sin eso no hay donde pintar los datos del
     * colaborador (ver OfficialFormatOverlayService::CAMPOS_DISPONIBLES).
     */
    public function tieneConfiguracion(): bool
    {
        if ($this->overlay_config === null) {
            return false;
        }

        foreach ($this->overlay_config as $campo) {
            if (($campo['enabled'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return HasMany<OfficialFormatGeneration, $this>
     */
    public function generaciones(): HasMany
    {
        return $this->hasMany(OfficialFormatGeneration::class);
    }
}
