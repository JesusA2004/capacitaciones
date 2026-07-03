<?php

namespace App\Models;

use App\Enums\CriterioCumplimientoAsistencia;
use App\Enums\EstadoSesionEnVivo;
use App\Enums\ProveedorSesion;
use Database\Factories\SesionEnVivoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 1:1 con una Leccion de tipo "sesion_en_vivo" (mismo patron que
 * RecursoMultimedia con las lecciones de video). El enlace de la reunion lo
 * escribe directamente el instructor (proveedor manual) o lo genera la
 * integracion correspondiente (Google Meet/Zoom) al crearse la sesion.
 *
 * @property int $id
 * @property int $leccion_id
 * @property string $titulo
 * @property string|null $descripcion
 * @property ProveedorSesion $proveedor
 * @property Carbon $fecha_inicio
 * @property int $duracion_minutos
 * @property string|null $enlace_reunion
 * @property string|null $id_reunion_externa
 * @property array<string, mixed>|null $datos_proveedor
 * @property EstadoSesionEnVivo $estado
 * @property int $creado_por
 * @property Carbon|null $recordatorio_enviado_en
 * @property int $porcentaje_minimo_asistencia
 * @property int|null $minutos_minimos_asistencia
 * @property int $tolerancia_minutos
 * @property CriterioCumplimientoAsistencia $criterio_cumplimiento
 * @property bool $considerar_tiempo_previo
 * @property bool $considerar_tiempo_posterior
 */
class SesionEnVivo extends Model
{
    /** @use HasFactory<SesionEnVivoFactory> */
    use HasFactory;

    protected $table = 'sesiones_en_vivo';

    protected $fillable = [
        'leccion_id', 'titulo', 'descripcion', 'proveedor', 'fecha_inicio', 'duracion_minutos',
        'enlace_reunion', 'id_reunion_externa', 'datos_proveedor', 'estado', 'creado_por',
        'recordatorio_enviado_en', 'porcentaje_minimo_asistencia', 'minutos_minimos_asistencia',
        'tolerancia_minutos', 'criterio_cumplimiento', 'considerar_tiempo_previo', 'considerar_tiempo_posterior',
    ];

    protected function casts(): array
    {
        return [
            'proveedor' => ProveedorSesion::class,
            'fecha_inicio' => 'datetime',
            'datos_proveedor' => 'array',
            'estado' => EstadoSesionEnVivo::class,
            'recordatorio_enviado_en' => 'datetime',
            'criterio_cumplimiento' => CriterioCumplimientoAsistencia::class,
            'considerar_tiempo_previo' => 'boolean',
            'considerar_tiempo_posterior' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Leccion, $this>
     */
    public function leccion(): BelongsTo
    {
        return $this->belongsTo(Leccion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return HasMany<Asistencia, $this>
     */
    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class);
    }

    /**
     * @return HasMany<RegistroSesion, $this>
     */
    public function registrosSesion(): HasMany
    {
        return $this->hasMany(RegistroSesion::class);
    }

    /**
     * @return HasMany<SincronizacionReunion, $this>
     */
    public function sincronizaciones(): HasMany
    {
        return $this->hasMany(SincronizacionReunion::class);
    }

    public function registroSesionDelProveedor(): ?RegistroSesion
    {
        return $this->registrosSesion()->where('proveedor', $this->proveedor->value)->first();
    }
}
