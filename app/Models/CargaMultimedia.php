<?php

namespace App\Models;

use App\Enums\EstadoCargaMultimedia;
use App\Enums\TipoRecursoMultimedia;
use Database\Factories\CargaMultimediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sesion de carga de video por bloques reanudable (ver
 * App\Services\Multimedia\CargaResumibleService). `identificador` es el
 * UUID publico que usa el frontend; `id` nunca se expone en las rutas de
 * bloques para no filtrar el autoincremental.
 *
 * @property int $id
 * @property string $identificador
 * @property int $user_id
 * @property int|null $recurso_multimedia_id
 * @property string $nombre_original
 * @property TipoRecursoMultimedia $tipo
 * @property string|null $ruta_temporal
 * @property int|null $tamano_total_bytes
 * @property int|null $tamano_bloque_bytes
 * @property int|null $total_bloques
 * @property int $bytes_recibidos
 * @property array<int, int>|null $bloques_recibidos
 * @property string|null $hash_esperado
 * @property string|null $hash_calculado
 * @property EstadoCargaMultimedia $estado
 * @property string|null $error
 * @property Carbon|null $expira_en
 */
class CargaMultimedia extends Model
{
    /** @use HasFactory<CargaMultimediaFactory> */
    use HasFactory;

    protected $table = 'cargas_multimedia';

    protected $fillable = [
        'identificador', 'user_id', 'recurso_multimedia_id', 'nombre_original', 'tipo',
        'ruta_temporal', 'tamano_total_bytes', 'tamano_bloque_bytes', 'total_bloques',
        'bytes_recibidos', 'bloques_recibidos', 'hash_esperado', 'hash_calculado',
        'estado', 'error', 'expira_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoRecursoMultimedia::class,
            'estado' => EstadoCargaMultimedia::class,
            'bloques_recibidos' => 'array',
            'expira_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<RecursoMultimedia, $this>
     */
    public function recursoMultimedia(): BelongsTo
    {
        return $this->belongsTo(RecursoMultimedia::class);
    }

    public function porcentaje(): int
    {
        if (! $this->tamano_total_bytes) {
            return 0;
        }

        return (int) round(($this->bytes_recibidos / $this->tamano_total_bytes) * 100);
    }

    /**
     * @return array<int, int>
     */
    public function bloquesFaltantes(): array
    {
        if ($this->total_bloques === null) {
            return [];
        }

        $recibidos = $this->bloques_recibidos ?? [];

        return array_values(array_diff(range(0, $this->total_bloques - 1), $recibidos));
    }

    public function estaCompleta(): bool
    {
        return $this->total_bloques !== null && count($this->bloques_recibidos ?? []) >= $this->total_bloques;
    }
}
