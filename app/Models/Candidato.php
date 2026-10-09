<?php

namespace App\Models;

use App\Enums\EstadoCandidato;
use App\Enums\TipoSeguimientoCandidato;
use Database\Factories\CandidatoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 * @property int|null $departamento_id
 * @property int|null $puesto_objetivo_id
 * @property int|null $vacante_id
 * @property bool $espontaneo
 * @property int|null $campana_reclutamiento_id
 * @property string $nombre
 * @property string|null $apellidos
 * @property string|null $telefono
 * @property string|null $correo
 * @property string|null $fuente
 * @property string|null $cv_disk
 * @property string|null $cv_path
 * @property string|null $cv_original_name
 * @property string|null $observaciones
 * @property string|null $documentos_solicitados
 * @property int|null $responsable_rh_id
 * @property int|null $gerente_involucrado_id
 * @property EstadoCandidato $estado
 * @property Carbon|null $fecha_entrevista
 * @property string|null $resultado_entrevista
 * @property int|null $creado_por
 * @property int|null $colaborador_id
 * @property Carbon|null $contratado_en
 * @property int $etapa_maxima
 * @property string|null $motivo_salida
 * @property int|null $motivo_rechazo_id
 * @property bool|null $recontratable
 * @property Carbon|null $salida_en
 * @property int|null $salida_por
 * @property Carbon|null $autorizado_rh_en
 * @property Carbon|null $updated_at
 * @property Carbon|null $created_at
 */
class Candidato extends Model
{
    /** @use HasFactory<CandidatoFactory> */
    use HasFactory, SoftDeletes;

    /**
     * El disco/ruta reales del NAS nunca se exponen al frontend (ver
     * docs/SYNOLOGY_STORAGE.md); el cliente solo conoce tieneCv().
     */
    protected $hidden = ['cv_disk', 'cv_path'];

    protected $appends = ['tiene_cv', 'fase'];

    protected $fillable = [
        'empresa_id',
        'sucursal_id',
        'departamento_id',
        'puesto_objetivo_id',
        'vacante_id',
        'espontaneo',
        'campana_reclutamiento_id',
        'nombre',
        'apellidos',
        'telefono',
        'correo',
        'fuente',
        'cv_disk',
        'cv_path',
        'cv_original_name',
        'cv_mime',
        'cv_size',
        'observaciones',
        'documentos_solicitados',
        'responsable_rh_id',
        'gerente_involucrado_id',
        'estado',
        'fecha_entrevista',
        'resultado_entrevista',
        'creado_por',
        'colaborador_id',
        'contratado_en',
        'etapa_maxima',
        'motivo_salida',
        'motivo_rechazo_id',
        'recontratable',
        'salida_en',
        'salida_por',
        'autorizado_rh_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoCandidato::class,
            'espontaneo' => 'boolean',
            'fecha_entrevista' => 'datetime',
            'contratado_en' => 'datetime',
            'cv_size' => 'integer',
            'etapa_maxima' => 'integer',
            'salida_en' => 'datetime',
            'autorizado_rh_en' => 'datetime',
            'recontratable' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MotivoRechazoCandidato, $this>
     */
    public function motivoRechazo(): BelongsTo
    {
        return $this->belongsTo(MotivoRechazoCandidato::class);
    }

    /**
     * Puesto/sucursal/empresa nunca se capturan a mano cuando hay una
     * vacante ligada: la vacante ya nació con esos tres datos resueltos
     * (baja, headcount nuevo, etc.), así que aquí se derivan automáticamente
     * para que nunca queden inconsistentes entre sí — el formulario solo
     * pide esos campos por separado para un candidato sin vacante activa
     * todavía (pipeline general).
     */
    protected static function booted(): void
    {
        static::saving(function (self $candidato): void {
            // Se re-deriva siempre que haya vacante (no solo cuando
            // vacante_id cambia): así un valor manual enviado junto con la
            // misma vacante nunca queda inconsistente con ella.
            if ($candidato->vacante_id === null) {
                return;
            }

            $vacante = Vacante::query()
                ->whereKey($candidato->vacante_id)
                ->first(['puesto_id', 'sucursal_id', 'empresa_id', 'departamento_id']);

            if ($vacante === null) {
                return;
            }

            // Empresa, sucursal, departamento y puesto SIEMPRE de la vacante:
            // lo que mande el formulario (o una petición manipulada) se ignora.
            $candidato->puesto_objetivo_id = $vacante->puesto_id;
            $candidato->sucursal_id = $vacante->sucursal_id;
            $candidato->empresa_id = $vacante->empresa_id;
            $candidato->departamento_id = $vacante->departamento_id ?? $candidato->departamento_id;
        });
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombre} {$this->apellidos}");
    }

    public function getTieneCvAttribute(): bool
    {
        return $this->cv_path !== null;
    }

