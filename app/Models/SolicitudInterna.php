<?php

namespace App\Models;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoBaja;
use App\Enums\TipoSolicitudInterna;
use Database\Factories\SolicitudInternaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Solicitud interna de un colaborador (permiso, incapacidad, constancia,
 * actualización de datos, etc. — ver docs/SOLICITUDES_UNIFICADAS.md).
 *
 * `TipoSolicitudInterna::Vacaciones` SÍ vive aquí (flujo web unificado,
 * comparte saldo con App\Services\Vacaciones\VacacionesService::saldo()).
 * App\Models\SolicitudVacaciones sigue existiendo aparte solo como el
 * endpoint legacy que usa la app móvil (App\Http\Controllers\VacacionesController) —
 * ambos caminos descuentan del mismo saldo, pero NO son la misma tabla; no
 * asumas que unificar esta clase también unificó esa tabla.
 *
 * @property int $id
 * @property string $folio
 * @property int $user_id
 * @property int|null $colaborador_objetivo_id
 * @property Carbon|null $fecha_efectiva
 * @property TipoBaja|null $tipo_baja
 * @property TipoSolicitudInterna $tipo
 * @property EstadoSolicitudInterna $estado
 * @property Carbon|null $fecha_inicio
 * @property Carbon|null $fecha_fin
 * @property int|null $dias_solicitados
 * @property float|null $monto_solicitado
 * @property int|null $plazo_meses
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
        'colaborador_id',
        'colaborador_objetivo_id',
        'objetivo_colaborador_id',
        'fecha_efectiva',
        'tipo_baja',
        'tipo',
        'estado',
        'fecha_inicio',
        'fecha_fin',
        'dias_solicitados',
        'monto_solicitado',
        'plazo_meses',
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
            'tipo_baja' => TipoBaja::class,
            'estado' => EstadoSolicitudInterna::class,
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha_efectiva' => 'date',
            'dias_solicitados' => 'integer',
            'monto_solicitado' => 'decimal:2',
            'plazo_meses' => 'integer',
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
     * Colaborador (persona/empleo) que presenta la solicitud. Fuente de
     * verdad nueva para "quién es esta solicitud" — `usuario()`/`user_id` se
     * conserva solo como actor (quién hizo el submit desde su sesión), nunca
     * como la persona en lógica de negocio nueva. Ver personaSolicitante().
     *
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    /**
     * Sujeto de la solicitud cuando NO es quien la crea (hoy solo
     * BajaColaborador: la crea un gerente/RH sobre otro colaborador).
     * Legacy: apunta a `users`. Ver objetivoColaborador() para el
     * equivalente sobre `colaboradores`.
     *
     * @return BelongsTo<User, $this>
     */
    public function colaboradorObjetivo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'colaborador_objetivo_id');
    }

    /**
     * Equivalente a colaboradorObjetivo() pero apuntando directo a
     * `colaboradores` (objetivo_colaborador_id) — fuente de verdad nueva
     * para el sujeto de una solicitud creada por alguien más (p. ej.
     * BajaColaborador).
     *
     * @return BelongsTo<Colaborador, $this>
     */
    public function objetivoColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'objetivo_colaborador_id');
    }

    /**
     * El colaborador (persona) dueño de esta solicitud: prefiere
     * `colaborador_id` (fuente de verdad nueva) y cae a
     * `usuario->colaborador` mientras el flujo de creación de solicitudes
     * no puebla esa columna todavía — nunca debe regresar null solo porque
     * `colaborador_id` sigue sin llenarse en una solicitud existente.
     */
    public function personaSolicitante(): ?Colaborador
    {
        return $this->colaborador ?? $this->usuario?->colaborador;
    }

    /**
     * El colaborador (persona) sujeto de una baja (App\Enums\TipoSolicitudInterna::BajaColaborador):
     * prefiere `objetivo_colaborador_id` (fuente de verdad nueva) y cae a
     * `colaboradorObjetivo->colaborador` mientras el flujo de creación de
     * solicitudes no puebla esa columna en solicitudes antiguas — mismo
     * criterio que personaSolicitante(). Usado por
     * App\Services\Finiquitos\FiniquitoService para no encadenar
     * User->colaborador a mano.
     */
    public function colaboradorDeBaja(): ?Colaborador
    {
        return $this->objetivoColaborador ?? $this->colaboradorObjetivo?->colaborador;
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

    /**
     * Documento oficial automático de esta solicitud (config/solicitudes.php
     * + App\Services\Solicitudes\SolicitudFormatoOficialService) — distinto
     * de documentosGenerados(), que son plantillas DOCX manuales/opcionales.
     *
     * @return HasMany<OfficialFormatGeneration, $this>
     */
    public function officialFormatGenerations(): HasMany
    {
        return $this->hasMany(OfficialFormatGeneration::class, 'solicitud_interna_id');
    }

    /**
     * @return HasOne<FiniquitoCalculo, $this>
     */
    public function finiquitoCalculo(): HasOne
    {
        return $this->hasOne(FiniquitoCalculo::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo', 'estado', 'revisado_por', 'motivo_rechazo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
