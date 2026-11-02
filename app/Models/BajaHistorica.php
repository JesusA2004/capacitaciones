<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baja individual de un periodo ANTERIOR al módulo de Bajas, cargada con una
 * importación auditable (BajasHistoricasImportService). Es un dato de reporte:
 * nunca crea colaboradores, nunca cambia su expediente ni su estatus, y el
 * motivo_original que capturó RH no se sobrescribe jamás (las correcciones
 * cambian la causal homologada y quedan en rotacion_correcciones).
 *
 * Una misma persona puede tener varias (cada reingreso es otra relación
 * laboral); `llave` evita cargar dos veces el mismo evento. Si el evento ya
 * existe como baja del sistema, `baja_id` los liga y la histórica deja de
 * contar (estado = duplicada): nunca se cuenta dos veces.
 *
 * @property int $id
 * @property int|null $importacion_excel_id
 * @property string $llave
 * @property string $nombre
 * @property string|null $numero_empleado
 * @property int|null $colaborador_id
 * @property int|null $baja_id
 * @property int|null $empresa_id
 * @property int|null $sucursal_id
 * @property int|null $puesto_id
 * @property int|null $departamento_id
 * @property Carbon|null $fecha_ingreso
 * @property Carbon $fecha_baja Último día laborado.
 * @property string|null $motivo_original
 * @property int|null $causal_analitica_id
 * @property string $estado valida | duplicada | descartada
 * @property string|null $nota
 * @property int|null $registrado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Sucursal|null $sucursal
 * @property-read Puesto|null $puesto
 * @property-read CausalAnalitica|null $causalAnalitica
 * @property-read Colaborador|null $colaborador
 * @property-read Baja|null $baja
 */
class BajaHistorica extends Model
{
    protected $table = 'bajas_historicas';

    public const VALIDA = 'valida';

    public const DUPLICADA = 'duplicada';

    public const DESCARTADA = 'descartada';

    protected $fillable = [
        'importacion_excel_id', 'llave', 'nombre', 'numero_empleado', 'colaborador_id', 'baja_id', 'empresa_id', 'sucursal_id', 'puesto_id',
        'departamento_id', 'fecha_ingreso', 'fecha_baja', 'motivo_original', 'causal_analitica_id', 'estado', 'nota', 'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
            'fecha_baja' => 'date',
        ];
    }

    /**
     * Llave del evento: misma persona, misma fecha de baja y misma sucursal.
     * Un reingreso que vuelve a salir tiene otra fecha: es otro evento.
     */
    public static function llaveDe(string $nombre, ?string $numeroEmpleado, string $fechaBaja, ?int $sucursalId): string
    {
        $persona = $numeroEmpleado !== null && trim($numeroEmpleado) !== ''
            ? 'n:'.trim($numeroEmpleado)
            : 'p:'.self::normalizar($nombre);

        return hash('sha256', implode('|', [$persona, $fechaBaja, (string) $sucursalId]));
    }

    public static function normalizar(string $texto): string
    {
        $sinAcentos = strtr(mb_strtolower(trim($texto)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $sinAcentos));
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * @return BelongsTo<CausalAnalitica, $this>
     */
    public function causalAnalitica(): BelongsTo
    {
        return $this->belongsTo(CausalAnalitica::class);
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * @return BelongsTo<Baja, $this>
     */
    public function baja(): BelongsTo
    {
        return $this->belongsTo(Baja::class);
    }
}
