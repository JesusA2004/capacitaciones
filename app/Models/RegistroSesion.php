<?php

namespace App\Models;

use App\Enums\EstadoSincronizacion;
use App\Enums\ProveedorSesion;
use Database\Factories\RegistroSesionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Registro de conferencia recuperado del proveedor externo para una
 * SesionEnVivo (conferenceRecord de Google Meet / reporte de reunión
 * pasada de Zoom). Ver docs/AUDITORIA_CUMPLIMIENTO.md sección 4.
 *
 * @property int $id
 * @property int $sesion_en_vivo_id
 * @property ProveedorSesion $proveedor
 * @property string|null $identificador_externo
 * @property string|null $registro_conferencia_externo
 * @property Carbon|null $inicio_real
 * @property Carbon|null $fin_real
 * @property int|null $duracion_real_segundos
 * @property EstadoSincronizacion $estado_sincronizacion
 * @property array<string, mixed>|null $respuesta_normalizada
 * @property int $intentos
 * @property string|null $ultimo_error
 * @property Carbon|null $consultado_en
 */
class RegistroSesion extends Model
{
    /** @use HasFactory<RegistroSesionFactory> */
    use HasFactory;

    protected $table = 'registros_sesion';

    protected $fillable = [
        'sesion_en_vivo_id', 'proveedor', 'identificador_externo', 'registro_conferencia_externo',
        'inicio_real', 'fin_real', 'duracion_real_segundos', 'estado_sincronizacion',
        'respuesta_normalizada', 'intentos', 'ultimo_error', 'consultado_en',
    ];

    protected function casts(): array
    {
        return [
            'proveedor' => ProveedorSesion::class,
            'inicio_real' => 'datetime',
            'fin_real' => 'datetime',
            'estado_sincronizacion' => EstadoSincronizacion::class,
            'respuesta_normalizada' => 'array',
            'consultado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SesionEnVivo, $this>
     */
    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionEnVivo::class, 'sesion_en_vivo_id');
    }

    /**
     * @return HasMany<SesionParticipante, $this>
     */
    public function participantes(): HasMany
    {
        return $this->hasMany(SesionParticipante::class);
    }
}
