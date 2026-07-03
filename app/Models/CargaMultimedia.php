<?php

namespace App\Models;

use App\Enums\EstadoMultimedia;
use Database\Factories\CargaMultimediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $recurso_multimedia_id
 * @property string $nombre_original
 * @property string|null $ruta_temporal
 * @property int|null $tamano_total_bytes
 * @property int $bytes_recibidos
 * @property EstadoMultimedia $estado
 * @property string|null $error
 */
class CargaMultimedia extends Model
{
    /** @use HasFactory<CargaMultimediaFactory> */
    use HasFactory;

    protected $table = 'cargas_multimedia';

    protected $fillable = [
        'user_id', 'recurso_multimedia_id', 'nombre_original', 'ruta_temporal',
        'tamano_total_bytes', 'bytes_recibidos', 'estado', 'error',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoMultimedia::class,
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
}
