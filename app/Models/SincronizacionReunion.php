<?php

namespace App\Models;

use App\Enums\EstadoSincronizacionReunion;
use App\Enums\ProveedorSesion;
use App\Enums\TipoSincronizacionReunion;
use Database\Factories\SincronizacionReunionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Historial de cada intento de sincronización de asistencia de una sesión
 * en vivo. Ver docs/AUDITORIA_CUMPLIMIENTO.md sección 4-5.
 *
 * @property int $id
 * @property int $sesion_en_vivo_id
 * @property ProveedorSesion $proveedor
 * @property TipoSincronizacionReunion $tipo_sincronizacion
 * @property EstadoSincronizacionReunion $estado
 * @property Carbon $iniciado_en
 * @property Carbon|null $finalizado_en
 * @property int $intentos
 * @property int|null $cantidad_participantes
 * @property string|null $error
 * @property array<string, mixed>|null $resumen
 * @property string|null $job_id
 * @property int|null $iniciado_por
 */
class SincronizacionReunion extends Model
{
    /** @use HasFactory<SincronizacionReunionFactory> */
    use HasFactory;

    protected $table = 'sincronizaciones_reunion';

    protected $fillable = [
        'sesion_en_vivo_id', 'proveedor', 'tipo_sincronizacion', 'estado', 'iniciado_en', 'finalizado_en',
        'intentos', 'cantidad_participantes', 'error', 'resumen', 'job_id', 'iniciado_por',
    ];

    protected function casts(): array
    {
        return [
            'proveedor' => ProveedorSesion::class,
            'tipo_sincronizacion' => TipoSincronizacionReunion::class,
            'estado' => EstadoSincronizacionReunion::class,
            'iniciado_en' => 'datetime',
            'finalizado_en' => 'datetime',
            'resumen' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }
}
