<?php

namespace App\Models;

use App\Enums\EstadoMultimedia;
use App\Enums\TipoRecursoMultimedia;
use Database\Factories\RecursoMultimediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Metadatos de un archivo multimedia (video/documento/imagen). El archivo
 * en si vive en el disco 'nas' (ver MediaStorageService); esta tabla nunca
 * almacena el binario, solo la ruta logica y los metadatos.
 *
 * @property int $id
 * @property TipoRecursoMultimedia $tipo
 * @property string $nombre_original
 * @property string $nombre_interno
 * @property string $disco
 * @property string $ruta_original
 * @property string|null $ruta_hls_manifiesto
 * @property string|null $ruta_miniatura
 * @property string|null $mime_type
 * @property int|null $tamano_bytes
 * @property int|null $duracion_segundos
 * @property string|null $resolucion_original
 * @property string|null $hash_sha256
 * @property EstadoMultimedia $estado
 * @property string|null $error_procesamiento
 * @property array<string, mixed>|null $metadatos
 * @property int $subido_por
 */
class RecursoMultimedia extends Model
{
    /** @use HasFactory<RecursoMultimediaFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'recursos_multimedia';

    protected $fillable = [
        'tipo', 'nombre_original', 'nombre_interno', 'disco', 'ruta_original',
        'ruta_hls_manifiesto', 'ruta_miniatura', 'mime_type', 'tamano_bytes',
        'duracion_segundos', 'resolucion_original', 'hash_sha256', 'estado',
        'error_procesamiento', 'metadatos', 'subido_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoRecursoMultimedia::class,
            'estado' => EstadoMultimedia::class,
            'metadatos' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    /**
     * @return HasMany<Leccion, $this>
     */
    public function lecciones(): HasMany
    {
        return $this->hasMany(Leccion::class);
    }

    public function estaDisponible(): bool
    {
        return $this->estado === EstadoMultimedia::Disponible;
    }
}
