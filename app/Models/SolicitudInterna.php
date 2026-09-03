<?php

namespace App\Models;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use Database\Factories\SolicitudInternaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Solicitud interna de un colaborador (permiso, incapacidad, constancia,
 * actualización de datos, etc. — ver docs/SOLICITUDES_INTERNAS.md).
 * Vacaciones tiene su propio modelo (SolicitudVacaciones): no se duplica esa
 * lógica aquí.
 *
 * @property int $id
 * @property string $folio
 * @property int $user_id
 * @property TipoSolicitudInterna $tipo
 * @property EstadoSolicitudInterna $estado
 * @property Carbon|null $fecha_inicio
 * @property Carbon|null $fecha_fin
 * @property string $motivo
 * @property string|null $observaciones
 * @property int|null $revisado_por
 * @property Carbon|null $revisado_en
 * @property string|null $motivo_rechazo
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 */
class SolicitudInterna extends Model
{
    /** @use HasFactory<SolicitudInternaFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'solicitudes_internas';

    protected $fillable = [
        'folio',
        'user_id',
        'tipo',
        'estado',
        'fecha_inicio',
        'fecha_fin',
        'motivo',
        'observaciones',
        'revisado_por',
        'revisado_en',
        'motivo_rechazo',
        'empresa_id',
        'sucursal_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoSolicitudInterna::class,
            'estado' => EstadoSolicitudInterna::class,
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'revisado_en' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return HasMany<SolicitudInternaDocumento, $this>
     */
    public function documentos(): HasMany
    {
        return $this->hasMany(SolicitudInternaDocumento::class);
    }

    /**
     * @return HasMany<SolicitudInternaHistorial, $this>
     */
    public function historial(): HasMany
    {
        return $this->hasMany(SolicitudInternaHistorial::class)->orderBy('created_at');
    }

    /**
     * @return HasMany<GeneratedDocument, $this>
     */
    public function documentosGenerados(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class, 'solicitud_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo', 'estado', 'revisado_por', 'motivo_rechazo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
