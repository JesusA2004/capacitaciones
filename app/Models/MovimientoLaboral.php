<?php

namespace App\Models;

use App\Enums\TipoMovimientoLaboral;
use Database\Factories\MovimientoLaboralFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Histórico inmutable de movimientos laborales de un colaborador: altas,
 * bajas, promociones, cambios de puesto/sucursal/departamento/jefe/empresa y
 * coberturas temporales. Se crea exclusivamente a través de
 * App\Services\MovimientosLaborales\MovimientoLaboralService — nunca
 * directamente desde un controlador (ver docs/MOVIMIENTOS_LABORALES.md).
 *
 * @property int $id
 * @property int $user_id
 * @property TipoMovimientoLaboral $tipo_movimiento
 * @property int|null $empresa_anterior_id
 * @property int|null $empresa_nueva_id
 * @property int|null $sucursal_anterior_id
 * @property int|null $sucursal_nueva_id
 * @property int|null $departamento_anterior_id
 * @property int|null $departamento_nuevo_id
 * @property int|null $puesto_anterior_id
 * @property int|null $puesto_nuevo_id
 * @property int|null $jefe_anterior_id
 * @property int|null $jefe_nuevo_id
 * @property int|null $vacante_id
 * @property int|null $candidato_id
 * @property int|null $alta_digital_id
 * @property int|null $documento_id
 * @property string|null $motivo
 * @property string|null $observaciones
 * @property Carbon $fecha_movimiento
 * @property Carbon|null $fecha_fin_cobertura
 * @property int|null $registrado_por
 */
class MovimientoLaboral extends Model
{
    /** @use HasFactory<MovimientoLaboralFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'movimientos_laborales';

    protected $fillable = [
        'user_id',
        'tipo_movimiento',
        'empresa_anterior_id',
        'empresa_nueva_id',
        'sucursal_anterior_id',
        'sucursal_nueva_id',
        'departamento_anterior_id',
        'departamento_nuevo_id',
        'puesto_anterior_id',
        'puesto_nuevo_id',
        'jefe_anterior_id',
        'jefe_nuevo_id',
        'vacante_id',
        'candidato_id',
        'alta_digital_id',
        'documento_id',
        'motivo',
        'observaciones',
        'fecha_movimiento',
        'fecha_fin_cobertura',
        'registrado_por',
    ];

    protected $appends = ['descripcion'];

    protected function casts(): array
    {
        return [
            'tipo_movimiento' => TipoMovimientoLaboral::class,
            'fecha_movimiento' => 'date',
            'fecha_fin_cobertura' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresaAnterior(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_anterior_id');
    }

    /**
     * @return BelongsTo<Empresa, $this>
     */
    public function empresaNueva(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_nueva_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursalAnterior(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_anterior_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursalNueva(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_nueva_id');
    }

    /**
     * @return BelongsTo<Departamento, $this>
     */
    public function departamentoAnterior(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_anterior_id');
    }

    /**
     * @return BelongsTo<Departamento, $this>
     */
    public function departamentoNuevo(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_nuevo_id');
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puestoAnterior(): BelongsTo
    {
        return $this->belongsTo(Puesto::class, 'puesto_anterior_id');
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puestoNuevo(): BelongsTo
    {
        return $this->belongsTo(Puesto::class, 'puesto_nuevo_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function jefeAnterior(): BelongsTo
    {
        return $this->belongsTo(User::class, 'jefe_anterior_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function jefeNuevo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'jefe_nuevo_id');
    }

    /**
     * @return BelongsTo<Vacante, $this>
     */
    public function vacante(): BelongsTo
    {
        return $this->belongsTo(Vacante::class);
    }

    /**
     * @return BelongsTo<Candidato, $this>
     */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    /**
     * @return BelongsTo<AltaDigital, $this>
     */
    public function altaDigital(): BelongsTo
    {
        return $this->belongsTo(AltaDigital::class);
    }

    /**
     * @return BelongsTo<EmployeeDocument, $this>
     */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'documento_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /**
     * Frase legible del movimiento para el timeline del expediente y el
     * panel de jerarquía (ver docs/MOVIMIENTOS_LABORALES.md). Requiere las
     * relaciones *Anterior/*Nuevo cargadas; si faltan, Eloquent las carga
     * de forma perezosa (evitar en listados grandes: usar with()).
     */
    public function getDescripcionAttribute(): string
    {
        $colaborador = $this->colaborador?->nombreCompleto() ?? 'El colaborador';

        return match ($this->tipo_movimiento) {
            TipoMovimientoLaboral::Alta => "{$colaborador} causó alta".($this->puestoNuevo ? " como {$this->puestoNuevo->nombre}" : '').'.',
            TipoMovimientoLaboral::Baja => "{$colaborador} causó baja".($this->puestoAnterior ? " de {$this->puestoAnterior->nombre}" : '').($this->vacante_id ? '; se generó vacante de reemplazo.' : '.'),
            TipoMovimientoLaboral::Promocion => "{$colaborador} ascendió de {$this->nombreOr($this->puestoAnterior, 'su puesto anterior')} a {$this->nombreOr($this->puestoNuevo, 'un nuevo puesto')}.",
            TipoMovimientoLaboral::CambioPuesto => "{$colaborador} cambió de {$this->nombreOr($this->puestoAnterior, 'su puesto anterior')} a {$this->nombreOr($this->puestoNuevo, 'un nuevo puesto')}.",
            TipoMovimientoLaboral::CambioSucursal => "{$colaborador} cambió de sucursal: {$this->nombreOr($this->sucursalAnterior, '—')} → {$this->nombreOr($this->sucursalNueva, '—')}.",
            TipoMovimientoLaboral::CambioDepartamento => "{$colaborador} cambió de departamento: {$this->nombreOr($this->departamentoAnterior, '—')} → {$this->nombreOr($this->departamentoNuevo, '—')}.",
            TipoMovimientoLaboral::CambioJefe => "{$colaborador} cambió de jefe directo: {$this->nombreCompletoOr($this->jefeAnterior, '—')} → {$this->nombreCompletoOr($this->jefeNuevo, '—')}.",
            TipoMovimientoLaboral::CambioEmpresa => "{$colaborador} cambió de empresa: {$this->nombreOr($this->empresaAnterior, '—')} → {$this->nombreOr($this->empresaNueva, '—')}.",
            TipoMovimientoLaboral::CoberturaTemporal => "{$colaborador} cubre temporalmente {$this->nombreOr($this->puestoNuevo, 'un puesto')}".($this->fecha_fin_cobertura ? " hasta el {$this->fecha_fin_cobertura->toDateString()}" : '').'.',
            TipoMovimientoLaboral::Reingreso => "{$colaborador} reingresó a la organización.",
            TipoMovimientoLaboral::AjusteManual => "Ajuste manual en el expediente de {$colaborador}.",
        };
    }

    private function nombreOr(?Model $modelo, string $fallback): string
    {
        if ($modelo === null) {
            return $fallback;
        }

        $nombre = $modelo->getAttribute('nombre');

        return is_string($nombre) && $nombre !== '' ? $nombre : $fallback;
    }

    private function nombreCompletoOr(?User $usuario, string $fallback): string
    {
        return $usuario?->nombreCompleto() ?: $fallback;
    }
}
