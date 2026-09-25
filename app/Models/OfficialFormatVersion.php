<?php

namespace App\Models;

use App\Enums\EstadoVersionFormato;
use App\Enums\EstrategiaFormato;
use App\Enums\TipoArchivoFormato;
use Database\Factories\OfficialFormatVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una versión de una plantilla oficial (docs/FORMATOS_OFICIALES.md):
 * archivo fuente + PDF base normalizado + campos mapeados + análisis.
 *
 * Una versión publicada es INMUTABLE: cambiar el mapeo o el archivo exige
 * una versión nueva (App\Services\Formatos\PlantillaOficialService), para
 * que cada documento generado se pueda reproducir/auditar con la versión
 * exacta que lo produjo.
 *
 * Esquema de cada campo en `campos` (validado por
 * App\Http\Requests\Rh\GuardarCamposFormatoRequest):
 * {id, tipo: variable|manual|texto|imagen, variable, etiqueta, texto,
 *  pagina, x, y, ancho, alto (mm, origen arriba-izquierda), font_size,
 *  align, negrita, color, formato, max_caracteres, multilinea, requerido}
 *
 * @property int $id
 * @property int $official_format_id
 * @property int $numero
 * @property EstadoVersionFormato $estado
 * @property EstrategiaFormato $estrategia
 * @property TipoArchivoFormato $file_type
 * @property string $source_disk
 * @property string $source_path
 * @property string $original_filename
 * @property string|null $source_mime
 * @property int|null $source_size
 * @property string|null $source_hash
 * @property string|null $base_path
 * @property string|null $base_hash
 * @property string $fidelidad
 * @property list<array{numero: int, ancho: float, alto: float}>|null $paginas
 * @property array<int, array<string, mixed>>|null $campos
 * @property array<string, mixed>|null $analisis
 * @property string|null $notas
 * @property int|null $created_by
 * @property int|null $publicada_por
 * @property Carbon|null $publicada_en
 * @property Carbon|null $created_at
 * @property-read OfficialFormat $formato
 * @property-read User|null $creadaPor
 * @property-read User|null $publicadaPor
 */
class OfficialFormatVersion extends Model
{
    /** @use HasFactory<OfficialFormatVersionFactory> */
    use HasFactory;

    protected $hidden = ['source_disk', 'source_path', 'base_path'];

    protected $fillable = [
        'official_format_id',
        'numero',
        'estado',
        'estrategia',
        'file_type',
        'source_disk',
        'source_path',
        'original_filename',
        'source_mime',
        'source_size',
        'source_hash',
        'base_path',
        'base_hash',
        'fidelidad',
        'paginas',
        'campos',
        'analisis',
        'notas',
        'created_by',
        'publicada_por',
        'publicada_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoVersionFormato::class,
            'estrategia' => EstrategiaFormato::class,
            'file_type' => TipoArchivoFormato::class,
            'paginas' => 'array',
            'campos' => 'array',
            'analisis' => 'array',
            'source_size' => 'integer',
            'numero' => 'integer',
            'publicada_en' => 'datetime',
        ];
    }

    public function esEditable(): bool
    {
        return $this->estado === EstadoVersionFormato::Borrador;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function camposConfigurados(): array
    {
        return array_values($this->campos ?? []);
    }

    /**
     * @return BelongsTo<OfficialFormat, $this>
     */
    public function formato(): BelongsTo
    {
        return $this->belongsTo(OfficialFormat::class, 'official_format_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publicadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicada_por');
    }

    /**
     * @return HasMany<OfficialFormatGeneration, $this>
     */
    public function generaciones(): HasMany
    {
        return $this->hasMany(OfficialFormatGeneration::class, 'official_format_version_id');
    }
}