    /**
     * Columna canónica del tablero (CLAUDE.md §4): nunca los 16 sub-estados
     * técnicos del workflow.
     */
    public function getFaseAttribute(): string
    {
        // Se agrega en cada serialización ($appends), incluso cuando el
        // candidato se cargó con columnas parciales (p. ej. `candidato:id,nombre`
        // en la invitación QR): sin `estado` cargado no hay fase, nunca un 500.
        $valor = $this->getAttributes()['estado'] ?? null;
        $estado = $valor instanceof EstadoCandidato ? $valor : (is_string($valor) ? EstadoCandidato::tryFrom($valor) : null);

        return $estado?->faseCanonica() ?? '';
    }

    /**
     * Colaborador creado al contratar a este candidato (trazabilidad
     * reclutamiento → alta, ver App\Services\Reclutamiento\ContratacionCandidatoService).
     *
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
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
     * @return BelongsTo<Departamento, $this>
     */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puestoObjetivo(): BelongsTo
    {
        return $this->belongsTo(Puesto::class, 'puesto_objetivo_id');
    }

    /**
     * Campaña de reclutamiento de la que vino (atribución exacta del costo).
     *
     * @return BelongsTo<CampanaReclutamiento, $this>
     */
    public function campana(): BelongsTo
    {
        return $this->belongsTo(CampanaReclutamiento::class, 'campana_reclutamiento_id');
    }

    /**
     * @return HasMany<IntervencionCandidato, $this>
     */
    public function intervenciones(): HasMany
    {
        return $this->hasMany(IntervencionCandidato::class);
    }

    /**
     * @return BelongsTo<Vacante, $this>
     */
    public function vacante(): BelongsTo
    {
        return $this->belongsTo(Vacante::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsableRh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_rh_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function gerenteInvolucrado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerente_involucrado_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return HasMany<SeguimientoCandidato, $this>
     */
    public function seguimientos(): HasMany
    {
        return $this->hasMany(SeguimientoCandidato::class)->orderByDesc('fecha');
    }

    /**
     * Seguimiento más reciente (cualquier tipo) — usado por la tarjeta del
     * tablero de candidatos para mostrar la nota/actividad más reciente sin
     * cargar el historial completo.
     *
     * @return HasOne<SeguimientoCandidato, $this>
     */
    public function ultimoSeguimiento(): HasOne
    {
        return $this->hasOne(SeguimientoCandidato::class)->latestOfMany('fecha');
    }

    /**
     * Fecha del último cambio de fase (App\Enums\EstadoCandidato) —
     * usado por la tarjeta del tablero para calcular "tiempo en la fase
     * actual" (created_at del candidato si todavía no tiene ninguno).
     *
     * @return HasOne<SeguimientoCandidato, $this>
     */
    public function ultimoCambioEstado(): HasOne
    {
        return $this->hasOne(SeguimientoCandidato::class)
            ->where('tipo', TipoSeguimientoCandidato::CambioEstado)
            ->latestOfMany('fecha');
    }

    /**
     * Más reciente si hay varias (p. ej. una alta cancelada y luego otra
     * generada de nuevo) — alta digital legacy, fuera del recorrido actual.
     *
     * @return HasOne<AltaDigital, $this>
     */
    public function altaDigital(): HasOne
    {
        return $this->hasOne(AltaDigital::class)->latestOfMany();
    }

    /**
     * @return HasOne<IncorporacionInvitacion, $this>
     */
    public function incorporacionInvitacion(): HasOne
    {
        return $this->hasOne(IncorporacionInvitacion::class)->latestOfMany();
    }

    /**
     * @return HasMany<CandidatoEntrevista, $this>
     */
    public function entrevistas(): HasMany
    {
        return $this->hasMany(CandidatoEntrevista::class)->orderByDesc('realizada_en');
    }

    /**
     * @return HasMany<CandidatoPsicometrica, $this>
     */
    public function psicometricas(): HasMany
    {
        return $this->hasMany(CandidatoPsicometrica::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<CandidatoSocioeconomico, $this>
     */
    public function socioeconomicos(): HasMany
    {
        return $this->hasMany(CandidatoSocioeconomico::class)->orderByDesc('fecha_visita');
    }

    /**
     * @return HasMany<CandidatoReferencia, $this>
     */
    public function referencias(): HasMany
    {
        return $this->hasMany(CandidatoReferencia::class)->orderBy('id');
    }

    /**
     * @return HasMany<CandidatoEvidencia, $this>
     */
    public function evidencias(): HasMany
    {
        return $this->hasMany(CandidatoEvidencia::class)->orderBy('id');
    }

    /**
     * Aprobaciones de la selección (preautorización del gerente y
     * autorización final de RH).
     *
     * @return MorphMany<Aprobacion, $this>
     */
    public function aprobaciones(): MorphMany
    {
        return $this->morphMany(Aprobacion::class, 'aprobable')->orderBy('ronda')->orderBy('id');
    }
}
