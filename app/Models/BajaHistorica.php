<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

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
 * @property string|null $unidad_externa Unidad de negocio fuera de People (config rotacion.unidades_externas); entonces sucursal_id es nulo.
 * @property int|null $puesto_id
 * @property int|null $departamento_id
 * @property Carbon|null $fecha_ingreso
 * @property Carbon $fecha_baja Último día laborado.
 * @property int|null $antiguedad_dias Antigüedad APROXIMADA que reporta la fuente cuando no hay fecha de ingreso.
 * @property string|null $rango_antiguedad RangoAntiguedad reportado por la fuente cuando no hay fecha de ingreso.
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
        'importacion_excel_id', 'llave', 'nombre', 'numero_empleado', 'colaborador_id', 'baja_id', 'empresa_id', 'sucursal_id', 'unidad_externa', 'puesto_id',
        'departamento_id', 'fecha_ingreso', 'fecha_baja', 'antiguedad_dias', 'rango_antiguedad', 'motivo_original', 'causal_analitica_id', 'estado', 'nota', 'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
            'fecha_baja' => 'date',
            'antiguedad_dias' => 'integer',
        ];
    }

    /**
     * Llave del evento: misma persona, misma fecha de baja y misma sucursal.
     * Un reingreso que vuelve a salir tiene otra fecha: es otro evento.
     */
    public static function llaveDe(string $nombre, ?string $numeroEmpleado, string $fechaBaja, ?int $sucursalId, ?string $unidadExterna = null): string
    {
        $persona = $numeroEmpleado !== null && trim($numeroEmpleado) !== ''
            ? 'n:'.trim($numeroEmpleado)
            : 'p:'.self::normalizar($nombre);
        $partes = [$persona, $fechaBaja, (string) $sucursalId];

        // Solo las bajas de una unidad externa llevan la unidad en la llave:
        // las llaves de las sucursales de People no cambian.
        if ($sucursalId === null && $unidadExterna !== null && trim($unidadExterna) !== '') {
            $partes[] = 'x:'.self::normalizar($unidadExterna);
        }

        return hash('sha256', implode('|', $partes));
    }

    /**
     * Id NEGATIVO y estable con el que Rotación agrupa una unidad externa
     * junto a las sucursales (nunca choca con un id real de sucursal).
     */
    public static function idUnidadExterna(string $unidad): int
    {
        return -1 - (int) (crc32(self::normalizar($unidad)) % 1000000);
    }

    /**
     * Históricas dentro del alcance de Rotación: sus sucursales y, solo si el
     * filtro lo permite (alcance global, sin empresa ni sucursal elegida), las
     * de unidades externas.
     *
     * @param  Builder<self>  $query
     * @param  Collection<int, int>  $sucursales
     */
    public function scopeEnAlcanceRotacion(Builder $query, Collection $sucursales, bool $externas): void
    {
        $query->where(function (Builder $q) use ($sucursales, $externas): void {
            $q->whereIn('sucursal_id', $sucursales->all());

            if ($externas) {
                $q->orWhere(fn (Builder $x) => $x->whereNull('sucursal_id')->whereNotNull('unidad_externa'));
            }
        });
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
